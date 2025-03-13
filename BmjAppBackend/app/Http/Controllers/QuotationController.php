<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class QuotationController extends Controller
{
    public function store(Request $request)
    {
        // Start a database transaction
        DB::beginTransaction();

        try {
            $userId = $request->user()->id;

            // Validate the request data
            $validatedData = $request->validate([
                'project' => 'required|string|max:255',
                'no' => 'required|string|unique:quotations,no',
                'type' => 'required|string',
                'status' => 'required|string',
                'id_customer' => 'required|exists:customers,id',
                'amount'=>'required|numeric',
                'discount'=>'required|numeric',
                'subtotal'=>'required|numeric',
                'vat'=>'required|numeric',
                'total'=>'required|numeric',
                'note'=>'sometimes|string',
                'review'=>'required|boolean',
                'goods' => 'required|array', // Array of goods to be added to the bridge table
                'goods.*.id_goods' => 'required|exists:goods,id', // Validate each id_goods
                'goods.*.quantity' => 'required|integer|min:1', // Validate each quantity
            ]);

            // Generate a unique slug based on the 'project' field
            $slug = Str::slug($validatedData['project']);
            $validatedData['slug'] = $slug . '-' . Str::random(6); // Add randomness for uniqueness
            $validatedData['employee_id'] = $userId;
            $validatedData['date'] = now();

            // Create the quotation with the validated data and slug
            $quotation = Quotation::create($validatedData);

            // Create DetailQuotation from list of goods in this quotations
            foreach ($request->input('goods') as $good) {
                // revalidate each good data
                $goodValidator = Validator::make($good, [
                    'id_goods' => 'required|exists:goods,id',
                    'quantity' => 'required|integer|min:1',
                ]);

                if ($goodValidator->fails()) {
                    throw new \Exception('Invalid good data: ' . $goodValidator->errors()->first());
                }

                // Insert into the bridge table
                DB::table('detail_quotations')->insert([
                    'id_quotation' => $quotation->id,
                    'id_goods' => $good['id_goods'],
                    'quantity' => $good['quantity'],
                    'created_at'=>now(),
                    'updated_at'=>now(),
                ]);
            }

            // Commit the transaction if everything is successful
            DB::commit();

            // Return a success response
            return response()->json([
                'message' => 'Quotation created successfully',
                'data' => $quotation
            ], Response::HTTP_CREATED);

        } catch (\Throwable $th) {
            // Roll back the transaction if an error occurs
            DB::rollBack();

            // Handle errors
            return $this->handleError($th, 'Quotation creation failed');
        }
    }

    public function review(Request $request, $slug, $reviewState)
    {
        try {
            if (!$reviewState) {
                return response()->json([
                    'message' => 'Invalid review status'
                ], Response::HTTP_BAD_REQUEST);
            }
            $quoatations = $this->getAccessedQuotation($request);
            $quotation = $quoatations->where('slug', $slug)->first();

            if (!$quotation) {
                return $this->handleNotFound('Quotation not found');
            }

            $quotation->status = $reviewState;
            $quotation->save();

            return response()->json([
                'message' => 'Quotation status updated successfully',
                'data' => $quotation
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th, 'Failed to update quotation status');
        }
    }

    public function getDetail(Request $request, $slug)
    {
        try {
            $quoatations = $this->getAccessedQuotation($request);
            $quotation = $quoatations->where('slug', $slug)->first();

            if (!$quotation) {
                return $this->handleNotFound('Quotation not found');
            }
            $customer = $quotation->customer;
            $spareParts = $quotation->detailQuotations->map(function ($detail) {
                return [
                    'partName' => $detail->goods->name ?? '',
                    'partNumber' => $detail->goods->no_sparepart ?? '',
                    'quantity' => $detail->quantity,
                    'unitPrice' => $detail->goods->unit_price_sell ?? 0,
                    'totalPrice' => $detail->quantity * ($detail->goods->unit_price_sell ?? 0),
                    'stock' => 'INDENT'
                ];
            });

            $response = [
                'customer' => [
                    'companyName' => $customer->company_name ?? '',
                    'address' => $customer->address ?? '',
                    'city' => $customer->city ?? '',
                    'province' => $customer->province ?? '',
                    'office' => $customer->office ?? '',
                    'urban' => $customer->urban_area ?? '',
                    'subdistrict' => $customer->subdistrict ?? '',
                    'postalCode' => $customer->postal_code ?? ''
                ],
                'project' => [
                    'noQuotation' => $quotation->no,
                    'type' => $quotation->type
                ],
                'price' => [
                    'subtotal' => $quotation->subtotal,
                    'ppn' => $quotation->vat,
                    'grandTotal' => $quotation->total
                ],
                'status' => $quotation->status,
                'notes' => $quotation->note,
                'spareparts' => $spareParts
            ];

            return response()->json([
                'message' => 'Quotation details retrieved successfully',
                'data' => $response
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function getAll(Request $request)
    {
        try {
            $q = $request->query('q');
            $month = $request->query('month'); // Month in English (e.g., "January")
            $year = $request->query('year'); // Year (e.g., 2024)

            $quoatations = $this->getAccessedQuotation($request);
            // Build the query with search functionality
            $quotationsQuery = $quoatations->where(function ($query) use ($q) {
                    $query->where('project', 'like', "%$q%")
                        ->orWhere('no', 'like', "%$q%")
                        ->orWhere('type', 'like', "%$q%");
                });

            // Filter by month and year if provided
            if ($month && $year) {
                $monthNumber = date('m', strtotime($month));
                $startDate = "{$year}-{$monthNumber}-01";
                $quotationsQuery->where('date', '>=', $startDate);
            }

            // Paginate the results
            $quotations = $quotationsQuery->paginate(20);

            // Transform the paginated results
            $transformedQuotations = $quotations->getCollection()->map(function ($quotation) {
                return [
                    'id' => (string) $quotation->id,
                    'customer' => $quotation->customer->company_name ?? '',
                    'date' => $quotation->date,
                    'type' => $quotation->type,
                    'status' => $quotation->status
                ];
            });

            // Replace the original collection with the transformed collection
            $quotations->setCollection($transformedQuotations);

            // Return the response with transformed data and pagination details
            return response()->json([
                'message' => 'List of all quotations retrieved successfully',
                'data' => [
                    'items' => $quotations,
                ],
            ], Response::HTTP_OK);

        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function moveToPo(Request $request, $slug)
    {
        DB::beginTransaction();

        try {
            $quoatations = $this->getAccessedQuotation($request);
            $quotation =  $quoatations->where('slug', $slug)->first();

            if (!$quotation) {
                return $this->handleNotFound('Quotation not found');
            }

            if ($quotation->purchaseOrder) {
                return response()->json([
                    'message' => 'Quotation already has a purchase order'
                ], Response::HTTP_BAD_REQUEST);
            }

            $purchaseOrder = PurchaseOrder::create([
                'id_quotation' => $quotation->id,
                'po_number' => 'PO-' . now()->format('YmdHis'),
                'po_date' => now(),
                'employee_id' => $quotation->employee_id,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Quotation promoted to purchase order successfully',
                'data' => $purchaseOrder
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->handleError($th, 'Failed to promote quotation');
        }
    }
    public function isNeedReview(Request $request, $isNeedReview)
    {
        try {
            $quoatations = $this->getAccessedQuotation($request);
            $quotationNeedReview= $quoatations->where('review', !$isNeedReview);

            // Paginate the results
            $quotationNeedReview = $quotationNeedReview->paginate(20);

            // Return the response with transformed data and pagination details
            return response()->json([
                'message' => 'List of all quotations that need to be review',
                'data' => $quotationNeedReview,
            ], Response::HTTP_OK);

        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    // Helper function to get list of quotation by user role and user id
    protected function getAccessedQuotation($request)
    {
        try {
            $user = $request->user();
            $userId = $user->id;
            $role = $user->role;
            $quotation= Quotation::with('customer')
                ->where('employee_id', $userId);

            // Allow director to see all quotation
            if($role === 'Director'){
                $quotation= Quotation::with('customer')->all();
            }

            // Return the response with transformed data and pagination details
            return $quotation;

        } catch (\Throwable $th) {
            echo('Error at getAccessedQuotation: '.$th->getMessage());
            return [];
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
