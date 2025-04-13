<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Buy;
use Symfony\Component\HttpFoundation\Response;

class BuyController extends Controller
{
    const APPROVE = "approve";
    const DECLINE = "decline";
    const NEED_CHANGE = "change";
    const DONE = "done";

    public function index()
    {
        try {
            $buys = Buy::paginate(20);
            return response()->json([
                'message' => 'Buys retrieved successfully',
                'data' => $buys
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function show($slug)
    {
        try {
            $buy = Buy::paginate(20)->where('slug', $slug)->first();

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

    public function update(Request $request, $slug)
    {
        try {
            $buy = Buy::where('slug', $slug)->first();

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

    public function destroy($slug)
    {
        try {
            $buy = Buy::where('slug', $slug)->first();

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
            $buys = Buy::with('detailBuys.sparepart')
                ->paginate(20)
                ->through(function ($buy) {
                    // Calculate total purchase amount
                    $totalPurchase = $buy->detailBuys->sum(function ($detail) {
                        return $detail->quantity * $detail->sparepart->unit_price_buy;
                    });

                    // Get spare parts details
                    $spareParts = $buy->detailBuys->map(function ($detail) {
                        return [
                            'partName'   => $detail->sparepart->name,
                            'partNumber' => $detail->sparepart->part_number,
                            'quantity'   => $detail->quantity,
                            'unitPrice'  => $detail->sparepart->unit_price_buy,
                            'totalPrice' => $detail->quantity * $detail->sparepart->unit_price_buy
                        ];
                    });

                    // Format response
                    return [
                        'buy_number'    => $buy->buy_number ?? '',
                        'date'          => $buy->created_at ?? '',
                        'notes'         => 'PURCHASE ITEM FROM SELLER KM',
                        'status'        => $buy->status,
                        'totalPurchase' => $totalPurchase,
                        'spareparts'    => $spareParts
                    ];
                });

            return response()->json([
                'message' => 'List of all buys retrieved successfully',
                'data' => $buys
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
