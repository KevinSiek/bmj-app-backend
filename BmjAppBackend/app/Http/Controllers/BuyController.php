<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Buy;
use App\Models\Seller;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class BuyController extends Controller
{
    const APPROVE = "Approved";
    const DECLINE = "Rejected";
    const WAIT_REVIEW = "Wait for Review";
    const DONE = "Done";

    public function index()
    {
        try {
            $buys = Buy::paginate(20);
            return response()->json([
                'message' => 'Buys retrieved successfully',
                'data' => $buys,
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function store(Request $request)
    {
        try {
            // Validate the request data
            $validatedData = $request->validate([
                'buyNumber' => 'required|string|unique:buys,buy_number',
                'totalAmount' => 'required|numeric',
                'review' => 'sometimes|boolean',
                'currentStatus' => 'required|string',
                'notes' => 'sometimes|string',
                'backOrderId' => 'sometimes|exists:back_orders,id',
                // Sparepart validation
                'spareparts' => 'required|array',
                'spareparts.*.sellerName' => 'required|string|max:255',
                'spareparts.*.sellerType' => 'required|string',
                'spareparts.*.sparepartId' => 'required|exists:spareparts,id',
                'spareparts.*.quantity' => 'required|integer|min:1',
                'spareparts.*.unitPrice' => 'required|numeric|min:1',
            ]);

            // Map API contract to database fields
            $buyData = [
                'buy_number' => $request->input('buyNumber'),
                'total_amount' => $request->input('totalAmount'),
                'review' =>  $request->input('review'),
                'current_status' => $request->input('currentStatus'),
                'back_order_id' => $request->input('backOrderId'),
                'notes' => $request->input('notes'),
            ];

            // Create buy data
            $buy = Buy::create($buyData);

            // Create DetailBuys from list of spareparts in this buy
            foreach ($request->input('spareparts') as $spareparts) {
                $sparepartsId = $spareparts['sparepartId'];
                $sparepartsUnitPrice = $spareparts['unitPrice'];
                $quantityOrderSparepart = $spareparts['quantity'];
                $sellerName = $spareparts['sellerName'];
                $sellerType = $spareparts['sellerType'];

                // Validate each spareparts data
                $sparepartsValidator = Validator::make($spareparts, [
                    'sellerName' => 'required|string|max:255',
                    'sellerType' => 'required|string',
                    'sparepartId' => 'required|exists:spareparts,id',
                    'quantity' => 'required|integer|min:1',
                    'unitPrice' => 'required|numeric|min:1',
                ]);

                if ($sparepartsValidator->fails()) {
                    throw new \Exception('Invalid spareparts data: ' . $sparepartsValidator->errors()->first());
                }

                // Find or create seller based on name and type
                $seller = Seller::firstOrCreate(
                    [
                        'name' => $sellerName,
                        'type' => $sellerType,
                    ]
                );

                // Insert into the bridge table
                DB::table('detail_buys')->insert([
                    'buy_id' => $buy->id,
                    'sparepart_id' => $sparepartsId,
                    'quantity' => $quantityOrderSparepart,
                    'unit_price' => $sparepartsUnitPrice,
                    'seller_id' => $seller->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return response()->json([
                'message' => 'Buy created successfully',
                'data' => $buy,
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
                'data' => $buy,
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
                'data' => null,
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th, 'Buy deletion failed');
        }
    }

    public function get($id)
    {
        try {
            $buy = Buy::with('detailBuys.sparepart', 'detailBuys.seller')
                ->findOrFail($id);

            // Calculate total purchase amount
            $totalPurchase = $buy->detailBuys->sum(function ($detail) {
                return $detail->quantity * $detail->unit_price;
            });

            // Get spare parts details
            $spareParts = $buy->detailBuys->map(function ($detail) {
                return [
                    'sparepart_name' => $detail->sparepart->sparepart_name,
                    'sparepart_number' => $detail->sparepart->sparepart_number,
                    'quantity' => $detail->quantity,
                    'unit_price' => $detail->unit_price,
                    'seller_name' => $detail->seller->name ?? '',
                    'seller_type' => $detail->seller->type ?? '',
                    'total_price' => $detail->quantity * $detail->unit_price,
                ];
            });

            // Format response
            $formattedBuy = [
                'buy_number' => $buy->buy_number ?? '',
                'date' => $buy->created_at ?? '',
                'notes' => 'PURCHASE ITEM FROM SELLER KM',
                'current_status' => $buy->current_status,
                'total_amount' => $totalPurchase,
                'spareparts' => $spareParts,
            ];

            return response()->json([
                'message' => 'Buy retrieved successfully',
                'data' => $formattedBuy,
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function getAll()
    {
        try {
            $buys = Buy::with('detailBuys.sparepart', 'detailBuys.seller')
                ->paginate(20)
                ->through(function ($buy) {
                    // Calculate total purchase amount
                    $totalPurchase = $buy->detailBuys->sum(function ($detail) {
                        return $detail->quantity * $detail->unit_price;
                    });

                    // Get spare parts details
                    $spareParts = $buy->detailBuys->map(function ($detail) {
                        return [
                            'sparepart_name' => $detail->sparepart->sparepart_name,
                            'sparepart_number' => $detail->sparepart->sparepart_number,
                            'quantity' => $detail->quantity,
                            'unit_price' => $detail->unit_price,
                            'seller_name' => $detail->seller->name ?? '',
                            'seller_type' => $detail->seller->type ?? '',
                            'total_price' => $detail->quantity * $detail->unit_price,
                        ];
                    });

                    // Format response
                    return [
                        'buy_number' => $buy->buy_number ?? '',
                        'date' => $buy->created_at ?? '',
                        'notes' => 'PURCHASE ITEM FROM SELLER KM',
                        'current_status' => $buy->current_status,
                        'total_amount' => $totalPurchase,
                        'spareparts' => $spareParts,
                    ];
                });

            return response()->json([
                'message' => 'List of all buys retrieved successfully',
                'data' => $buys,
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
            'error' => $th->getMessage(),
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    protected function handleNotFound($message = 'Resource not found')
    {
        return response()->json([
            'message' => $message,
        ], Response::HTTP_NOT_FOUND);
    }
}
