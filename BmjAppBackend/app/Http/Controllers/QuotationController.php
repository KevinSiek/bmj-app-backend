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
    const APPROVE = "Approved";
    const REJECTED = "Rejected";
    const NEED_CHANGE = "Change";

    const SERVICE = "Service";
    const SPAREPARTS = "Spareparts";

    public function store(Request $request)
    {
        // Start a database transaction
        DB::beginTransaction();

        try {
            $userId = $request->user()->id;

            // Validate the request data based on API contract
            $validatedData = $request->validate([
                'project.quotationNumber' => 'required|string|unique:quotations,quotation_number',
                'project.type' => 'required|string',
                'project.date' => 'required|date',
                'price.amount' => 'required|numeric',
                'price.discount' => 'required|numeric',
                'price.subtotal' => 'required|numeric',
                'price.ppn' => 'required|numeric',
                'price.grandTotal' => 'required|numeric',
                'notes' => 'sometimes|string',
                // Customer validation
                'customer.companyName' => 'required|string',
                'customer.office' => 'required|string',
                'customer.address' => 'required|string',
                'customer.urban' => 'required|string',
                'customer.subdistrict' => 'required|string',
                'customer.city' => 'required|string',
                'customer.province' => 'required|string',
                'customer.postalCode' => 'required|numeric',
                // Sparepart validation
                'spareparts' => 'required|array',
                'spareparts.*.sparepartId' => 'required|exists:spareparts,id',
                'spareparts.*.quantity' => 'required|integer|min:1',
                'spareparts.*.unitPriceSell' => 'required|numeric|min:1',
            ]);

            // Map API contract to database fields
            $quotationData = [
                'quotation_number' => $request->input('project.quotationNumber'),
                'type' => $request->input('project.type'),
                'date' => $request->input('project.date'),
                'amount' => $request->input('price.amount'),
                'discount' => $request->input('price.discount'),
                'subtotal' => $request->input('price.subtotal'),
                'ppn' => $request->input('price.ppn'),
                'grand_total' => $request->input('price.grandTotal'),
                'notes' => $request->input('notes'),
                'project' => $request->input('project.quotationNumber'), // Using quotationNumber as project name
            ];

            // Handle Customer Data
            $customerData = [
                'slug' => Str::slug($request->input('customer.companyName')) . '-' . Str::random(6),
                'company_name' => $request->input('customer.companyName'),
                'office' => $request->input('customer.office'),
                'address' => $request->input('customer.address'),
                'urban' => $request->input('customer.urban'),
                'subdistrict' => $request->input('customer.subdistrict'),
                'city' => $request->input('customer.city'),
                'province' => $request->input('customer.province'),
                'postal_code' => $request->input('customer.postalCode'),
            ];

            // Check if customer already exists
            $customer = Customer::where('company_name', $customerData['company_name'])
                ->where('office', $customerData['office'])
                ->where('address', $customerData['address'])
                ->where('urban', $customerData['urban'])
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
            $slug = Str::slug($quotationData['project']);
            $quotationData['slug'] = $slug . '-' . Str::random(6); // Add randomness for uniqueness
            $quotationData['employee_id'] = $userId;
            $quotationData['review'] = true;
            $quotationData['current_status'] = QuotationController::APPROVE;
            $quotationData['customer_id'] = $customer->id; // Assign the customer ID to the quotation

            // Create the quotation with the validated data and slug
            $quotation = Quotation::create($quotationData);

            // Create DetailQuotation from list of spareparts in this quotations
            foreach ($request->input('spareparts') as $sparepart) {
                $sparepartId = $sparepart['sparepartId'];
                $sparepartUnitPrice = $sparepart['unitPriceSell'];
                $quantityOrderSparepart = $sparepart['quantity'];
                // Validate against each sparepart data
                $sparepartValidator = Validator::make($sparepart, [
                    'sparepartId' => 'required|exists:spareparts,id',
                    'quantity' => 'required|integer|min:1',
                    'unitPriceSell' => 'required|numeric|min:1',
                ]);

                // If unit price that employee give different with official unit price, then this quotation need review
                $sparepartDbData = Sparepart::find($sparepartId);
                $sparepartDbUnitPriceSell = $sparepartDbData->unit_price_sell;
                if ($sparepartUnitPrice != $sparepartDbUnitPriceSell) {
                    $quotationData['review'] = false;
                    $quotationData['current_status'] = '';
                    $quotation->update($quotationData);
                }
                // Determine if current sparepart quantity is exist or not.
                $sparepart['is_indent'] = false;
                if ($quantityOrderSparepart > $sparepartDbData->total_unit) {
                    $sparepart['is_indent'] = true;
                }

                if ($sparepartValidator->fails()) {
                    throw new \Exception('Invalid sparepart data: ' . $sparepartValidator->errors()->first());
                }

                // Insert into the bridge table
                DB::table('detail_quotations')->insert([
                    'quotation_id' => $quotation->id,
                    'sparepart_id' => $sparepartId,
                    'quantity' => $quantityOrderSparepart,
                    'is_indent' => $sparepart['is_indent'],
                    'unit_price' => $sparepartUnitPrice,
                    'created_at' => now(),
                    'updated_at' => now(),
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
            $quotations = $this->getAccessedQuotation($request);
            $quotation = $quotations->where('slug', $slug)->firstOrFail();
            $po = $quotation->purchaseOrder;

            if ($po) {
                return response()->json([
                    'message' => 'Quotation already in purchase order.'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validate the request data
            $validatedData = $request->validate([
                'project.quotationNumber' => 'required|string',
                'project.type' => 'required|string',
                'project.date' => 'required|date',
                'price.amount' => 'required|numeric',
                'price.discount' => 'required|numeric',
                'price.subtotal' => 'required|numeric',
                'price.ppn' => 'required|numeric',
                'price.grandTotal' => 'required|numeric',
                'notes' => 'sometimes|string',
                // Customer validation
                'customer.companyName' => 'required|string',
                'customer.office' => 'required|string',
                'customer.address' => 'required|string',
                'customer.urban' => 'required|string',
                'customer.subdistrict' => 'required|string',
                'customer.city' => 'required|string',
                'customer.province' => 'required|string',
                'customer.postalCode' => 'required|numeric',
                // Sparepart validation
                'spareparts' => 'required|array',
                'spareparts.*.sparepartId' => 'required|exists:spareparts,id',
                'spareparts.*.quantity' => 'required|integer|min:1',
                'spareparts.*.unitPriceSell' => 'required|numeric|min:1',
            ]);

            // Map API contract to database fields
            $quotationData = [
                'quotation_number' => $request->input('project.quotationNumber'),
                'type' => $request->input('project.type'),
                'date' => $request->input('project.date'),
                'amount' => $request->input('price.amount'),
                'discount' => $request->input('price.discount'),
                'subtotal' => $request->input('price.subtotal'),
                'ppn' => $request->input('price.ppn'),
                'grand_total' => $request->input('price.grandTotal'),
                'notes' => $request->input('notes'),
                'project' => $request->input('project.quotationNumber'),
            ];

            // Handle versioning for quotation_number
            $baseQuotationNumber = $quotationData['quotation_number'];
            $existingVersions = Quotation::where('quotation_number', 'like', $baseQuotationNumber . '%')
                ->count();
            $version = $existingVersions + 1;
            $quotationData['quotation_number'] = $baseQuotationNumber . "-v{$version}";

            // Validate the new quotation_number for uniqueness
            $validator = Validator::make($quotationData, [
                'quotation_number' => 'required|string|unique:quotations,quotation_number'
            ]);
            if ($validator->fails()) {
                throw new \Exception('Generated quotation number is not unique: ' . $validator->errors()->first());
            }

            // Handle Customer Data if provided
            $customerData = [
                'slug' => Str::slug($request->input('customer.companyName')) . '-' . Str::random(6),
                'company_name' => $request->input('customer.companyName'),
                'office' => $request->input('customer.office'),
                'address' => $request->input('customer.address'),
                'urban' => $request->input('customer.urban'),
                'subdistrict' => $request->input('customer.subdistrict'),
                'city' => $request->input('customer.city'),
                'province' => $request->input('customer.province'),
                'postal_code' => $request->input('customer.postalCode'),
            ];

            // Check if customer already exists
            $customer = Customer::where('company_name', $customerData['company_name'])
                ->where('office', $customerData['office'])
                ->where('address', $customerData['address'])
                ->where('urban', $customerData['urban'])
                ->where('subdistrict', $customerData['subdistrict'])
                ->where('city', $customerData['city'])
                ->where('province', $customerData['province'])
                ->where('postal_code', $customerData['postal_code'])
                ->first();

            // Create new customer if it doesn't exist
            if (!$customer) {
                $customer = Customer::create($customerData);
            }

            // Assign the customer ID and employee ID to the quotation
            $quotationData['customer_id'] = $customer->id;
            $quotationData['employee_id'] = $quotation->employee_id; // Retain original employee_id
            $quotationData['review'] = true;
            $quotationData['current_status'] = QuotationController::APPROVE;

            // Generate a unique slug based on the 'project' field
            $slug = Str::slug($quotationData['project']);
            $quotationData['slug'] = $slug . '-' . Str::random(6); // Add randomness for uniqueness

            // Create new quotation with the validated data
            $newQuotation = Quotation::create($quotationData);

            // Create DetailQuotation from list of spareparts in this quotations
            foreach ($request->input('spareparts') as $sparepart) {
                $sparepartId = $sparepart['sparepartId'];
                $sparepartUnitPrice = $sparepart['unitPriceSell'];

                // Validate against each sparepart data
                $sparepartValidator = Validator::make($sparepart, [
                    'sparepartId' => 'required|exists:spareparts,id',
                    'quantity' => 'required|integer|min:1',
                    'unitPriceSell' => 'required|numeric|min:1',
                ]);

                // If unit price that employee give different with official unit price, then this quotation need review
                $sparepartDbData = Sparepart::find($sparepartId);
                $sparepartDbUnitPriceSell = $sparepartDbData->unit_price_sell;
                if ($sparepartUnitPrice != $sparepartDbUnitPriceSell) {
                    $quotationData['review'] = false;
                    $quotationData['current_status'] = '';
                    $newQuotation->update($quotationData);
                }

                // Determine if current sparepart quantity is exist or not.
                $sparepart['is_indent'] = false;
                if ($sparepart['quantity'] > $sparepartDbData->total_unit) {
                    $sparepart['is_indent'] = true;
                }

                if ($sparepartValidator->fails()) {
                    throw new \Exception('Invalid sparepart data: ' . $sparepartValidator->errors()->first());
                }

                // Insert into the bridge table
                DB::table('detail_quotations')->insert([
                    'quotation_id' => $newQuotation->id,
                    'sparepart_id' => $sparepartId,
                    'quantity' => $sparepart['quantity'],
                    'is_indent' => $sparepart['is_indent'],
                    'unit_price' => $sparepartUnitPrice,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            // Commit the transaction if everything is successful
            DB::commit();

            // Return a success response
            return response()->json([
                'message' => 'New quotation version created successfully',
                'data' => $newQuotation
            ], Response::HTTP_CREATED);
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
            $quotation->current_status = QuotationController::NEED_CHANGE;
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
            if ($role != 'Director') {
                return response()->json([
                    'message' => 'You are not authorized to approve this quotation'
                ], Response::HTTP_BAD_REQUEST);
            }

            $quotation->review = true;
            $quotation->current_status = QuotationController::APPROVE;
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
            if ($role != 'Director') {
                return response()->json([
                    'message' => 'You are not authorized to decline this quotation'
                ], Response::HTTP_BAD_REQUEST);
            }

            $quotation->review = true;
            $quotation->current_status = QuotationController::REJECTED;
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

    public function get(Request $request, $slug)
    {
        try {
            $quoatations = $this->getAccessedQuotation($request);
            $quotation = $quoatations->where('slug', $slug)->firstOrFail();

            $customer = $quotation->customer;
            $spareParts = $quotation->detailQuotations->map(function ($detail) {
                return [
                    'sparepart_name' => $detail->sparepart->sparepart_name ?? '',
                    'sparepart_number' => $detail->sparepart->sparepart_number ?? '',
                    'quantity' => $detail->quantity ?? 0,
                    'unit_price_sell' => $detail->unit_price ?? 0,
                    'total_price' => $detail->quantity * ($detail->unit_price ?? 0),
                    'stock' => $detail->is_indent
                ];
            });

            $formattedQuotation = [
                'id' => (string) $quotation->id,
                'slug' => $quotation->slug,
                'quotation_number' => $quotation->quotation_number,
                'customer' => [
                    'company_name' => $customer->company_name ?? '',
                    'address' => $customer->address ?? '',
                    'city' => $customer->city ?? '',
                    'province' => $customer->province ?? '',
                    'office' => $customer->office ?? '',
                    'urban' => $customer->urban ?? '',
                    'subdistrict' => $customer->subdistrict ?? '',
                    'postal_code' => $customer->postal_code ?? ''
                ],
                'project' => [
                    'quotation_number' => $quotation->quotation_number,
                    'type' => $quotation->type,
                    'date' => $quotation->date
                ],
                'price' => [
                    'subtotal' => $quotation->subtotal,
                    'ppn' => $quotation->ppn,
                    'grand_total' => $quotation->grand_total
                ],
                'current_status' => $quotation->current_status,
                'status' => json_decode($quotation->status, true) ?? [], // Added status field
                'notes' => $quotation->notes,
                'spareparts' => $spareParts,
                'date' => $quotation->date
            ];

            return response()->json([
                'message' => 'Quotation retrieved successfully',
                'data' => $formattedQuotation
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function getAll(Request $request)
    {
        try {
            $q = $request->query('search');
            $month = $request->query('month');
            $year = $request->query('year');

            $quoatations = $this->getAccessedQuotation($request);
            $quotationsQuery = $quoatations->where(function ($query) use ($q) {
                $query->where('project', 'like', "%$q%")
                    ->orWhere('quotation_number', 'like', "%$q%")
                    ->orWhere('type', 'like', "%$q%");
            });

            if ($year) {
                $quotationsQuery->whereYear('date', $year);
                if ($month) {
                    $monthNumber = date('m', strtotime($month));
                    $quotationsQuery->whereMonth('date', $monthNumber);
                }
            }

            $quotations = $quotationsQuery->orderByRaw("
                REGEXP_REPLACE(quotation_number, '-v[0-9]+$', '') ASC,
                COALESCE(
                    CAST(NULLIF(REGEXP_SUBSTR(quotation_number, '-v([0-9]+)$'), '') AS UNSIGNED),
                    0
                ) ASC
            ")->paginate(20)->through(function ($quotation) {
                $customer = $quotation->customer;
                $spareParts = $quotation->detailQuotations->map(function ($detail) {
                    return [
                        'sparepartName' => $detail->sparepart->sparepart_name ?? '',
                        'sparepartNumber' => $detail->sparepart->sparepart_number ?? '',
                        'quantity' => $detail->quantity ?? 0,
                        'unitPriceSell' => $detail->unit_price ?? 0,
                        'totalPrice' => $detail->quantity * ($detail->unit_price ?? 0),
                        'stock' => $detail->is_indent
                    ];
                });

                return [
                    'id' => (string) $quotation->id,
                    'slug' => $quotation->slug,
                    'customer' => [
                        'companyName' => $customer->company_name ?? '',
                        'address' => $customer->address ?? '',
                        'city' => $customer->city ?? '',
                        'province' => $customer->province ?? '',
                        'office' => $customer->office ?? '',
                        'urban' => $customer->urban ?? '',
                        'subdistrict' => $customer->subdistrict ?? '',
                        'postalCode' => $customer->postal_code ?? ''
                    ],
                    'project' => [
                        'quotationNumber' => $quotation->quotation_number,
                        'type' => $quotation->type,
                        'date' => $quotation->date
                    ],
                    'price' => [
                        'amount' => $quotation->amount,
                        'discount' => $quotation->discount,
                        'subtotal' => $quotation->subtotal,
                        'ppn' => $quotation->ppn,
                        'grandTotal' => $quotation->grand_total
                    ],
                    'current_status' => $quotation->current_status,
                    'status' => json_decode($quotation->status, true) ?? [], // Added status field
                    'notes' => $quotation->notes,
                    'spareparts' => $spareParts
                ];
            });

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
            $quotation = $quoatations->where('slug', $slug)->first();
            $isNeedReview = $quotation->review;
            $isApproved = $quotation->current_status == QuotationController::APPROVE;

            if (!$quotation) {
                return $this->handleNotFound('Quotation not found');
            }

            if ($quotation->purchaseOrder) {
                return response()->json([
                    'message' => 'Quotation already has a purchase order'
                ], Response::HTTP_BAD_REQUEST);
            }

            if (!$isNeedReview || !$isApproved) {
                return response()->json([
                    'message' => 'Quotation need to reviewed or approved first before move to purchase order'
                ], Response::HTTP_BAD_REQUEST);
            }

            $quotation->update(['current_status' => 'PO']);

            // Get the spareparts associated with the quotation
            $spareparts = DB::table('detail_quotations')
                ->where('quotation_id', $quotation->id)
                ->get();

            $purchaseOrder = PurchaseOrder::create([
                'quotation_id' => $quotation->id,
                'purchase_order_number' => 'PO-' . now()->format('YmdHis'),
                'purchase_order_date' => now(),
                'employee_id' => $quotation->employee_id,
                'notes' => $request->input('notes', '') // Use request notes or default to empty string
            ]);

            $backOrder = BackOrder::create([
                'purchase_order_id' => $purchaseOrder->id,
                'back_order_number' => 'PT' . now(),
                'current_status' => 'Pending',
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
                $stockIsNotExistBeforePo = $sparepartTotalUnit <= 0;
                if ($stockIsExistButAfterPoBecomeIndent) {
                    // If sparepart stock exist but become minus after PO then :
                    //      1. The number of BO is total order minus total stock (Need to buy)
                    //      2. The number of DO is total existing stock (Ready)
                    $numberBoInBo = ($sparepartQuantityOrderInPo - $sparepartTotalUnit);
                    $numberDoInBo = $sparepartTotalUnit;
                } elseif ($stockIsNotExistBeforePo) {
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
                if ($numberBoInBo) {
                    $boStatus = 'pending';
                }
                $backOrder->update([
                    'current_status' => $boStatus
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
            if ($isService && !$quotation->workOrder) {
                $user = $request->user();
                $userId = $user->id;

                WorkOrder::create([
                    'quotation_id' => $quotation->id,
                    'work_order_number' => 'WO-' . now()->format('YmdHis'),
                    'received_by' => $userId,
                    'expected_days' => null,
                    'expected_start_date' => null,
                    'expected_end_date' => null,
                    'compiled' => $userId,
                    'start_date' => null,
                    'end_date' => null,
                    'job_descriptions' => null,
                    'worker' => null,
                    'head_of_service' => null,
                    'approver' => $userId,
                    'spareparts' => null,
                    'backup_sparepart' => null,
                    'scope' => null,
                    'vaccine' => null,
                    'apd' => null,
                    'peduli_lindungi' => null,
                    'is_done' => false,
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Quotation moved to purchase order successfully',
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
            $quotationNeedReview = $quoatations->where('review', !$isNeedReview);

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
            $quotation = Quotation::with('customer')
                ->where('employee_id', $userId);

            // Allow director to see all quotation
            if ($role == 'Director') {
                $quotation = Quotation::with('customer');
            }

            // Return the response with transformed data and pagination details
            return $quotation;
        } catch (\Throwable $th) {
            echo ('Error at getAccessedQuotation: ' . $th->getMessage());
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
