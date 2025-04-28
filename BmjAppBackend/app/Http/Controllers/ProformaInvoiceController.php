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
    public function getAll(Request $request)
    {
        try {
            $query = $this->getAccessedProformaInvoice($request)
                ->with([
                    'purchaseOrder.quotation.customer',
                    'purchaseOrder.quotation.detailQuotations.sparepart.detailBuys',
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

            // Apply month and year filter if both are provided
            if ($month && $year) {
                $monthNumber = date('m', strtotime($month));
                $startDate = "{$year}-{$monthNumber}-01";
                $endDate = date("Y-m-t", strtotime($startDate));

                $query->whereBetween('proforma_invoice_date', [$startDate, $endDate]);
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
                    'purchaseOrder.quotation.detailQuotations.sparepart.detailBuys',
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
                    'total' => $quotation->grand_total ?? 0,
                    'ppn' => $quotation->ppn ?? 0,
                    'total_amount' => $quotation->total_amount,
                ],
                'down_payment' => $proformaInvoice->down_payment ?? 0,
                'notes' => $quotation->notes ?? '',
                'date' => $proformaInvoice->created_at,
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

            if ($proformaInvoice->invoices->isNotEmpty()) {
                return response()->json([
                    'message' => 'Proforma invoice already has an invoice'
                ], Response::HTTP_BAD_REQUEST);
            }

            $invoice = Invoice::create([
                'proforma_invoice_id' => $proformaInvoice->id,
                'invoice_number' => 'INVOICE-' . now()->format('YmdHis'),
                'invoice_date' => now(),
                'employee_id' => $proformaInvoice->employee_id,
            ]);

            $quotation = $proformaInvoice->purchaseOrder->quotation;
            $quotation->update([
                'status' => 'INVOICE'
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
