<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ProformaInvoice;
use App\Models\PurchaseOrder;
use App\Models\WorkOrder;
use App\Models\WoUnit;
use App\Models\DeliveryOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PurchaseOrderController extends Controller
{
    const BO = "BO";
    const PREPARE = "Prepare";
    const READY = "Ready";
    const RELEASE = "Release";
    const FINISHED = "Finished";
    const RETURNED = "Returned";
    const PAID = "Paid";

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
                ->findOrFail($id);

            $quotation = $purchaseOrder->quotation;
            $customer = $quotation ? $quotation->customer : null;
            $proformaInvoice = $purchaseOrder->proformaInvoice ? $purchaseOrder->proformaInvoice : null;

            $spareParts = $quotation && $quotation->detailQuotations ? $quotation->detailQuotations->map(function ($detail) {
                $sparepart = $detail->sparepart;
                return [
                    'sparepart_id' => $sparepart ? $sparepart->id : '',
                    'sparepart_name' => $sparepart ? $sparepart->sparepart_name : '',
                    'sparepart_number' => $sparepart ? $sparepart->sparepart_number : '',
                    'quantity' => $detail->quantity ?? 0,
                    'unit_price_sell' => $detail->unit_price ?? 0,
                    'total_price' => ($detail->quantity * ($detail->unit_price ?? 0)),
                    'stock' => $detail->is_indent ? 'indent' : 'available'
                ];
            })->toArray() : [];

            $formattedPurchaseOrder = [
                'id' => (string) ($purchaseOrder->id ?? ''),
                'purchase_order' => [
                    'purchase_order_number' => $purchaseOrder->purchase_order_number ?? '',
                    'purchase_order_date' => $purchaseOrder->purchase_order_date ?? '',
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
                'notes' => $purchaseOrder->notes ?? '',
                'current_status' => $purchaseOrder->current_status ?? '',
                'status' =>  $quotation->status,
                'down_payment' => $proformaInvoice ? $proformaInvoice->down_payment : 0,
                'quotationn_number' => $quotation ? $quotation->quotation_number : '',
                'spareparts' => $spareParts
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
            $query = $this->getAccessedPurchaseOrder($request)
                ->with(['quotation.customer', 'quotation.detailQuotations.sparepart', 'proformaInvoice', 'employee']);

            // Get query parameters
            $q = $request->query('search');
            $month = $request->query('month');
            $year = $request->query('year');

            // Apply search term filter if 'q' is provided
            if ($q) {
                $query->where(function ($query) use ($q) {
                    $query->where('purchase_order_number', 'like', '%' . $q . '%')
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
                $query->whereYear('purchase_order_date', $year);
                if ($month) {
                    $monthNumber = date('m', strtotime($month));
                    $query->whereMonth('purchase_order_date', $monthNumber);
                }
            }

            // Paginate the results
            $purchaseOrders = $query->orderBy('purchase_order_date', 'DESC')
                ->paginate(20)->through(function ($po) {
                    $quotation = $po->quotation;
                    $customer = $quotation ? $quotation->customer : null;
                    $proformaInvoice = $po->proformaInvoice ? $po->proformaInvoice : null;

                    $spareParts = $quotation && $quotation->detailQuotations ? $quotation->detailQuotations->map(function ($detail) {
                        $sparepart = $detail->sparepart;
                        return [
                            'sparepart_id' => $sparepart ? $sparepart->id : '',
                            'sparepart_name' => $sparepart ? $sparepart->sparepart_name : '',
                            'sparepart_number' => $sparepart ? $sparepart->sparepart_number : '',
                            'quantity' => $detail->quantity ?? 0,
                            'unit_price_sell' => $detail->unit_price ?? 0,
                            'total_price' => ($detail->quantity * ($detail->unit_price ?? 0)),
                            'stock' => $detail->is_indent ? 'indent' : 'available'
                        ];
                    })->toArray() : [];

                    return [
                        'id' => (string) ($po->id ?? ''),
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
                        'status' => $quotation->status,
                        'down_payment' => $proformaInvoice ? $proformaInvoice->down_payment : 0,
                        'quotationn_number' => $quotation ? $quotation->quotation_number : '',
                        'spareparts' => $spareParts
                    ];
                });

            return response()->json([
                'message' => 'List of purchase orders retrieved successfully',
                'data' => $purchaseOrders,
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
            $purchaseOrder = $this->getAccessedPurchaseOrder($request)->find($id);

            if (!$purchaseOrder) {
                return $this->handleNotFound('Purchase order not found');
            }

            if ($purchaseOrder->proformaInvoice) {
                return response()->json([
                    'message' => 'Purchase order already has a proforma invoice'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Generate proforma invoice number from purchase order number
            try {
                // Expected purchase_order_number format: PO-IN/033/V/24
                $parts = explode('/', $purchaseOrder->purchase_order_number);
                $piNumber = $parts[1]; // e.g., 033
                $romanMonth = $parts[2]; // e.g., V
                $year = $parts[3]; // e.g., 24
                $proformaInvoiceNumber = "PI-IN/{$piNumber}/{$romanMonth}/{$year}";
            } catch (\Throwable $th) {
                // Fallback to timestamp-based PI number with current month and year
                $currentMonth = now()->month; // e.g., 5 for May
                $romanMonth = $this->getRomanMonth($currentMonth); // e.g., V
                $year = now()->format('y'); // e.g., 25 for 2025
                $timestamp = now()->format('YmdHis'); // Unique identifier
                $proformaInvoiceNumber = "PI-IN/{$timestamp}/{$romanMonth}/{$year}";
            }

            $proformaInvoice = ProformaInvoice::create([
                'purchase_order_id' => $purchaseOrder->id,
                'proforma_invoice_number' => $proformaInvoiceNumber,
                'proforma_invoice_date' => now(),
                'employee_id' => $purchaseOrder->employee_id,
                'is_dp_paid' => false,
                'is_full_paid' => false,
            ]);

            $quotation = $purchaseOrder->quotation;
            $quotation->update([
                'current_status' => QuotationController::PI
            ]);
            $this->quotationController->changeStatusToPi($request, $quotation);

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
            return $this->handleError($th, 'Failed to update purchase order status to' . $status);
        }
    }

    public function ready(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $purchaseOrder = $this->getAccessedPurchaseOrder($request)
                ->findOrFail($id);

            // Update purchase order status
            $purchaseOrder->current_status = self::READY;
            $purchaseOrder->save();

            // Update status quotation for tracking
            $quotation = $purchaseOrder->quotation;
            $this->quotationController->changeStatusToReady($request, $quotation);

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
            return $this->handleError($th, 'Failed to release purchase order');
        }
    }

    public function release(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $purchaseOrder = $this->getAccessedPurchaseOrder($request)
                ->findOrFail($id);

            $quotation = $purchaseOrder->quotation;

            // Check if quotation exists
            if (!$quotation) {
                return response()->json([
                    'message' => 'Quotation not found for this purchase order'
                ], Response::HTTP_BAD_REQUEST);
            }

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
                ]);

                if ($validator->fails()) {
                    return response()->json([
                        'message' => 'Validation failed',
                        'error' => $validator->errors()
                    ], Response::HTTP_BAD_REQUEST);
                }

                // Check if work order already exists
                if ($quotation->workOrder) {
                    return response()->json([
                        'message' => 'Work order already exists for this quotation'
                    ], Response::HTTP_BAD_REQUEST);
                }

                // Check if quotation has PI with DP paid
                $proformaInvoice = $purchaseOrder->proformaInvoice;
                if (!$proformaInvoice || !$proformaInvoice->is_dp_paid) {
                    return response()->json([
                        'message' => 'Proforma invoice must exist and down payment must be paid'
                    ], Response::HTTP_BAD_REQUEST);
                }

                // Generate work order number
                $orderNumber = sprintf('%03d', WorkOrder::count() + 1);
                $randomString1 = strtoupper(Str::random(3));
                $randomString2 = strtoupper(Str::random(3));
                $monthRoman = $this->getRomanMonth(now()->month);
                $year = now()->year;
                $workOrderNumber = "WO.{$orderNumber}/{$randomString1}-{$randomString2}/{$monthRoman}/{$year}";

                // Create work order
                $workOrder = WorkOrder::create([
                    'quotation_id' => $quotation->id,
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
                    'spareparts' => json_encode($request->input('additional.spareparts')),
                    'backup_sparepart' => $request->input('additional.backupSparepart') ? json_encode($request->input('additional.backupSparepart')) : null,
                    'scope' => $request->input('additional.scope'),
                    'vaccine' => $request->input('additional.vaccine'),
                    'apd' => $request->input('additional.apd'),
                    'peduli_lindungi' => $request->input('additional.peduliLindungi'),
                    'execution_time' => $request->input('additional.executionTime')
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

                $this->quotationController->changeStatusToRelease($request, $quotation);

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
                if ($quotation->deliveryOrder) {
                    return response()->json([
                        'message' => 'Delivery order already exists for this quotation'
                    ], Response::HTTP_BAD_REQUEST);
                }

                // Check if quotation has PI
                $proformaInvoice = $purchaseOrder->proformaInvoice;
                if (!$proformaInvoice) {
                    return response()->json([
                        'message' => 'Proforma invoice must exist for Sparepart type'
                    ], Response::HTTP_BAD_REQUEST);
                }

                // Create delivery order
                $deliveryOrder = DeliveryOrder::create([
                    'quotation_id' => $quotation->id,
                    'type' => 'Sparepart',
                    'current_status' => 'Process',
                    'notes' => $request->input('notes') ?? null,
                ]);

                // Update purchase order status
                $purchaseOrder->current_status = self::RELEASE;
                $purchaseOrder->save();

                $this->quotationController->changeStatusToRelease($request, $quotation);

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
                return response()->json([
                    'message' => 'Invalid quotation type. Only SERVICE or Sparepart types are supported'
                ], Response::HTTP_BAD_REQUEST);
            }
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->handleError($th, 'Failed to release purchase order');
        }
    }

    public function update(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $purchaseOrder = $this->getAccessedPurchaseOrder($request)
                ->findOrFail($id);

            // Map camelCase input to snake_case for a validation and update
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
                'current_status' => ['nullable', Rule::in([self::BO, self::PREPARE, self::READY, self::RELEASE, self::FINISHED, self::RETURNED, self::PAID])],
                'notes' => 'nullable|string',
            ]);

            if ($validator->fails()) {
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

            $spareParts = $quotation && $quotation->detailQuotations ? $quotation->detailQuotations->map(function ($detail) {
                $sparepart = $detail->sparepart;
                return [
                    'sparepart_id' => $sparepart ? $sparepart->id : '',
                    'sparepart_name' => $sparepart ? $sparepart->sparepart_name : '',
                    'sparepart_number' => $sparepart ? $sparepart->sparepart_number : '',
                    'quantity' => $detail->quantity ?? 0,
                    'unit_price_sell' => $detail->unit_price ?? 0,
                    'total_price' => ($detail->quantity * ($detail->quantity ?? 0)),
                    'stock' => $detail->is_indent ? 'indent' : 'available'
                ];
            })->toArray() : [];

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
                'status' => $quotation->status ?? [],
                'down_payment' => $proformaInvoice ? $proformaInvoice->down_payment : 0,
                'quotationn_number' => $quotation ? $quotation->quotation_number : '',
                'spareparts' => $spareParts
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
