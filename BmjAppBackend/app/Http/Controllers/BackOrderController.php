<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\BackOrder;
use App\Models\DetailQuotation;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\DB;

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

                // Update sparepart stock
                $sparepart->total_unit += $detailBackOrder->number_back_order;
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

            // Update back order status
            $backOrder->status = self::READY;
            $backOrder->save();

            DB::commit();

            return response()->json([
                'message' => 'Back order processed successfully',
                'data' => $backOrder
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
