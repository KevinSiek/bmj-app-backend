<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Buy;
use Symfony\Component\HttpFoundation\Response;

class BuyController extends Controller
{
    public function index()
    {
        try {
            $buys = Buy::with('backOrder')->get();
            return response()->json([
                'message' => 'Buys retrieved successfully',
                'data' => $buys
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function show($id)
    {
        try {
            $buy = Buy::with('backOrder')->find($id);

            if (!$buy) {
                return $this->handleNotFound('Buy not found');
            }

            return response()->json([
                'message' => 'Buy retrieved successfully',
                'data' => $buy
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function store(Request $request)
    {
        try {
            $buy = Buy::create($request->all());
            return response()->json([
                'message' => 'Buy created successfully',
                'data' => $buy
            ], Response::HTTP_CREATED);
        } catch (\Throwable $th) {
            return $this->handleError($th, 'Buy creation failed');
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $buy = Buy::find($id);

            if (!$buy) {
                return $this->handleNotFound('Buy not found');
            }

            $buy->update($request->all());
            return response()->json([
                'message' => 'Buy updated successfully',
                'data' => $buy
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th, 'Buy update failed');
        }
    }

    public function destroy($id)
    {
        try {
            $buy = Buy::find($id);

            if (!$buy) {
                return $this->handleNotFound('Buy not found');
            }

            $buy->delete();
            return response()->json([
                'message' => 'Buy deleted successfully',
                'data' => null
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th, 'Buy deletion failed');
        }
    }

    public function getAll()
    {
        try {
            $buys = Buy::with('backOrder')->get();
            $buysData = $buys->map(function ($buy) {
                $backOrder = $buy->backOrder;
                return [
                    'name' => $buy->no_buy ?? '',
                    'date' => $buy->created_at ?? '',
                    'status' => $backOrder->status ?? '',
                ];
            });

            return response()->json([
                'message' => 'List of all buys retrieved successfully',
                'data' => $buysData
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function getDetail($id)
    {
        try {
            $buy = Buy::with('detailBuys.goods')->find($id);

            if (!$buy) {
                return $this->handleNotFound('Buy record not found');
            }

            // Calculate total purchase amount
            $totalPurchase = $buy->detailBuys->sum(function ($detail) {
                return $detail->quantity * $detail->goods->unit_price_buy;
            });

            // Get spare parts details
            $spareParts = $buy->detailBuys->map(function ($detail) {
                return [
                    'partName'   => $detail->goods->name,
                    'partNumber' => $detail->goods->no_sparepart,
                    'quantity'   => $detail->quantity,
                    'unitPrice'  => $detail->goods->unit_price_buy,
                    'totalPrice' => $detail->quantity * $detail->goods->unit_price_buy
                ];
            });

            $backOrder = $buy->backOrder;

            // Format response
            $purchase = [
                'notes'         => 'PURCHASE ITEM FROM SELLER KM',
                'status'        => $backOrder->status,
                'totalPurchase' => $totalPurchase,
                'spareparts'    => $spareParts
            ];

            return response()->json([
                'message' => 'Buy details retrieved successfully',
                'data' => $purchase
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
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
