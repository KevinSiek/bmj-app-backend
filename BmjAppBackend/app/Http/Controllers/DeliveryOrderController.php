<?php

namespace App\Http\Controllers;

use App\Models\DeliveryOrder;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DeliveryOrderController extends Controller
{
    const ON_PROGRESS = "On Progress";
    const DONE = "Done";

    protected $quotationController;
    public function __construct(QuotationController $quotationController)
    {
        $this->quotationController = $quotationController;
    }

    /**
     * Format delivery order according to API contract
     */
    private function formatDeliveryOrder($deliveryOrder)
    {
        $purchaseOrder = $deliveryOrder->purchaseOrder;
        $quotation = $purchaseOrder ? $purchaseOrder->quotation : null;
        $customer = $quotation ? $quotation->customer : null;

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
            'id' => (string) ($deliveryOrder->id ?? ''),
            'current_status' => $deliveryOrder->current_status ?? '',
            'delivery_order' => [
                'delivery_order_number' => $deliveryOrder->delivery_order_number ?? '',
                'delivery_order_date' => $deliveryOrder->delivery_order_date ?? '',
                'received_by' => $deliveryOrder->received_by ?? '',
                'picked_by' => $deliveryOrder->picked_by ?? '',
                'prepared_by' => $deliveryOrder->prepared_by ?? '',
                'ship_mode' => $deliveryOrder->ship_mode ?? '',
                'order_type' => $deliveryOrder->order_type ?? '',
                'delivery' => $deliveryOrder->delivery ?? '',
                'npwp' => $deliveryOrder->npwp ?? ''
            ],
            'purchase_order' => [
                'purchase_order_number' => $purchaseOrder ? $purchaseOrder->purchase_order_number : '',
                'purchase_order_date' => $purchaseOrder ? $purchaseOrder->purchase_order_date : '',
                'type' => $quotation ? $quotation->type : '',
                'version' => $purchaseOrder ? $purchaseOrder->version : '',
            ],
            'customer' => [
                'company_name' => $customer ? $customer->company_name : '',
                'address' => $customer ? $customer->address : '',
                'city' => $customer ? $customer->city : '',
                'province' => $customer ? $customer->province : '',
                'office' => $customer ? $customer->office : '',
                'urban' => $customer ? $customer->urban : '',
                'subdistrict' => $customer ? $customer->subdistrict : '',
                'postal_code' => $customer ? $customer->postal_code : ''
            ],
            'notes' => $deliveryOrder->notes ?? '',
            'status' => $quotation->status ?? [],
            'spareparts' => $spareParts
        ];
    }

    /**
     * Get a single delivery order
     */
    public function get(Request $request, $id)
    {
        try {
            $deliveryOrder = $this->getAccessedDeliveryOrder($request)
                ->with(['purchaseOrder.quotation.customer', 'purchaseOrder.quotation.detailQuotations.sparepart'])
                ->findOrFail($id);

            return response()->json([
                'message' => 'Delivery order retrieved successfully',
                'data' => $this->formatDeliveryOrder($deliveryOrder),
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
                ->with(['purchaseOrder.quotation.customer', 'purchaseOrder.quotation.detailQuotations.sparepart']);

            // Apply search term filter
            $q = $request->query('search');
            if ($q) {
                $query->where(function ($query) use ($q) {
                    $query->where('type', 'like', '%' . $q . '%')
                        ->orWhere('current_status', 'like', '%' . $q . '%')
                        ->orWhere('work_order_number', 'like', '%' . $q . '%')
                        ->orWhereHas('purchaseOrder.quotation', function ($qry) use ($q) {
                            $qry->where('quotation_number', 'like', '%' . $q . '%')
                                ->orWhere('project', 'like', '%' . $q . '%');
                        })
                        ->orWhereHas('purchaseOrder', function ($qry) use ($q) {
                            $qry->where('purchase_order_number', 'like', '%' . $q . '%');
                        })
                        ->orWhereHas('purchaseOrder.quotation.customer', function ($qry) use ($q) {
                            $qry->where('company_name', 'like', '%' . $q . '%');
                        });
                });
            }

            // Apply date filters
            $month = $request->query('month');
            $year = $request->query('year');
            if ($year) {
                $query->whereYear('delivery_order_date', $year);
                if ($month) {
                    $monthNumber = date('m', strtotime($month));
                    $query->whereMonth('delivery_order_date', $monthNumber);
                }
            }

            $deliveryOrders = $query
                ->orderBy('delivery_order_date', 'DESC')
                ->orderBy('id', 'DESC')
                ->paginate(20)->through(function ($do) {
                    return $this->formatDeliveryOrder($do);
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
                ->lockForUpdate() // Lock the row for update
                ->findOrFail($id);

            // Map camelCase input to snake_case
            $input = $request->all();
            $mappedInput = [];
            $fieldMap = [
                'type' => 'type',
                'currentStatus' => 'current_status',
                'notes' => 'notes',
                'workOrderNumber' => 'work_order_number',
                'deliveryOrderDate' => 'delivery_order_date',
                'preparedBy' => 'prepared_by',
                'receivedBy' => 'received_by',
                'pickedBy' => 'picked_by',
                'shipMode' => 'ship_mode',
                'orderType' => 'order_type',
                'delivery' => 'delivery',
                'npwp' => 'npwp',
            ];
            foreach ($fieldMap as $camel => $snake) {
                if (array_key_exists($camel, $input)) {
                    $mappedInput[$snake] = $input[$camel];
                }
            }

            // Validation rules
            $validator = Validator::make($mappedInput, [
                'type' => 'nullable|string|max:255',
                'current_status' => ['nullable', Rule::in([self::ON_PROGRESS, self::DONE])],
                'notes' => 'nullable|string',
                'work_order_number' => 'nullable|string|max:255',
                'delivery_order_date' => 'nullable|date',
                'received_by' => 'nullable|string|max:255',
                'prepared_by' => 'nullable|string|max:255',
                'picked_by' => 'nullable|string|max:255',
                'ship_mode' => 'nullable|string|max:255',
                'order_type' => 'nullable|string|max:255',
                'delivery' => 'nullable|string|max:255',
                'npwp' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                DB::rollBack();
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
            $updatedDeliveryOrder = $this->getAccessedDeliveryOrder(request())
                ->with(['purchaseOrder.quotation.customer', 'purchaseOrder.quotation.detailQuotations.sparepart'])
                ->findOrFail($id);

            return response()->json([
                'message' => 'Delivery order updated successfully',
                'data' => $this->formatDeliveryOrder($updatedDeliveryOrder)
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->handleError($th, 'Failed to update delivery order');
        }
    }

    /**
     * Process delivery order
     */
    public function process(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $deliveryOrder = $this->getAccessedDeliveryOrder($request)->lockForUpdate()->find($id);

            if (!$deliveryOrder) {
                DB::rollBack();
                return $this->handleNotFound('Delivery order not found');
            }

            if ($deliveryOrder->current_status === self::DONE) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Delivery order already done'
                ], Response::HTTP_BAD_REQUEST);
            }

            $deliveryOrder->current_status = self::DONE;
            $deliveryOrder->save();

            // Lock the related purchase order and quotation for updating
            $purchaseOrder = PurchaseOrder::lockForUpdate()->find($deliveryOrder->purchase_order_id);
            if ($purchaseOrder) {
                $purchaseOrder->current_status = PurchaseOrderController::DONE;
                $purchaseOrder->save();

                $quotation = Quotation::lockForUpdate()->find($purchaseOrder->quotation_id);
                if ($quotation) {
                    // Inlined logic from QuotationController->changeStatusToDone
                    $user = $request->user();
                    $currentStatus = $quotation->status ?? [];
                    if (!is_array($currentStatus)) {
                        $currentStatus = [];
                    }
                    $currentStatus[] = [
                        'state' => QuotationController::DONE,
                        'employee' => $user->username,
                        'timestamp' => now()->toIso8601String(),
                    ];
                    $quotation->status = $currentStatus;
                    $quotation->current_status = QuotationController::DONE;
                    $quotation->save();
                }
            }

            DB::commit();

            // Fetch updated delivery order for response
            $updatedDeliveryOrder = $this->getAccessedDeliveryOrder($request)
                ->with(['purchaseOrder.quotation.customer', 'purchaseOrder.quotation.detailQuotations.sparepart'])
                ->findOrFail($id);

            return response()->json([
                'message' => 'Delivery order processed successfully',
                'data' => $this->formatDeliveryOrder($updatedDeliveryOrder)
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->handleError($th, 'Delivery order process failed');
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
                $query->whereHas('purchaseOrder', function ($q) use ($userId) {
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
