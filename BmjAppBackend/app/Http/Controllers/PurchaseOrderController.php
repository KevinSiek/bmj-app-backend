<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ProformaInvoice;
use Illuminate\Http\Request;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;

class PurchaseOrderController extends Controller
{
    public function index() {
        return PurchaseOrder::with('quotation', 'employee')->get();
    }

    public function show($id) {
        return PurchaseOrder::with('quotation', 'employee')->find($id);
    }

    public function store(Request $request) {
        return PurchaseOrder::create($request->all());
    }

    public function update(Request $request, $id) {
        $po = PurchaseOrder::find($id);
        $po->update($request->all());
        return $po;
    }

    public function destroy($id) {
        return PurchaseOrder::destroy($id);
    }
    // Aditional function
    public function getAll()
    {

        $purchaseOrders = PurchaseOrder::with('quotation.customer')->get()->map(function ($po) {
            return [
                'id' => (string) $po->id,
                'customer' => $po->quotation->customer->company_name ?? 'Unknown',
                'date' => $po->po_date,
                'type' => $po->quotation->type ?? 'Unknown',
                'status' => $po->quotation->status // Replace with actual status if available
            ];
        });

        return response()->json($purchaseOrders);
    }

    public function getDetail($id)
    {
        $purchaseOrder = PurchaseOrder::with(['quotation.customer', 'quotation.detailQuotations.goods', 'proformaInvoices', 'employee'])
            ->find($id);

        if (!$purchaseOrder) {
            return response()->json(['error' => 'Purchase Order not found'], 404);
        }

        $quotation = $purchaseOrder->quotation;
        $customer = $quotation->customer ?? null;
        $proformaInvoice = $purchaseOrder->proformaInvoices->first();

        $spareParts = $quotation->detailQuotations->map(function ($detail) {
            return [
                'partName' => $detail->goods->name ?? '',
                'partNumber' => $detail->goods->no_sparepart ?? '',
                'quantity' => $detail->quantity,
                'unit' => 'pcs',
                'unitPrice' => $detail->goods->unit_price_sell ?? 0,
                'amount' => ($detail->quantity * ($detail->goods->unit_price_sell ?? 0))
            ];
        });

        $response = [
            'purchaseOrder' => [
                'no' => $purchaseOrder->po_number,
                'date' => $purchaseOrder->po_date,
                'type' => $quotation->type ?? ''
            ],
            'proformaInvoice' => [
                'no' => $proformaInvoice->pi_number ?? '',
                'date' => $proformaInvoice->pi_date ?? ''
            ],
            'customer' => [
                'companyName' => $customer->company_name ?? '',
                'address' => $customer->address ?? '',
                'city' => $customer->city ?? '',
                'province' => $customer->province ?? '',
                'office' => $customer->office ?? '',
                'urban' => $customer->urban_area ?? '',
                'subdistrict' => $customer->subdistrict ?? '',
                'postalCode' => $customer->postal_code ?? ''
            ],
            'price' => [
                'amount' => $quotation->amount ?? 0,
                'discount' => $quotation->discount ?? 0,
                'subtotal' => $quotation->subtotal ?? 0,
                'advancePayment' => $proformaInvoice->advance_payment ?? 0,
                'total' => $proformaInvoice->total ?? 0,
                'vat' => $quotation->vat ?? 0,
                'totalAmount' => $proformaInvoice->total_amount ?? 0
            ],
            'notes' => $quotation->note ?? '',
            'downPayment' => $proformaInvoice->advance_payment ?? 0,
            'spareparts' => $spareParts
        ];

        return response()->json($response);
    }

    public function moveUp($id, $employeId) {
        // TODO: Should we get $employeId that moveUp this PO from session login at server side ?
        // Reason: Handle someone that hardcode the API

        // Start a database transaction
        DB::beginTransaction();

        try {
            // Find the quotation
            $purchoseOrder = $this->show($id);

            if (!$purchoseOrder) {
                return response()->json(['error' => 'Purchose Order not found'], 404);
            }

            // Check if the quotation already has a purchase order
            if ($purchoseOrder->purchaseOrder) {
                return response()->json(['error' => 'Purchose Order already has a purchase order'], 400);
            }

            // Create a new purchase order
            $proformaInvoice = ProformaInvoice::create([
                'id_po' => $purchoseOrder->id,
                'pi_number' => 'PI-' . now()->format('YmdHis'), // Generate a unique PO number
                'pi_date' => now(),
                'employee_id' => $employeId,
            ]);

            // Commit the transaction
            DB::commit();

            return response()->json([
                'message' => 'Purchose Order promoted to Proforma Invoice successfully',
                'proforma Invoice' => $proformaInvoice,
            ]);

        } catch (\Exception $e) {
            // Rollback the transaction on error
            DB::rollBack();
            return response()->json(['error' => 'Failed to promote quotation: ' . $e->getMessage()], 500);
        }
    }
}
