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
    public function index()
    {
        try {
            $proformaInvoices = ProformaInvoice::with('purchaseOrder', 'employee')->get();
            return response()->json([
                'message' => 'Proforma invoices retrieved successfully',
                'data' => $proformaInvoices
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function show($id)
    {
        try {
            $proformaInvoice = ProformaInvoice::with('purchaseOrder', 'employee')->find($id);

            if (!$proformaInvoice) {
                return $this->handleNotFound('Proforma invoice not found');
            }

            return response()->json([
                'message' => 'Proforma invoice retrieved successfully',
                'data' => $proformaInvoice
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function store(Request $request)
    {
        try {
            $proformaInvoice = ProformaInvoice::create($request->all());
            return response()->json([
                'message' => 'Proforma invoice created successfully',
                'data' => $proformaInvoice
            ], Response::HTTP_CREATED);
        } catch (\Throwable $th) {
            return $this->handleError($th, 'Proforma invoice creation failed');
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $proformaInvoice = ProformaInvoice::find($id);

            if (!$proformaInvoice) {
                return $this->handleNotFound('Proforma invoice not found');
            }

            $proformaInvoice->update($request->all());
            return response()->json([
                'message' => 'Proforma invoice updated successfully',
                'data' => $proformaInvoice
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th, 'Proforma invoice update failed');
        }
    }

    public function destroy($id)
    {
        try {
            $proformaInvoice = ProformaInvoice::find($id);

            if (!$proformaInvoice) {
                return $this->handleNotFound('Proforma invoice not found');
            }

            $proformaInvoice->delete();
            return response()->json([
                'message' => 'Proforma invoice deleted successfully',
                'data' => null
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th, 'Proforma invoice deletion failed');
        }
    }

    public function getAll()
    {
        try {
            $proformaInvoices = ProformaInvoice::with('purchaseOrder', 'employee')->get();
            $formattedInvoices = $proformaInvoices->map(function ($invoice) {
                return [
                    'id' => (string) $invoice->id,
                    'customer' => $invoice->purchaseOrder ? $invoice->purchaseOrder->customer_name : 'Unknown',
                    'date' => $invoice->pi_date ? date('d M Y', strtotime($invoice->pi_date)) : '',
                    'type' => 'Spareparts'
                ];
            });

            return response()->json([
                'message' => 'List of all proforma invoices retrieved successfully',
                'data' => $formattedInvoices
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function getDetail($id)
    {
        try {
            $proformaInvoice = ProformaInvoice::with([
                'purchaseOrder.quotation.customer',
                'purchaseOrder.quotation.detailQuotations.spareparts.detailBuys'
            ])->find($id);

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
                    'totalAmount' => $quotation->total + ($quotation->total * $quotation->vat / 100), // Correct total calculation
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

    public function moveUp($id)
    {
        DB::beginTransaction();

        try {
            $proformaInvoice = ProformaInvoice::find($id);

            if (!$proformaInvoice) {
                return $this->handleNotFound('Proforma invoice not found');
            }

            if ($proformaInvoice->invoices) {
                return response()->json([
                    'message' => 'Proforma invoice already has an invoice'
                ], Response::HTTP_BAD_REQUEST);
            }

            $invoice = Invoice::create([
                'id_pi' => $proformaInvoice->id,
                'invoice_number' => 'INVOICE-' . now()->format('YmdHis'),
                'invoice_date' => now(),
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
