<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\WorkOrder;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Validator;

class WorkOrderController extends Controller
{
    public function getAll(Request $request)
    {
        try {
            $query = $this->getAccessedWorkOrder($request)
                ->with(['quotation', 'quotation.customer', 'quotation.detailQuotations.sparepart']);

            // Get query parameters
            $q = $request->query('q');
            $month = $request->query('month');
            $year = $request->query('year');

            // Apply search term filter if 'q' is provided
            if ($q) {
                $query->where(function ($query) use ($q) {
                    $query->where('work_order_number', 'like', '%' . $q . '%')
                        ->orWhereHas('quotation', function ($qry) use ($q) {
                            $qry->where('quotation_number', 'like', '%' . $q . '%')
                                ->orWhere('project', 'like', '%' . $q . '%')
                                ->orWhere('type', 'like', '%' . $q . '%')
                                ->orWhere('status', 'like', '%' . $q . '%');
                        })
                        ->orWhereHas('quotation.customer', function ($qry) use ($q) {
                            $qry->where('company_name', 'like', '%' . $q . '%');
                        });
                });
            }

            // Apply month and year filter if both are provided
            if ($month && $year) {
                $monthNumber = date('m', strtotime($month));
                $startDate = "{$year}-{$monthNumber}-01";
                $endDate = date("Y-m-t", strtotime($startDate));

                $query->whereBetween('created_at', [$startDate, $endDate]);
            }

            // Paginate the results
            $workOrders = $query->orderBy('created_at', 'desc')
                ->paginate(20);

            // Transform the results
            $workOrders->getCollection()->transform(function ($wo) {
                $quotation = $wo->quotation;
                $customer = $quotation->customer ?? null;

                $spareParts = $quotation->detailQuotations->map(function ($detail) {
                    return [
                        'sparepartName' => $detail->sparepart->sparepart_name ?? '',
                        'sparepartNumber' => $detail->sparepart->sparepart_number ?? '',
                        'quantity' => $detail->quantity,
                        'unit' => 'pcs',
                        'unitPrice' => $detail->unit_price ?? 0,
                        'amount' => ($detail->quantity * ($detail->unit_price ?? 0))
                    ];
                });

                return [
                    'id' => (string) $wo->id,
                    'workOrder' => [
                        'no' => $wo->work_order_number,
                        'received_by' => $wo->received_by,
                        'expected_start_date' => $wo->expected_start_date,
                        'expected_end_date' => $wo->expected_end_date,
                        'start_date' => $wo->start_date,
                        'end_date' => $wo->end_date,
                        'job_descriptions' => $wo->job_descriptions,
                        'work_performed_by' => $wo->work_peformed_by,
                        'approved_by' => $wo->approved_by,
                        'additional_components' => $wo->additional_components
                    ],
                    'quotation' => [
                        'quotationNumber' => $quotation->quotation_number ?? '',
                        'project' => $quotation->project ?? '',
                        'type' => $quotation->type ?? ''
                    ],
                    'customer' => [
                        'companyName' => $customer->company_name ?? '',
                        'address' => $customer->address ?? '',
                        'city' => $customer->city ?? '',
                        'province' => $customer->province ?? '',
                        'office' => $customer->office ?? '',
                        'urban' => $customer->urban ?? '',
                        'subdistrict' => $customer->subdistrict ?? '',
                        'postalCode' => $customer->postal_code ?? ''
                    ],
                    'price' => [
                        'amount' => $quotation->amount ?? 0,
                        'discount' => $quotation->discount ?? 0,
                        'subtotal' => $quotation->subtotal ?? 0,
                        'ppn' => $quotation->ppn ?? 0,
                        'grandTotal' => $quotation->grand_total ?? 0
                    ],
                    'notes' => $quotation->notes ?? '',
                    'spareparts' => $spareParts
                ];
            });

            return response()->json([
                'message' => 'List of work orders retrieved successfully',
                'data' => $workOrders,
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $workOrder = $this->getAccessedWorkOrder($request)->find($id);

            if (!$workOrder) {
                return $this->handleNotFound('Work order not found');
            }

            $alreadyDone = $workOrder->is_done;
            if ($alreadyDone) {
                return response()->json([
                    'message' => 'Work order already done'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validation rules
            $validator = Validator::make($request->all(), [
                'work_order_number' => 'sometimes|required|string|max:255|unique:work_orders,work_order_number,' . $workOrder->id,
                'received_by' => 'nullable|string|max:255',
                'expected_start_date' => 'nullable|date',
                'expected_end_date' => 'nullable|date|after_or_equal:expected_start_date',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
                'job_descriptions' => 'nullable|string',
                'work_peformed_by' => 'nullable|string|max:255',
                'approved_by' => 'nullable|string|max:255',
                'additional_components' => 'nullable|string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $validatedData = $validator->validated();

            // For Marketing users, prevent changing certain fields
            $user = $request->user();
            if ($user->role == 'Marketing') {
                // Remove fields that Marketing shouldn't be able to update
                unset($validatedData['wo_number']);
                unset($validatedData['approved_by']);
                // Add other restricted fields as needed
            }

            $workOrder->update($validatedData);

            return response()->json([
                'message' => 'Work order updated successfully',
                'data' => $workOrder
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th, 'Work order update failed');
        }
    }

    public function process(Request $request, $id)
    {
        try {
            $workOrder = $this->getAccessedWorkOrder($request)->find($id);

            if (!$workOrder) {
                return $this->handleNotFound('Work order not found');
            }

            $alreadyDone = $workOrder->is_done;
            if ($alreadyDone) {
                return response()->json([
                    'message' => 'Work order already done'
                ], Response::HTTP_BAD_REQUEST);
            }

            $workOrder->update([
                'is_done' => true,
            ]);

            return response()->json([
                'message' => 'Work order processed successfully',
                'data' => $workOrder
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th, 'Work order update failed');
        }
    }

    protected function getAccessedWorkOrder($request)
    {
        try {
            $user = $request->user();
            $userId = $user->id;
            $role = $user->role;

            $query = WorkOrder::query();

            // Only allow work orders for authorized users
            if ($role == 'Service') {
                $query->whereHas('quotation', function ($q) use ($userId) {
                    $q->where('employee_id', $userId);
                });
            }

            return $query;
        } catch (\Throwable $th) {
            // Return empty query builder
            return WorkOrder::whereNull('id');
        }
    }

    // Helper methods for consistent error handling
    protected function handleError(\Throwable $th, $message = 'Internal server error')
    {
        return response()->json([
            'message' => $message,
            'error' => $th->getMessage()
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    protected function handleNotFound($message = 'Resource not found')
    {
        return response()->json([
            'message' => $message
        ], Response::HTTP_NOT_FOUND);
    }
}
