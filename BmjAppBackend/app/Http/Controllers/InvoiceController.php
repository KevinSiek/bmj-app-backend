<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InvoiceController extends Controller
{
    public function get(Request $request, $id)
    {
        try {
            $invoice = $this->getAccessedInvoice($request)
                ->with([
                    'proformaInvoice.purchaseOrder.quotation.customer',
                    'proformaInvoice.purchaseOrder.quotation.detailQuotations.sparepart',
                    'employee'
                ])
                ->findOrFail($id);

            $proformaInvoice = $invoice->proformaInvoice;
            $purchaseOrder = $proformaInvoice->purchaseOrder;
            $quotation = $purchaseOrder->quotation;
            $customer = $quotation->customer ?? null;

            $spareParts = [];
            $services = [];
            if ($quotation && $quotation->detailQuotations) {
                foreach ($quotation->detailQuotations as $detail) {
                    if ($detail->sparepart_id) {
                        $sparepart = $detail->sparepart;
                        $spareParts[] = [
                            'sparepart_id' => $sparepart->id ?? '',
                            'sparepart_name' => $sparepart->sparepart_name ?? '',
                            'sparepart_number' => $sparepart->part_number ?? '',
                            'quantity' => $detail->quantity ?? 0,
                            'unit_price_sell' => $detail->unit_price ?? 0,
                            'total_price' => ($detail->quantity * ($detail->unit_price ?? 0))
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

            $formattedInvoice = [
                'id' => (string) $invoice->id,
                'invoice' => [
                    'invoice_number' => $invoice->invoice_number,
                    'date' => $invoice->invoice_date,
                    'term_of_payment' => $invoice->term_of_payment ?? '',
                    'subtotal' => $quotation->subtotal ?? 0,
                    'grand_total' => $quotation->grand_total ?? 0,
                ],
                'purchase_order' => [
                    'purchase_order_number' => $purchaseOrder->purchase_order_number ?? '',
                    'purchase_order_date' => $purchaseOrder->purchase_order_date ?? '',
                    'purchase_order_type' => $quotation->type ?? '',
                    'payment_due' => $purchaseOrder->payment_due,
                    'discount' => $quotation ? $quotation->discount : ''
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
                'price' => [
                    'subtotal' => $quotation->subtotal ?? 0,
                    'ppn' => $quotation->ppn ?? 0,
                    'grand_total' => $quotation->grand_total ?? 0,
                ],
                'status' => $quotation->status ?? [],
                'quotation_number' => $quotation ? $quotation->quotation_number : '',
                'version' => $purchaseOrder->version,
                'notes' => $quotation->notes ?? '',
                'spareparts' => $spareParts,
                'services' => $services,
                'type' => $quotation->type,
            ];

            return response()->json([
                'message' => 'Invoice retrieved successfully',
                'data' => $formattedInvoice,
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function getAll(Request $request)
    {
        try {
            // Get all invoice numbers first to ensure we capture all versions
            $invoiceNumbers = $this->getAccessedInvoice($request)
                ->select('invoice_number');

            // Get query parameters
            $q = $request->query('search');
            $month = $request->query('month');
            $year = $request->query('year');

            // Apply search term filter if 'q' is provided
            if ($q) {
                $invoiceNumbers->where(function ($invoiceNumbers) use ($q) {
                    $invoiceNumbers->where('invoice_number', 'like', '%' . $q . '%')
                        ->orWhereHas('proformaInvoice.purchaseOrder.quotation.customer', function ($qry) use ($q) {
                            $qry->where('company_name', 'like', '%' . $q . '%');
                        });
                });
            }

            // Apply year and month filter
            if ($year) {
                $invoiceNumbers->whereYear('invoice_date', $year);
                if ($month) {
                    $monthNumber = date('m', strtotime($month));
                    $invoiceNumbers->whereMonth('invoice_date', $monthNumber);
                }
            }

            // Paginate the distinct quotation numbers
            $paginatedInvoiceNumbers = $invoiceNumbers->groupBy('invoice_number')->paginate(20);

            // Get all quotations for the paginated quotation numbers
            $query = $this->getAccessedInvoice($request)
                ->whereIn('invoice_number', $paginatedInvoiceNumbers->pluck('invoice_number'));

            // Return like API contract
            $invoiceOrders =  $query
                ->orderBy('invoice_date', 'DESC')
                ->orderBy('id', 'DESC')
                ->get();
            // Return like API contract
            $invoices = $invoiceOrders->map(function ($invoice) {
                $proformaInvoice = $invoice->proformaInvoice;
                $purchaseOrder = $proformaInvoice->purchaseOrder;
                $quotation = $purchaseOrder->quotation;
                $customer = $quotation->customer ?? null;

                $spareParts = [];
                $services = [];
                if ($quotation && $quotation->detailQuotations) {
                    foreach ($quotation->detailQuotations as $detail) {
                        if ($detail->sparepart_id) {
                            $sparepart = $detail->sparepart;
                            $spareParts[] = [
                                'sparepart_id' => $sparepart->id ?? '',
                                'sparepart_name' => $sparepart->sparepart_name ?? '',
                                'sparepart_number' => $sparepart->part_number ?? '',
                                'quantity' => $detail->quantity ?? 0,
                                'unit_price_sell' => $detail->unit_price ?? 0,
                                'total_price' => ($detail->quantity * ($detail->unit_price ?? 0))
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
                    'id' => (string) $invoice->id,
                    'invoice' => [
                        'invoice_number' => $invoice->invoice_number,
                        'date' => $invoice->invoice_date,
                        'term_of_payment' => $invoice->term_of_payment ?? '',
                        'subtotal' => $quotation->subtotal ?? 0,
                        'grand_total' => $quotation->grand_total ?? 0,
                    ],
                    'purchase_order' => [
                        'purchase_order_number' => $purchaseOrder->purchase_order_number ?? '',
                        'purchase_order_date' => $purchaseOrder->purchase_order_date ?? '',
                        'payment_due' => $purchaseOrder->payment_due,
                        'discount' => $quotation ? $quotation->discount : ''
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
                    'price' => [
                        'subtotal' => $quotation->subtotal ?? 0,
                        'ppn' => $quotation->ppn ?? 0,
                        'grand_total' => $quotation->grand_total ?? 0,
                    ],
                    'status' => $quotation->status ?? [],
                    'quotation_number' => $quotation ? $quotation->quotation_number : '',
                    'version' => $purchaseOrder->version,
                    'notes' => $quotation->notes ?? '',
                    'spareparts' => $spareParts,
                    'services' => $services,
                    'type' => $quotation->type,
                ];
            });

            return response()->json([
                'message' => 'List of invoices retrieved successfully',
                'data' => [
                    'data' => $invoices,
                    'from' => $paginatedInvoiceNumbers->firstItem(),
                    'to' => $paginatedInvoiceNumbers->lastItem(),
                    'total' => $paginatedInvoiceNumbers->total(),
                    'per_page' => $paginatedInvoiceNumbers->perPage(),
                    'current_page' => $paginatedInvoiceNumbers->currentPage(),
                    'last_page' => $paginatedInvoiceNumbers->lastPage(),
                ]
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
