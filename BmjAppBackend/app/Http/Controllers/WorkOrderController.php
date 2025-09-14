<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use Illuminate\Http\Request;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Validator;

class WorkOrderController extends Controller
{
    const ON_PROGRESS = "On Progress";
    const DONE = "Done";

    protected $quotationController;
    public function __construct(QuotationController $quotationController)
    {
        $this->quotationController = $quotationController;
    }

    public function get(Request $request, $id)
    {
        try {
            $workOrder = $this->getAccessedWorkOrder($request)
                ->with(['purchaseOrder', 'purchaseOrder.quotation.customer', 'purchaseOrder.quotation.detailQuotations.sparepart', 'woUnits'])
                ->findOrFail($id);

            $purchaseOrder = $workOrder->purchaseOrder;
            $quotation = $purchaseOrder->quotation ?? null;
            $proformaInvoice = $purchaseOrder->proformaInvoice ?? null;
            $customer = $quotation->customer ?? null;
            $director = Employee::where('role', '=', 'Director')->first(); // Only with director at moment

            $formattedWorkOrder = [
                'id' => (string) $workOrder->id,
                'service_order' => [
                    'no' => $workOrder->work_order_number,
                    'date' => $workOrder->created_at->format('Y-m-d'),
                    'received_by' => $workOrder->received_by ?? '',
                    'start_date' => $workOrder->start_date,
                    'end_date' => $workOrder->end_date,
                ],
                'proforma_invoice' => [
                    'proforma_invoice_number' => $proformaInvoice->proforma_invoice_number ?? '',
                    'proforma_invoice_date' => $proformaInvoice->proforma_invoice_date->format('Y-m-d') ?? '',
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
                    'compiled' => $workOrder->compiled ?? '',
                    'head_of_service' => $workOrder->head_of_service ?? '',
                    'director' => $director->fullname ?? '',
                    'worker' => $workOrder->worker ?? '',
                    'approver' => $workOrder->approver ?? '',
                ],
                'date' => [
                    'start_date' => $workOrder->start_date,
                    'end_date' => $workOrder->end_date,
                ],
                'description' => $workOrder->notes ?? '',
                'status' => $quotation->status ?? [],
                'current_status' => $workOrder->current_status ?? '',
                'quotation_number' => $quotation ? $quotation->quotation_number : '',
                'version' => $purchaseOrder->version,
                'additional' => [
                    'spareparts' => $workOrder->spareparts,
                    'backup_sparepart' => $workOrder->backup_sparepart,
                    'scope' => $workOrder->scope,
                    'vaccine' => $workOrder->vaccine,
                    'apd' => $workOrder->apd,
                    'execution_time' => $workOrder->expected_days,
                    'peduli_lindungi' => $workOrder->peduli_lindungi
                ],
                'units' => $workOrder->woUnits->map(function ($woUnit) {
                    return [
                        'id' => (string) $woUnit->id,
                        'job_descriptions' => $woUnit->job_descriptions,
                        'unit_type' => $woUnit->unit_type,
                        'quantity' => $woUnit->quantity,
                    ];
                })->toArray(),
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
                ->with(['purchaseOrder', 'purchaseOrder.quotation.customer', 'purchaseOrder.quotation.detailQuotations.sparepart', 'woUnits']);

            // Get query parameters
            $q = $request->query('search');
            $month = $request->query('month');
            $year = $request->query('year');

            // Apply search term filter if 'q' is provided
            if ($q) {
                $query->where(function ($query) use ($q) {
                    $query->where('work_order_number', 'like', '%' . $q . '%')
                        ->orWhereHas('purchaseOrder.quotation', function ($qry) use ($q) {
                            $qry->where('quotation_number', 'like', '%' . $q . '%')
                                ->orWhere('project', 'like', '%' . $q . '%')
                                ->orWhere('type', 'like', '%' . $q . '%')
                                ->orWhere('current_status', 'like', '%' . $q . '%');
                        })
                        ->orWhereHas('purchaseOrder.quotation.customer', function ($qry) use ($q) {
                            $qry->where('company_name', 'like', '%' . $q . '%');
                        });
                });
            }

            // Apply year and month filter
            if ($year) {
                $query->whereYear('start_date', $year);
                if ($month) {
                    $monthNumber = date('m', strtotime($month));
                    $query->whereMonth('start_date', $monthNumber);
                }
            }

            // Paginate the results
            $workOrders = $query
                // WO might want to order using 'start_date'
                // ->orderBy('start_date', 'desc')
                ->orderBy('id', 'DESC')
                ->paginate(20);

            // Transform the results
            $workOrders->getCollection()->transform(function ($wo) {
                $purchaseOrder = $wo->purchaseOrder;
                $quotation = $purchaseOrder->quotation ?? null;
                $proformaInvoice = $purchaseOrder->proformaInvoice ?? null;
                $customer = $quotation->customer ?? null;
                $director = Employee::where('role', '=', 'Director')->first(); // Only with director at moment

                return [
                    'id' => (string) $wo->id,
                    'service_order' => [
                        'no' => $wo->work_order_number,
                        'date' => $wo->created_at->format('Y-m-d'),
                        'received_by' => $wo->received_by ?? '',
                        'start_date' => $wo->start_date,
                        'end_date' => $wo->end_date,
                    ],
                    'proforma_invoice' => [
                        'proforma_invoice_number' => $proformaInvoice->proforma_invoice_number ?? '',
                        'proforma_invoice_date' => $proformaInvoice->proforma_invoice_date ?? '',
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
                        'compiled' => $wo->compiled ?? '',
                        'head_of_service' => $wo->head_of_service ?? '',
                        'director' => $director->fullname ?? '',
                        'worker' => $wo->worker ?? '',
                        'approver' => $wo->approver ?? '',
                    ],
                    'date' => [
                        'start_date' => $wo->start_date,
                        'end_date' => $wo->end_date,
                    ],
                    'description' => $wo->notes ?? '',
                    'status' => $quotation->status ?? [],
                    'current_status' => $wo->current_status ?? '',
                    'quotation_number' => $quotation ? $quotation->quotation_number : '',
                    'version' => $purchaseOrder->version,
                    'additional' => [
                        'spareparts' => $wo->spareparts,
                        'backup_sparepart' => $wo->backup_sparepart,
                        'scope' => $wo->scope,
                        'vaccine' => $wo->vaccine,
                        'apd' => $wo->apd,
                        'execution_time' => $wo->expected_days,
                        'peduli_lindungi' => $wo->peduli_lindungi
                    ],
                    'units' => $wo->woUnits->map(function ($woUnit) {
                        return [
                            'id' => (string) $woUnit->id,
                            'job_descriptions' => $woUnit->job_descriptions,
                            'unit_type' => $woUnit->unit_type,
                            'quantity' => $woUnit->quantity,
                        ];
                    })->toArray(),
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
        DB::beginTransaction();
        try {
            $workOrder = $this->getAccessedWorkOrder($request)->lockForUpdate()->find($id);

            if (!$workOrder) {
                DB::rollBack();
                return $this->handleNotFound('Work order not found');
            }

            $alreadyDone = $workOrder->is_done;
            if ($alreadyDone) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Work order already done'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Map camelCase input to snake_case for validation and update
            $input = $request->all();
            $mappedInput = [
                'work_order_number'    => $input['workOrderNumber'] ?? null,
                'received_by'          => $input['receivedBy'] ?? null,
                'expected_start_date'  => $input['expectedStartDate'] ?? null,
                'expected_end_date'    => $input['expectedEndDate'] ?? null,
                'start_date'           => $input['startDate'] ?? null,
                'end_date'             => $input['endDate'] ?? null,
                'job_descriptions'     => $input['jobDescriptions'] ?? null,
                'worker'               => $input['worker'] ?? null,
                'compiled'             => $input['compiled'] ?? null,
                'approver'             => $input['approver'] ?? null,
                'additional_components' => $input['additionalComponents'] ?? null,
            ];

            // Validation rules
            $validator = Validator::make($mappedInput, [
                'work_order_number'    => 'sometimes|required|string|max:255|unique:work_orders,work_order_number,' . $workOrder->id,
                'received_by'          => 'nullable|string|max:255',
                'expected_start_date'  => 'nullable|date',
                'expected_end_date'    => 'nullable|date|after_or_equal:expected_start_date',
                'start_date'           => 'nullable|date',
                'end_date'             => 'nullable|date|after_or_equal:start_date',
                'job_descriptions'     => 'nullable|string',
                'worker'               => 'nullable|string|max:255',
                'compiled'             => 'nullable|string|max:255',
                'approver'             => 'nullable|string|max:255',
                'additional_components' => 'nullable|string'
            ]);

            if ($validator->fails()) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $validatedData = $validator->validated();

            // For Marketing users, prevent changing certain fields
            $user = $request->user();
            if ($user->role == 'Marketing') {
                unset($validatedData['work_order_number']);
                unset($validatedData['approver']);
                unset($validatedData['compiled']);
            }

            $workOrder->update($validatedData);

            DB::commit();

            return response()->json([
                'message' => 'Work order updated successfully',
                'data' => $workOrder
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->handleError($th, 'Work order update failed');
        }
    }

    public function process(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $workOrder = $this->getAccessedWorkOrder($request)->lockForUpdate()->find($id);

            if (!$workOrder) {
                DB::rollBack();
                return $this->handleNotFound('Work order not found');
            }

            if ($workOrder->is_done) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Work order already done'
                ], Response::HTTP_BAD_REQUEST);
            }

            $workOrder->update([
                'is_done' => true,
                'current_status' => self::DONE,
                'end_date' => now()
            ]);

            $purchaseOrder = PurchaseOrder::lockForUpdate()->find($workOrder->purchase_order_id);
            if ($purchaseOrder) {
                $purchaseOrder->update([
                    'current_status' => PurchaseOrderController::DONE,
                ]);

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

            return response()->json([
                'message' => 'Work order processed successfully',
                'data' => $workOrder
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->handleError($th, 'Work order process failed');
        }
    }

    protected function getAccessedWorkOrder($request)
    {
        try {
            // For now, there is no filter for this


            $query = WorkOrder::query();

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
