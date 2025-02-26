<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\Request;
use App\Models\ProformaInvoice;
use Illuminate\Support\Facades\DB;
class ProformaInvoiceController extends Controller
{
    public function index() {
        return ProformaInvoice::with('purchaseOrder', 'employee')->get();
    }

    public function show($id) {
        return ProformaInvoice::with('purchaseOrder', 'employee')->find($id);
    }

    public function store(Request $request) {
        return ProformaInvoice::create($request->all());
    }

    public function update(Request $request, $id) {
        $pi = ProformaInvoice::find($id);
        $pi->update($request->all());
        return $pi;
    }

    public function destroy($id) {
        return ProformaInvoice::destroy($id);
    }
    // Additional function
    public function getAll()
    {
        $proformaInvoices = ProformaInvoice::with('purchaseOrder', 'employee')->get();
        $formattedInvoices = $proformaInvoices->map(function ($invoice) {
            return [
                'id' => (string) $invoice->id,
                'customer' => $invoice->purchaseOrder ? $invoice->purchaseOrder->customer_name : 'Unknown',
                'date' => $invoice->pi_date ? date('d M Y', strtotime($invoice->pi_date)) : '',
                'type' => 'Goods' // Assuming all invoices are goods-related
            ];
        });

        return response()->json($formattedInvoices);
    }

    // Function to get detail of proforma invoice
    public function getDetail($id)
    {
        $proformaInvoice = ProformaInvoice::with([
            'purchaseOrder.quotation.customer',
            'purchaseOrder.quotation.detailQuotations.goods.detailBuys'
        ])->find($id);

        if (!$proformaInvoice) {
            return response()->json(['error' => 'Proforma Invoice not found'], 404);
        }

        $purchaseOrder = $proformaInvoice->purchaseOrder;
        $quotation = $purchaseOrder->quotation;
        $customer = $quotation->customer;
        $detailQuotations = $quotation->detailQuotations;

        $spareparts = collect();
        foreach ($detailQuotations as $detailQuotation) {
            $good = $detailQuotation->goods;
            foreach ($good->detailBuys as $detailBuy) {
                $spareparts->push([
                    'partName' => $good->name ?? '',
                    'partNumber' => $good->no_sparepart ?? '',
                    'quantity' => $detailBuy->quantity ?? 0,
                    'unit' => 'pcs',
                    'unitPrice' => $good->unit_price_sell ?? 0,
                    'amount' => ($detailBuy->quantity ?? 0) * ($good->unit_price_sell ?? 0),
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

        return response()->json($response);
    }

    public function moveUp($id) {
        // Start a database transaction
        DB::beginTransaction();

        try {
            // Find the quotation
            $proformaInvoice = $this->show($id);

            if (!$proformaInvoice) {
                return response()->json(['error' => 'Proforma Invoice not found'], 404);
            }

            // Check if the quotation already has a purchase order
            if ($proformaInvoice->invoices) {
                return response()->json(['error' => 'Proforma Invoice already has a purchase order'], 400);
            }

            // Create a new purchase order
            $invoice = Invoice::create([
                'id_pi' => $proformaInvoice->id,
                'invoice_number' => 'INVOICE-' . now()->format('YmdHis'), // Generate a unique PO number
                'invoice_date' => now(),
                'employee_id' => $proformaInvoice->employee_id,
            ]);

            // Commit the transaction
            DB::commit();

            return response()->json([
                'message' => 'Proforma invoice promoted to Invoice successfully',
                'Invoice ' => $invoice,
            ]);

        } catch (\Exception $e) {
            // Rollback the transaction on error
            DB::rollBack();
            return response()->json(['error' => 'Failed to promote quotation: ' . $e->getMessage()], 500);
        }
    }
}
