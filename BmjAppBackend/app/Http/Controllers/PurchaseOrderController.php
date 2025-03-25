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
                ->with(['quotation.customer', 'quotation']);

            // Get query parameters
            $q = $request->query('q');
            $month = $request->query('month');
            $year = $request->query('year');

            // Apply search term filter if 'q' is provided
            if ($q) {
                $query->where(function($query) use ($q) {
                    $query->where('po_number', 'like', '%' . $q . '%')
                        ->orWhereHas('quotation', function($qry) use ($q) {
                            $qry->where('no', 'like', '%' . $q . '%')
                                ->orWhere('project', 'like', '%' . $q . '%')
                                ->orWhere('type', 'like', '%' . $q . '%')
                                ->orWhere('status', 'like', '%' . $q . '%');
                        })
                        ->orWhereHas('quotation.customer', function($qry) use ($q) {
                            $qry->where('company_name', 'like', '%' . $q . '%');
                        });
                });
            }

            // Apply month and year filter if both are provided
            if ($month && $year) {
                $monthNumber = date('m', strtotime($month));
                $startDate = "{$year}-{$monthNumber}-01";
                $endDate = date("Y-m-t", strtotime($startDate));

                $query->whereBetween('po_date', [$startDate, $endDate]);
            }

            // Paginate the results
            $purchaseOrders = $query->orderBy('po_date', 'desc')
                ->paginate(20);

            // Transform the results
            $transformed = $purchaseOrders->map(function ($po) {
                return [
                    'id' => (string) $po->id,
                    'po_number' => $po->po_number,
                    'customer' => $po->quotation->customer->company_name ?? 'Unknown',
                    'date' => $po->po_date,
                    'type' => $po->quotation->type ?? 'Unknown',
                    'status' => $po->quotation->status ?? 'Unknown',
                ];
            });

            return response()->json([
                'message' => 'List of purchase orders retrieved successfully',
                'data' => $transformed,
                'meta' => [
                    'current_page' => $purchaseOrders->currentPage(),
                    'per_page' => $purchaseOrders->perPage(),
                    'total' => $purchaseOrders->total(),
                    'last_page' => $purchaseOrders->lastPage(),
                ]
            ], Response::HTTP_OK);

        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function getDetail(Request $request, $id)
    {
        try {
            $purchaseOrder = $this->getAccessedPurchaseOrder($request)
                ->with(['quotation.customer', 'employee'])
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

    public function moveToPi(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $purchaseOrder = $this->getAccessedPurchaseOrder($request)->find($id);

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
                'pi_number' => 'PI-' . now()->format('YmdHis'),
                'pi_date' => now(),
                'employee_id' => $request->user()->id,
            ]);

            $quotation = $purchaseOrder->quotation;
            $quotation->update([
                'status'=>'PI'
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
