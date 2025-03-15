<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ProformaInvoice;
use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class PurchaseOrderController extends Controller
{
    public function index()
    {
        try {
            $purchaseOrders = PurchaseOrder::with('quotation', 'employee')->get();
            return response()->json([
                'message' => 'Purchase orders retrieved successfully',
                'data' => $purchaseOrders
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function show($id)
    {
        try {
            $purchaseOrder = PurchaseOrder::with('quotation', 'employee')->find($id);

            if (!$purchaseOrder) {
                return $this->handleNotFound('Purchase order not found');
            }

            return response()->json([
                'message' => 'Purchase order retrieved successfully',
                'data' => $purchaseOrder
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function store(Request $request)
    {
        try {
            $purchaseOrder = PurchaseOrder::create($request->all());
            return response()->json([
                'message' => 'Purchase order created successfully',
                'data' => $purchaseOrder
            ], Response::HTTP_CREATED);
        } catch (\Throwable $th) {
            return $this->handleError($th, 'Purchase order creation failed');
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $purchaseOrder = PurchaseOrder::find($id);

            if (!$purchaseOrder) {
                return $this->handleNotFound('Purchase order not found');
            }

            $purchaseOrder->update($request->all());
            return response()->json([
                'message' => 'Purchase order updated successfully',
                'data' => $purchaseOrder
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th, 'Purchase order update failed');
        }
    }

    public function destroy($id)
    {
        try {
            $purchaseOrder = PurchaseOrder::find($id);

            if (!$purchaseOrder) {
                return $this->handleNotFound('Purchase order not found');
            }

            $purchaseOrder->delete();
            return response()->json([
                'message' => 'Purchase order deleted successfully',
                'data' => null
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th, 'Purchase order deletion failed');
        }
    }

    public function getAll()
    {
        try {
            $purchaseOrders = PurchaseOrder::with('quotation.customer')->get()->map(function ($po) {
                return [
                    'id' => (string) $po->id,
                    'customer' => $po->quotation->customer->company_name ?? 'Unknown',
                    'date' => $po->po_date,
                    'type' => $po->quotation->type ?? 'Unknown',
                    'status' => $po->quotation->status // Replace with actual status if available
                ];
            });

            return response()->json([
                'message' => 'List of all purchase orders retrieved successfully',
                'data' => $purchaseOrders
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function getDetail($id)
    {
        try {
            $purchaseOrder = PurchaseOrder::with(['quotation.customer', 'quotation.detailQuotations.spareparts', 'proformaInvoices', 'employee'])
                ->find($id);

            if (!$purchaseOrder) {
                return $this->handleNotFound('Purchase order not found');
            }

            $quotation = $purchaseOrder->quotation;
            $customer = $quotation->customer ?? null;
            $proformaInvoice = $purchaseOrder->proformaInvoices->first();

            $spareParts = $quotation->detailQuotations->map(function ($detail) {
                return [
                    'partName' => $detail->spareparts->name ?? '',
                    'partNumber' => $detail->spareparts->no_sparepart ?? '',
                    'quantity' => $detail->quantity,
                    'unit' => 'pcs',
                    'unitPrice' => $detail->spareparts->unit_price_sell ?? 0,
                    'amount' => ($detail->quantity * ($detail->spareparts->unit_price_sell ?? 0))
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

            return response()->json([
                'message' => 'Purchase order details retrieved successfully',
                'data' => $response
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function moveUp($id, $employeeId)
    {
        DB::beginTransaction();

        try {
            $purchaseOrder = PurchaseOrder::find($id);

            if (!$purchaseOrder) {
                return $this->handleNotFound('Purchase order not found');
            }

            if ($purchaseOrder->proformaInvoices->isNotEmpty()) {
                return response()->json([
                    'message' => 'Purchase order already has a proforma invoice'
                ], Response::HTTP_BAD_REQUEST);
            }

            $proformaInvoice = ProformaInvoice::create([
                'id_po' => $purchaseOrder->id,
                'pi_number' => 'PI-' . now()->format('YmdHis'), // Generate a unique PI number
                'pi_date' => now(),
                'employee_id' => $employeeId,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Purchase order promoted to proforma invoice successfully',
                'data' => $proformaInvoice
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->handleError($th, 'Failed to promote purchase order');
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
