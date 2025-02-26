<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class QuotationController extends Controller
{
    public function index() {
        return Quotation::with('customer', 'employee')->get();
    }

    public function show($id) {
        return Quotation::with('customer', 'employee')->find($id);
    }

    public function store(Request $request) {
        return Quotation::create($request->all());
    }

    public function update(Request $request, $id) {
        $quotation = Quotation::find($id);
        $quotation->update($request->all());
        return $quotation;
    }

    public function destroy($id) {
        return Quotation::destroy($id);
    }
    // Aditional function
    public function review($id, $reviewState) {
        try {
            // Validate if the provided status exists in the enum
            if (!$reviewState) {
                return response()->json(['error' => 'Invalid review status'], 400);
            }

            // Find the quotation
            $quotation = Quotation::find($id);
            if (!$quotation) {
                return response()->json(['error' => 'Quotation not found'], 404);
            }
            // Update the review status
            $quotation->status = $reviewState;
            $quotation->save();

            return response()->json([
                'message' => 'Quotation status updated successfully',
                'quotation' => $quotation
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to update quotation: ' . $e->getMessage()], 500);
        }
    }

    public function getDetail($id)
    {
        $quotation = Quotation::with(['customer', 'detailQuotations.goods'])->find($id);

        if (!$quotation) {
            return response()->json(['error' => 'Quotation not found'], 404);
        }

        $customer = $quotation->customer;
        $spareParts = $quotation->detailQuotations->map(function ($detail) {
            return [
                'partName' => $detail->goods->name ?? '',
                'partNumber' => $detail->goods->no_sparepart ?? '',
                'quantity' => $detail->quantity,
                'unitPrice' => $detail->goods->unit_price_sell ?? 0,
                'totalPrice' => $detail->quantity * ($detail->goods->unit_price_sell ?? 0),
                'stock' => 'INDENT' // Assuming stock information
            ];
        });

        $response = [
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
            'project' => [
                'noQuotation' => $quotation->no,
                'type' => $quotation->type
            ],
            'price' => [
                'subtotal' => $quotation->subtotal,
                'ppn' => $quotation->vat,
                'grandTotal' => $quotation->total
            ],
            'status' => $quotation->status,
            'notes' => $quotation->note,
            'spareparts' => $spareParts
        ];

        return response()->json($response);
    }

    // Function to get a list of all quotations
    public function getAll()
    {
        $quotations = Quotation::with('customer')->get()->map(function ($quotation) {
            return [
                'id' => (string) $quotation->id,
                'customer' => $quotation->customer->company_name ?? '',
                'date' => $quotation->date,
                'type' => $quotation->type,
                'status' => $quotation->status
            ];
        });

        return response()->json($quotations);
    }

    public function moveUp($id) {
        // Start a database transaction
        DB::beginTransaction();

        try {
            // Find the quotation
            $quotation = Quotation::find($id);

            if (!$quotation) {
                return response()->json(['error' => 'Quotation not found'], 404);
            }

            // Check if the quotation already has a purchase order
            if ($quotation->purchaseOrder) {
                return response()->json(['error' => 'Quotation already has a purchase order'], 400);
            }

            // Create a new purchase order
            $purchaseOrder = PurchaseOrder::create([
                'id_quotation' => $quotation->id,
                'po_number' => 'PO-' . now()->format('YmdHis'), // Generate a unique PO number
                'po_date' => now(),
                'employee_id' => $quotation->employee_id,
            ]);

            // Commit the transaction
            DB::commit();

            return response()->json([
                'message' => 'Quotation promoted to purchase order successfully',
                'purchase_order' => $purchaseOrder,
            ]);

        } catch (\Exception $e) {
            // Rollback the transaction on error
            DB::rollBack();
            return response()->json(['error' => 'Failed to promote quotation: ' . $e->getMessage()], 500);
        }
    }
}
