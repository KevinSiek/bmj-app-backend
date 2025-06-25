<?php

namespace App\Http\Controllers;

use App\Models\DeliveryOrder;
use App\Models\Quotation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DeliveryOrderController extends Controller
{
    const PROCESS = "Process";
    const SHIPPED = "Shipped";
    const DELIVERED = "Delivered";
    const CANCELLED = "Cancelled";

    /**
     * Get a single delivery order
     */
    public function get(Request $request, $id)
    {
        try {
            $deliveryOrder = $this->getAccessedDeliveryOrder($request)
                ->with(['quotation.detailQuotations.sparepart', 'quotation.purchaseOrder'])
                ->findOrFail($id);

            $quotation = $deliveryOrder->quotation;
            $purchaseOrder = $quotation ? $quotation->purchaseOrder : null;

            $spareParts = $quotation && $quotation->detailQuotations ? $quotation->detailQuotations->map(function ($detail) {
                $sparepart = $detail->sparepart;
                return [
                    'sparepart_name' => $sparepart ? $sparepart->sparepart_name : '',
                    'sparepart_number' => $sparepart ? $sparepart->sparepart_number : '',
                    'quantity' => $detail->quantity ?? 0,
                    'unit_price_sell' => $detail->unit_price ?? 0,
                    'total_price' => ($detail->quantity * ($detail->unit_price ?? 0)),
                    'stock' => $detail->is_indent ? 'indent' : 'available'
                ];
            })->toArray() : [];

            $formattedDeliveryOrder = [
                'id' => (string) ($deliveryOrder->id ?? ''),
                'purchaseOrder' => [
                    'purchaseOrderNumber' => $purchaseOrder ? $purchaseOrder->purchase_order_number : '',
                    'purchaseOrderDate' => $purchaseOrder ? $purchaseOrder->purchase_order_date : '',
                    'type' => $purchaseOrder && $quotation ? $quotation->type : ''
                ],
                'type' => $deliveryOrder->type ?? '',
                'notes' => $deliveryOrder->notes ?? '',
                'status' => $deliveryOrder->current_status ?? '',
                'spareparts' => $spareParts
            ];

            return response()->json([
                'message' => 'Delivery order retrieved successfully',
                'data' => $formattedDeliveryOrder,
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    /**
     * Get all delivery orders with filters
     */
    public function getAll(Request $request)
    {
        try {
            $query = $this->getAccessedDeliveryOrder($request)
                ->with(['quotation.detailQuotations.sparepart', 'quotation.purchaseOrder']);

            // Apply search term filter
            $q = $request->query('search');
            if ($q) {
                $query->where(function ($query) use ($q) {
                    $query->where('type', 'like', '%' . $q . '%')
                        ->orWhere('current_status', 'like', '%' . $q . '%')
                        ->orWhereHas('quotation', function ($qry) use ($q) {
                            $qry->where('quotation_number', 'like', '%' . $q . '%')
                                ->orWhere('project', 'like', '%' . $q . '%');
                        })
                        ->orWhereHas('quotation.purchaseOrder', function ($qry) use ($q) {
                            $qry->where('purchase_order_number', 'like', '%' . $q . '%');
                        });
                });
            }

            // Apply date filters
            $month = $request->query('month');
            $year = $request->query('year');
            if ($year) {
                $query->whereYear('created_at', $year);
                if ($month) {
                    $monthNumber = date('m', strtotime($month));
                    $query->whereMonth('created_at', $monthNumber);
                }
            }

            $deliveryOrders = $query->orderBy('created_at', 'DESC')
                ->paginate(20)->through(function ($do) {
                    $quotation = $do->quotation;
                    $purchaseOrder = $quotation ? $quotation->purchaseOrder : null;

                    $spareParts = $quotation && $quotation->detailQuotations ? $quotation->detailQuotations->map(function ($detail) {
                        $sparepart = $detail->sparepart;
                        return [
                            'sparepart_name' => $sparepart ? $sparepart->sparepart_name : '',
                            'sparepart_number' => $sparepart ? $sparepart->sparepart_number : '',
                            'quantity' => $detail->quantity ?? 0,
                            'unit_price_sell' => $detail->unit_price ?? 0,
                            'total_price' => ($detail->quantity * ($detail->unit_price ?? 0)),
                            'stock' => $detail->is_indent ? 'indent' : 'available'
                        ];
                    })->toArray() : [];

                    return [
                        'id' => (string) ($do->id ?? ''),
                        'purchaseOrder' => [
                            'purchaseOrderNumber' => $purchaseOrder ? $purchaseOrder->purchase_order_number : '',
                            'purchaseOrderDate' => $purchaseOrder ? $purchaseOrder->purchase_order_date : '',
                            'type' => $purchaseOrder && $quotation ? $quotation->type : ''
                        ],
                        'type' => $do->type ?? '',
                        'notes' => $do->notes ?? '',
                        'status' => $do->current_status ?? '',
                        'spareparts' => $spareParts
                    ];
                });

            return response()->json([
                'message' => 'List of delivery orders retrieved successfully',
                'data' => $deliveryOrders,
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    /**
     * Update delivery order
     */
    public function update(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $deliveryOrder = $this->getAccessedDeliveryOrder($request)
                ->findOrFail($id);

            // Map camelCase input to snake_case
            $input = $request->all();
            $mappedInput = [];
            $fieldMap = [
                'quotationId' => 'quotation_id',
                'type' => 'type',
                'currentStatus' => 'current_status',
                'notes' => 'notes',
            ];
            foreach ($fieldMap as $camel => $snake) {
                if (array_key_exists($camel, $input)) {
                    $mappedInput[$snake] = $input[$camel];
                }
            }

            // Validation rules
            $validator = Validator::make($mappedInput, [
                'quotation_id' => 'nullable|exists:quotations,id',
                'type' => 'nullable|string|max:255',
                'current_status' => ['nullable', Rule::in([self::PROCESS, self::SHIPPED, self::DELIVERED, self::CANCELLED])],
                'notes' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], Response::HTTP_BAD_REQUEST);
            }

            // Prepare update data
            $updateData = [];
            foreach ($fieldMap as $camel => $snake) {
                if (array_key_exists($camel, $input) && $input[$camel] !== null) {
                    $updateData[$snake] = $input[$camel];
                }
            }

            // Update if there are changes
            if (!empty($updateData)) {
                $deliveryOrder->update($updateData);
            }

            DB::commit();

            // Fetch updated delivery order
            $updatedDeliveryOrder = $this->getAccessedDeliveryOrder($request)
                ->with(['quotation.detailQuotations.sparepart', 'quotation.purchaseOrder'])
                ->findOrFail($id);

            $quotation = $updatedDeliveryOrder->quotation;
            $purchaseOrder = $quotation ? $quotation->purchaseOrder : null;

            $spareParts = $quotation && $quotation->detailQuotations ? $quotation->detailQuotations->map(function ($detail) {
                $sparepart = $detail->sparepart;
                return [
                    'sparepart_name' => $sparepart ? $sparepart->sparepart_name : '',
                    'sparepart_number' => $sparepart ? $sparepart->sparepart_number : '',
                    'quantity' => $detail->quantity ?? 0,
                    'unit_price_sell' => $detail->unit_price ?? 0,
                    'total_price' => ($detail->quantity * ($detail->unit_price ?? 0)),
                    'stock' => $detail->is_indent ? 'indent' : 'available'
                ];
            })->toArray() : [];

            $formattedDeliveryOrder = [
                'id' => (string) ($updatedDeliveryOrder->id ?? ''),
                'purchaseOrder' => [
                    'purchaseOrderNumber' => $purchaseOrder ? $purchaseOrder->purchase_order_number : '',
                    'purchaseOrderDate' => $purchaseOrder ? $purchaseOrder->purchase_order_date : '',
                    'type' => $purchaseOrder && $quotation ? $quotation->type : ''
                ],
                'type' => $updatedDeliveryOrder->type ?? '',
                'notes' => $updatedDeliveryOrder->notes ?? '',
                'status' => $updatedDeliveryOrder->current_status ?? '',
                'spareparts' => $spareParts
            ];

            return response()->json([
                'message' => 'Delivery order updated successfully',
                'data' => $formattedDeliveryOrder
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->handleError($th, 'Failed to update delivery order');
        }
    }

    /**
     * Get accessed delivery orders based on user role
     */
    protected function getAccessedDeliveryOrder($request)
    {
        try {
            $user = $request->user();
            $userId = $user->id;
            $role = $user->role;

            $query = DeliveryOrder::query();

            // Restrict access for Marketing role
            if ($role == 'Marketing') {
                $query->whereHas('quotation.purchaseOrder', function ($q) use ($userId) {
                    $q->where('employee_id', $userId);
                });
            }

            return $query;
        } catch (\Throwable $th) {
            return DeliveryOrder::whereNull('id');
        }
    }

    /**
     * Handle errors consistently
     */
    protected function handleError(\Throwable $th, $message = 'Internal server error')
    {
        return response()->json([
            'message' => $message,
            'error' => $th->getMessage()
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * Handle not found errors
     */
    protected function handleNotFound($message = 'Resource not found')
    {
        return response()->json([
            'message' => $message
        ], Response::HTTP_NOT_FOUND);
    }
}
