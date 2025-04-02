<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Models\Sparepart;
use App\Models\Customer;
use App\Models\BackOrder;
use App\Models\DetailBackOrder;
use App\Models\PurchaseOrder;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class QuotationController extends Controller
{
    const APPROVE = "approve";
    const DECLINE = "decline";
    const NEED_CHANGE = "change";

    const SERVICE = "Service";
    const SPAREPARTS = "Spareparts";

    public function store(Request $request)
    {
        // Start a database transaction
        DB::beginTransaction();

        try {
            $userId = $request->user()->id;

            // Validate the request data
            $validatedData = $request->validate([
                'project' => 'required|string|max:255',
                'number' => 'required|string|unique:quotations,number',
                'type' => 'required|string',
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
                'spareparts.*.sparepart_id' => 'required|exists:spareparts,id',
                'spareparts.*.quantity' => 'required|integer|min:1',
                'spareparts.*.unit_price' => 'required|numeric|min:1',
            ]);

            // Handle Customer Data
            $customerData = [
                'slug' => Str::slug($validatedData['company_name']). '-' . Str::random(6),
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
            $validatedData['status'] = QuotationController::APPROVE;
            $validatedData['customer_id'] = $customer->id; // Assign the customer ID to the quotation

            // Create the quotation with the validated data and slug
            $quotation = Quotation::create($validatedData);

            // Create DetailQuotation from list of spareparts in this quotations
            foreach ($request->input('spareparts') as $spareparts) {
                $sparepartsId = $spareparts['sparepart_id'];
                $sparepartsUnitPrice =$spareparts['unit_price'];
                $quantityOrderSparepart = $spareparts['quantity'];
                // Validate agans each spareparts data
                $sparepartsValidator = Validator::make($spareparts, [
                    'sparepart_id' => 'required|exists:spareparts,id',
                    'quantity' => 'required|integer|min:1',
                    'unit_price' =>'required|numeric|min:1',
                ]);

                // If unit price that employee give different with official unit price, then this quotation need review
                $sparepartsDbData = Sparepart::find($sparepartsId);
                $sparepartsDbUnitPriceSell = $sparepartsDbData->unit_price_sell;
                if($sparepartsUnitPrice != $sparepartsDbUnitPriceSell){
                    $validatedData['review'] = false;
                    $validatedData['status'] = '';
                    $quotation->update($validatedData);
                }
                // Determine if current sparepart quantity is exist or not.
                $spareparts['is_indent'] = false;
                if($quantityOrderSparepart > $sparepartsDbData->total_unit){
                    $spareparts['is_indent'] = true;
                }

                if ($sparepartsValidator->fails()) {
                    throw new \Exception('Invalid spareparts data: ' . $sparepartsValidator->errors()->first());
                }

                // Insert into the bridge table
                DB::table('detail_quotations')->insert([
                    'quotation_id' => $quotation->id,
                    'sparepart_id' => $sparepartsId,
                    'quantity' => $quantityOrderSparepart,
                    'is_indent' => $spareparts['is_indent'],
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

    public function update(Request $request, $slug)
    {
        // Start a database transaction
        DB::beginTransaction();

        try {
            // Find the quotation by slug
            $quoatations = $this->getAccessedQuotation($request);
            $quotation = $quoatations->where('slug', $slug)->firstOrFail();
            $po = $quotation->purchaseOrder;

            if($po){
                return response()->json([
                    'message' => 'Quotation already in purchase order.'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validate the request data
            $validatedData = $request->validate([
                'project' => 'required|string|max:255',
                'number' => 'sometimes|string|unique:quotations,number,' . $quotation->id,
                'type' => 'required|string',
                'amount' => 'required|numeric',
                'discount' => 'required|numeric',
                'subtotal' => 'required|numeric',
                'vat' => 'required|numeric',
                'total' => 'required|numeric',
                'note' => 'sometimes|string',
                // Customer validation
                'company_name' => 'required|string',
                'office' => 'required|string',
                'address' => 'required|string',
                'urban_area' => 'required|string',
                'subdistrict' => 'required|string',
                'city' => 'required|string',
                'province' => 'required|string',
                'postal_code' => 'required|numeric',
                // Sparepart validation
                'spareparts' => 'required|array',
                'spareparts.*.sparepart_id' => 'required|exists:spareparts,id',
                'spareparts.*.quantity' => 'required|integer|min:1',
                'spareparts.*.unit_price' => 'required|numeric|min:1',
            ]);

            // Handle Customer Data if provided
            $customerData = [
                'slug' => Str::slug($validatedData['company_name']) . '-' . Str::random(6),
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

            // Assign the customer ID to the quotation
            $validatedData['customer_id'] = $customer->id;

            // Update the quotation with the validated data
            $quotation->update($validatedData);

            // Handle Spareparts
            // Delete existing spareparts for this quotation
            DB::table('detail_quotations')->where('quotation_id', $quotation->id)->delete();

            // Create DetailQuotation from list of spareparts in this quotations
            foreach ($request->input('spareparts') as $spareparts) {
                $sparepartsId = $spareparts['sparepart_id'];
                $sparepartsUnitPrice = $spareparts['unit_price'];

                // Validate against each spareparts data
                $sparepartsValidator = Validator::make($spareparts, [
                    'sparepart_id' => 'required|exists:spareparts,id',
                    'quantity' => 'required|integer|min:1',
                    'unit_price' => 'required|numeric|min:1',
                ]);

                // If unit price that employee give different with official unit price, then this quotation need review
                $sparepartsDbData = Sparepart::find($sparepartsId);
                $sparepartsDbUnitPriceSell = $sparepartsDbData->unit_price_sell;
                if ($sparepartsUnitPrice != $sparepartsDbUnitPriceSell) {
                    $validatedData['review'] = false;
                    $validatedData['status'] = '';
                    $quotation->update($validatedData);
                }

                // Determine if current sparepart quantity is exist or not.
                $spareparts['is_indent'] = false;
                if ($spareparts['quantity'] > $sparepartsDbData->total_unit) {
                    $spareparts['is_indent'] = true;
                }

                if ($sparepartsValidator->fails()) {
                    throw new \Exception('Invalid spareparts data: ' . $sparepartsValidator->errors()->first());
                }

                // Insert into the bridge table
                DB::table('detail_quotations')->insert([
                    'quotation_id' => $quotation->id,
                    'sparepart_id' => $sparepartsId,
                    'quantity' => $spareparts['quantity'],
                    'is_indent' => $spareparts['is_indent'],
                    'unit_price' => $sparepartsUnitPrice,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            // Commit the transaction if everything is successful
            DB::commit();

            // Return a success response
            return response()->json([
                'message' => 'Quotation updated successfully',
                'data' => $quotation
            ], Response::HTTP_OK);

        } catch (\Throwable $th) {
            // Roll back the transaction if an error occurs
            DB::rollBack();

            // Handle errors
            return $this->handleError($th, 'Quotation update failed');
        }
    }

    public function needChange(Request $request, $slug)
    {
        // Start a database transaction
        DB::beginTransaction();

        try {
            // Retrieve the quotation
            $quoatations = $this->getAccessedQuotation($request);
            $quotation = $quoatations->where('slug', $slug)->first();

            if (!$quotation) {
                return $this->handleNotFound('Quotation not found');
            }

            $quotation->review = true;
            $quotation->status = QuotationController::NEED_CHANGE;
            $quotation->save();

            // Commit the transaction
            DB::commit();

            return response()->json([
                'message' => 'Quotation status updated successfully',
                'data' => $quotation
            ], Response::HTTP_OK);

        } catch (\Throwable $th) {
            // Roll back the transaction if an error occurs
            DB::rollBack();

            // Handle errors
            return $this->handleError($th, 'Failed to update quotation status');
        }
    }

    public function approve(Request $request, $slug)
    {
        // Start a database transaction
        DB::beginTransaction();

        try {
            // Retrieve the quotation
            $quoatations = $this->getAccessedQuotation($request);
            $quotation = $quoatations->where('slug', $slug)->first();

            if (!$quotation) {
                return $this->handleNotFound('Quotation not found');
            }

            // Only allow director approve quotation
            $user = $request->user();
            $role = $user->role;
            if($role != 'Director'){
                return response()->json([
                    'message' => 'You are not authorized to approve this quotation'
                ], Response::HTTP_BAD_REQUEST);
            }

            $quotation->review = true;
            $quotation->status = QuotationController::APPROVE;
            $quotation->save();

            // Commit the transaction
            DB::commit();

            return response()->json([
                'message' => 'Quotation status updated successfully',
                'data' => $quotation
            ], Response::HTTP_OK);

        } catch (\Throwable $th) {
            // Roll back the transaction if an error occurs
            DB::rollBack();

            // Handle errors
            return $this->handleError($th, 'Failed to update quotation status');
        }
    }

    public function decline(Request $request, $slug)
    {
        // Start a database transaction
        DB::beginTransaction();

        try {
            // Retrieve the quotation
            $quoatations = $this->getAccessedQuotation($request);
            $quotation = $quoatations->where('slug', $slug)->first();

            if (!$quotation) {
                return $this->handleNotFound('Quotation not found');
            }

            // Only allow director decline quotation
            $user = $request->user();
            $role = $user->role;
            if($role != 'Director'){
                return response()->json([
                    'message' => 'You are not authorized to decline this quotation'
                ], Response::HTTP_BAD_REQUEST);
            }

            $quotation->review = true;
            $quotation->status = QuotationController::DECLINE;
            $quotation->save();

            // Commit the transaction
            DB::commit();

            return response()->json([
                'message' => 'Quotation status updated successfully',
                'data' => $quotation
            ], Response::HTTP_OK);

        } catch (\Throwable $th) {
            // Roll back the transaction if an error occurs
            DB::rollBack();

            // Handle errors
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
                    'partName' => $detail->sparepart->name ?? '',
                    'partNumber' => $detail->sparepart->part_number ?? '',
                    'quantity' => $detail->quantity,
                    'unitPrice' => $detail->unit_price ?? 0,
                    'totalPrice' => $detail->quantity * ($detail->unit_price ?? 0),
                    'stock' => $detail->is_indent
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
                    'number' => $quotation->number,
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
                        ->orWhere('number', 'like', "%$q%")
                        ->orWhere('type', 'like', "%$q%");
                });

            // Filter by month and year if provided
            if ($month && $year) {
                $monthNumber = date('m', strtotime($month));
                $startDate = "{$year}-{$monthNumber}-01";
                $quotationsQuery->where('date', '>=', $startDate);
            }

            // Paginate the results
            $quotations = $quotationsQuery->paginate(20)->through(function ($quotation) {
                return [
                    'id' => (string) $quotation->id,
                    'number' => $quotation->number,
                    'customer' => $quotation->customer->company_name ?? '',
                    'date' => $quotation->date,
                    'type' => $quotation->type,
                    'status' => $quotation->status
                ];
            });

            // Return the response with transformed data and pagination details
            return response()->json([
                'message' => 'List of all quotations retrieved successfully',
                'data' => $quotations
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
            $isNeedReview = $quotation->review;
            $isApproved = $quotation->status == QuotationController::APPROVE;

            if (!$quotation) {
                return $this->handleNotFound('Quotation not found');
            }

            if ($quotation->purchaseOrder) {
                return response()->json([
                    'message' => 'Quotation already has a purchase order'
                ], Response::HTTP_BAD_REQUEST);
            }

            if(!$isNeedReview || !$isApproved){
                return response()->json([
                    'message' => 'Quotation need to reviewed or approved first before move to purchase order'
                ], Response::HTTP_BAD_REQUEST);
            }

            $quotation->update(['status' => 'PO']);

            // Get the spareparts associated with the quotation
            $spareparts = DB::table('detail_quotations')
                ->where('quotation_id', $quotation->id)
                ->get();

            $purchaseOrder = PurchaseOrder::create([
                'quotation_id' => $quotation->id,
                'purchase_order_number' => 'PO-' . now()->format('YmdHis'),
                'purchase_order_date' => now(),
                'employee_id' => $quotation->employee_id,
            ]);

            $backOrder = BackOrder::create([
                'purchase_order_id' => $purchaseOrder->id,
                'back_order_number' => 'PT'.now(),
                'status' => 'Pending',
            ]);

            // Decrease the total_unit for each sparepart after moveToPo
            foreach ($spareparts as $sparepart) {
                $sparepartRecord = Sparepart::find($sparepart->sparepart_id);
                $sparepartTotalUnit = $sparepartRecord->total_unit;
                $sparepartQuantityOrderInPo = $sparepart->quantity;
                $numberBoInBo = 0;
                $numberDoInBo = $sparepart->quantity;

                # When create BO, need to determine number of BO and DO for each sparepart in this PO
                $sparepartQuantityAfterPo = $sparepartTotalUnit - $sparepartQuantityOrderInPo;
                $stockIsExistButAfterPoBecomeIndent = $sparepartQuantityAfterPo < 0 && $sparepartTotalUnit >= 0;
                $stockIsNotExistBeforePo =  $sparepartTotalUnit <= 0;
                if($stockIsExistButAfterPoBecomeIndent){
                    // If sparepart stock exist but become minus after PO then :
                    //      1. The number of BO is total order minus total stock (Need to buy)
                    //      2. The number of DO is total existing stock (Ready)
                    $numberBoInBo = ($sparepartQuantityOrderInPo - $sparepartTotalUnit);
                    $numberDoInBo = $sparepartTotalUnit;
                }elseif($stockIsNotExistBeforePo){
                    // If sparepart stock is not exist then :
                    //      1. The number of BO is total order in this PO only (Need to buy)
                    //      2. The number of DO is 0  (Nothing is ready)
                    $numberBoInBo = $sparepartQuantityOrderInPo;
                    $numberDoInBo = 0;
                }

                // Decrease the number of sparepart
                if ($sparepartRecord) {
                    $sparepartRecord->total_unit -= $sparepartQuantityOrderInPo;
                    $sparepartRecord->save();
                }

                // Create Detail back order for each sparepart
                // TODO: This is maybe not efficient but we need to handle multiple sparepart statuse in single BO ID
                $boStatus = BackOrderController::READY;
                if($numberBoInBo){
                    $boStatus = 'pending';
                }
                $backOrder->update([
                    'status'=>$boStatus
                ]);
                DetailBackOrder::create([
                    'back_order_id' => $backOrder->id,
                    'sparepart_id' => $sparepart->sparepart_id,
                    'number_delivery_order' => $numberDoInBo,
                    'number_back_order' => $numberBoInBo,
                ]);
            }

            // Check if this quotation is Service or not
            $isService = $quotation->type == QuotationController::SERVICE;
            if($isService && !$quotation->workOrder){
                $user = $request->user();
                $userId = $user->id;

                WorkOrder::create([
                    'quotation_id' => $quotation->id,
                    'work_order_number' => 'WO-' . now()->format('YmdHis'),
                    'received_by' => $userId,
                    'expected_days' => null,
                    'expected_start_date' => null,
                    'expected_end_date' => null,
                    'compiled_by' => $userId,
                    'start_date' => null,
                    'end_date' => null,
                    'job_descriptions' => null,
                    'work_peformed_by' => null,
                    'approved_by' => $userId,
                    'additional_components' => null,
                    'is_done' => false,
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Quotation move to purchase order successfully',
                'data' => $purchaseOrder
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->handleError($th, 'Failed to move quotation to purchase order');
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
            if($role == 'Director'){
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
