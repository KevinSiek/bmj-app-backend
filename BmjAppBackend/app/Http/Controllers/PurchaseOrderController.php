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
    const PREPARE = "Prepare";
    const READY = "Ready";
    const RELEASE = "Release";
    const FINISHED = "Finished";
    const RETURNED = "Returned";
    const PAID = "Paid";

    public function get(Request $request, $id)
    {
        try {
            $purchaseOrder = $this->getAccessedPurchaseOrder($request)
                ->with(['quotation.customer', 'quotation.detailQuotations.sparepart', 'proformaInvoice', 'employee'])
                ->findOrFail($id);

            $quotation = $purchaseOrder->quotation;
            $customer = $quotation ? $quotation->customer : null;
            $proformaInvoice = $purchaseOrder->proformaInvoice->first();

            $spareParts = $quotation && $quotation->detailQuotations ? $quotation->detailQuotations->map(function ($detail) {
                $sparepart = $detail->sparepart;
                return [
                    'sparepart_id' => $sparepart ? $sparepart->id : '',
                    'sparepart_name' => $sparepart ? $sparepart->sparepart_name : '',
                    'sparepart_number' => $sparepart ? $sparepart->sparepart_number : '',
                    'quantity' => $detail->quantity ?? 0,
                    'unit_price_sell' => $detail->unit_price ?? 0,
                    'total_price' => ($detail->quantity * ($detail->unit_price ?? 0)),
                    'stock' => $detail->is_indent ? 'indent' : 'available'
                ];
            })->toArray() : [];

            $formattedPurchaseOrder = [
                'id' => (string) ($purchaseOrder->id ?? ''),
                'purchase_order' => [
                    'purchase_order_number' => $purchaseOrder->purchase_order_number ?? '',
                    'purchase_order_date' => $purchaseOrder->purchase_order_date ?? '',
                    'type' => $quotation ? $quotation->type : ''
                ],
                'proforma_invoice' => [
                    'proforma_invoice_number' => $proformaInvoice ? $proformaInvoice->proforma_invoice_number : '',
                    'proforma_invoice_date' => $proformaInvoice ? $proformaInvoice->proforma_invoice_date : ''
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
                    'down_payment' => $proformaInvoice ? $proformaInvoice->down_payment : 0,
                    'total' => $proformaInvoice ? $proformaInvoice->grand_total : 0,
                    'ppn' => $quotation ? $quotation->ppn : 0,
                    'total_amount' => $proformaInvoice ? $proformaInvoice->total_amount : 0
                ],
                'notes' => $purchaseOrder->notes ?? '',
                'current_status' => $purchaseOrder->current_status ?? '',
                'status' => json_decode($quotation->status, true) ?? [], // Added status field
                'down_payment' => $proformaInvoice ? $proformaInvoice->down_payment : 0,
                'quotationNumber' => $quotation ? $quotation->quotation_number : '',
                'spareparts' => $spareParts
            ];

            return response()->json([
                'message' => 'Purchase order retrieved successfully',
                'data' => $formattedPurchaseOrder,
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function getAll(Request $request)
    {
        try {
            $query = $this->getAccessedPurchaseOrder($request)
                ->with(['quotation.customer', 'quotation.detailQuotations.sparepart', 'proformaInvoice', 'employee']);

            // Get query parameters
            $q = $request->query('search');
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
                                ->orWhere('current_status', 'like', '%' . $q . '%');
                        })
                        ->orWhereHas('quotation.customer', function ($qry) use ($q) {
                            $qry->where('company_name', 'like', '%' . $q . '%');
                        });
                });
            }

            // Apply year and month filter
            if ($year) {
                $query->whereYear('purchase_order_date', $year);
                if ($month) {
                    $monthNumber = date('m', strtotime($month));
                    $query->whereMonth('purchase_order_date', $monthNumber);
                }
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
                            'sparepart_id' => $sparepart ? $sparepart->id : '',
                            'sparepart_name' => $sparepart ? $sparepart->sparepart_name : '',
                            'sparepart_number' => $sparepart ? $sparepart->sparepart_number : '',
                            'quantity' => $detail->quantity ?? 0,
                            'unit_price_sell' => $detail->unit_price ?? 0,
                            'total_price' => ($detail->quantity * ($detail->unit_price ?? 0)),
                            'stock' => $detail->is_indent ? 'indent' : 'available'
                        ];
                    })->toArray() : [];

                    return [
                        'id' => (string) ($po->id ?? ''),
                        'purchase_order' => [
                            'purchase_order_number' => $po->purchase_order_number ?? '',
                            'purchase_order_date' => $po->purchase_order_date ?? '',
                            'type' => $quotation ? $quotation->type : ''
                        ],
                        'proforma_invoice' => [
                            'proforma_invoice_number' => $proformaInvoice ? $proformaInvoice->proforma_invoice_number : '',
                            'proforma_invoice_date' => $proformaInvoice ? $proformaInvoice->proforma_invoice_date : ''
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
                            'down_payment' => $proformaInvoice ? $proformaInvoice->down_payment : 0,
                            'total' => $proformaInvoice ? $proformaInvoice->grand_total : 0,
                            'ppn' => $quotation ? $quotation->ppn : 0,
                            'total_amount' => $proformaInvoice ? $proformaInvoice->total_amount : 0
                        ],
                        'notes' => $po->notes ?? '',
                        'current_status' => $po->current_status ?? '',
                        'status' => json_decode($quotation->status, true) ?? [], // Added status field
                        'down_payment' => $proformaInvoice ? $proformaInvoice->down_payment : 0,
                        'quotationNumber' => $quotation ? $quotation->quotation_number : '',
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

    /**
     * Convert month number to Roman numeral
     *
     * @param int $month
     * @return string
     */
    protected function getRomanMonth($month)
    {
        $romanNumerals = [
            1 => 'I',
            2 => 'II',
            3 => 'III',
            4 => 'IV',
            5 => 'V',
            6 => 'VI',
            7 => 'VII',
            8 => 'VIII',
            9 => 'IX',
            10 => 'X',
            11 => 'XI',
            12 => 'XII'
        ];
        return $romanNumerals[$month] ?? 'I';
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

            // Generate proforma invoice number from purchase order number
            try {
                // Expected purchase_order_number format: PO-IN/033/V/24
                $parts = explode('/', $purchaseOrder->purchase_order_number);
                $piNumber = $parts[1]; // e.g., 033
                $romanMonth = $parts[2]; // e.g., V
                $year = $parts[3]; // e.g., 24
                $proformaInvoiceNumber = "PI-IN/{$piNumber}/{$romanMonth}/{$year}";
            } catch (\Throwable $th) {
                // Fallback to timestamp-based PI number with current month and year
                $currentMonth = now()->month; // e.g., 5 for May
                $romanMonth = $this->getRomanMonth($currentMonth); // e.g., V
                $year = now()->format('y'); // e.g., 25 for 2025
                $timestamp = now()->format('YmdHis'); // Unique identifier
                $proformaInvoiceNumber = "PI-IN/{$timestamp}/{$romanMonth}/{$year}";
            }

            $proformaInvoice = ProformaInvoice::create([
                'purchase_order_id' => $purchaseOrder->id,
                'proforma_invoice_number' => $proformaInvoiceNumber,
                'proforma_invoice_date' => now(),
                'employee_id' => $purchaseOrder->employee_id,
            ]);

            $quotation = $purchaseOrder->quotation;
            $quotation->update([
                'current_status' => 'PI'
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
