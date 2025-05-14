<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Buy;
use App\Models\Sparepart;
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
                'buy_number' =>  'required|string|unique:buys,buy_number',
                'total_amount' =>  'required|numeric',
                'review' => 'sometimes|boolean',
                'current_status' =>  'required|string',
                'notes' => 'sometimes|string',
                'back_order_id' => 'sometimes|exists:back_orders,id',
                // Sparepart validation
                'spareparts' => 'required|array',
                "spareparts.*.seller" => 'required|string',
                'spareparts.*.sparepart_id' => 'required|exists:spareparts,id',
                'spareparts.*.quantity' => 'required|integer|min:1',
                'spareparts.*.unit_price' => 'required|numeric|min:1',
            ]);

            // Create buy data
            $buy = Buy::create($validatedData);

            // Create DetailBuys from list of spareparts in this buy
            foreach ($request->input('spareparts') as $spareparts) {
                $sparepartsId = $spareparts['sparepart_id'];
                $sparepartsUnitPrice = $spareparts['unit_price'];
                $quantityOrderSparepart = $spareparts['quantity'];
                $seller = $spareparts['seller'];
                // Validate agans each spareparts data
                $sparepartsValidator = Validator::make($spareparts, [
                    'seller' => 'required|string',
                    'sparepart_id' => 'required|exists:spareparts,id',
                    'quantity' => 'required|integer|min:1',
                    'unit_price' => 'required|numeric|min:1',
                ]);


                if ($sparepartsValidator->fails()) {
                    throw new \Exception('Invalid spareparts data: ' . $sparepartsValidator->errors()->first());
                }

                // Insert into the bridge table
                DB::table('detail_buys')->insert([
                    'buy_id' => $buy->id,
                    'sparepart_id' => $sparepartsId,
                    'quantity' => $quantityOrderSparepart,
                    'unit_price' => $sparepartsUnitPrice,
                    'seller' => $seller,
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
            $buy = Buy::with('detailBuys.sparepart')
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
                    'seller' => $detail->seller,
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
            $buys = Buy::with('detailBuys.sparepart')
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
                            'seller' => $detail->seller,
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
