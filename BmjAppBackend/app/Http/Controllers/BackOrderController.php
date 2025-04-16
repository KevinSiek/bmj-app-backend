<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\BackOrder;
use App\Models\Buy;
use App\Models\DetailBuy;
use App\Models\DetailQuotation;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BackOrderController extends Controller
{
    // Status constants
    const READY = 'Ready';
    const ALLOWED_PROCESS_ROLES = ['Director', 'Inventory'];

    public function getAll(Request $request)
    {
        try {
            // Get query parameters
            $q = $request->query('q');
            $month = $request->query('month');
            $year = $request->query('year');

            // Initialize the query builder
            $backOrderQuery = $this->getAccessedBackOrder($request);

            // Apply search term filter if 'q' is provided
            if ($q) {
                $backOrderQuery->where(function ($query) use ($q) {
                    $query->where('back_order_number', 'like', "%$q%")
                        ->orWhere('status', 'like', "%$q%")
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

            // Paginate the results
            $backOrders = $backOrderQuery->with(['purchaseOrder', 'purchaseOrder.employee'])
                ->orderBy('created_at', 'desc')
                ->paginate(20);

            return response()->json([
                'message' => 'List of all back orders retrieved successfully',
                'data' => $backOrders,
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function process(Request $request, $id)
    {
        try {
            // Check user role, only director and inventory that able process back order
            $user = $request->user();
            if (!in_array($user->role, self::ALLOWED_PROCESS_ROLES)) {
                return $this->handleForbidden('You are not authorized to process back orders');
            }

            DB::beginTransaction();

            // Get the back order with all necessary relations
            $backOrder = $this->getAccessedBackOrder($request)
                ->with([
                    'detailBackOrders.sparepart',
                    'purchaseOrder.quotation.detailQuotations'
                ])
                ->find($id);

            if (!$backOrder) {
                return $this->handleNotFound('Back order not found');
            }

            // Check if back order is already processed
            if ($backOrder->status === self::READY) {
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
                'status' => BuyController::DONE, // Process means it already done
                'notes' => 'Auto-generated from BackOrder #' . $backOrder->back_order_number,
                'back_order_id' => $backOrder->id,
            ]);

            // Process each detail back order
            foreach ($backOrder->detailBackOrders as $detailBackOrder) {
                // Skip if no back order quantity
                if ($detailBackOrder->number_back_order <= 0) {
                    continue;
                }

                $sparepart = $detailBackOrder->sparepart;
                if (!$sparepart) {
                    continue;
                }

                // Find the DetailBuy with the lowest unit_price for this sparepart
                $cheapestDetailBuy = DetailBuy::where('sparepart_id', $sparepart->id)
                    ->orderBy('unit_price', 'asc')
                    ->first();

                if (!$cheapestDetailBuy) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'No purchase history found for sparepart #' . $sparepart->sparepart_number
                    ], Response::HTTP_BAD_REQUEST);
                }

                // Create DetailBuy record
                $quantity = $detailBackOrder->number_back_order;
                $unitPrice = $cheapestDetailBuy->unit_price;
                $subtotal = $quantity * $unitPrice;

                DetailBuy::create([
                    'buy_id' => $buy->id,
                    'sparepart_id' => $sparepart->id,
                    'quantity' => $quantity,
                    'seller' => $cheapestDetailBuy->seller,
                    'unit_price' => $unitPrice,
                ]);

                $totalAmount += $subtotal;

                // Update sparepart stock
                $sparepart->total_unit += $quantity;
                $sparepart->save();

                // Find corresponding detail quotation and update its status
                $quotation = $backOrder->purchaseOrder->quotation;
                if ($quotation) {
                    $detailQuotation = DetailQuotation::where('quotation_id', $quotation->id)
                        ->where('sparepart_id', $detailBackOrder->sparepart_id)
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
            $backOrder->status = self::READY;
            $backOrder->save();

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
