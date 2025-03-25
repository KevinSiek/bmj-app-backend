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
                ->with(['purchaseOrder.quotation.customer', 'employee']);

            // Get query parameters
            $q = $request->query('q');
            $month = $request->query('month');
            $year = $request->query('year');

            // Apply search term filter if 'q' is provided
            if ($q) {
                $query->where(function($query) use ($q) {
                    $query->where('pi_number', 'like', '%' . $q . '%')
                        ->orWhereHas('purchaseOrder.quotation.customer', function($qry) use ($q) {
                            $qry->where('company_name', 'like', '%' . $q . '%');
                        });
                });
            }

            // Apply month and year filter if both are provided
            if ($month && $year) {
                $monthNumber = date('m', strtotime($month));
                $startDate = "{$year}-{$monthNumber}-01";
                $endDate = date("Y-m-t", strtotime($startDate));

                $query->whereBetween('pi_date', [$startDate, $endDate]);
            }

            // Paginate the results
            $proformaInvoices = $query->orderBy('pi_date', 'desc')
                ->paginate(20);

            // Transform the results
            $transformed = $proformaInvoices->map(function ($pi) {
                return [
                    'id' => (string) $pi->id,
                    'pi_number' => $pi->pi_number,
                    'customer' => $pi->purchaseOrder->quotation->customer->company_name ?? 'Unknown',
                    'date' => $pi->pi_date,
                    'type' => $pi->purchaseOrder->quotation->type ?? 'Unknown',
                ];
            });

            return response()->json([
                'message' => 'List of proforma invoices retrieved successfully',
                'data' => $transformed,
                'meta' => [
                    'current_page' => $proformaInvoices->currentPage(),
                    'per_page' => $proformaInvoices->perPage(),
                    'total' => $proformaInvoices->total(),
                    'last_page' => $proformaInvoices->lastPage(),
                ]
            ], Response::HTTP_OK);

        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function getDetail(Request $request, $id)
    {
        try {
            $proformaInvoice = $this->getAccessedProformaInvoice($request)
                ->with([
                    'purchaseOrder.quotation.customer',
                    'employee'
                ])
                ->find($id);

            if (!$proformaInvoice) {
                return $this->handleNotFound('Proforma invoice not found');
            }

            $purchaseOrder = $proformaInvoice->purchaseOrder;
            $quotation = $purchaseOrder->quotation;
            $customer = $quotation->customer;
            $detailQuotations = $quotation->detailQuotations;

            $spareparts = collect();
            foreach ($detailQuotations as $detailQuotation) {
                $sparepart = $detailQuotation->spareparts;
                foreach ($sparepart->detailBuys as $detailBuy) {
                    $spareparts->push([
                        'partName' => $sparepart->name ?? '',
                        'partNumber' => $sparepart->no_sparepart ?? '',
                        'quantity' => $detailBuy->quantity ?? 0,
                        'unit' => 'pcs',
                        'unitPrice' => $sparepart->unit_price_sell ?? 0,
                        'amount' => ($detailBuy->quantity ?? 0) * ($sparepart->unit_price_sell ?? 0),
                    ]);
                }
            }

            $response = [
                'project' => [
                    'noProformaInvoice' => $proformaInvoice->pi_number,
                    'type' => $quotation->type ?? '',
                ],
                'customer' => [
                    'companyName' => $customer->company_name ?? '',
                    'address' => $customer->address ?? '',
                    'city' => $customer->city ?? '',
                    'province' => $customer->province ?? '',
                    'office' => $customer->office ?? '',
                    'urban' => $customer->urban_area ?? '',
                    'subdistrict' => $customer->subdistrict ?? '',
                    'postalCode' => $customer->postal_code ?? '',
                ],
                'price' => [
                    'amount' => $quotation->amount ?? 0,
                    'discount' => $quotation->discount ?? 0,
                    'subtotal' => $quotation->subtotal ?? 0,
                    'advancePayment' => $proformaInvoice->advance_payment ?? 0,
                    'total' => $quotation->total ?? 0,
                    'vat' => $quotation->vat ?? 0,
                    'totalAmount' => $quotation->total + ($quotation->total * $quotation->vat / 100),
                ],
                'downPayment' => $proformaInvoice->advance_payment ?? 0,
                'notes' => $quotation->note ?? '',
                'spareparts' => $spareparts,
            ];

            return response()->json([
                'message' => 'Proforma invoice details retrieved successfully',
                'data' => $response
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
                'id_pi' => $proformaInvoice->id,
                'invoice_number' => 'INVOICE-' . now()->format('YmdHis'),
                'invoice_date' => now(),
                'employee_id' => $request->user()->id,
            ]);

            $quotation = $proformaInvoice->purchaseOrder->quotation;
            $quotation->update([
                'status'=>'INVOICE'
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
