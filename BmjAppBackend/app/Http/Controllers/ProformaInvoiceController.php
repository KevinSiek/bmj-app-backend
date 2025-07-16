<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\ProformaInvoice;
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
            $query = $this->getAccessedProformaInvoice($request)
                ->with([
                    'purchaseOrder.quotation.customer',
                    'purchaseOrder.quotation.detailQuotations.sparepart',
                    'employee'
                ]);

            // Get query parameters
            $q = $request->query('search');
            $month = $request->query('month');
            $year = $request->query('year');

            // Apply search term filter if 'q' is provided
            if ($q) {
                $query->where(function ($query) use ($q) {
                    $query->where('proforma_invoice_number', 'like', '%' . $q . '%')
                        ->orWhereHas('purchaseOrder.quotation.customer', function ($qry) use ($q) {
                            $qry->where('company_name', 'like', '%' . $q . '%');
                        });
                });
            }

            // Apply year and month filter
            if ($year) {
                $query->whereYear('proforma_invoice_date', $year);
                if ($month) {
                    $monthNumber = date('m', strtotime($month));
                    $query->whereMonth('proforma_invoice_date', $monthNumber);
                }
            }

            // Paginate the results
            $proformaInvoices = $query->orderBy('proforma_invoice_date', 'desc')
                ->paginate(20)->through(function ($pi) {
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

                    return [
                        'id' => (string) $pi->id,
                        'project' => [
                            'proforma_invoice_number' => $pi->proforma_invoice_number,
                            'type' => $quotation->type ?? '',
                            'purchase_order_number' => $purchaseOrder->purchase_order_number ?? '',
                            'purchase_order_date' => $purchaseOrder->purchase_order_date ?? '',
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
                            'down_payment' => $pi->down_payment ?? 0,
                            'total' => $quotation->grand_total ?? 0,
                            'ppn' => $quotation->ppn ?? 0,
                            'total_amount' => $quotation->total_amount ?? 0,
                        ],
                        'down_payment' => $pi->down_payment ?? 0,
                        'status' => $quotation->status ?? [],
                        'quotation_number' => $quotation ? $quotation->quotation_number : '',
                        'version' => $purchaseOrder->version,
                        'notes' => $quotation->notes ?? '',
                        'date' => $pi->created_at,
                        'spareparts' => $spareParts,
                        'services' => $services
                    ];
                });

            return response()->json([
                'message' => 'List of proforma invoices retrieved successfully',
                'data' => $proformaInvoices,
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

            $formattedProformaInvoice = [
                'id' => (string) $proformaInvoice->id,
                'project' => [
                    'proforma_invoice_number' => $proformaInvoice->proforma_invoice_number,
                    'type' => $quotation->type ?? '',
                    'purchase_order_number' => $purchaseOrder->purchase_order_number ?? '',
                    'purchase_order_date' => $purchaseOrder->purchase_order_date ?? '',
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
                    'down_payment' => $proformaInvoice->down_payment ?? 0,
                    'total' => $quotation->grand_total ?? 0,
                    'ppn' => $quotation->ppn ?? 0,
                    'total_amount' => $quotation->total_amount ?? 0,
                ],
                'down_payment' => $proformaInvoice->down_payment ?? 0,
                'quotation_number' => $quotation ? $quotation->quotation_number : '',
                'version' => $purchaseOrder->version,
                'notes' => $quotation->notes ?? '',
                'date' => $proformaInvoice->created_at,
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
            $proformaInvoice = $this->getAccessedProformaInvoice($request)->find($id);

            if (!$proformaInvoice) {
                return $this->handleNotFound('Proforma invoice not found');
            }

            if ($proformaInvoice->invoices) {
                return response()->json([
                    'message' => 'Proforma invoice already has an invoice'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Get current date components
            $currentDate = now();
            $year = $currentDate->format('Y');
            $month = $currentDate->month;

            // Convert month to Roman numeral
            $romanMonths = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
            $romanMonth = $romanMonths[$month - 1];

            // Get the count of invoices for the current month and year to determine the sequence number
            $invoiceCount = Invoice::whereYear('invoice_date', $year)
                ->whereMonth('invoice_date', $month)
                ->count() + 1; // Increment by 1 for the new invoice


            // Generate proforma invoice number from purchase order number
            try {
                // Expected purchase_order_number format: PO-IN/033/V/24
                $parts = explode('/', $proformaInvoice->proforma_invoice_number);
                $piNumber = $parts[0]; // e.g., 033
                $branch = $parts[3]; // e.g., V
                $romanMonth = $parts[2]; // e.g., 24
                $year = $parts[5]; // e.g., 24
                $invoiceNumber = "IP/{$piNumber}/{$romanMonth}/{$branch}/{$year}";
            } catch (\Throwable $th) {
                // Fallback to timestamp-based PI number with current month and year
                $currentMonth = Carbon::now()->format('m'); // Two-digit month
                $currentYear = Carbon::now()->format('Y'); // Four-digit year
                $latestPi = $this->getAccessedProformaInvoice($request)
                    ->whereMonth('created_at', $currentMonth)
                    ->whereYear('created_at', $currentYear)
                    ->latest('id')
                    ->first();
                $lastestInvoice = $latestPi ? $latestPi->invoices : null;
                $nextLastestInvoice = $lastestInvoice ? $lastestInvoice->id + 1 : 1;

                // Get user branch
                $user = $request->user();
                $branchCode = $user->branch === EmployeeController::SEMARANG ? 'SMG' : 'JKT';
                $currentMonth = now()->month; // e.g., 7 for July
                $romanMonth = $this->getRomanMonth($currentMonth); // e.g., VII
                $year = now()->format('y'); // e.g., 25 for 2025
                $invoiceNumber = "IP/{$nextLastestInvoice}/{$romanMonth}/{$branchCode}/{$year}";
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
        try {
            // Validate the request data
            $validatedData = $request->validate([
                'downPayment' => 'required|numeric|min:0',
            ]);

            // Find the proforma invoice with access control
            $proformaInvoice = $this->getAccessedProformaInvoice($request)->find($id);

            if (!$proformaInvoice) {
                return $this->handleNotFound('Proforma invoice not found');
            }

            // Update only the down_payment field for now
            $proformaInvoice->update([
                'down_payment' => $validatedData['downPayment'],
            ]);

            return response()->json([
                'message' => 'Down payment updated successfully',
                'data' => [
                    'id' => (string) $proformaInvoice->id,
                    'proforma_invoice_number' => $proformaInvoice->proforma_invoice_number,
                    'down_payment' => $proformaInvoice->down_payment,
                ],
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th, 'Failed to update down payment');
        }
    }

    public function dpPaid(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $proformaInvoice = $this->getAccessedProformaInvoice($request)->find($id);

            if (!$proformaInvoice) {
                return $this->handleNotFound('Proforma invoice not found');
            }

            $purchaseOrder = $proformaInvoice->purchaseOrder;
            $purchaseOrder->current_status = QuotationController::DP_PAID;
            $purchaseOrder->save();

            $proformaInvoice->is_dp_paid = true;
            $proformaInvoice->save();
            $quotation = $purchaseOrder->quotation;
            $this->quotationController->changeStatusToPaid($request, $quotation, true);

            DB::commit();

            return response()->json([
                'message' => 'Proforma invoice down payment paid successfully',
                'data' => $proformaInvoice
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->handleError($th, 'Failed to promote proforma invoice');
        }
    }

    public function fullPaid(Request $request, $po_id)
    {
        DB::beginTransaction();

        try {
            // Search PI via PO id
            $purchaseOrder = $this->purchaseOrderController->getAccessedPurchaseOrder($request)->find($po_id);
            $proformaInvoice = $purchaseOrder->proformaInvoice;

            if (!$proformaInvoice) {
                return $this->handleNotFound('Proforma invoice not found');
            }

            $purchaseOrder = $proformaInvoice->purchaseOrder;
            $quotation = $purchaseOrder->quotation;
            $purchaseOrder->current_status = QuotationController::FULL_PAID;
            $purchaseOrder->save();

            $proformaInvoice->is_full_paid = true;
            $proformaInvoice->save();

            $this->quotationController->changeStatusToPaid($request, $quotation, false);

            DB::commit();

            return response()->json([
                'message' => 'Proforma invoice paid fully successfully',
                'data' => $proformaInvoice
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->handleError($th, 'Failed to promote proforma invoice');
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
