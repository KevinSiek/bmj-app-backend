<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use Illuminate\Http\Request;
use App\Models\BackOrder;
use App\Models\Buy;
use App\Models\DetailBuy;
use App\Models\DetailQuotation;
use App\Models\PurchaseOrder;
use App\Models\Sparepart;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BackOrderController extends Controller
{
    // Status constants
    const PROCESS = 'Process';
    const READY = 'Ready';

    const ALLOWED_PROCESS_ROLES = ['Director', 'Inventory'];

    protected $quotationController;
    public function __construct(QuotationController $quotationController)
    {
        $this->quotationController = $quotationController;
    }

    public function getAll(Request $request)
    {
        try {
            // Get query parameters
            $q = $request->query('search');
            $month = $request->query('month');
            $year = $request->query('year');

            // Initialize the query builder
            $backOrderQuery = $this->getAccessedBackOrder($request);

            // Filter BackOrders with non-zero number_back_order in detailBackOrders
            $backOrderQuery->whereHas('detailBackOrders', function ($query) {
                $query->where('number_back_order', '>', 0);
            });

            // Apply search term filter if 'q' is provided
            if ($q) {
                $backOrderQuery->where(function ($query) use ($q) {
                    $query->where('back_order_number', 'like', "%$q%")
                        ->orWhere('current_status', 'like', "%$q%")
                        ->orWhereHas('purchaseOrder', function ($qry) use ($q) {
                            $qry->where('purchase_order_number', 'like', "%$q%");
                        });
                });
            }

            // Apply date filter if 'month' and 'year' are provided
            if ($month && $year) {
                $monthNumber = date('m', strtotime($month));
                $startDate = "{$year}-{$monthNumber}-01";
                $endDate = date("Y-m-t", strtotime($startDate));

                $backOrderQuery->whereBetween('created_at', [$startDate, $endDate]);
            }

            // Paginate the results with all required relationships
            $backOrders = $backOrderQuery->with([
                'purchaseOrder.quotation',
                'purchaseOrder.quotation.customer',
                'detailBackOrders.sparepart',
            ])
                ->orderBy('created_at', 'desc')
                ->orderBy('id', 'DESC')
                ->paginate(20);

            // Transform the data to match the API contract
            $formattedBackOrders = $backOrders->getCollection()->map(function ($backOrder) {
                return $this->formatBackOrder($backOrder);
            });

            // Return paginated response with transformed data
            return response()->json([
                'message' => 'List of all back orders retrieved successfully',
                'data' => $backOrders->setCollection($formattedBackOrders),
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    /**
     * Get a single back order by ID.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function get(Request $request, $id)
    {
        try {
            // Initialize the query builder with access control
            $backOrderQuery = $this->getAccessedBackOrder($request);

            // Find the back order by ID with all required relationships
            $backOrder = $backOrderQuery->with([
                'purchaseOrder',
                'purchaseOrder.quotation',
                'purchaseOrder.quotation.customer',
                'detailBackOrders.sparepart',
            ])->findOrFail($id);

            // Format the back order to match the API contract
            $formattedBackOrder = $this->formatBackOrder($backOrder);

            return response()->json([
                'message' => 'Back order retrieved successfully',
                'data' => $formattedBackOrder
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    /**
     * Format a back order to match the API contract.
     *
     * @param BackOrder $backOrder
     * @return array
     */
    private function formatBackOrder(BackOrder $backOrder)
    {
        return [
            'id' => $backOrder->id,
            'back_order_number' => $backOrder->back_order_number,
            'current_status' => $backOrder->current_status,
            'purchase_order' => [
                'purchase_order_number' => $backOrder->purchaseOrder?->purchase_order_number,
                'purchase_order_date' => $backOrder->purchaseOrder?->purchase_order_date,
                'type' => $backOrder->purchaseOrder?->quotation?->type,
            ],
            'deliveryOrder' => [
                'no' => $backOrder->detailBackOrders->first()?->number_delivery_order ?? '',
                'date' => $backOrder->created_at->toDateString(), // Assuming delivery date is same as back order creation
                'ship_mode' => '', // Placeholder as no direct mapping exists
            ],
            'customer' => [
                'company_name' => $backOrder->purchaseOrder?->quotation?->customer?->company_name ?? '',
                'address' => $backOrder->purchaseOrder?->quotation?->customer?->address ?? '',
                'city' => $backOrder->purchaseOrder?->quotation?->customer?->city ?? '',
                'province' => $backOrder->purchaseOrder?->quotation?->customer?->province ?? '',
                'office' => $backOrder->purchaseOrder?->quotation?->customer?->office ?? '',
                'urban' => $backOrder->purchaseOrder?->quotation?->customer?->urban ?? '',
                'subdistrict' => $backOrder->purchaseOrder?->quotation?->customer?->subdistrict ?? '',
                'postal_code' => $backOrder->purchaseOrder?->quotation?->customer?->postal_code ?? '',
                'npwp' => $backOrder->purchaseOrder?->quotation?->customer?->npwp ?? '', // Assuming nullable
                'delivery' => $backOrder->purchaseOrder?->quotation?->customer?->delivery ?? '', // Assuming nullable
            ],
            'notes' => $backOrder->notes ?? '',
            'spareparts' => $backOrder->detailBackOrders->map(function ($detail) {
                $order = $detail->number_back_order + $detail->number_delivery_order;
                return [
                    'sparepart_name' => $detail->sparepart?->sparepart_name ?? '',
                    'sparepart_number' => $detail->sparepart?->sparepart_number ?? '',
                    'unit_price_sell' => $detail->sparepart?->unit_price_sell ?? 0, // Assuming in Sparepart
                    'total_price' => ($detail->quantity ?? 1) * ($detail->sparepart?->unit_price_sell ?? 0),
                    'total_unit' => $detail->sparepart?->total_unit ?? 0, // Assuming total unit in Sparepart
                    'order' => $order ?? 0,
                    'delivery_order' => $detail->number_delivery_order ?? '',
                    'back_order' => $detail->number_back_order ?? '',
                ];
            })->toArray(),
        ];
    }

    public function process(Request $request, $id)
    {
        // The entire process is a single atomic operation.
        DB::beginTransaction();

        try {
            // Check user role, only director and inventory that able process back order
            $user = $request->user();
            if (!in_array($user->role, self::ALLOWED_PROCESS_ROLES)) {
                return $this->handleForbidden('You are not authorized to process back orders');
            }

            // Get the back order and lock it to prevent concurrent processing.
            $backOrder = $this->getAccessedBackOrder($request)
                ->with([
                    'detailBackOrders', // Eager load details
                    'purchaseOrder.quotation' // Eager load PO and Quotation
                ])
                ->lockForUpdate() // Lock the back order row for this transaction
                ->find($id);

            if (!$backOrder) {
                DB::rollBack();
                return $this->handleNotFound('Back order not found');
            }

            // Check if back order is already processed to prevent re-processing.
            if ($backOrder->current_status === self::READY) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Back order already processed'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Create a new Buy record
            $totalAmount = 0;
            $buy = Buy::create([
                'buy_number' => 'BUY-' . Str::random(8),
                'total_amount' => 0, // Will be updated after calculating
                'review' => true, // Process means it already approved
                'current_status' => BuyController::DONE, // Process means it already done
                'notes' => 'Auto-generated from BackOrder #' . $backOrder->back_order_number,
                'back_order_id' => $backOrder->id,
            ]);


            // Process each detail back order
            $purchaseOrder = PurchaseOrder::lockForUpdate()->find($backOrder->purchase_order_id);
            $quotation = Quotation::lockForUpdate()->find($purchaseOrder->quotation_id);

            foreach ($backOrder->detailBackOrders as $detailBackOrder) {
                // Skip if no back order quantity
                if ($detailBackOrder->number_back_order <= 0) {
                    continue;
                }

                // Lock the sparepart to prevent race conditions on stock update
                $sparepart = Sparepart::lockForUpdate()->find($detailBackOrder->sparepart_id);
                if (!$sparepart) {
                    throw new \Exception("Sparepart with ID {$detailBackOrder->sparepart_id} not found during processing.");
                }

                // Find the DetailSparepart with the lowest unit_price for this sparepart and seller
                $cheapestDetailSparepart = $sparepart->detailSpareparts()
                    ->orderBy('unit_price', 'asc')
                    ->firstOrFail();

                // Create DetailBuy record
                $quantity = $detailBackOrder->number_back_order;
                $unitPrice = $cheapestDetailSparepart->unit_price;
                $subtotal = $quantity * $unitPrice;

                DetailBuy::create([
                    'buy_id' => $buy->id,
                    'sparepart_id' => $sparepart->id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                ]);

                $totalAmount += $subtotal;

                // Update sparepart stock safely within the lock
                $sparepart->total_unit += $quantity;
                $sparepart->save();

                // Update detail quotation stock state from is_indent true to false
                if ($quotation) {
                    $detailQuotation = DetailQuotation::where('quotation_id', $quotation->id)
                        ->where('sparepart_id', $detailBackOrder->sparepart_id)
                        ->lockForUpdate() // Lock for safe update
                        ->first();

                    if ($detailQuotation && $detailQuotation->is_indent) {
                        $detailQuotation->is_indent = false;
                        $detailQuotation->save();
                    }
                }
            }

            // Update Buy total_amount
            $buy->total_amount = $totalAmount;
            $buy->save();

            // Update back order status
            $backOrder->current_status = self::READY;
            $backOrder->save();

            // Update PO current status to PREPARE
            // It will become ready after user click "Sparepart ready" in PO
            $purchaseOrder->current_status = PurchaseOrderController::PREPARE;
            $purchaseOrder->save();

            // The logic from quotationController->changeStatusToInventory is now inlined
            // to avoid nested transactions and ensure atomicity.
            $currentQuotationStatus = $quotation->status ?? [];
            if (!is_array($currentQuotationStatus)) {
                $currentQuotationStatus = [];
            }

            $currentQuotationStatus[] = [
                'state' => QuotationController::Inventory,
                'employee' => $user->username,
                'timestamp' => now()->toIso8601String(),
            ];

            $quotation->status = $currentQuotationStatus;
            $quotation->current_status = QuotationController::Inventory;
            $quotation->save();

            DB::commit();

            return response()->json([
                'message' => 'Back order processed successfully',
                'data' => $backOrder->load('buy.detailBuys')
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->handleError($th, 'Failed to process back order');
        }
    }

    protected function getAccessedBackOrder($request)
    {
        try {
            $user = $request->user();
            $userId = $user->id;
            $role = $user->role;

            $query = BackOrder::query();

            // Only allow back orders for authorized users
            if ($role == 'Marketing') {
                $query->whereHas('purchaseOrder', function ($q) use ($userId) {
                    $q->where('employee_id', $userId);
                });
            }
            return $query;
        } catch (\Throwable $th) {
            // Return empty query builder
            return BackOrder::whereNull('id');
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

    protected function handleForbidden($message = 'Forbidden')
    {
        return response()->json([
            'message' => $message
        ], Response::HTTP_FORBIDDEN);
    }
}
