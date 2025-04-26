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
    public function getAll(Request $request)
    {
        try {
            $query = $this->getAccessedPurchaseOrder($request)
                ->with(['quotation.customer', 'quotation.detailQuotations.sparepart', 'proformaInvoice', 'employee']);

            // Get query parameters
            $q = $request->query('q');
            $month = $request->query('month');
            $year = $request->query('year');

            // Apply search term filter if 'q' is provided
            if ($q) {
                $query->where(function ($query) use ($q) {
                    $query->where('purchase_order_number', 'like', '%' . $q . '%')
                        ->orWhereHas('quotation', function ($qry) use ($q) {
                            $qry->where('quotation_number', 'like', '%' . $q . '%')
                                ->orWhere('project', 'like', '%' . $q . '%')
                                ->orWhere('type', 'like', '%' . $q . '%')
                                ->orWhere('status', 'like', '%' . $q . '%');
                        })
                        ->orWhereHas('quotation.customer', function ($qry) use ($q) {
                            $qry->where('company_name', 'like', '%' . $q . '%');
                        });
                });
            }

            // Apply month and year filter if both are provided
            if ($month && $year) {
                $monthNumber = date('m', strtotime($month));
                $startDate = "{$year}-{$monthNumber}-01";
                $endDate = date("Y-m-t", strtotime($startDate));

                $query->whereBetween('purchase_order_date', [$startDate, $endDate]);
            }

            // Paginate the results
            $purchaseOrders = $query->orderBy('purchase_order_date', 'desc')
                ->paginate(20)->through(function ($po) {
                    $quotation = $po->quotation;
                    $customer = $quotation ? $quotation->customer : null;
                    $proformaInvoice = $po->proformaInvoice->first();

                    $spareParts = $quotation && $quotation->detailQuotations ? $quotation->detailQuotations->map(function ($detail) {
                        $sparepart = $detail->sparepart;
                        return [
                            'sparepartName' => $sparepart ? $sparepart->sparepart_name : '',
                            'sparepartNumber' => $sparepart ? $sparepart->sparepart_number : '',
                            'quantity' => $detail->quantity ?? 0,
                            'unitPriceSell' => $detail->unit_price ?? 0,
                            'totalPrice' => ($detail->quantity * ($detail->unit_price ?? 0)),
                            'stock' => $detail->is_indent ? 'indent' : 'available'
                        ];
                    })->toArray() : [];

                    return [
                        'id' => (string) ($po->id ?? ''),
                        'purchaseOrder' => [
                            'purchaseOrderNumber' => $po->purchase_order_number ?? '',
                            'purchaseOrderDate' => $po->purchase_order_date ?? '',
                            'type' => $quotation ? $quotation->type : ''
                        ],
                        'proformaInvoice' => [
                            'proformaInvoiceNumber' => $proformaInvoice ? $proformaInvoice->proforma_invoice_number : '',
                            'proformaInvoiceDate' => $proformaInvoice ? $proformaInvoice->proforma_invoice_date : ''
                        ],
                        'customer' => [
                            'company_name' => $customer ? $customer->company_name : '',
                            'address' => $customer ? $customer->address : '',
                            'city' => $customer ? $customer->city : '',
                            'province' => $customer ? $customer->province : '',
                            'office' => $customer ? $customer->office : '',
                            'urban' => $customer ? $customer->urban : '',
                            'subdistrict' => $customer ? $customer->subdistrict : '',
                            'postal_code' => $customer ? $customer->postal_code : ''
                        ],
                        'price' => [
                            'amount' => $quotation ? $quotation->amount : 0,
                            'discount' => $quotation ? $quotation->discount : 0,
                            'subtotal' => $quotation ? $quotation->subtotal : 0,
                            'advancePayment' => $proformaInvoice ? $proformaInvoice->advance_payment : 0,
                            'total' => $proformaInvoice ? $proformaInvoice->grand_total : 0,
                            'ppn' => $quotation ? $quotation->ppn : 0,
                            'totalAmount' => $proformaInvoice ? $proformaInvoice->total_amount : 0
                        ],
                        'notes' => $po->notes ?? '',
                        'downPayment' => $proformaInvoice ? $proformaInvoice->advance_payment : 0,
                        'spareparts' => $spareParts
                    ];
                });

            return response()->json([
                'message' => 'List of purchase orders retrieved successfully',
                'data' => $purchaseOrders,
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function moveToPi(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $purchaseOrder = $this->getAccessedPurchaseOrder($request)->find($id);

            if (!$purchaseOrder) {
                return $this->handleNotFound('Purchase order not found');
            }

            if ($purchaseOrder->proformaInvoice->isNotEmpty()) {
                return response()->json([
                    'message' => 'Purchase order already has a proforma invoice'
                ], Response::HTTP_BAD_REQUEST);
            }

            $proformaInvoice = ProformaInvoice::create([
                'purchase_order_id' => $purchaseOrder->id,
                'proforma_invoice_number' => 'PI-' . now()->format('YmdHis'),
                'proforma_invoice_date' => now(),
                'employee_id' => $purchaseOrder->employee_id,
            ]);

            $quotation = $purchaseOrder->quotation;
            $quotation->update([
                'status' => 'PI'
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

    protected function getAccessedPurchaseOrder($request)
    {
        try {
            $user = $request->user();
            $userId = $user->id;
            $role = $user->role;

            $query = PurchaseOrder::query();

            // Only allow purchase orders for authorized users
            if ($role == 'Marketing') {
                $query->where('employee_id', $userId);
            }

            return $query;
        } catch (\Throwable $th) {
            // Return empty query builder
            return PurchaseOrder::whereNull('id');
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
