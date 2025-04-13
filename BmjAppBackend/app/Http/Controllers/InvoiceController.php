<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InvoiceController extends Controller
{
    public function getAll(Request $request)
    {
        try {
            $query = $this->getAccessedInvoice($request)
                ->with([
                    'proformaInvoice.purchaseOrder.quotation.customer',
                    'proformaInvoice.purchaseOrder.quotation.detailQuotations.sparepart',
                    'employee'
                ]);

            // Get query parameters
            $q = $request->query('q');
            $month = $request->query('month');
            $year = $request->query('year');

            // Apply search term filter if 'q' is provided
            if ($q) {
                $query->where(function ($query) use ($q) {
                    $query->where('invoice_number', 'like', '%' . $q . '%')
                        ->orWhereHas('proformaInvoice.purchaseOrder.quotation.customer', function ($qry) use ($q) {
                            $qry->where('company_name', 'like', '%' . $q . '%');
                        });
                });
            }

            // Apply month and year filter if both are provided
            if ($month && $year) {
                $monthNumber = date('m', strtotime($month));
                $startDate = "{$year}-{$monthNumber}-01";
                $endDate = date("Y-m-t", strtotime($startDate));

                $query->whereBetween('invoice_date', [$startDate, $endDate]);
            }

            // Paginate the results
            $invoices = $query->orderBy('invoice_date', 'desc')
                ->paginate(20)->through(function ($invoice) {
                    $proformaInvoice = $invoice->proformaInvoice;
                    $purchaseOrder = $proformaInvoice->purchaseOrder;
                    $quotation = $purchaseOrder->quotation;
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

                    return [
                        'id' => (string) $invoice->id,
                        'invoice' => [
                            'no' => $invoice->invoice_number,
                            'date' => $invoice->invoice_date,
                            'term_of_pay' => $invoice->term_of_pay ?? ''
                        ],
                        'proformaInvoice' => [
                            'no' => $proformaInvoice->pi_number ?? '',
                            'date' => $proformaInvoice->proforma_invoice_date ?? ''
                        ],
                        'purchaseOrder' => [
                            'no' => $purchaseOrder->purchase_order_number ?? '',
                            'date' => $purchaseOrder->purchase_order_date ?? ''
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
                            'advancePayment' => $proformaInvoice->advance_payment ?? 0,
                            'total' => $quotation->total ?? 0,
                            'vat' => $quotation->vat ?? 0,
                            'totalAmount' => $quotation->total + ($quotation->total * $quotation->vat / 100)
                        ],
                        'notes' => $quotation->note ?? '',
                        'downPayment' => $proformaInvoice->advance_payment ?? 0,
                        'spareparts' => $spareParts,
                        'employee' => $invoice->employee->name ?? 'Unknown'
                    ];
                });

            return response()->json([
                'message' => 'List of invoices retrieved successfully',
                'data' => $invoices,
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    protected function getAccessedInvoice($request)
    {
        try {
            $user = $request->user();
            $userId = $user->id;
            $role = $user->role;

            $query = Invoice::query();

            // Only allow invoices for authorized users
            if ($role == 'Marketing') {
                $query->where('employee_id', $userId);
            }

            return $query;
        } catch (\Throwable $th) {
            // Return empty query builder
            return Invoice::whereNull('id');
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
