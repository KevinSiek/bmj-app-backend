<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\ProformaInvoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

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
                    // 'purchaseOrder.quotation.detailQuotations.sparepart.detailBuys', // we might want to change "detailBuys" to "detailSpareparts"
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

                    $spareparts = collect();
                    foreach ($detailQuotations as $detailQuotation) {
                        $sparepart = $detailQuotation->sparepart;
                        $spareparts->push([
                            'sparepart_id' => $sparepart->id ?? '',
                            'sparepart_name' => $sparepart->sparepart_name ?? '',
                            'sparepart_number' => $sparepart->part_number ?? '',
                            'quantity' => $detailQuotation->quantity ?? 0,
                            'unit_price_sell' => $detailQuotation->unit_price ?? 0,
                            'total_price' => ($detailQuotation->quantity ?? 0) * ($detailQuotation->unit_price ?? 0),
                            'stock' => $detailQuotation->is_indent ? 'indent' : 'available'
                        ]);
                    }

                    return [
                        'id' => (string) $pi->id,
                        'project' => [
                            'proforma_invoice_number' => $pi->proforma_invoice_number,
                            'type' => $quotation->type ?? '',
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
                            'total_amount' => $quotation->total_amount,
                        ],
                        'down_payment' => $pi->down_payment ?? 0,
                        'status' => $quotation->status,
                        'quotationn_number' => $quotation ? $quotation->quotation_number : '',
                        'notes' => $quotation->notes ?? '',
                        'date' => $pi->created_at,
                        'spareparts' => $spareparts,
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
                    // 'purchaseOrder.quotation.detailQuotations.sparepart.detailBuys',  // we might want to change "detailBuys" to "detailSpareparts"
                    'employee'
                ])
                ->findOrFail($id);

            $purchaseOrder = $proformaInvoice->purchaseOrder;
            $quotation = $purchaseOrder->quotation;
            $customer = $quotation->customer;
            $detailQuotations = $quotation->detailQuotations;

            $spareparts = collect();
            foreach ($detailQuotations as $detailQuotation) {
                $sparepart = $detailQuotation->sparepart;
                $spareparts->push([
                    'sparepart_id' => $sparepart->id ?? '',
                    'sparepart_name' => $sparepart->sparepart_name ?? '',
                    'sparepart_number' => $sparepart->part_number ?? '',
                    'quantity' => $detailQuotation->quantity ?? 0,
                    'unit_price_sell' => $detailQuotation->unit_price ?? 0,
                    'total_price' => ($detailQuotation->quantity ?? 0) * ($detailQuotation->unit_price ?? 0),
                    'stock' => $detailQuotation->is_indent ? 'indent' : 'available'
                ]);
            }

            $formattedProformaInvoice = [
                'id' => (string) $proformaInvoice->id,
                'project' => [
                    'proforma_invoice_number' => $proformaInvoice->proforma_invoice_number,
                    'type' => $quotation->type ?? '',
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
                    'quotationn_number' => $quotation ? $quotation->quotation_number : '',
                    'total' => $quotation->grand_total ?? 0,
                    'ppn' => $quotation->ppn ?? 0,
                    'total_amount' => $quotation->total_amount,
                ],
                'down_payment' => $proformaInvoice->down_payment ?? 0,
                'quotationn_number' => $quotation ? $quotation->quotation_number : '',
                'notes' => $quotation->notes ?? '',
                'date' => $proformaInvoice->created_at,
                'status' => $quotation->status,
                'spareparts' => $spareparts,
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

            // Format the sequence number with leading zeros (e.g., 001)
            $sequenceNumber = str_pad($invoiceCount, 3, '0', STR_PAD_LEFT);

            // Generate invoice number in the format IP/<InputNumberInOrder>/<RomawiMonth>/<Year>
            $invoiceNumber = "IP/{$sequenceNumber}/{$romanMonth}/{$year}";

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

    // Update the data for a specific proforma invoice
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
