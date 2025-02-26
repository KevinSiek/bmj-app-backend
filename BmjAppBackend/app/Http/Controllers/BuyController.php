<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Buy;

class BuyController extends Controller
{
    public function index() {
        return Buy::with('backOrder')->get();
    }

    public function show($id) {
        return Buy::with('backOrder')->find($id);
    }

    public function store(Request $request) {
        return Buy::create($request->all());
    }

    public function update(Request $request, $id) {
        $buy = Buy::find($id);
        $buy->update($request->all());
        return $buy;
    }

    public function destroy($id) {
        return Buy::destroy($id);
    }

    // Get all purchases (buys)
    public function getAll()
    {
        $buys = Buy::with('backOrder')->get();
        $buysData = collect();
        foreach ($buys as $buy ) {
            $backOrder = $buy->backOrder;
            $buysData->push([
                'name' => $buy->no_buy ?? '',
                'date' => $buy->created_at ?? '',
                'status' => $backOrder->status ?? '',
            ]);
        }
        return response()->json($buysData);
    }

    // Get buy details
    public function getDetail($id)
    {
        $buy = Buy::with('detailBuys.goods')->find($id);

        if (!$buy) {
            return response()->json(['message' => 'Buy record not found'], 404);
        }

        // Calculate total purchase amount
        $totalPurchase = $buy->detailBuys->sum(function ($detail) {
            return $detail->quantity * $detail->goods->unit_price_buy;
        });

        // Get spare parts details
        $spareParts = [];
        foreach ($buy->detailBuys as $detail) {
            $spareParts[] = [
                'partName'   => $detail->goods->name,
                'partNumber' => $detail->goods->no_sparepart,
                'quantity'   => $detail->quantity,
                'unitPrice'  => $detail->goods->unit_price_buy,
                'totalPrice' => $detail->quantity * $detail->goods->unit_price_buy
            ];
        }

        $backOrder = $buy->backOrder;

        // Format respons
        $purchase = [
            'notes'         => 'PURCHASE ITEM FROM SELLER KM',
            'status'        => $backOrder->status,
            'totalPurchase' => $totalPurchase,
            'spareparts'    => $spareParts
        ];

        return response()->json($purchase);
    }
}
