<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\Request;
use App\Models\WorkOrder;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Validator;

class WorkOrderController extends Controller
{
    public function get(Request $request, $id)
    {
        try {
            $workOrder = $this->getAccessedWorkOrder($request)
                ->with(['quotation', 'quotation.customer', 'quotation.detailQuotations.sparepart'])
                ->findOrFail($id);

            $quotation = $workOrder->quotation;
            $proformaInvoice = $quotation->proformaInvoice ?? null;
            $customer = $quotation->customer ?? null;
            $director = Employee::where('role', '=', 'Director')->first();

            $formattedWorkOrder = [
                'id' => (string) $workOrder->id,
                'service_order' => [
                    'no' => $workOrder->work_order_number,
                    'date' => $workOrder->created_at,
                    'received_by' => $workOrder->received_by,
                    'start_date' => $workOrder->start_date,
                    'end_date' => $workOrder->end_date,
                ],
                'proforma_invoice' => [
                    'proforma_invoice_number ' => $proformaInvoice->quotation_number ?? '',
                    'proforma_invoice_date ' => $proformaInvoice->project ?? '',
                ],
                'customer' => [
                    'company_name' => $customer->company_name ?? '',
                    'address' => $customer->address ?? '',
                    'city' => $customer->city ?? '',
                    'province' => $customer->province ?? '',
                    'office' => $customer->office ?? '',
                    'urban' => $customer->urban ?? '',
                    'subdistrict' => $customer->subdistrict ?? '',
                    'postal_code' => $customer->postal_code ?? ''
                ],
                'poc' => [
                    'compiled' => $workOrder->compiled,
                    'head_of_service' => $workOrder->head_of_service,
                    'director' => $director->fullname,
                    'worker' => $workOrder->worker,
                    'approver' => $workOrder->approver,
                ],
                'date' => [
                    'start_date' => $workOrder->start_date,
                    'end_date' => $workOrder->end_date,
                ],
                'description' => $quotation->notes ?? '',
                'additional' => [
                    'spareparts' => $workOrder->spareparts,
                    'backup_sparepart' => $workOrder->backup_sparepart,
                    'scope' => $workOrder->scope,
                    'vaccine' => $workOrder->vaccine,
                    'apd' => $workOrder->apd,
                    'execution_time' => $workOrder->expected_days,
                ],
            ];

            return response()->json([
                'message' => 'Work order retrieved successfully',
                'data' => $formattedWorkOrder,
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function getAll(Request $request)
    {
        try {
            $query = $this->getAccessedWorkOrder($request)
                ->with(['quotation', 'quotation.customer', 'quotation.detailQuotations.sparepart']);

            // Get query parameters
            $q = $request->query('search');
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
                $proformaInvoice = $quotation->proformaInvoice ?? null;
                $customer = $quotation->customer ?? null;
                $director = Employee::where('role', '=', 'Director')->first();


                return [
                    'id' => (string) $wo->id,
                    'service_order' => [
                        'no' => $wo->work_order_number,
                        'date' => $wo->created_at,
                        'received_by' => $wo->received_by,
                        'start_date' => $wo->start_date,
                        'end_date' => $wo->end_date,
                    ],
                    'proforma_invoice' => [
                        'proforma_invoice_number ' => $proformaInvoice->quotation_number ?? '',
                        'proforma_invoice_date ' => $proformaInvoice->project ?? '',
                    ],
                    'customer' => [
                        'company_name' => $customer->company_name ?? '',
                        'address' => $customer->address ?? '',
                        'city' => $customer->city ?? '',
                        'province' => $customer->province ?? '',
                        'office' => $customer->office ?? '',
                        'urban' => $customer->urban ?? '',
                        'subdistrict' => $customer->subdistrict ?? '',
                        'postal_code' => $customer->postal_code ?? ''
                    ],
                    'poc' => [
                        'compiled' => $wo->compiled,
                        'head_of_service' => $wo->head_of_service,
                        'director' => $director->fullname,
                        'worker' => $wo->worker,
                        'approver' => $wo->approver,
                    ],
                    'date' => [
                        'start_date' => $wo->start_date,
                        'end_date' => $wo->end_date,
                    ],
                    'description' => $quotation->notes ?? '',
                    'additional' => [
                        'spareparts' => $wo->spareparts,
                        'backup_sparepart' => $wo->backup_sparepart,
                        'scope' => $wo->scope,
                        'vaccine' => $wo->vaccine,
                        'apd' => $wo->apd,
                        'execution_time' => $wo->expected_days,
                    ],
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
                'worker' => 'nullable|string|max:255',
                'approver' => 'nullable|string|max:255',
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
                unset($validatedData['approver']);
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
