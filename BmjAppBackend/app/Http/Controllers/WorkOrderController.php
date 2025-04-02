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
                ->with(['quotation.customer', 'quotation']);

            // Get query parameters
            $q = $request->query('q');
            $month = $request->query('month');
            $year = $request->query('year');

            // Apply search term filter if 'q' is provided
            if ($q) {
                $query->where(function($query) use ($q) {
                    $query->where('wo_number', 'like', '%' . $q . '%')
                        ->orWhereHas('quotation', function($qry) use ($q) {
                            $qry->where('number', 'like', '%' . $q . '%')
                                ->orWhere('project', 'like', '%' . $q . '%')
                                ->orWhere('type', 'like', '%' . $q . '%')
                                ->orWhere('status', 'like', '%' . $q . '%');
                        })
                        ->orWhereHas('quotation.customer', function($qry) use ($q) {
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
                return [
                    'id' => (string) $wo->id,
                    'no_wo' => $wo->no_wo,
                    'customer' => $wo->quotation->customer->company_name ?? 'Unknown',
                    'project' => $wo->quotation->project ?? 'Unknown',
                    'type' => $wo->quotation->type ?? 'Unknown',
                    'status' => $wo->quotation->status ?? 'Unknown',
                    'expected_start_date' => $wo->expected_start_date,
                    'expected_end_date' => $wo->expected_end_date,
                    'start_date' => $wo->start_date,
                    'end_date' => $wo->end_date
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

    public function getDetail(Request $request, $id)
    {
        try {
            $workOrder = $this->getAccessedWorkOrder($request)
                ->with(['quotation.customer', 'quotation.detailQuotations.spareparts'])
                ->find($id);

            if (!$workOrder) {
                return $this->handleNotFound('Work order not found');
            }

            $quotation = $workOrder->quotation;
            $customer = $quotation->customer ?? null;

            $spareParts = $quotation->detailQuotations->map(function ($detail) {
                return [
                    'partName' => $detail->sparepart->name ?? '',
                    'partNumber' => $detail->sparepart->part_number ?? '',
                    'quantity' => $detail->quantity,
                    'unit' => 'pcs',
                    'unitPrice' => $detail->sparepart->unit_price_sell ?? 0,
                    'amount' => ($detail->quantity * ($detail->sparepart->unit_price_sell ?? 0))
                ];
            });

            $response = [
                'workOrder' => [
                    'no' => $workOrder->wo_number,
                    'received_by' => $workOrder->received_by,
                    'expected_start_date' => $workOrder->expected_start_date,
                    'expected_end_date' => $workOrder->expected_end_date,
                    'start_date' => $workOrder->start_date,
                    'end_date' => $workOrder->end_date,
                    'job_descriptions' => $workOrder->job_descriptions,
                    'work_performed_by' => $workOrder->work_peformed_by,
                    'approved_by' => $workOrder->approved_by,
                    'additional_components' => $workOrder->additional_components
                ],
                'quotation' => [
                    'number' => $quotation->number ?? '',
                    'project' => $quotation->project ?? '',
                    'type' => $quotation->type ?? ''
                ],
                'customer' => [
                    'companyName' => $customer->company_name ?? '',
                    'address' => $customer->address ?? '',
                    'city' => $customer->city ?? '',
                    'province' => $customer->province ?? '',
                    'office' => $customer->office ?? '',
                    'urban' => $customer->urban_area ?? '',
                    'subdistrict' => $customer->subdistrict ?? '',
                    'postalCode' => $customer->postal_code ?? ''
                ],
                'price' => [
                    'amount' => $quotation->amount ?? 0,
                    'discount' => $quotation->discount ?? 0,
                    'subtotal' => $quotation->subtotal ?? 0,
                    'vat' => $quotation->vat ?? 0,
                    'total' => $quotation->total ?? 0
                ],
                'notes' => $quotation->note ?? '',
                'spareparts' => $spareParts
            ];

            return response()->json([
                'message' => 'Work order details retrieved successfully',
                'data' => $response
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

            // Validation rules
            $validator = Validator::make($request->all(), [
                'wo_number' => 'sometimes|required|string|max:255',
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

            $workOrder->update([
                'is_done'=> true,
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
                $query->whereHas('quotation', function($q) use ($userId) {
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
