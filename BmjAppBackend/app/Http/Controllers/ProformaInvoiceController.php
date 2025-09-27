<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\ProformaInvoice;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Carbon\Carbon;

class ProformaInvoiceController extends Controller
{
    protected $quotationController;
    protected $purchaseOrderController;

    public function __construct(QuotationController $quotationController, PurchaseOrderController $purchaseOrderController)
    {
        $this->quotationController = $quotationController;
        $this->purchaseOrderController = $purchaseOrderController;
    }

    public function getAll(Request $request)
    {
        try {
            // Get all invoice numbers first to ensure we capture all versions
            $proformaInvoiceNumbers = $this->getAccessedProformaInvoice($request)
                ->select('proforma_invoice_number');

            // Get query parameters
            $q = $request->query('search');
            $month = $request->query('month');
            $year = $request->query('year');

            // Apply search term filter if 'q' is provided
            if ($q) {
                $proformaInvoiceNumbers->where(function ($proformaInvoiceNumbers) use ($q) {
                    $proformaInvoiceNumbers->where('proforma_invoice_number', 'like', '%' . $q . '%')
                        ->orWhereHas('purchaseOrder.quotation.customer', function ($qry) use ($q) {
                            $qry->where('company_name', 'like', '%' . $q . '%');
                        });
                });
            }

            // Apply year and month filter
            if ($year) {
                $proformaInvoiceNumbers->whereYear('proforma_invoice_date', $year);
                if ($month) {
                    $monthNumber = date('m', strtotime($month));
                    $proformaInvoiceNumbers->whereMonth('proforma_invoice_date', $monthNumber);
                }
            }

            // Paginate after groupBy proforma_invoice_number
            $paginatedProformaInvoiceNumbers = $proformaInvoiceNumbers->groupBy('proforma_invoice_number')->paginate(20);


            // Get all quotations for the paginated quotation numbers
            $query = $this->getAccessedProformaInvoice($request)
                ->whereIn('proforma_invoice_number', $paginatedProformaInvoiceNumbers->pluck('proforma_invoice_number'));

            // Return like API format
            $proformaInvoice = $query
                // Sort primarily by the numeric part of the proforma_invoice number (e.g., 033 from PI/033/...).
                // The existing sorting logic is kept as secondary sorting criteria.
                ->orderByRaw('CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(proforma_invoice_number, \'/\', 2), \'/\', -1) AS UNSIGNED) DESC')
                ->orderBy('proforma_invoice_date', 'desc')
                ->orderBy('id', 'asc')
                ->get();
            $proformaInvoices = $proformaInvoice->map(function ($pi) {
                $purchaseOrder = $pi->purchaseOrder;
                $quotation = $purchaseOrder->quotation;
                $customer = $quotation->customer;
                $detailQuotations = $quotation->detailQuotations;

                $spareParts = [];
                $services = [];
                foreach ($detailQuotations as $detail) {
                    if ($detail->sparepart_id) {
                        $sparepart = $detail->sparepart;
                        $spareParts[] = [
                            'sparepart_id' => $sparepart->id ?? '',
                            'sparepart_name' => $sparepart->sparepart_name ?? '',
                            'sparepart_number' => $sparepart->part_number ?? '',
                            'quantity' => $detail->quantity ?? 0,
                            'unit_price_sell' => $detail->unit_price ?? 0,
                            'total_price' => ($detail->quantity ?? 0) * ($detail->unit_price ?? 0),
                            'stock' => $detail->is_indent ? 'indent' : 'available'
                        ];
                    } else {
                        $services[] = [
                            'service' => $detail->service ?? '',
                            'unit_price_sell' => $detail->unit_price ?? 0,
                            'quantity' => $detail->quantity ?? 0,
                            'total_price' => ($detail->quantity ?? 0) * ($detail->unit_price ?? 0)
                        ];
                    }
                }

                $advancePayment = ($quotation->subtotal * $pi->down_payment)/100 ?? 0;
                $total = $quotation->subtotal - $advancePayment ?? 0;
                $totalAmount = $total + $quotation->ppn ?? 0;

                return [
                    'id' => (string) $pi->id,
                    'project' => [
                        'proforma_invoice_number' => $pi->proforma_invoice_number,
                        'type' => $quotation->type ?? '',
                        'purchase_order_number' => $purchaseOrder->purchase_order_number ?? '',
                        'purchase_order_date' => $purchaseOrder->purchase_order_date ?? '',
                        'date' => $pi->proforma_invoice_date->format('Y-m-d') ?? '',
                    ],
                    'customer' => [
                        'company_name' => $customer->company_name ?? '',
                        'address' => $customer->address ?? '',
                        'city' => $customer->city ?? '',
                        'province' => $customer->province ?? '',
                        'office' => $customer->office ?? '',
                        'urban' => $customer->urban ?? '',
                        'subdistrict' => $customer->subdistrict ?? '',
                        'postal_code' => $customer->postal_code ?? '',
                    ],
                    'price' => [
                        'amount' => $quotation->amount ?? 0,
                        'discount' => $quotation->discount ?? 0,
                        'subtotal' => $quotation->subtotal ?? 0,
                        'advance_payment' => $advancePayment,
                        'total' => $total,
                        'ppn' => $quotation->ppn ?? 0,
                        'total_amount' => $totalAmount,
                    ],
                    'down_payment' => $pi->down_payment ?? 0,
                    'status' => $quotation->status ?? [],
                    'quotation_number' => $quotation ? $quotation->quotation_number : '',
                    'version' => $purchaseOrder->version,
                    'notes' => $pi->notes ?? '',
                    'spareparts' => $spareParts,
                    'services' => $services
                ];
            });

            return response()->json([
                'message' => 'List of proforma invoices retrieved successfully',
                'data' => [
                    'data' => $proformaInvoices,
                    'from' => $paginatedProformaInvoiceNumbers->firstItem(),
                    'to' => $paginatedProformaInvoiceNumbers->lastItem(),
                    'total' => $paginatedProformaInvoiceNumbers->total(),
                    'per_page' => $paginatedProformaInvoiceNumbers->perPage(),
                    'current_page' => $paginatedProformaInvoiceNumbers->currentPage(),
                    'last_page' => $paginatedProformaInvoiceNumbers->lastPage(),
                ]
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function get(Request $request, $id)
    {
        try {
            $proformaInvoice = $this->getAccessedProformaInvoice($request)
                ->with([
                    'purchaseOrder.quotation.customer',
                    'purchaseOrder.quotation.detailQuotations.sparepart',
                    'employee'
                ])
                ->findOrFail($id);

            $purchaseOrder = $proformaInvoice->purchaseOrder;
            $quotation = $purchaseOrder->quotation;
            $customer = $quotation->customer;
            $detailQuotations = $quotation->detailQuotations;

            $spareParts = [];
            $services = [];
            foreach ($detailQuotations as $detail) {
                if ($detail->sparepart_id) {
                    $sparepart = $detail->sparepart;
                    $spareParts[] = [
                        'sparepart_id' => $sparepart->id ?? '',
                        'sparepart_name' => $sparepart->sparepart_name ?? '',
                        'sparepart_number' => $sparepart->part_number ?? '',
                        'quantity' => $detail->quantity ?? 0,
                        'unit_price_sell' => $detail->unit_price ?? 0,
                        'total_price' => ($detail->quantity ?? 0) * ($detail->unit_price ?? 0),
                        'stock' => $detail->is_indent ? 'indent' : 'available'
                    ];
                } else {
                    $services[] = [
                        'service' => $detail->service ?? '',
                        'unit_price_sell' => $detail->unit_price ?? 0,
                        'quantity' => $detail->quantity ?? 0,
                        'total_price' => ($detail->quantity ?? 0) * ($detail->unit_price ?? 0)
                    ];
                }
            }

            $advancePayment = ($quotation->subtotal * $proformaInvoice->down_payment)/100 ?? 0;
            $total = $quotation->subtotal - $advancePayment ?? 0;
            $totalAmount = $total + $quotation->ppn ?? 0;

            $formattedProformaInvoice = [
                'id' => (string) $proformaInvoice->id,
                'project' => [
                    'proforma_invoice_number' => $proformaInvoice->proforma_invoice_number,
                    'type' => $quotation->type ?? '',
                    'purchase_order_number' => $purchaseOrder->purchase_order_number ?? '',
                    'purchase_order_date' => $purchaseOrder->purchase_order_date ?? '',
                    'date' => $proformaInvoice->proforma_invoice_date->format('Y-m-d') ?? '',
                ],
                'customer' => [
                    'company_name' => $customer->company_name ?? '',
                    'address' => $customer->address ?? '',
                    'city' => $customer->city ?? '',
                    'province' => $customer->province ?? '',
                    'office' => $customer->office ?? '',
                    'urban' => $customer->urban ?? '',
                    'subdistrict' => $customer->subdistrict ?? '',
                    'postal_code' => $customer->postal_code ?? '',
                ],
                'price' => [
                    'amount' => $quotation->amount ?? 0,
                    'discount' => $quotation->discount ?? 0,
                    'subtotal' => $quotation->subtotal ?? 0,
                    'advance_payment' => $advancePayment,
                    'total' => $total,
                    'ppn' => $quotation->ppn ?? 0,
                    'total_amount' => $totalAmount,
                ],
                'down_payment' => $proformaInvoice->down_payment ?? 0,
                'quotation_number' => $quotation ? $quotation->quotation_number : '',
                'version' => $purchaseOrder->version,
                'notes' => $proformaInvoice->notes ?? '',
                'status' => $quotation->status ?? [],
                'spareparts' => $spareParts,
                'services' => $services
            ];

            return response()->json([
                'message' => 'Proforma invoice retrieved successfully',
                'data' => $formattedProformaInvoice,
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function moveToInvoice(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $proformaInvoice = $this->getAccessedProformaInvoice($request)->lockForUpdate()->find($id);

            if (!$proformaInvoice) {
                DB::rollBack();
                return $this->handleNotFound('Proforma invoice not found');
            }

            // Use a relationship check which is cleaner
            if ($proformaInvoice->invoice()->exists()) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Proforma invoice already has an invoice'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Get current date
            $currentDate = now();

            // Generate proforma invoice number from purchase order number
            try {
                // Expected purchase_order_number format: PI/001/BMJ-MEGAH/JKT/1/V/25
                $parts = explode('/', $proformaInvoice->proforma_invoice_number);
                $piNumber = $parts[1]; // e.g., 033
                $branch = $parts[3]; // e.g., V
                $currentMonth = now()->month; // e.g., 7 for July
                $romanMonth = $this->getRomanMonth($currentMonth); // e.g., VII
                $year = now()->format('y'); // e.g., 25 for 2025
                $user = $request->user();
                $userId = $user->id;
                $invoiceNumber = "IP/{$piNumber}/BMJ-MEGAH/{$branch}/{$userId}/{$romanMonth}/{$year}";
            } catch (\Throwable $th) {
                // Fallback to timestamp-based PI number with current month and year
                $latestInvoice = Invoice::latest('id')->lockForUpdate()->first();
                $nextLastestInvoice = $latestInvoice ? $latestInvoice->id + 1 : 1;

                // Get user branch
                $user = $request->user();
                $userId = $user->id;
                $branchCode = $user->branch === EmployeeController::SEMARANG ? 'SMG' : 'JKT';
                $currentMonth = now()->month; // e.g., 7 for July
                $romanMonth = $this->getRomanMonth($currentMonth); // e.g., VII
                $year = now()->format('y'); // e.g., 25 for 2025
                $invoiceNumber = "IP/{$nextLastestInvoice}/BMJ-MEGAH/{$branchCode}/{$userId}/{$romanMonth}/{$year}";
            }
            $invoice = Invoice::create([
                'proforma_invoice_id' => $proformaInvoice->id,
                'invoice_number' => $invoiceNumber,
                'invoice_date' => $currentDate,
                'employee_id' => $proformaInvoice->employee_id,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Proforma invoice promoted to invoice successfully',
                'data' => $invoice
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->handleError($th, 'Failed to promote proforma invoice');
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

    public function update(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            // Validate the request data
            $validatedData = $request->validate([
                'downPayment' => 'required|numeric|min:0',
            ]);

            // Find the proforma invoice with access control and lock it
            $proformaInvoice = $this->getAccessedProformaInvoice($request)->lockForUpdate()->find($id);

            if (!$proformaInvoice) {
                DB::rollBack();
                return $this->handleNotFound('Proforma invoice not found');
            }

            // Update only the down_payment field for now
            $proformaInvoice->update([
                'down_payment' => $validatedData['downPayment'],
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Down payment updated successfully',
                'data' => [
                    'id' => (string) $proformaInvoice->id,
                    'proforma_invoice_number' => $proformaInvoice->proforma_invoice_number,
                    'down_payment' => $proformaInvoice->down_payment,
                ],
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->handleError($th, 'Failed to update down payment');
        }
    }

    public function dpPaid(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $proformaInvoice = $this->getAccessedProformaInvoice($request)->lockForUpdate()->find($id);

            if (!$proformaInvoice) {
                DB::rollBack();
                return $this->handleNotFound('Proforma invoice not found');
            }

            $purchaseOrder = PurchaseOrder::lockForUpdate()->find($proformaInvoice->purchase_order_id);
            if ($purchaseOrder->current_status === QuotationController::REJECTED) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Cannot full paid a rejected purchase order'
                ], Response::HTTP_BAD_REQUEST);
            }
            if ($purchaseOrder) {
                $purchaseOrder->current_status = QuotationController::DP_PAID;
                $purchaseOrder->save();

                $quotation = Quotation::lockForUpdate()->find($purchaseOrder->quotation_id);
                if ($quotation) {
                    // Inlined logic from QuotationController->changeStatusToPaid
                    $user = $request->user();
                    $currentStatus = $quotation->status ?? [];
                    if (!is_array($currentStatus)) {
                        $currentStatus = [];
                    }
                    $currentStatus[] = [
                        'state' => QuotationController::DP_PAID,
                        'employee' => $user->username,
                        'timestamp' => now()->toIso8601String(),
                    ];
                    $quotation->status = $currentStatus;
                    $quotation->current_status = QuotationController::DP_PAID;
                    $quotation->save();
                }
            }

            $proformaInvoice->is_dp_paid = true;
            $proformaInvoice->save();

            DB::commit();

            return response()->json([
                'message' => 'Proforma invoice down payment paid successfully',
                'data' => $proformaInvoice
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->handleError($th, 'Failed to update payment status');
        }
    }

    public function fullPaid(Request $request, $po_id)
    {
        DB::beginTransaction();

        try {
            // Search PI via PO id and lock the PO
            $purchaseOrder = $this->purchaseOrderController->getAccessedPurchaseOrder($request)->lockForUpdate()->find($po_id);

            if (!$purchaseOrder) {
                DB::rollBack();
                return $this->handleNotFound('Purchase order not found');
            }

            if ($purchaseOrder->current_status === QuotationController::REJECTED) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Cannot full paid a rejected purchase order'
                ], Response::HTTP_BAD_REQUEST);
            }

            $proformaInvoice = ProformaInvoice::where('purchase_order_id', $purchaseOrder->id)->lockForUpdate()->first();

            if (!$proformaInvoice) {
                DB::rollBack();
                return $this->handleNotFound('Proforma invoice not found for this purchase order');
            }

            $quotation = Quotation::lockForUpdate()->find($purchaseOrder->quotation_id);

            $purchaseOrder->current_status = QuotationController::FULL_PAID;
            $purchaseOrder->save();

            $proformaInvoice->is_full_paid = true;
            $proformaInvoice->save();

            if ($quotation) {
                // Inlined logic from QuotationController->changeStatusToPaid
                $user = $request->user();
                $currentStatus = $quotation->status ?? [];
                if (!is_array($currentStatus)) {
                    $currentStatus = [];
                }
                $currentStatus[] = [
                    'state' => QuotationController::FULL_PAID,
                    'employee' => $user->username,
                    'timestamp' => now()->toIso8601String(),
                ];
                $quotation->status = $currentStatus;
                $quotation->current_status = QuotationController::FULL_PAID;
                $quotation->save();
            }

            DB::commit();

            return response()->json([
                'message' => 'Proforma invoice paid fully successfully',
                'data' => $proformaInvoice
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->handleError($th, 'Failed to update payment status');
        }
    }

    protected function getAccessedProformaInvoice($request)
    {
        try {
            $user = $request->user();
            $userId = $user->id;
            $role = $user->role;

            $query = ProformaInvoice::query();

            // Only allow proforma invoices for authorized users
            // Just in case we want enable this in future
            if ($role == 'Marketing') {
                $query->where('employee_id', $userId);
            }

            return $query;
        } catch (\Throwable $th) {
            // Return empty query builder
            return ProformaInvoice::whereNull('id');
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
