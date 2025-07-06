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
    const NEED_CHANGE = "Need Change";
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
                'totalAmount' => 'required|numeric',
                'notes' => 'sometimes|string',
                // Sparepart validation
                'spareparts' => 'required|array',
                'spareparts.*.sparepartId' => 'required|exists:spareparts,id',
                'spareparts.*.quantity' => 'required|integer|min:1',
                'spareparts.*.unitPriceSell' => 'required|numeric|min:1',
            ]);

            // Map API contract to database fields
            $buyData = [
                'buy_number' => 'id',
                'review' => true,
                'current_status' => SELF::WAIT_REVIEW,
                'back_order_id' => 1,
                'total_amount' => $request->input('totalAmount'),
                'notes' => $request->input('notes'),
            ];

            // Create buy data
            $buy = Buy::create($buyData);

            // Create DetailBuys from list of spareparts in this buy
            foreach ($request->input('spareparts') as $spareparts) {
                $sparepartsId = $spareparts['sparepartId'];
                $sparepartsUnitPrice = $spareparts['unitPriceSell'];
                $quantityOrderSparepart = $spareparts['quantity'];

                // Validate each spareparts data
                $sparepartsValidator = Validator::make($spareparts, [
                    'sparepartId' => 'required|exists:spareparts,id',
                    'quantity' => 'required|integer|min:1',
                    'unitPriceSell' => 'required|numeric|min:1',
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

    public function update(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $buy = Buy::find($id);

            if (!$buy) {
                return $this->handleNotFound('Buy not found');
            }

            // Validate the request data
            $validator = Validator::make($request->all(), [
                'buyNumber' => 'sometimes|string|unique:buys,buy_number,' . $buy->id,
                'totalAmount' => 'sometimes|numeric',
                'review' => 'sometimes|boolean',
                'currentStatus' => 'sometimes|string',
                'notes' => 'sometimes|string',
                'backOrderId' => 'sometimes|exists:back_orders,id',
                // Sparepart validation
                'spareparts' => 'sometimes|array',
                'spareparts.*.sparepartId' => 'required_with:spareparts|exists:spareparts,id',
                'spareparts.*.quantity' => 'required_with:spareparts|integer|min:1',
                'spareparts.*.unitPrice' => 'required_with:spareparts|numeric|min:1',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Map camelCase inputs to database fields
            $buyData = [];
            if ($request->has('buyNumber')) {
                $buyData['buy_number'] = $request->input('buyNumber');
            }
            if ($request->has('totalAmount')) {
                $buyData['total_amount'] = $request->input('totalAmount');
            }
            if ($request->has('review')) {
                $buyData['review'] = $request->input('review');
            }
            if ($request->has('currentStatus')) {
                $buyData['current_status'] = $request->input('currentStatus');
            }
            if ($request->has('notes')) {
                $buyData['notes'] = $request->input('notes');
            }
            if ($request->has('backOrderId')) {
                $buyData['back_order_id'] = $request->input('backOrderId');
            }

            // Update Buy model if there are changes
            if (!empty($buyData)) {
                $buy->update($buyData);
            }

            // Handle spareparts update if provided
            if ($request->has('spareparts')) {
                // Delete existing detail_buys
                DB::table('detail_buys')->where('buy_id', $buy->id)->delete();

                // Create new detail_buys
                foreach ($request->input('spareparts') as $sparepart) {
                    $sparepartValidator = Validator::make($sparepart, [
                        'sparepartId' => 'required|exists:spareparts,id',
                        'quantity' => 'required|integer|min:1',
                        'unitPrice' => 'required|numeric|min:1',
                    ]);

                    if ($sparepartValidator->fails()) {
                        throw new \Exception('Invalid sparepart data: ' . $sparepartValidator->errors()->first());
                    }

                    // Insert into detail_buys
                    DB::table('detail_buys')->insert([
                        'buy_id' => $buy->id,
                        'sparepart_id' => $sparepart['sparepartId'],
                        'quantity' => $sparepart['quantity'],
                        'unit_price' => $sparepart['unitPrice'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // Fetch updated buy with relations for response
            $updatedBuy = Buy::with('detailBuys.sparepart')->findOrFail($buy->id);

            // Calculate total purchase amount
            $totalPurchase = $updatedBuy->detailBuys->sum(function ($detail) {
                return $detail->quantity * $detail->unit_price;
            });

            // Get spare parts details
            $spareParts = $updatedBuy->detailBuys->map(function ($detail) {
                return [
                    'sparepart_name' => $detail->sparepart->sparepart_name,
                    'sparepart_number' => $detail->sparepart->sparepart_number,
                    'quantity' => $detail->quantity,
                    'unit_price' => $detail->unit_price,
                    'total_price' => $detail->quantity * $detail->unit_price,
                ];
            });

            // Format response
            $formattedBuy = [
                'buy_number' => $updatedBuy->buy_number ?? '',
                'date' => $updatedBuy->created_at ?? '',
                'notes' => '',
                'current_status' => $updatedBuy->current_status,
                'total_amount' => $totalPurchase,
                'spareparts' => $spareParts,
            ];

            DB::commit();

            return response()->json([
                'message' => 'Buy updated successfully',
                'data' => $formattedBuy,
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            DB::rollBack();
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
                    'total_price' => $detail->quantity * $detail->unit_price,
                ];
            });

            // Format response
            $formattedBuy = [
                'id' => $buy->id ?? '',
                'buy_number' => $buy->buy_number ?? '',
                'date' => $buy->created_at ?? '',
                'notes' => $buy->notes,
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
                            'total_price' => $detail->quantity * $detail->unit_price,
                        ];
                    });

                    // Format response
                    return [
                        'id' => $buy->id ?? '',
                        'buy_number' => $buy->buy_number ?? '',
                        'date' => $buy->created_at ?? '',
                        'notes' => $buy->notes,
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

    public function needChange(Request $request, $id)
    {
        // Start a database transaction
        DB::beginTransaction();

        try {
            // Retrieve the quotation
            $buy = Buy::find($id);
            if (!$buy) {
                return $this->handleNotFound('Purchase not found');
            }

            $buy->review = false;
            $buy->current_status = self::NEED_CHANGE;
            $buy->save();

            // Commit the transaction
            DB::commit();

            return response()->json([
                'message' => 'Purchase status updated successfully',
                'data' => $buy
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            // Roll back the transaction if an error occurs
            DB::rollBack();

            // Handle errors
            return $this->handleError($th, 'Failed to update purchase status');
        }
    }

    public function approve(Request $request, $id)
    {
        // Start a database transaction
        DB::beginTransaction();

        try {
            // Retrieve the quotation
            $buy = Buy::find($id);
            if (!$buy) {
                return $this->handleNotFound('Purchase not found');
            }

            $buy->review = true;
            $buy->current_status = self::APPROVE;
            $buy->save();

            // Commit the transaction
            DB::commit();

            return response()->json([
                'message' => 'Purchase status updated successfully',
                'data' => $buy
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            // Roll back the transaction if an error occurs
            DB::rollBack();

            // Handle errors
            return $this->handleError($th, 'Failed to update purchase status');
        }
    }

    public function decline(Request $request, $id)
    {
        // Start a database transaction
        DB::beginTransaction();

        try {
            // Retrieve the quotation
            $buy = Buy::find($id);
            if (!$buy) {
                return $this->handleNotFound('Purchase not found');
            }

            $buy->review = true;
            $buy->current_status = self::DECLINE;
            $buy->save();

            // Commit the transaction
            DB::commit();

            return response()->json([
                'message' => 'Purchase status updated successfully',
                'data' => $buy
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            // Roll back the transaction if an error occurs
            DB::rollBack();

            // Handle errors
            return $this->handleError($th, 'Failed to update purchase status');
        }
    }

    public function isNeedReview(Request $request, $isNeedReview)
    {
        try {
            $q = $request->query('search');
            $month = $request->query('month');
            $year = $request->query('year');

            $buyNeedReview = Buy::where('review', !$isNeedReview);

            // Apply search filter if provided
            if ($q) {
                $buyNeedReview->where(function ($query) use ($q) {
                    $query->where('current_status', 'like', "%$q%")
                        ->orWhere('buy_number', 'like', "%$q%");
                });
            }

            // Apply year and month filters if provided
            if ($year) {
                $buyNeedReview->whereYear('created_at', $year);
                if ($month) {
                    $monthNumber = date('m', strtotime($month));
                    $buyNeedReview->whereMonth('created_at', $monthNumber);
                }
            }

            // Paginate the results
            $quotations = $buyNeedReview->orderBy('created_at', 'DESC')
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

            // Return the response with transformed data and pagination details
            return response()->json([
                'message' => $isNeedReview ? 'List of all quotations that need to be reviewed' : 'List of all quotations that do not need to be reviewed',
                'data' => $quotations,
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function done(Request $request, $id)
    {
        // Start a database transaction
        DB::beginTransaction();

        try {
            // Retrieve the quotation
            $buy = Buy::find($id);
            if (!$buy) {
                return $this->handleNotFound('Purchase not found');
            }

            $buy->current_status = self::DONE;
            $buy->save();

            // Commit the transaction
            DB::commit();

            return response()->json([
                'message' => 'Purchase processed',
                'data' => $buy
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            // Roll back the transaction if an error occurs
            DB::rollBack();

            // Handle errors
            return $this->handleError($th, 'Failed to process purchase');
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
