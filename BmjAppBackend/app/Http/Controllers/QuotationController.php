<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Models\Sparepart;
use App\Models\Customer;
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
                'amount'=>'required|numeric',
                'discount'=>'required|numeric',
                'subtotal'=>'required|numeric',
                'vat'=>'required|numeric',
                'total'=>'required|numeric',
                'note'=>'sometimes|string',
                // Customer validation
                'company_name'=>'required|string',
                'office'=>'required|string',
                'address'=>'required|string',
                'urban_area'=>'required|string',
                'subdistrict'=>'required|string',
                'city'=>'required|string',
                'province'=>'required|string',
                'postal_code'=>'required|numeric',
                // Sparepart validation
                'spareparts' => 'required|array',
                'spareparts.*.id_spareparts' => 'required|exists:spareparts,id',
                'spareparts.*.quantity' => 'required|integer|min:1',
                'spareparts.*.unit_price' => 'required|numeric|min:1',
            ]);

            // Handle Customer Data
            $customerData = [
                'slug' => Str::slug($validatedData['company_name']),
                'company_name' => $validatedData['company_name'],
                'office' => $validatedData['office'],
                'address' => $validatedData['address'],
                'urban_area' => $validatedData['urban_area'],
                'subdistrict' => $validatedData['subdistrict'],
                'city' => $validatedData['city'],
                'province' => $validatedData['province'],
                'postal_code' => $validatedData['postal_code'],
            ];

            // Check if customer already exists
            $customer = Customer::where('company_name', $customerData['company_name'])
                ->where('office', $customerData['office'])
                ->where('address', $customerData['address'])
                ->where('urban_area', $customerData['urban_area'])
                ->where('subdistrict', $customerData['subdistrict'])
                ->where('city', $customerData['city'])
                ->where('province', $customerData['province'])
                ->where('postal_code', $customerData['postal_code'])
                ->first();

            // Create new customer if it doesn't exist
            if (!$customer) {
                $customer = Customer::create($customerData);
            }

            // Generate a unique slug based on the 'project' field
            $slug = Str::slug($validatedData['project']);
            $validatedData['slug'] = $slug . '-' . Str::random(6); // Add randomness for uniqueness
            $validatedData['employee_id'] = $userId;
            $validatedData['date'] = now();
            $validatedData['review'] = true;
            $validatedData['id_customer'] = $customer->id; // Assign the customer ID to the quotation

            // Create the quotation with the validated data and slug
            $quotation = Quotation::create($validatedData);

            // Create DetailQuotation from list of spareparts in this quotations
            foreach ($request->input('spareparts') as $spareparts) {
                $sparepartsId = $spareparts['id_spareparts'];
                $sparepartsUnitPrice =$spareparts['unit_price'];
                // Validate agans each spareparts data
                $sparepartsValidator = Validator::make($spareparts, [
                    'id_spareparts' => 'required|exists:spareparts,id',
                    'quantity' => 'required|integer|min:1',
                    'unit_price' =>'required|numeric|min:1',
                ]);

                // If unit price that employee give different with official unit price, then this quotation need review
                $sparepartsDbData = Sparepart::find($sparepartsId);
                $sparepartsDbUnitPriceSell = $sparepartsDbData->unit_price_sell;
                if($sparepartsUnitPrice != $sparepartsDbUnitPriceSell){
                    $validatedData['review'] = false;
                    $quotation->update($validatedData);
                }
                if ($sparepartsValidator->fails()) {
                    throw new \Exception('Invalid spareparts data: ' . $sparepartsValidator->errors()->first());
                }

                // Insert into the bridge table
                DB::table('detail_quotations')->insert([
                    'id_quotation' => $quotation->id,
                    'id_spareparts' => $sparepartsId,
                    'quantity' => $spareparts['quantity'],
                    'unit_price' => $sparepartsUnitPrice,
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
                    'partName' => $detail->spareparts->name ?? '',
                    'partNumber' => $detail->spareparts->no_sparepart ?? '',
                    'quantity' => $detail->quantity,
                    'unitPrice' => $detail->unit_price ?? 0,
                    'totalPrice' => $detail->quantity * ($detail->unit_price ?? 0),
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
            $quotations = $quotations->setCollection($transformedQuotations);

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
                $quotation= Quotation::with('customer');
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
