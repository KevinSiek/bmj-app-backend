<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ProformaInvoice;
use App\Models\PurchaseOrder;
use App\Models\WorkOrder;
use App\Models\WoUnit;
use App\Models\DeliveryOrder;
use App\Models\Quotation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class PurchaseOrderController extends Controller
{
    const BO = "BO";
    const PREPARE = "Prepare";
    const READY = "Ready";
    const RELEASE = "Release";
    const DONE = "Done";
    const RETURNED = "Returned";
    const PAID = "Paid";
    const REJECTED = "Rejected";

    protected $quotationController;
    public function __construct(QuotationController $quotationController)
    {
        $this->quotationController = $quotationController;
    }

    public function get(Request $request, $id)
    {
        try {
            $purchaseOrder = $this->getAccessedPurchaseOrder($request)
                ->with(['quotation.customer', 'quotation.detailQuotations.sparepart', 'proformaInvoice', 'employee'])
                ->where('id', $id)
                ->orderBy('version', 'asc')
                ->firstOrFail();

            $quotation = $purchaseOrder->quotation;
            $customer = $quotation ? $quotation->customer : null;
            $proformaInvoice = $purchaseOrder->proformaInvoice ? $purchaseOrder->proformaInvoice : null;

            $spareParts = [];
            $services = [];
            if ($quotation && $quotation->detailQuotations) {
                foreach ($quotation->detailQuotations as $detail) {
                    if ($detail->sparepart_id) {
                        $sparepart = $detail->sparepart;
                        $spareParts[] = [
                            'sparepart_id' => $sparepart ? $sparepart->id : '',
                            'sparepart_name' => $sparepart ? $sparepart->sparepart_name : '',
                            'sparepart_number' => $sparepart ? $sparepart->sparepart_number : '',
                            'quantity' => $detail->quantity ?? 0,
                            'unit_price_sell' => $detail->unit_price ?? 0,
                            'total_price' => ($detail->quantity * ($detail->unit_price ?? 0)),
                            'stock' => $detail->is_indent ? 'indent' : 'available'
                        ];
                    } else {
                        $services[] = [
                            'service' => $detail->service ?? '',
                            'unit_price_sell' => $detail->unit_price ?? 0,
                            'quantity' => $detail->quantity ?? 0,
                            'total_price' => ($detail->quantity * ($detail->unit_price ?? 0))
                        ];
                    }
                }
            }

            $formattedPurchaseOrder = [
                'id' => (string) ($purchaseOrder->id ?? ''),
                'purchase_order_number' => $purchaseOrder->purchase_order_number ?? '',
                'purchase_order' => [
                    'purchase_order_number' => $purchaseOrder->purchase_order_number ?? '',
                    'purchase_order_date' => $purchaseOrder->purchase_order_date ?? '',
                    'type' => $quotation ? $quotation->type : ''
                ],
                'proforma_invoice' => [
                    'proforma_invoice_number' => $proformaInvoice ? $proformaInvoice->proforma_invoice_number : '',
                    'proforma_invoice_date' => $proformaInvoice ? $proformaInvoice->proforma_invoice_date->format('Y-m-d') : '',
                    'is_dp_paid' => $proformaInvoice ? $proformaInvoice->is_dp_paid : '',
                    'is_full_paid' => $proformaInvoice ? $proformaInvoice->is_full_paid : ''
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
                'price' => [
                    'amount' => $quotation ? $quotation->amount : 0,
                    'discount' => $quotation ? $quotation->discount : 0,
                    'subtotal' => $quotation ? $quotation->subtotal : 0,
                    'down_payment' => $proformaInvoice ? $proformaInvoice->down_payment : 0,
                    'total' => $proformaInvoice ? $proformaInvoice->grand_total : 0,
                    'ppn' => $quotation ? $quotation->ppn : 0,
                    'total_amount' => $proformaInvoice ? $proformaInvoice->total_amount : 0
                ],
                'notes' => $purchaseOrder->notes ?? '',
                'current_status' => $purchaseOrder->current_status ?? '',
                'status' => $quotation ? $quotation->status : [],
                'down_payment' => $proformaInvoice ? $proformaInvoice->down_payment : 0,
                'quotation_number' => $quotation ? $quotation->quotation_number : '',
                'version' => $purchaseOrder->version,
                'spareparts' => $spareParts,
                'services' => $services
            ];

            return response()->json([
                'message' => 'Purchase order retrieved successfully',
                'data' => $formattedPurchaseOrder,
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function getAll(Request $request)
    {
        try {
            // Get query parameters
            $q = $request->query('search');
            $month = $request->query('month');
            $year = $request->query('year');

            // Get all purchaseOrder numbers first to ensure we capture all versions
            $purchaseOrderNumbers = $this->getAccessedPurchaseOrder($request)
                ->select('purchase_order_number');

            // Apply search term filter if 'q' is provided
            if ($q) {
                $purchaseOrderNumbers->where(function ($purchaseOrderNumbers) use ($q) {
                    $purchaseOrderNumbers->where('purchase_order_number', 'like', '%' . $q . '%')
                        ->orWhereHas('quotation', function ($qry) use ($q) {
                            $qry->where('quotation_number', 'like', '%' . $q . '%')
                                ->orWhere('project', 'like', '%' . $q . '%')
                                ->orWhere('type', 'like', '%' . $q . '%')
                                ->orWhere('current_status', 'like', '%' . $q . '%');
                        })
                        ->orWhereHas('quotation.customer', function ($qry) use ($q) {
                            $qry->where('company_name', 'like', '%' . $q . '%');
                        });
                });
            }

            // Apply year and month filter
            if ($year) {
                $purchaseOrderNumbers->whereYear('purchase_order_date', $year);
                if ($month) {
                    $monthNumber = date('m', strtotime($month));
                    $purchaseOrderNumbers->whereMonth('purchase_order_date', $monthNumber);
                }
            }

            // Paginate the distinct quotation numbers
            $paginatedPurchaseOrders = $purchaseOrderNumbers->groupBy('purchase_order_number')->paginate(20);

            $queryTwo = $this->getAccessedPurchaseOrder($request)
                ->whereIn('purchase_order_number', $paginatedPurchaseOrders->pluck('purchase_order_number'))
                ->orderBy('version', 'asc');

            // Aply q
            if ($q) {
                $queryTwo->where(function ($queryTwo) use ($q) {
                    $queryTwo->where('purchase_order_number', 'like', '%' . $q . '%')
                        ->orWhereHas('quotation', function ($qry) use ($q) {
                            $qry->where('quotation_number', 'like', '%' . $q . '%')
                                ->orWhere('project', 'like', '%' . $q . '%')
                                ->orWhere('type', 'like', '%' . $q . '%')
                                ->orWhere('current_status', 'like', '%' . $q . '%');
                        })
                        ->orWhereHas('quotation.customer', function ($qry) use ($q) {
                            $qry->where('company_name', 'like', '%' . $q . '%');
                        });
                });
            }

            // Apply year and month filter
            if ($year) {
                $queryTwo->whereYear('purchase_order_date', $year);
                if ($month) {
                    $monthNumber = date('m', strtotime($month));
                    $queryTwo->whereMonth('purchase_order_date', $monthNumber);
                }
            }

            // Return like API contract
            $purchaseOrders =  $queryTwo
                // Sort primarily by the numeric part of the purchase_order number (e.g., 033 from PO-IN/033/...).
                // The existing sorting logic is kept as secondary sorting criteria.
                ->orderByRaw('CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(purchase_order_number, \'/\', 2), \'/\', -1) AS UNSIGNED) DESC')
                ->orderBy('purchase_order_date', 'DESC')
                ->orderBy('id', 'DESC')
                ->get();
            $grouped = $purchaseOrders->map(function ($po) {
                $quotation = $po->quotation;
                $customer = $quotation ? $quotation->customer : null;
                $proformaInvoice = $po->proformaInvoice ? $po->proformaInvoice : null;

                $spareParts = [];
                $services = [];
                if ($quotation && $quotation->detailQuotations) {
                    foreach ($quotation->detailQuotations as $detail) {
                        if ($detail->sparepart_id) {
                            $sparepart = $detail->sparepart;
                            $spareParts[] = [
                                'sparepart_id' => $sparepart ? $sparepart->id : '',
                                'sparepart_name' => $sparepart ? $sparepart->sparepart_name : '',
                                'sparepart_number' => $sparepart ? $sparepart->sparepart_number : '',
                                'quantity' => $detail->quantity ?? 0,
                                'unit_price_sell' => $detail->unit_price ?? 0,
                                'total_price' => ($detail->quantity * ($detail->unit_price ?? 0)),
                                'stock' => $detail->is_indent ? 'indent' : 'available'
                            ];
                        } else {
                            $services[] = [
                                'service' => $detail->service ?? '',
                                'unit_price_sell' => $detail->unit_price ?? 0,
                                'quantity' => $detail->quantity ?? 0,
                                'total_price' => ($detail->quantity * ($detail->unit_price ?? 0))
                            ];
                        }
                    }
                }

                return [
                    'id' => (string) ($po->id ?? ''),
                    'purchase_order_number' => $po->purchase_order_number ?? '',
                    'purchase_order' => [
                        'purchase_order_number' => $po->purchase_order_number ?? '',
                        'purchase_order_date' => $po->purchase_order_date ?? '',
                        'type' => $quotation ? $quotation->type : ''
                    ],
                    'proforma_invoice' => [
                        'proforma_invoice_number' => $proformaInvoice ? $proformaInvoice->proforma_invoice_number : '',
                        'proforma_invoice_date' => $proformaInvoice ? $proformaInvoice->proforma_invoice_date : '',
                        'is_dp_paid' => $proformaInvoice ? $proformaInvoice->is_dp_paid : '',
                        'is_full_paid' => $proformaInvoice ? $proformaInvoice->is_full_paid : ''
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
                    'price' => [
                        'amount' => $quotation ? $quotation->amount : 0,
                        'discount' => $quotation ? $quotation->discount : 0,
                        'subtotal' => $quotation ? $quotation->subtotal : 0,
                        'down_payment' => $proformaInvoice ? $proformaInvoice->down_payment : 0,
                        'total' => $proformaInvoice ? $proformaInvoice->grand_total : 0,
                        'ppn' => $quotation ? $quotation->ppn : 0,
                        'total_amount' => $proformaInvoice ? $proformaInvoice->total_amount : 0
                    ],
                    'notes' => $po->notes ?? '',
                    'current_status' => $po->current_status ?? '',
                    'status' => $quotation ? $quotation->status : [],
                    'down_payment' => $proformaInvoice ? $proformaInvoice->down_payment : 0,
                    'quotation_number' => $quotation ? $quotation->quotation_number : '',
                    'version' => $po->version,
                    'spareparts' => $spareParts,
                    'services' => $services
                ];
            });

            return response()->json([
                'message' => 'List of purchase orders retrieved successfully',
                'data' => [
                    'data' => $grouped,
                    'from' => $paginatedPurchaseOrders->firstItem(),
                    'to' => $paginatedPurchaseOrders->lastItem(),
                    'total' => $paginatedPurchaseOrders->total(),
                    'per_page' => $paginatedPurchaseOrders->perPage(),
                    'current_page' => $paginatedPurchaseOrders->currentPage(),
                    'last_page' => $paginatedPurchaseOrders->lastPage(),
                ]
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    /**
     * Convert month number to Roman numeral
     *
     * @param int $month
     * @return string
     */
    protected function getRomanMonth($month)
    {
        $romanNumerals = [
            1 => 'I',
            2 => 'II',
            3 => 'III',
            4 => 'IV',
            5 => 'V',
            6 => 'VI',
            7 => 'VII',
            8 => 'VIII',
            9 => 'IX',
            10 => 'X',
            11 => 'XI',
            12 => 'XII'
        ];
        return $romanNumerals[$month] ?? 'I';
    }

    public function moveToPi(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            // Validate the there is notes for downPayment
            $request->validate([
                'notes' => 'required|string',
            ]);
            $notes = $request->input('notes');

            $purchaseOrder = $this->getAccessedPurchaseOrder($request)
                ->where('id', $id)
                ->lockForUpdate() // Lock the PO to prevent race conditions
                ->firstOrFail();

            if ($purchaseOrder->proformaInvoice) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Purchase order already has a proforma invoice'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Generate proforma invoice number from purchase order number
            try {
                // Expected purchase_order_number format: PO-IN/001/BMJ-MEGAH/SMG/1/V/25
                $parts = explode('/', $purchaseOrder->purchase_order_number);
                $piNumber = $parts[1]; // e.g., 033
                $branch = $parts[3]; // e.g., V
                $romanMonth = $parts[5]; // e.g., 24
                $year = $parts[6]; // e.g., 24
                $user = $request->user();
                $userId = $user->id;
                // Expected purchase_order_number format: PI-IN/001/BMJ-MEGAH/SMG/1/V/25
                $proformaInvoiceNumber = "PI-IN/{$piNumber}/BMJ-MEGAH/{$branch}/{$userId}/{$romanMonth}/{$year}";
            } catch (\Throwable $th) {
                // Fallback to timestamp-based PI number with current month and year
                $latestPi = ProformaInvoice::latest('id')->lockForUpdate()->first();
                $nextLastestPi = $latestPi ? $latestPi->id + 1 : 1;

                // Get user branch
                $user = $request->user();
                $userId = $user->id;
                $branchCode = $user->branch === EmployeeController::SEMARANG ? 'SMG' : 'JKT';
                $currentMonth = now()->month; // e.g., 7 for July
                $romanMonth = $this->getRomanMonth($currentMonth); // e.g., VII
                $year = now()->format('y'); // e.g., 25 for 2025
                $proformaInvoiceNumber = "PI-IN/{$nextLastestPi}/BMJ-MEGAH/{$branchCode}/{$userId}/{$romanMonth}/{$year}";
            }

            $proformaInvoice = ProformaInvoice::create([
                'purchase_order_id' => $purchaseOrder->id,
                'proforma_invoice_number' => $proformaInvoiceNumber,
                'proforma_invoice_date' => now(),
                'employee_id' => $purchaseOrder->employee_id,
                'notes' => $notes,
                'is_dp_paid' => false,
                'is_full_paid' => false,
            ]);

            $quotation = Quotation::lockForUpdate()->find($purchaseOrder->quotation_id);
            if ($quotation) {
                // Inlined logic from QuotationController->changeStatusToPi
                $user = $request->user();
                $currentStatus = $quotation->status ?? [];
                if (!is_array($currentStatus)) {
                    $currentStatus = [];
                }
                $currentStatus[] = [
                    'state' => QuotationController::PI,
                    'employee' => $user->username,
                    'timestamp' => now()->toIso8601String(),
                ];
                $quotation->status = $currentStatus;
                $quotation->current_status = QuotationController::PI;
                $quotation->save();
            }

            DB::commit();

            return response()->json([
                'message' => 'Purchase order promoted to proforma invoice successfully',
                'data' => $proformaInvoice
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->handleError($th, 'Failed to promote purchase order');
        }
    }

    public function updateStatus(Request $request, $id)
    {
        DB::beginTransaction();

        $status = $request->input('status');
        try {
            $purchaseOrder = $this->getAccessedPurchaseOrder($request)
                ->lockForUpdate() // Lock the record for update
                ->findOrFail($id);

            $purchaseOrder->current_status = $status;
            $purchaseOrder->save();

            // Commit the transaction
            DB::commit();

            // Return a success response
            return response()->json([
                'message' => 'Purchase order status updated successfully',
                'data' => $purchaseOrder
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->handleError($th, 'Failed to update purchase order status to ' . $status);
        }
    }

    public function ready(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $purchaseOrder = $this->getAccessedPurchaseOrder($request)
                ->lockForUpdate() // Lock the record for update
                ->findOrFail($id);

            // Check if BackOrder exist then it must in READY state
            $backOrder = $purchaseOrder->backOrders;
            if ($backOrder) {
                $backOrderStatus = $backOrder->current_status;
                if ($backOrderStatus !== BackOrderController::READY) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Please process back order first.'
                    ], Response::HTTP_BAD_REQUEST);
                }
            }

            // Update purchase order status
            $purchaseOrder->current_status = self::READY;
            $purchaseOrder->save();

            // Update status quotation for tracking
            $quotation = Quotation::lockForUpdate()->find($purchaseOrder->quotation_id);
            if ($quotation) {
                // Inlined logic from QuotationController->changeStatusToReady
                $user = $request->user();
                $status = $quotation->status ?? [];
                if (!is_array($status)) {
                    $status = [];
                }
                $status[] = [
                    'state' => QuotationController::READY,
                    'employee' => $user->username,
                    'timestamp' => now()->toIso8601String(),
                ];
                $quotation->status = $status;
                $quotation->current_status = QuotationController::READY;
                $quotation->save();
            }

            // Commit the transaction
            DB::commit();

            // Return a success response
            return response()->json([
                'message' => 'Purchase order is ready',
                'data' => [
                    'purchase_order' => $purchaseOrder,
                ]
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->handleError($th, 'Failed to set purchase order to ready');
        }
    }

    public function done(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $purchaseOrder = $this->getAccessedPurchaseOrder($request)
                ->lockForUpdate() // Lock the record for update
                ->findOrFail($id);

            // Update purchase order status
            $purchaseOrder->current_status = self::DONE;
            $purchaseOrder->save();

            // Update status quotation for tracking
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

            // Commit the transaction
            DB::commit();

            // Return a success response
            return response()->json([
                'message' => 'Purchase order is Done',
                'data' => [
                    'purchase_order' => $purchaseOrder,
                ]
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->handleError($th, 'Failed to mark purchase order as done');
        }
    }

    public function release(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $purchaseOrder = $this->getAccessedPurchaseOrder($request)
                ->lockForUpdate() // Lock the PO to prevent concurrent modifications
                ->findOrFail($id);

            $quotation = $purchaseOrder->quotation;

            // Check if quotation exists
            if (!$quotation) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Quotation not found for this purchase order'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Lock the quotation as well, since its state will be updated
            $quotation = Quotation::lockForUpdate()->find($quotation->id);

            // Handle Service type quotations
            if ($quotation->type === QuotationController::SERVICE) {
                // Validate request input for service type
                $validator = Validator::make($request->all(), [
                    'serviceOrder.receivedBy' => 'required|string',
                    'serviceOrder.startDate' => 'nullable|string',
                    'serviceOrder.endDate' => 'nullable|string',
                    'poc.compiled' => 'required|string',
                    'poc.approver' => 'required|string',
                    'poc.headOfService' => 'required|string',
                    'poc.worker' => 'nullable|string',
                    'additional.spareparts' => 'nullable',
                    'additional.backupSparepart' => 'nullable',
                    'additional.scope' => 'nullable|string',
                    'additional.vaccine' => 'nullable|string',
                    'additional.apd' => 'nullable|string',
                    'additional.peduliLindungi' => 'nullable|string',
                    'additional.executionTime' => 'nullable|string',
                    'units' => 'required|array',
                    'units.*.jobDescriptions' => 'nullable|string',
                    'units.*.unitType' => 'nullable|string',
                    'units.*.quantity' => 'nullable|integer|min:1',
                    'date.startDate' => 'nullable|string',
                    'date.endDate' => 'nullable|string',
                    'description' => 'nullable|string',
                ]);

                if ($validator->fails()) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Validation failed',
                        'error' => $validator->errors()
                    ], Response::HTTP_BAD_REQUEST);
                }

                // Check if work order already exists
                if ($purchaseOrder->workOrder) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Work order already exists for this purchase order'
                    ], Response::HTTP_BAD_REQUEST);
                }

                // Check if quotation has PI with DP paid
                $proformaInvoice = $purchaseOrder->proformaInvoice;
                if (!$proformaInvoice || !$proformaInvoice->is_dp_paid) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Proforma invoice must exist and down payment must be paid'
                    ], Response::HTTP_BAD_REQUEST);
                }

                // Generate work order number safely
                $latestWorkOrder = WorkOrder::latest('id')->lockForUpdate()->first();
                $orderNumber = $latestWorkOrder ? $latestWorkOrder->id + 1 : 1;
                $orderNumber = sprintf('%03d', $orderNumber);

                $randomString1 = strtoupper(Str::random(3));
                $randomString2 = strtoupper(Str::random(3));
                $monthRoman = $this->getRomanMonth(now()->month);
                $year = now()->year;
                $workOrderNumber = "WO/{$orderNumber}/{$randomString1}-{$randomString2}/{$monthRoman}/{$year}";

                // Create work order
                $workOrder = WorkOrder::create([
                    'purchase_order_id' => $purchaseOrder->id,
                    'work_order_number' => $workOrderNumber,
                    'received_by' => $request->input('serviceOrder.receivedBy'),
                    'expected_start_date' => $request->input('serviceOrder.startDate'),
                    'expected_end_date' => $request->input('serviceOrder.endDate'),
                    'start_date' => $request->input('date.startDate'),
                    'end_date' => $request->input('date.endDate'),
                    'current_status' => WorkOrderController::ON_PROGRESS,
                    'worker' => $request->input('poc.worker'),
                    'compiled' => $request->input('poc.compiled'),
                    'head_of_service' => $request->input('poc.headOfService'),
                    'approver' => $request->input('poc.approver'),
                    'is_done' => false,
                    'notes' => $request->input('description'),
                    'spareparts' => json_encode($request->input('additional.spareparts')),
                    'backup_sparepart' => $request->input('additional.backupSparepart') ? json_encode($request->input('additional.backupSparepart')) : null,
                    'scope' => $request->input('additional.scope'),
                    'vaccine' => $request->input('additional.vaccine'),
                    'apd' => $request->input('additional.apd'),
                    'peduli_lindungi' => $request->input('additional.peduliLindungi'),
                    'execution_time' => $request->input('additional.executionTime'),
                ]);

                // Create wo_units
                $unitsData = $request->input('units', []);
                foreach ($unitsData as $unit) {
                    WoUnit::create([
                        'id_wo' => $workOrder->id,
                        'job_descriptions' => $unit['jobDescriptions'] ?? null,
                        'unit_type' => $unit['unitType'] ?? null,
                        'quantity' => $unit['quantity'] ?? null,
                    ]);
                }

                // Update purchase order status
                $purchaseOrder->current_status = self::RELEASE;
                $purchaseOrder->save();

                // Inlined logic from QuotationController->changeStatusToRelease
                $user = $request->user();
                $currentStatus = $quotation->status ?? [];
                if (!is_array($currentStatus)) {
                    $currentStatus = [];
                }
                $currentStatus[] = [
                    'state' => QuotationController::RELEASE,
                    'employee' => $user->username,
                    'timestamp' => now()->toIso8601String(),
                ];
                $quotation->status = $currentStatus;
                $quotation->current_status = QuotationController::RELEASE;
                $quotation->save();


                // Commit the transaction
                DB::commit();

                return response()->json([
                    'message' => 'Purchase order released and work order created successfully',
                    'data' => [
                        'purchase_order' => $purchaseOrder,
                        'work_order' => $workOrder->load('woUnits')
                    ]
                ], Response::HTTP_OK);
            }
            // Handle Sparepart type quotations
            else if ($quotation->type === QuotationController::SPAREPARTS) {
                // Check if delivery order already exists
                if ($purchaseOrder->deliveryOrder) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Delivery order already exists for this purchase order'
                    ], Response::HTTP_BAD_REQUEST);
                }

                // Check if quotation has PI
                $proformaInvoice = $purchaseOrder->proformaInvoice;
                if (!$proformaInvoice) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Proforma invoice must exist for Sparepart type'
                    ], Response::HTTP_BAD_REQUEST);
                }

                // Validate request input for sparepart type
                $validator = Validator::make($request->all(), [
                    'deliveryOrder.deliveryOrderDate' => 'required|string',
                    'deliveryOrder.preparedBy' => 'nullable|string',
                    'deliveryOrder.receivedBy' => 'nullable|string',
                    'deliveryOrder.pickedBy' => 'required|string',
                    'deliveryOrder.shipMode' => 'required|string',
                    'deliveryOrder.orderType' => 'required|string',
                    'deliveryOrder.npwp' => 'nullable|string',
                    'notes' => 'nullable|string',
                ]);

                if ($validator->fails()) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Validation failed',
                        'error' => $validator->errors()
                    ], Response::HTTP_BAD_REQUEST);
                }


                // Generate delivery order number safely
                $latestDeliveryOrder = DeliveryOrder::latest('id')->lockForUpdate()->first();
                $orderNumber = $latestDeliveryOrder ? $latestDeliveryOrder->id + 1 : 1;
                $orderNumber = sprintf('%03d', $orderNumber);

                $randomString1 = strtoupper(Str::random(3));
                $randomString2 = strtoupper(Str::random(3));
                $monthRoman = $this->getRomanMonth(now()->month);
                $year = now()->year;
                $deliveryOrderNumber = "DO/{$orderNumber}/{$randomString1}-{$randomString2}/{$monthRoman}/{$year}";

                // Create delivery order
                $deliveryOrder = DeliveryOrder::create([
                    'purchase_order_id' => $purchaseOrder->id,
                    'type' => 'Sparepart',
                    'current_status' => DeliveryOrderController::ON_PROGRESS,
                    'delivery_order_number' => $deliveryOrderNumber,
                    'delivery_order_date' => $request->input('deliveryOrder.deliveryOrderDate') ?? null,
                    'prepared_by' => $request->input('deliveryOrder.preparedBy'),
                    'received_by' => $request->input('deliveryOrder.receivedBy'),
                    'picked_by' => $request->input('deliveryOrder.pickedBy'),
                    'ship_mode' => $request->input('deliveryOrder.shipMode'),
                    'order_type' => $request->input('deliveryOrder.orderType'),
                    'delivery' => $request->input('deliveryOrder.delivery'),
                    'npwp' => $request->input('deliveryOrder.npwp'),
                    'notes' => $request->input('notes'),
                ]);

                // Update purchase order status
                $purchaseOrder->current_status = self::RELEASE;
                $purchaseOrder->save();

                // Inlined logic from QuotationController->changeStatusToRelease
                $user = $request->user();
                $currentStatus = $quotation->status ?? [];
                if (!is_array($currentStatus)) {
                    $currentStatus = [];
                }
                $currentStatus[] = [
                    'state' => QuotationController::RELEASE,
                    'employee' => $user->username,
                    'timestamp' => now()->toIso8601String(),
                ];
                $quotation->status = $currentStatus;
                $quotation->current_status = QuotationController::RELEASE;
                $quotation->save();

                // Commit the transaction
                DB::commit();

                return response()->json([
                    'message' => 'Purchase order released and delivery order created successfully',
                    'data' => [
                        'purchase_order' => $purchaseOrder,
                        'delivery_order' => $deliveryOrder
                    ]
                ], Response::HTTP_OK);
            } else {
                DB::rollBack();
                return response()->json([
                    'message' => 'Invalid quotation type. Only SERVICE or Sparepart types are supported'
                ], Response::HTTP_BAD_REQUEST);
            }
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->handleError($th, 'Failed to release purchase order');
        }
    }

    public function decline(Request $request, $id)
    {
        // Start a database transaction
        DB::beginTransaction();

        // Validate the there is notes for downPayment
        $request->validate([
            'notes' => 'required|string',
        ]);
        $notes = $request->input('notes');

        try {
            $user = $request->user();
            $role = $user->role;
            // Only allow Finance or Director to create PI
            if ($role !== 'Finance' && $role !== 'Director') {
                DB::rollBack();
                return response()->json([
                    'message' => 'You don\'t have access to decline this.'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Retrieve the purchase order and lock it
            $purchaseOrder = $this->getAccessedPurchaseOrder($request)
                ->lockForUpdate()
                ->find($id);

            if (!$purchaseOrder) {
                DB::rollBack();
                return $this->handleNotFound('Purchase order not found');
            }

            if ($purchaseOrder->proformaInvoice) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Can\'t decline, purchase order already processed.'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Only allow director decline purchase order
            if ($role != 'Director') {
                DB::rollBack();
                return response()->json([
                    'message' => 'You are not authorized to decline this purchase order'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Change purchase order state
            $purchaseOrder->current_status = PurchaseOrderController::REJECTED;
            $purchaseOrder->notes  = $notes;
            $purchaseOrder->save();

            // Commit the transaction
            DB::commit();

            return response()->json([
                'message' => 'Purchase order status decline successfully',
                'data' => $purchaseOrder
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            // Roll back the transaction if an error occurs
            DB::rollBack();

            // Handle errors
            return $this->handleError($th, 'Failed to decline purchase order');
        }
    }

    public function update(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $purchaseOrder = $this->getAccessedPurchaseOrder($request)
                ->lockForUpdate() // Lock the record
                ->findOrFail($id);

            // Map camelCase input to snake_case for validation and update
            $input = $request->all();
            $mappedInput = [];
            $fieldMap = [
                'quotationId' => 'quotation_id',
                'purchaseOrderNumber' => 'purchase_order_number',
                'purchaseOrderDate' => 'purchase_order_date',
                'paymentDue' => 'payment_due',
                'employeeId' => 'employee_id',
                'currentStatus' => 'current_status',
                'notes' => 'notes',
            ];
            foreach ($fieldMap as $camel => $snake) {
                if (array_key_exists($camel, $input)) {
                    $mappedInput[$snake] = $input[$camel];
                }
            }

            // Define validation rules, all fields are nullable
            $validator = Validator::make($mappedInput, [
                'quotation_id' => 'nullable|exists:quotations,id',
                'purchase_order_number' => ['nullable', 'max:255', Rule::unique('purchase_orders')->ignore($id)],
                'purchase_order_date' => 'nullable|date',
                'payment_due' => 'nullable|date',
                'employee_id' => 'nullable|exists:employees,id',
                'current_status' => ['nullable', Rule::in([self::BO, self::PREPARE, self::READY, self::RELEASE, self::RETURNED, self::PAID])],
                'notes' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], Response::HTTP_BAD_REQUEST);
            }

            // Prepare update data, only include fields that are provided and not null
            $updateData = [];
            foreach ($fieldMap as $camel => $snake) {
                if (array_key_exists($camel, $input) && $input[$camel] !== null) {
                    $updateData[$snake] = $input[$camel];
                }
            }

            // Update the purchase order if there are changes
            if (!empty($updateData)) {
                $purchaseOrder->update($updateData);
            }

            // Commit the transaction
            DB::commit();

            // Fetch the updated purchase order with related data for response
            $updatedPurchaseOrder = $this->getAccessedPurchaseOrder($request)
                ->with(['quotation.customer', 'quotation.detailQuotations.sparepart', 'proformaInvoice', 'employee'])
                ->findOrFail($id);

            $quotation = $updatedPurchaseOrder->quotation;
            $customer = $quotation ? $quotation->customer : null;
            $proformaInvoice = $updatedPurchaseOrder->proformaInvoice ? $updatedPurchaseOrder->proformaInvoice : null;

            $spareParts = [];
            $services = [];
            if ($quotation && $quotation->detailQuotations) {
                foreach ($quotation->detailQuotations as $detail) {
                    if ($detail->sparepart_id) {
                        $sparepart = $detail->sparepart;
                        $spareParts[] = [
                            'sparepart_id' => $sparepart ? $sparepart->id : '',
                            'sparepart_name' => $sparepart ? $sparepart->sparepart_name : '',
                            'sparepart_number' => $sparepart ? $sparepart->sparepart_number : '',
                            'quantity' => $detail->quantity ?? 0,
                            'unit_price_sell' => $detail->unit_price ?? 0,
                            'total_price' => ($detail->quantity * ($detail->unit_price ?? 0)),
                            'stock' => $detail->is_indent ? 'indent' : 'available'
                        ];
                    } else {
                        $services[] = [
                            'service' => $detail->service ?? '',
                            'unit_price_sell' => $detail->unit_price ?? 0,
                            'quantity' => $detail->quantity ?? 0,
                            'total_price' => ($detail->quantity * ($detail->unit_price ?? 0))
                        ];
                    }
                }
            }

            $formattedPurchaseOrder = [
                'id' => (string)($updatedPurchaseOrder->id ?? ''),
                'purchase_order' => [
                    'purchase_order_number' => $updatedPurchaseOrder->purchase_order_number ?? '',
                    'purchase_order_date' => $updatedPurchaseOrder->purchase_order_date ?? '',
                    'type' => $quotation ? $quotation->type : ''
                ],
                'proforma_invoice' => [
                    'proforma_invoice_number' => $proformaInvoice ? $proformaInvoice->proforma_invoice_number : '',
                    'proforma_invoice_date' => $proformaInvoice ? $proformaInvoice->proforma_invoice_date : '',
                    'is_dp_paid' => $proformaInvoice ? $proformaInvoice->is_dp_paid : '',
                    'is_full_paid' => $proformaInvoice ? $proformaInvoice->is_full_paid : ''
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
                'price' => [
                    'amount' => $quotation ? $quotation->amount : 0,
                    'discount' => $quotation ? $quotation->discount : 0,
                    'subtotal' => $quotation ? $quotation->subtotal : 0,
                    'down_payment' => $proformaInvoice ? $proformaInvoice->down_payment : 0,
                    'total' => $proformaInvoice ? $proformaInvoice->grand_total : 0,
                    'ppn' => $quotation ? $quotation->ppn : 0,
                    'total_amount' => $proformaInvoice ? $proformaInvoice->total_amount : 0
                ],
                'notes' => $updatedPurchaseOrder->notes ?? '',
                'current_status' => $updatedPurchaseOrder->current_status ?? '',
                'status' => $quotation ? $quotation->status : [],
                'down_payment' => $proformaInvoice ? $proformaInvoice->down_payment : 0,
                'quotation_number' => $quotation ? $quotation->quotation_number : '',
                'spareparts' => $spareParts,
                'services' => $services
            ];

            return response()->json([
                'message' => 'Purchase order updated successfully',
                'data' => $formattedPurchaseOrder
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->handleError($th, 'Failed to update purchase order');
        }
    }

    function getAccessedPurchaseOrder($request)
    {
        try {
            $user = $request->user();
            $userId = $user->id;
            $role = $user->role;

            $query = PurchaseOrder::query();

            // Only allow purchase orders for authorized users
            if ($role == 'Marketing') {
                $query->where('employee_id', $userId);
            } elseif ($role == 'Service') {
                $query->whereHas('quotation', function ($q) {
                    $q->where('type', QuotationController::SERVICE);
                });
            } elseif ($role == 'Inventory') {
                $query->whereHas('quotation', function ($q) {
                    $q->where('type', QuotationController::SPAREPARTS);
                });
            }

            return $query;
        } catch (\Throwable $th) {
            // Return empty query builder
            return PurchaseOrder::whereNull('id');
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