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
use App\Models\DetailQuotation;
use App\Models\General;
use Carbon\Carbon;

class QuotationController extends Controller
{
    // Quotation reviewing state in general
    const APPROVE = "Approved";
    const WAITING = "Waiting";
    const REJECTED = "Rejected";
    const PROCESS = "Process";
    const DONE = "Done";

    // Quotation review state
    const NEED_CHANGE = "Change";
    const REVISED = "Revised";
    const ON_REVIEW = "On Review";
    const CANCELLED = "Cancelled";

    // Type of quotation
    const SERVICE = "Service";
    const SPAREPARTS = "Spareparts";

    const ALLOWED_ROLE_TO_CREATE = ['Marketing', 'Director'];

    // Status for whole quotation
    const PO = 'Po';
    const PI = 'Pi';
    const Inventory = 'Inventory';
    const PAID = 'Paid';
    const DP_PAID = 'DP Paid';
    const FULL_PAID = 'Full Paid';
    const READY = "Ready";
    const RELEASE = 'Release';
    const RETURN = 'Return';
    const DECLINED = "Declined";
    const APPROVED = "Approved";

    /**
     * Convert month number to Roman numeral
     *
     * @param int $month
     * @return string
     */
    protected function getRomanMonth($month)
    {
        $romanNumerals = [
            1 => 'I',
            2 => 'II',
            3 => 'III',
            4 => 'IV',
            5 => 'V',
            6 => 'VI',
            7 => 'VII',
            8 => 'VIII',
            9 => 'IX',
            10 => 'X',
            11 => 'XI',
            12 => 'XII'
        ];
        return $romanNumerals[$month] ?? 'I';
    }

    public function store(Request $request)
    {
        // Start a database transaction
        DB::beginTransaction();

        try {
            $role = $request->user()->role;
            $allowed = $this->isAllowedRole($role);

            if (!$allowed) {
                return $this->handleNotFound('You have no access in this action');
            }

            $user = $request->user();
            $userId = $user->id;

            // Validate the request data based on API contract
            $validatedData = $request->validate([
                'project.type' => 'required|string',
                'price.amount' => 'required|numeric',
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

            // Generate quotation_number
            $currentMonth = Carbon::now()->format('m'); // Two-digit month
            $currentYear = Carbon::now()->format('Y'); // Four-digit year
            $latestQuotation = $this->getAccessedQuotation($request)
                ->whereMonth('created_at', $currentMonth)
                ->whereYear('created_at', $currentYear)
                ->latest('id')
                ->first();
            $nextLatestId = $latestQuotation ? $latestQuotation->id + 1 : 1;
            $quotationNumber = "{$nextLatestId}/QUOT/BMJ-MEGAH/{$user->branch}/{$currentMonth}/{$currentYear}";

            // Get the latest discount and PPN from General model
            $general = General::latest()->first();
            $discount = $general ? $general->discount : 0;
            $ppn = $general ? $general->ppn : 0;

            $totalAmount = $request->input('price.amount');
            $priceDiscount = $totalAmount  * $discount;
            $subTotal = $totalAmount - $priceDiscount;
            $pricePpn = $subTotal  * $ppn;
            $grandTotal = $subTotal - $pricePpn;

            // Map API contract to database fields
            $quotationData = [
                'quotation_number' => $quotationNumber,
                'version' => 1, // Set initial version to 1
                'type' => $request->input('project.type'),
                'date' => now(),
                'amount' => $totalAmount,
                'ppn' => $request->input('price.ppn'),
                'grand_total' => $grandTotal,
                'notes' => $request->input('notes'),
                'project' => $quotationNumber, // Using quotationNumber as project name
                'discount' => $priceDiscount,
                'ppn' => $pricePpn,
                'subtotal' => $subTotal,
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
                    $quotationData['current_status'] = QuotationController::ON_REVIEW;
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

            $latestVersion = $this->getAccessedQuotation($request)->where('quotation_number', $quotation->quotation_number)
                ->max('version');

            // Allow update only if this is the latest version
            if ($quotation->version < $latestVersion) {
                return response()->json([
                    'message' => 'Only the latest version can be updated'
                ], Response::HTTP_BAD_REQUEST);
            }

            if ($po) {
                return response()->json([
                    'message' => 'Quotation already in purchase order'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validate the request data
            $validatedData = $request->validate([
                'project.quotationNumber' => 'required|string',
                'project.type' => 'required|string',
                'project.date' => 'required|date',
                'price.amount' => 'required|numeric',
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
                'discount' => 0,
                'subtotal' => 0,
                'ppn' => 0,
                'grand_total' => 0,
                'notes' => $request->input('notes'),
                'project' => $request->input('project.quotationNumber'), // Using quotationNumber as project name
            ];

            // Handle versioning using the version field
            $baseQuotationNumber = $quotation['quotation_number'];
            $existingVersion = Quotation::where('quotation_number', $baseQuotationNumber)
                ->max('version');
            $quotationData['version'] = $existingVersion + 1;

            // Validate the new quotation_number for uniqueness
            $existingQuotation = Quotation::where('quotation_number', $baseQuotationNumber)
                ->where('version', $quotationData['version'])
                ->first();
            if ($existingQuotation) {
                throw new \Exception('Quotation number with this version already exists.');
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
                    $quotationData['current_status'] = QuotationController::ON_REVIEW;
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
            $po = $quotation->purchaseOrder;

            if ($po) {
                return response()->json([
                    'message' => 'Quotation already in purchase order.'
                ], Response::HTTP_BAD_REQUEST);
            }

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
            $po = $quotation->purchaseOrder;

            if ($po) {
                return response()->json([
                    'message' => 'Quotation already in purchase order.'
                ], Response::HTTP_BAD_REQUEST);
            }

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
            $po = $quotation->purchaseOrder;

            if ($po) {
                return response()->json([
                    'message' => 'Quotation already in purchase order.'
                ], Response::HTTP_BAD_REQUEST);
            }

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
                    'sparepart_id' => $detail->sparepart->id ?? '',
                    'sparepart_name' => $detail->sparepart->sparepart_name ?? '',
                    'sparepart_number' => $detail->sparepart->sparepart_number ?? '',
                    'quantity' => $detail->quantity ?? 0,
                    'unit_price_sell' => $detail->unit_price ?? 0,
                    'total_price' => $detail->quantity * ($detail->unit_price ?? 0),
                    'stock' => $detail->is_indent ? 'indent' : 'available'
                ];
            });

            // Get the latest discount and PPN from General model
            $general = General::latest()->first();
            $discount = $general ? $general->discount : 0;
            $ppn = $general ? $general->ppn : 0;

            $formattedQuotation = [
                'id' => (string) $quotation->id,
                'slug' => $quotation->slug,
                'quotation_number' => $quotation->quotation_number,
                'version' => $quotation->version, // Include version
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
                'status' => $quotation->status,
                'notes' => $quotation->notes,
                'discount' => $discount,
                'ppn' => $ppn,
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

            $paginated = $quotationsQuery
                ->orderBy('date', 'DESC')
                ->orderBy('version', 'ASC')
                ->paginate(20);


            $grouped = collect($paginated->items())->map(function ($quotation) {
                $customer = $quotation->customer;
                $spareParts = $quotation->detailQuotations->map(function ($detail) {
                    return [
                        'sparepart_id' => $detail->sparepart->id ?? '',
                        'sparepart_name' => $detail->sparepart->sparepart_name ?? '',
                        'sparepart_number' => $detail->sparepart->sparepart_number ?? '',
                        'quantity' => $detail->quantity ?? 0,
                        'unit_price_sell' => $detail->unit_price ?? 0,
                        'total_price' => $detail->quantity * ($detail->unit_price ?? 0),
                        'stock' => $detail->is_indent ? 'indent' : 'available'
                    ];
                });

                // Get the latest discount and PPN from General model
                $general = General::latest()->first();
                $discount = $general ? $general->discount : 0;
                $ppn = $general ? $general->ppn : 0;


                return [
                    'quotation_number' => $quotation->quotation_number,
                    'version' => $quotation->version,
                    'slug' => $quotation->slug,
                    'current_status' => $quotation->current_status,
                    'status' => $quotation->status,
                    'notes' => $quotation->notes,
                    'discount' => $discount,
                    'ppn' => $ppn,
                    'project' => [
                        'quotation_number' => $quotation->quotation_number,
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
                    'spareparts' => $spareParts
                ];
            })->groupBy('quotation_number')->map(function ($group, $quotationNumber) {
                return [
                    'quotation_number' => $quotationNumber,
                    'versions' => $group->values() // reset index for frontend
                ];
            })->values();

            return response()->json([
                'message' => 'List of all quotations retrieved successfully',
                'data' => [
                    'data' => $grouped,
                    'from' => $paginated->firstItem(),
                    'to' => $paginated->lastItem(),
                    'total' => $paginated->total(),
                    'per_page' => $paginated->perPage(),
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                ]
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function moveToPo(Request $request, $slug)
    {
        DB::beginTransaction();

        try {
            $quotations = $this->getAccessedQuotation($request);
            $quotation = $quotations->where('slug', $slug)->first();
            $isNeedReview = $quotation->review;
            $isApproved = $quotation->current_status == QuotationController::APPROVE;

            $latestVersion = $this->getAccessedQuotation($request)->where('quotation_number', $quotation->quotation_number)
                ->max('version');

            // Allow update only if this is the latest version
            if ($quotation->version < $latestVersion) {
                return response()->json([
                    'message' => 'Only the latest version can be updated',
                ], Response::HTTP_BAD_REQUEST);
            }

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

            $this->changeStatusToPo($request, $quotation);

            // Get the spareparts associated with the quotation
            $spareparts = DB::table('detail_quotations')
                ->where('quotation_id', $quotation->id)
                ->get();

            // Generate purchase order number from quotation number
            try {
                // Expected quotation_number format: 033/BMJ-PI/V/2024
                $parts = explode('/', $quotation->quotation_number);
                $poNumber = $parts[0]; // e.g., 033
                $romanMonth = $parts[2]; // e.g., V
                $year = substr($parts[3], -2); // e.g., 24 from 2024
                $purchaseOrderNumber = "PO-IN/{$poNumber}/{$romanMonth}/{$year}";
            } catch (\Throwable $th) {
                // Fallback to timestamp-based PO number with current month and year
                $currentMonth = now()->month; // e.g., 5 for May
                $romanMonth = $this->getRomanMonth($currentMonth); // e.g., V
                $year = now()->format('y'); // e.g., 25 for 2025
                $timestamp = now()->format('YmdHis'); // Unique identifier
                $purchaseOrderNumber = "PO-IN/{$timestamp}/{$romanMonth}/{$year}";
            }

            $purchaseOrder = PurchaseOrder::create([
                'quotation_id' => $quotation->id,
                'purchase_order_number' => $purchaseOrderNumber,
                'purchase_order_date' => now(),
                'employee_id' => $quotation->employee_id,
                'notes' => $request->input('notes', ''), // Use request notes or default to empty string
                'current_status' => PurchaseOrderController::PREPARE,
            ]);

            $backOrder = BackOrder::create([
                'purchase_order_id' => $purchaseOrder->id,
                'back_order_number' => 'PT' . now(),
                'current_status' => BackOrderController::PROCESS, // Assume BO still need to "Process" at first time, if there is no BO, will update to "Ready".
            ]);

            $hasBoSparepart = false;
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

                // Change current status of PO to BO because we have sparepart BO
                if ($numberBoInBo) {
                    $purchaseOrder->update(['current_status' => PurchaseOrderController::BO]);
                    $hasBoSparepart = true;
                }

                DetailBackOrder::create([
                    'back_order_id' => $backOrder->id,
                    'sparepart_id' => $sparepart->sparepart_id,
                    'number_delivery_order' => $numberDoInBo,
                    'number_back_order' => $numberBoInBo,
                ]);
            }

            // If po that we created has no bo sparepart then make 'backOrder' to 'Ready' and quotation status to Inventory.
            if (!$hasBoSparepart) {
                $backOrder->update(['current_status' => BackOrderController::READY]);
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
            $q = $request->query('search');
            $month = $request->query('month');
            $year = $request->query('year');

            $quoatations = $this->getAccessedQuotation($request);
            $quotationNeedReview = $quoatations->where('review', !$isNeedReview);

            // Apply search filter if provided
            if ($q) {
                $quotationNeedReview->where(function ($query) use ($q) {
                    $query->where('project', 'like', "%$q%")
                        ->orWhere('quotation_number', 'like', "%$q%")
                        ->orWhere('type', 'like', "%$q%");
                });
            }

            // Apply year and month filters if provided
            if ($year) {
                $quotationNeedReview->whereYear('date', $year);
                if ($month) {
                    $monthNumber = date('m', strtotime($month));
                    $quotationNeedReview->whereMonth('date', $monthNumber);
                }
            }

            // Paginate the results
            $quotations = $quotationNeedReview->orderBy('quotation_number', 'ASC')
                ->orderBy('version', 'ASC') // Sort by version
                ->paginate(20)->through(function ($quotation) {
                    $customer = $quotation->customer;
                    $spareParts = $quotation->detailQuotations->map(function ($detail) {
                        return [
                            'sparepart_id' => $detail->sparepart->id ?? '',
                            'sparepart_name' => $detail->sparepart->sparepart_name ?? '',
                            'sparepart_number' => $detail->sparepart->sparepart_number ?? '',
                            'quantity' => $detail->quantity ?? 0,
                            'unit_price_sell' => $detail->unit_price ?? 0,
                            'total_price' => $detail->quantity * ($detail->unit_price ?? 0),
                            'stock' => $detail->is_indent ? 'indent' : 'available'
                        ];
                    });

                    return [
                        'id' => (string) $quotation->id,
                        'slug' => $quotation->slug,
                        'quotation_number' => $quotation->quotation_number,
                        'version' => $quotation->version, // Include version
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
                        'status' => $quotation->status,
                        'notes' => $quotation->notes,
                        'spareparts' => $spareParts,
                        'date' => $quotation->date
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

    public function isNeedReturn(Request $request, $isNeedReturn)
    {
        try {
            $q = $request->query('search');
            $month = $request->query('month');
            $year = $request->query('year');

            $quoatations = $this->getAccessedQuotation($request);
            $quotationNeedReturn = $quoatations->where('is_return', !$isNeedReturn);

            // Apply search filter if provided
            if ($q) {
                $quotationNeedReturn->where(function ($query) use ($q) {
                    $query->where('project', 'like', "%$q%")
                        ->orWhere('quotation_number', 'like', "%$q%")
                        ->orWhere('type', 'like', "%$q%");
                });
            }

            // Apply year and month filters if provided
            if ($year) {
                $quotationNeedReturn->whereYear('date', $year);
                if ($month) {
                    $monthNumber = date('m', strtotime($month));
                    $quotationNeedReturn->whereMonth('date', $monthNumber);
                }
            }

            // Paginate the results
            $quotations = $quotationNeedReturn->orderBy('quotation_number', 'ASC')
                ->orderBy('version', 'ASC') // Sort by version
                ->paginate(20)->through(function ($quotation) {
                    $customer = $quotation->customer;
                    $spareParts = $quotation->detailQuotations->map(function ($detail) {
                        return [
                            'sparepart_id' => $detail->sparepart->id ?? '',
                            'sparepart_name' => $detail->sparepart->sparepart_name ?? '',
                            'sparepart_number' => $detail->sparepart->sparepart_number ?? '',
                            'quantity' => $detail->quantity ?? 0,
                            'unit_price_sell' => $detail->unit_price ?? 0,
                            'is_return' => $detail->is_return ?? 0,
                            'total_price' => $detail->quantity * ($detail->unit_price ?? 0),
                            'stock' => $detail->is_indent ? 'indent' : 'available'
                        ];
                    });

                    return [
                        'id' => (string) $quotation->id,
                        'slug' => $quotation->slug,
                        'quotation_number' => $quotation->quotation_number,
                        'version' => $quotation->version, // Include version
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
                        'status' => $quotation->status,
                        'notes' => $quotation->notes,
                        'spareparts' => $spareParts,
                        'date' => $quotation->date
                    ];
                });

            // Return the response with transformed data and pagination details
            return response()->json([
                'message' => $isNeedReturn ? 'List of all quotations that need to be returned' : 'List of all quotations that do not need to be returned',
                'data' => $quotations,
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    // Functions to change quotation state in general
    public function changeStatusToPo(Request $request, $quotation)
    {
        // Start a database transaction
        DB::beginTransaction();

        try {
            if (!$quotation) {
                return $this->handleNotFound('Quotation not found');
            }

            // Only allow Director or Marketing to change status to Po
            $user = $request->user();
            // Ensure status is initialized as an array
            $currentStatus = $quotation->status ?? [];
            if (!is_array($currentStatus)) {
                $currentStatus = [];
            }

            // Append new status entry
            $currentStatus[] = [
                'state' => self::PO,
                'employee' => $user->username,
                'timestamp' => now()->toIso8601String(),
            ];

            // Update quotation with new status and current_status
            $quotation->update([
                'status' => $currentStatus,
                'current_status' => self::PO
            ]);

            // Commit the transaction
            DB::commit();

            // Return the response with transformed data
            return response()->json([
                'message' => 'Success update status of the quotation to Po',
                'data' => $quotation,
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            // Roll back the transaction if an error occurs
            DB::rollBack();
            return $this->handleError($th, 'Failed to update quotation status to Po');
        }
    }

    public function changeStatusToPi(Request $request, $quotation)
    {
        // Start a database transaction
        DB::beginTransaction();

        try {
            if (!$quotation) {
                return $this->handleNotFound('Quotation not found');
            }

            $user = $request->user();
            // Ensure status is initialized as an array
            $currentStatus = $quotation->status ?? [];
            if (!is_array($currentStatus)) {
                $currentStatus = [];
            }

            // Append new status entry
            $currentStatus[] = [
                'state' => self::PI,
                'employee' => $user->username,
                'timestamp' => now()->toIso8601String(),
            ];

            // Update quotation with new status and current_status
            $quotation->update([
                'status' => $currentStatus,
                'current_status' => self::PI
            ]);

            // Commit the transaction
            DB::commit();

            // Return the response with transformed data
            return response()->json([
                'message' => 'Success update status of the quotation to Pi',
                'data' => $quotation,
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            // Roll back the transaction if an error occurs
            DB::rollBack();
            return $this->handleError($th, 'Failed to update quotation status to Pi');
        }
    }

    public function changeStatusToInventory(Request $request, $quotation)
    {
        // Start a database transaction
        DB::beginTransaction();

        try {
            if (!$quotation) {
                return $this->handleNotFound('Quotation not found');
            }

            $user = $request->user();
            // Ensure status is initialized as an array
            $currentStatus = $quotation->status ?? [];
            if (!is_array($currentStatus)) {
                $currentStatus = [];
            }

            // Append new status entry
            $currentStatus[] = [
                'state' => self::Inventory,
                'employee' => $user->username,
                'timestamp' => now()->toIso8601String(),
            ];

            // Update quotation with new status and current_status
            $quotation->update([
                'status' => $currentStatus,
                'current_status' => self::Inventory
            ]);

            // Commit the transaction
            DB::commit();

            // Return the response with transformed data
            return response()->json([
                'message' => 'Success update status of the quotation to Inventory',
                'data' => $quotation,
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            // Roll back the transaction if an error occurs
            DB::rollBack();
            return $this->handleError($th, 'Failed to update quotation status to Inventory');
        }
    }


    public function changeStatusToReady(Request $request, $quotation)
    {
        // Start a database transaction
        DB::beginTransaction();

        try {
            if (!$quotation) {
                return $this->handleNotFound('Quotation not exist');
            }

            $user = $request->user();
            // Ensure status is initialized as an array
            $status = $quotation->status ?? [];
            if (!is_array($status)) {
                $status = [];
            }

            // Append new status entry for decline
            $status[] = [
                'state' => self::READY,
                'employee' => $user->username,
                'timestamp' => now()->toIso8601String(),
            ];

            // Update quotation with new status and current_status
            $quotation->update([
                'status' => $status,
                'current_status' => self::READY
            ]);

            // Commit the transaction
            DB::commit();

            // Return the response with transformed data
            return response()->json([
                'message' => 'Success update status ready for the quotation',
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            // Roll back the transaction if an error occurs
            DB::rollBack();
            return $this->handleError($th, 'Failed to update status ready for the quotation');
        }
    }


    public function changeStatusToPaid(Request $request, $quotation, $isDpPaid)
    {
        // Start a database transaction
        DB::beginTransaction();

        try {
            if (!$quotation) {
                return $this->handleNotFound('Quotation not found');
            }

            $user = $request->user();
            // Ensure status is initialized as an array
            $currentStatus = $quotation->status ?? [];
            if (!is_array($currentStatus)) {
                $currentStatus = [];
            }

            // Append new status entry
            $currentStatus[] = [
                'state' => $isDpPaid ? self::DP_PAID : self::FULL_PAID,
                'employee' => $user->username,
                'timestamp' => now()->toIso8601String(),
            ];

            // Update quotation with new status and current_status
            $quotation->update([
                'status' => $currentStatus,
                'current_status' => $isDpPaid ? self::DP_PAID : self::FULL_PAID,
            ]);

            // Commit the transaction
            DB::commit();

            // Return the response with transformed data
            return response()->json([
                'message' => 'Success update status of the quotation to Paid',
                'data' => $quotation,
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            // Roll back the transaction if an error occurs
            DB::rollBack();
            return $this->handleError($th, 'Failed to update quotation status to Paid');
        }
    }

    public function changeStatusToRelease(Request $request, $quotation)
    {
        // Start a database transaction
        DB::beginTransaction();

        try {
            if (!$quotation) {
                return $this->handleNotFound('Quotation not found');
            }

            $user = $request->user();
            // Ensure status is initialized as an array
            $currentStatus = $quotation->status ?? [];
            if (!is_array($currentStatus)) {
                $currentStatus = [];
            }

            // Append new status entry
            $currentStatus[] = [
                'state' => self::RELEASE,
                'employee' => $user->username,
                'timestamp' => now()->toIso8601String(),
            ];

            // Update quotation with new status and current_status
            $quotation->update([
                'status' => $currentStatus,
                'current_status' => self::RELEASE,
            ]);

            // Commit the transaction
            DB::commit();

            // Return the response with transformed data
            return response()->json([
                'message' => 'Success update status of the quotation to Release',
                'data' => $quotation,
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            // Roll back the transaction if an error occurs
            DB::rollBack();
            return $this->handleError($th, 'Failed to update quotation status to Release');
        }
    }
    public function changeStatusToDone(Request $request, $quotation)
    {
        // Start a database transaction
        DB::beginTransaction();

        try {
            if (!$quotation) {
                return $this->handleNotFound('Quotation not found');
            }

            $user = $request->user();
            // Ensure status is initialized as an array
            $currentStatus = $quotation->status ?? [];
            if (!is_array($currentStatus)) {
                $currentStatus = [];
            }

            // Append new status entry
            $currentStatus[] = [
                'state' => self::DONE,
                'employee' => $user->username,
                'timestamp' => now()->toIso8601String(),
            ];

            // Update quotation with new status and current_status
            $quotation->update([
                'status' => $currentStatus,
                'current_status' => self::DONE,
            ]);

            // Commit the transaction
            DB::commit();

            // Return the response with transformed data
            return response()->json([
                'message' => 'Success update status of the quotation to Done',
                'data' => $quotation,
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            // Roll back the transaction if an error occurs
            DB::rollBack();
            return $this->handleError($th, 'Failed to update quotation status to Done');
        }
    }

    public function changeStatusToReturn(Request $request, $slug)
    {
        // Start a database transaction
        DB::beginTransaction();

        try {
            // Validate the returned parameter
            $request->validate([
                'returned' => 'required|array',
                'returned.*' => 'integer|exists:spareparts,id',
            ]);

            $quotations = $this->getAccessedQuotation($request);
            $quotation = $quotations->where('slug', $slug)->firstOrFail();

            if (!$quotation) {
                return $this->handleNotFound('Quotation not found');
            }

            // Check if quotation is already in return state and has any returned spare parts
            $inReturnState = $quotation->is_return;
            $alreadyHaveReturnedSparepart = DetailQuotation::where('quotation_id', $quotation->id)->where('is_return', true)->exists();
            if ($inReturnState && $alreadyHaveReturnedSparepart) {
                return response()->json([
                    'message' => 'Cannot change to Return state: Quotation is already in return state with returned spare parts',
                ], Response::HTTP_BAD_REQUEST);
            }

            // Update DetailQuotation entries for the specified sparepart_ids
            $returnedSparepartIds = $request->input('returned', []);
            if (!empty($returnedSparepartIds)) {
                DetailQuotation::where('quotation_id', $quotation->id)
                    ->whereIn('sparepart_id', $returnedSparepartIds)
                    ->update(['is_return' => true]);
            }

            $user = $request->user();
            // Ensure status is initialized as an array
            $currentStatus = $quotation->status ?? [];
            if (!is_array($currentStatus)) {
                $currentStatus = [];
            }

            // Append new status entry
            $currentStatus[] = [
                'state' => self::RETURN,
                'employee' => $user->username,
                'timestamp' => now()->toIso8601String(),
            ];

            // Update quotation with new status and is_return flag
            $quotation->update([
                'review' => false,
                'status' => $currentStatus,
                'current_status' => self::DONE,
                'is_return' => !empty($returnedSparepartIds), // Set is_return to true if any spare parts are returned
            ]);

            // Format the quotation data
            $customer = $quotation->customer;
            $spareParts = $quotation->detailQuotations->map(function ($detail) {
                return [
                    'sparepart_id' => $detail->sparepart->id ?? '',
                    'sparepart_name' => $detail->sparepart->sparepart_name ?? '',
                    'sparepart_number' => $detail->sparepart->sparepart_number ?? '',
                    'quantity' => $detail->quantity ?? 0,
                    'unit_price_sell' => $detail->unit_price ?? 0,
                    'is_return' => $detail->is_return ?? 0,
                    'total_price' => $detail->quantity * ($detail->unit_price ?? 0),
                    'stock' => $detail->is_indent ? 'indent' : 'available'
                ];
            });

            $formattedQuotation = [
                'id' => (string) $quotation->id,
                'slug' => $quotation->slug,
                'quotation_number' => $quotation->quotation_number,
                'version' => $quotation->version, // Include version
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
                'status' => $quotation->status,
                'notes' => $quotation->notes,
                'spareparts' => $spareParts,
                'date' => $quotation->date
            ];

            // Commit the transaction
            DB::commit();

            // Return the response with transformed data
            return response()->json([
                'message' => 'Success update status of the quotation to Return',
                'data' => $formattedQuotation,
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            // Roll back the transaction if an error occurs
            DB::rollBack();
            return $this->handleError($th, 'Failed to update quotation status to Return');
        }
    }

    public function declineReturn(Request $request, $slug)
    {
        // Start a database transaction
        DB::beginTransaction();

        try {
            $quotations = $this->getAccessedQuotation($request);
            $quotation = $quotations->where('slug', $slug)->firstOrFail();

            if (!$quotation) {
                return $this->handleNotFound('Quotation not found');
            }

            // Check if the quotation is in a return state
            if (!$quotation->is_return) {
                return response()->json([
                    'message' => 'Quotation is not in a return state',
                ], Response::HTTP_BAD_REQUEST);
            }

            $user = $request->user();
            // Ensure status is initialized as an array
            $status = $quotation->status ?? [];
            if (!is_array($status)) {
                $status = [];
            }

            // Append new status entry for decline
            $status[] = [
                'state' => self::DECLINED,
                'employee' => $user->username,
                'timestamp' => now()->toIso8601String(),
            ];

            // Update quotation with new status, review, and is_return
            $quotation->update([
                'status' => $status,
                'review' => true,
                'current_status' => self::DECLINED,
                'is_return' => false,
            ]);

            // Reset is_return in DetailQuotation entries for this quotation
            DetailQuotation::where('quotation_id', $quotation->id)
                ->update(['is_return' => false]);

            // Format the quotation data
            $customer = $quotation->customer;
            $spareParts = $quotation->detailQuotations->map(function ($detail) {
                return [
                    'sparepart_id' => $detail->sparepart->id ?? '',
                    'sparepart_name' => $detail->sparepart->sparepart_name ?? '',
                    'sparepart_number' => $detail->sparepart->sparepart_number ?? '',
                    'quantity' => $detail->quantity ?? 0,
                    'unit_price_sell' => $detail->unit_price ?? 0,
                    'is_return' => $detail->is_return ?? 0,
                    'total_price' => $detail->quantity * ($detail->unit_price ?? 0),
                    'stock' => $detail->is_indent ? 'indent' : 'available'
                ];
            });

            $formattedQuotation = [
                'id' => (string) $quotation->id,
                'slug' => $quotation->slug,
                'quotation_number' => $quotation->quotation_number,
                'version' => $quotation->version, // Include version
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
                'status' => $quotation->status,
                'notes' => $quotation->notes,
                'spareparts' => $spareParts,
                'date' => $quotation->date
            ];

            // Commit the transaction
            DB::commit();

            // Return the response with transformed data
            return response()->json([
                'message' => 'Success decline return process for the quotation',
                'data' => $formattedQuotation,
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            // Roll back the transaction if an error occurs
            DB::rollBack();
            return $this->handleError($th, 'Failed to decline return process for the quotation');
        }
    }

    public function approveReturn(Request $request, $slug)
    {
        // Start a database transaction
        DB::beginTransaction();

        try {
            $quotations = $this->getAccessedQuotation($request);
            $quotation = $quotations->where('slug', $slug)->firstOrFail();

            if (!$quotation) {
                return $this->handleNotFound('Quotation not found');
            }

            // Check if the quotation is in a return state
            if (!$quotation->is_return) {
                return response()->json([
                    'message' => 'Quotation is not in a return state',
                ], Response::HTTP_BAD_REQUEST);
            }

            $user = $request->user();
            // Ensure status is initialized as an array
            $status = $quotation->status ?? [];
            if (!is_array($status)) {
                $status = [];
            }

            // Append new status entry for approval
            $status[] = [
                'state' => self::APPROVED,
                'employee' => $user->username,
                'timestamp' => now()->toIso8601String(),
            ];

            // Update quotation with new status, review, and is_return
            $quotation->update([
                'status' => $status,
                'review' => true, // Mark review as true to indicate return process already reviewed
                'current_status' => self::APPROVED, // Update current_status to Approved
            ]);

            // Format the quotation data
            $customer = $quotation->customer;
            $spareParts = $quotation->detailQuotations->map(function ($detail) {
                return [
                    'sparepart_id' => $detail->sparepart->id ?? '',
                    'sparepart_name' => $detail->sparepart->sparepart_name ?? '',
                    'sparepart_number' => $detail->sparepart->sparepart_number ?? '',
                    'quantity' => $detail->quantity ?? 0,
                    'unit_price_sell' => $detail->unit_price ?? 0,
                    'is_return' => $detail->is_return ?? 0,
                    'total_price' => $detail->quantity * ($detail->unit_price ?? 0),
                    'stock' => $detail->is_indent ? 'indent' : 'available'
                ];
            });

            $formattedQuotation = [
                'id' => (string) $quotation->id,
                'slug' => $quotation->slug,
                'quotation_number' => $quotation->quotation_number,
                'version' => $quotation->version,
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
                'status' => $status,
                'notes' => $quotation->notes,
                'spareparts' => $spareParts,
                'date' => $quotation->date
            ];

            // Commit the transaction
            DB::commit();

            // Return a success response with transformed data
            return response()->json([
                'message' => 'Success approve return process for the quotation',
                'data' => $formattedQuotation,
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            // Roll back the transaction if an error occurs
            DB::rollBack();
            return $this->handleError($th, 'Failed to approve return process for the quotation');
        }
    }
    // Helper function to secure special access for function in this class
    protected function isAllowedRole($role)
    {
        try {
            $allowed = in_array($role, QuotationController::ALLOWED_ROLE_TO_CREATE);

            // Return the response with transformed data and pagination details
            return $allowed;
        } catch (\Throwable $th) {
            echo ('Error at getAccessedQuotation: ' . $th->getMessage());
            return [];
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
