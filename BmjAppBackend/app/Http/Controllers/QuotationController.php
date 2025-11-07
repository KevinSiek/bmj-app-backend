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
use App\Models\Branch;
use App\Services\SparepartStockService;

class QuotationController extends Controller
{
    protected SparepartStockService $stockService;

    public function __construct(SparepartStockService $stockService)
    {
        $this->stockService = $stockService;
    }

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
                'project.type' => 'required|string|in:' . self::SERVICE . ',' . self::SPAREPARTS,
                'price.amount' => 'required|numeric',
                'project.branch' => 'sometimes|string',
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
                // Sparepart or Service validation based on type
                'spareparts' => 'required_if:project.type,' . self::SPAREPARTS . '|array',
                'spareparts.*.sparepartId' => 'required_if:project.type,' . self::SPAREPARTS . '|exists:spareparts,id',
                'spareparts.*.quantity' => 'required_if:project.type,' . self::SPAREPARTS . '|integer|min:1',
                'spareparts.*.unitPriceSell' => 'required_if:project.type,' . self::SPAREPARTS . '|numeric|min:1',
                'services' => 'required_if:project.type,' . self::SERVICE . '|array',
                'services.*.service' => 'required_if:project.type,' . self::SERVICE . '|string',
                'services.*.quantity' => 'required_if:project.type,' . self::SERVICE . '|integer|min:1',
                'services.*.unitPriceSell' => 'required_if:project.type,' . self::SERVICE . '|numeric|min:1',
            ]);

            // Generate quotation_number
            $quotationNumber = '';
            $currentMonth = Carbon::now()->month; // e.g., 7 for July
            $romanMonth = $this->getRomanMonth($currentMonth); // e.g., VII
            $currentYear = Carbon::now()->format('Y'); // Four-digit year
            $branchIdentifier = $request->input('project.branch', $user->branch);
            $branchModel = $this->resolveBranchModel($branchIdentifier);

            if (!$branchModel) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Branch not found. Please provide a valid branch name or code.',
                ], Response::HTTP_BAD_REQUEST);
            }

            $branchCode = $branchModel->code;
            $branchId = $branchModel->id;
            $userId = $user->id;
            // Use the stored 'date' field (not 'created_at') when checking month/year
            // so the quotation numeric sequence resets correctly each month.
            $latestQuotation = $this->getAllWithoutPermission($request)
                ->whereMonth('date', $currentMonth)
                ->whereYear('date', $currentYear)
                ->latest('id')
                ->lockForUpdate() // Lock to prevent race condition on number generation
                ->first();
            if (!$latestQuotation) {
                // No quotations found for the current month and year, start from 1
                $quotationNumber = "QUOT/1/BMJ-MEGAH/{$branchCode}/{$userId}/{$romanMonth}/{$currentYear}";
            } else {
                $parts = explode('/', $latestQuotation->quotation_number);
                $nextLatestQuotationNumber = $parts[1] + 1;
                $quotationNumber = "QUOT/{$nextLatestQuotationNumber}/BMJ-MEGAH/{$branchCode}/{$userId}/{$romanMonth}/{$currentYear}";
            }

            // Get the latest discount and PPN from General model
            $general = General::latest()->first();
            $discount = $general ? $general->discount : 0;
            $ppn = $general ? $general->ppn : 0;

            $totalAmount = $request->input('price.amount');

            // Map API contract to database fields
            $quotationData = [
                'quotation_number' => $quotationNumber,
                'version' => 1, // Set initial version to 1
                'type' => $request->input('project.type'),
                'date' => now(),
                'amount' => $totalAmount,
                'grand_total' => 0,
                'notes' => $request->input('notes'),
                'project' => $quotationNumber, // Using quotationNumber as project name
                'discount' => 0,
                'ppn' => 0,
                'subtotal' => 0,
                'employee_id' => $userId,
                'branch_id' => $branchId,
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

            // Prevent an employee from creating a quotation for a customer handled by another employee.
            // Directors are exempt from this rule.
            if ($user->role !== 'Director') {
                $existingQuotation = Quotation::where('customer_id', $customer->id)
                    ->latest('created_at') // Get the most recent quotation for this customer
                    ->first();

                // If a quotation exists and it belongs to a different employee, block the creation.
                if ($existingQuotation && $existingQuotation->employee_id !== $userId) {
                    $handlingEmployee = $existingQuotation->employee;
                    $employeeName = $handlingEmployee ? $handlingEmployee->username : 'another employee';
                    return response()->json([
                        'message' => 'This customer is already being handled by ' . $employeeName . '. Please contact them for assistance.',
                    ], Response::HTTP_FORBIDDEN);
                }
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

            // Handle Spareparts or Services based on type
            if ($quotationData['type'] === self::SPAREPARTS) {
                // To count the actual price - price need to pay (after discount if yes)
                $totalNormalPriceSparepart = 0;
                $totalPaidPriceSparepart = 0;

                foreach ($request->input('spareparts', []) as $sparepart) {
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

                    // Store the total of actual what need to pay and normal price
                    $totalNormalPriceSparepart  += $sparepartDbUnitPriceSell;
                    $totalPaidPriceSparepart  += $sparepartUnitPrice;
                    $totalPriceAfterDiscount = $sparepartUnitPrice - ($sparepartUnitPrice * $discount);

                    // Check if the total sparepart price is below the maximum discount allowed
                    if ($sparepartUnitPrice < $totalPriceAfterDiscount) {
                        $quotationData['review'] = false;
                        $quotationData['current_status'] = QuotationController::ON_REVIEW;
                        $quotation->update($quotationData);
                    }
                    // Determine if current sparepart quantity is exist or not.
                    $sparepart['is_indent'] = false;
                    $availableStock = $this->stockService->getQuantity($sparepartDbData, $branchId);
                    if ($quantityOrderSparepart > $availableStock) {
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
                // Calculate the total of actual what need to pay and normal price
                // $lowestNormalPriceAfterDiscount = $totalNormalPriceSparepart - $totalNormalPriceSparepart;
                // if ($totalPaidPriceSparepart < $lowestNormalPriceAfterDiscount) {
                //     $dicountInPercentage = $discount * 100;
                //     return response()->json([
                //         'message' => "The total price is bellow the limit the maximun total discount is {$dicountInPercentage}%. Please check again"
                //     ], Response::HTTP_BAD_REQUEST);
                // }
                $priceDiscount = $totalNormalPriceSparepart - $totalPaidPriceSparepart;
                $subTotal = $totalAmount - $priceDiscount;
                $pricePpn = $subTotal  * $ppn;
                $grandTotal = $subTotal + $pricePpn;
                $quotation->update([
                    'grand_total' => $grandTotal,
                    'discount' => $priceDiscount,
                    'ppn' => $pricePpn,
                    'subtotal' => $subTotal,
                ]);
            } elseif ($quotationData['type'] === self::SERVICE) {
                foreach ($request->input('services', []) as $service) {
                    $serviceValidator = Validator::make($service, [
                        'service' => 'required|string',
                        'quantity' => 'required|integer|min:1',
                        'unitPriceSell' => 'required|numeric|min:1',
                    ]);

                    if ($serviceValidator->fails()) {
                        throw new \Exception('Invalid service data: ' . $serviceValidator->errors()->first());
                    }

                    DB::table('detail_quotations')->insert([
                        'quotation_id' => $quotation->id,
                        'service' => $service['service'],
                        'unit_price' => $service['unitPriceSell'],
                        'quantity' => $service['quantity'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                $subTotal = $totalAmount;
                $pricePpn = $subTotal  * $ppn;
                $grandTotal = $subTotal + $pricePpn;
                $quotation->update([
                    'grand_total' => $grandTotal,
                    'discount' => 0,
                    'ppn' => $pricePpn,
                    'subtotal' => $subTotal,
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
            // Find the quotation by slug and lock it for update
            $quotations = $this->getAccessedQuotation($request);
            $quotation = $quotations->where('slug', $slug)->lockForUpdate()->firstOrFail();
            $po = $quotation->purchaseOrder->first();

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
                'project.type' => 'required|string|in:' . self::SERVICE . ',' . self::SPAREPARTS,
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
                // Sparepart or Service validation based on type
                'spareparts' => 'required_if:project.type,' . self::SPAREPARTS . '|array',
                'spareparts.*.sparepartId' => 'required_if:project.type,' . self::SPAREPARTS . '|exists:spareparts,id',
                'spareparts.*.quantity' => 'required_if:project.type,' . self::SPAREPARTS . '|integer|min:1',
                'spareparts.*.unitPriceSell' => 'required_if:project.type,' . self::SPAREPARTS . '|numeric|min:1',
                'services' => 'required_if:project.type,' . self::SERVICE . '|array',
                'services.*.service' => 'required_if:project.type,' . self::SERVICE . '|string',
                'services.*.quantity' => 'required_if:project.type,' . self::SERVICE . '|integer|min:1',
                'services.*.unitPriceSell' => 'required_if:project.type,' . self::SERVICE . '|numeric|min:1',
            ]);

            // Get the latest discount and PPN from General model
            $general = General::latest()->first();
            $discount = $general ? $general->discount : 0;
            $ppn = $general ? $general->ppn : 0;

            $totalAmount = $request->input('price.amount');

            // Ensure we have user and branch identifiers for later logic
            $user = $request->user();
            $userId = $user->id;

            // Prefer branch from request if provided; otherwise fall back to quotation's branch
            $branchId = null;
            if ($request->filled('project.branch')) {
                $branchModel = $this->resolveBranchModel($request->input('project.branch'));
                if ($branchModel) {
                    $branchId = $branchModel->id;
                }
            }
            if (!$branchId) {
                $branchId = $this->ensureQuotationBranchId($quotation);
            }

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
                'employee_id' => $userId,
                'branch_id' => $branchId,
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

            // Handle Spareparts or Services based on type
            if ($quotationData['type'] === self::SPAREPARTS) {
                // To count the actual price - price need to pay (after discount if yes)
                $totalNormalPriceSparepart = 0;
                $totalPaidPriceSparepart = 0;

                foreach ($request->input('spareparts', []) as $sparepart) {
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

                    // Store the total of actual what need to pay and normal price
                    $totalNormalPriceSparepart  += $sparepartDbUnitPriceSell;
                    $totalPaidPriceSparepart  += $sparepartUnitPrice;
                    $totalPriceAfterDiscount = $sparepartUnitPrice - ($sparepartUnitPrice * $discount);

                    if ($sparepartUnitPrice < $totalPriceAfterDiscount) {
                        $quotationData['review'] = false;
                        $quotationData['current_status'] = QuotationController::ON_REVIEW;
                        $newQuotation->update($quotationData);
                    }

                    // Determine if current sparepart quantity is exist or not.
                    $sparepart['is_indent'] = false;
                    $availableStock = $this->stockService->getQuantity($sparepartDbData, $branchId);
                    if ($sparepart['quantity'] > $availableStock) {
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
                // Calculate the total of actual what need to pay and normal price
                // $lowestNormalPriceAfterDiscount = $totalNormalPriceSparepart - $totalNormalPriceSparepart  *  $discount;
                // if ($totalPaidPriceSparepart < $lowestNormalPriceAfterDiscount) {
                //     $dicountInPercentage = $discount * 100;
                //     return response()->json([
                //         'message' => "The total price is bellow the limit the maximun total discount is {$dicountInPercentage}%. Please check again"
                //     ], Response::HTTP_BAD_REQUEST);
                // }
                $priceDiscount = $totalNormalPriceSparepart - $totalPaidPriceSparepart;
                $subTotal = $totalAmount - $priceDiscount;
                $pricePpn = $subTotal  * $ppn;
                $grandTotal = $subTotal + $pricePpn;
                $newQuotation->update([
                    'grand_total' => $grandTotal,
                    'discount' => $priceDiscount,
                    'ppn' => $pricePpn,
                    'subtotal' => $subTotal,
                ]);
            } elseif ($quotationData['type'] === self::SERVICE) {
                foreach ($request->input('services', []) as $service) {
                    $serviceValidator = Validator::make($service, [
                        'service' => 'required|string',
                        'quantity' => 'required|integer|min:1',
                        'unitPriceSell' => 'required|numeric|min:1',
                    ]);

                    if ($serviceValidator->fails()) {
                        throw new \Exception('Invalid service data: ' . $serviceValidator->errors()->first());
                    }

                    DB::table('detail_quotations')->insert([
                        'quotation_id' => $newQuotation->id,
                        'service' => $service['service'],
                        'quantity' => $service['quantity'],
                        'unit_price' => $service['unitPriceSell'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                $subTotal = $totalAmount;
                $pricePpn = $subTotal  * $ppn;
                $grandTotal = $subTotal + $pricePpn;
                $newQuotation->update([
                    'grand_total' => $grandTotal,
                    'discount' => 0,
                    'ppn' => $pricePpn,
                    'subtotal' => $subTotal,
                ]);
            }

            // Change all need review quotation to be Changed
            $existingQuotationOnReviews = Quotation::where('quotation_number', $baseQuotationNumber)
                ->where('review', false)
                ->where('version', '<', $quotationData['version'])
                ->get();

            foreach ($existingQuotationOnReviews as $quotationOnReview) {
                $quotationOnReview->review = true;
                $quotationOnReview->current_status = QuotationController::NEED_CHANGE;
                $quotationOnReview->save();
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
            // Retrieve the quotation and lock it for update
            $quoatations = $this->getAccessedQuotation($request);
            $quotation = $quoatations->where('slug', $slug)->lockForUpdate()->first();
            $po = $quotation->purchaseOrder->first();

            if ($po) {
                return response()->json([
                    'message' => 'Quotation already in purchase order.'
                ], Response::HTTP_BAD_REQUEST);
            }

            if (!$quotation) {
                return $this->handleNotFound('Quotation not found');
            }

            $quotation->review = false; // Make it false, because it need to be review again
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
            // Retrieve the quotation and lock it for update
            $quoatations = $this->getAccessedQuotation($request);
            $quotation = $quoatations->where('slug', $slug)->lockForUpdate()->first();
            $po = $quotation->purchaseOrder->first();

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
            // Retrieve the quotation and lock it for update
            $quoatations = $this->getAccessedQuotation($request);
            $quotation = $quoatations->where('slug', $slug)->lockForUpdate()->first();
            $po = $quotation->purchaseOrder->first();

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
            $spareParts = [];
            $services = [];
            if ($quotation && $quotation->detailQuotations) {
                foreach ($quotation->detailQuotations as $detail) {
                    if ($detail->sparepart_id) {
                        $sparepart = $detail->sparepart;
                        $spareParts[] = [
                            'sparepart_id' => $sparepart ? $sparepart->id : '',
                            'sparepart_name' => $sparepart ? $sparepart->sparepart_name : '',
                            'sparepart_number' => $sparepart ? $sparepart->sparepart_number : '',
                            'quantity' => $detail->quantity ?? 0,
                            'unit_price_sell' => $detail->unit_price ?? 0,
                            'total_price' => ($detail->quantity * ($detail->unit_price ?? 0)),
                            'stock' => $detail->is_indent ? 'indent' : 'available'
                        ];
                    } else {
                        $services[] = [
                            'service' => $detail->service ?? '',
                            'unit_price_sell' => $detail->unit_price ?? 0,
                            'quantity' => $detail->quantity ?? 0,
                            'total_price' => ($detail->quantity * ($detail->unit_price ?? 0))
                        ];
                    }
                }
            }

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
                    'amount' => $quotation->amount,
                    'discount' => $quotation->discount,
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
                'services' => $services,
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
            // Build a base query with access rules and filters. We'll use this to derive
            // a distinct set of quotation groups (one representative id per quotation_number)
            // and paginate those groups in a stable manner.
            $baseBuilder = $this->getAccessedQuotation($request);

            // Apply the same search filter as before
            if ($q) {
                $baseBuilder->where(function ($query) use ($q) {
                    $query->where('project', 'like', "%$q%")
                        ->orWhere('quotation_number', 'like', "%$q%")
                        ->orWhere('type', 'like', "%$q%");
                });
            }

            // Exclude quotations that have a purchaseOrder with version > 1 (same logic as original)
            $baseBuilder->where(function ($query) {
                $query->whereDoesntHave('purchaseOrder', function ($subQuery) {
                    $subQuery->where('version', '>', 1);
                })->orWhereDoesntHave('purchaseOrder');
            });

            if ($year) {
                $baseBuilder->whereYear('date', $year);
                if ($month) {
                    $monthNumber = date('m', strtotime($month));
                    $baseBuilder->whereMonth('date', $monthNumber);
                }
            }

            // Create a grouped query that selects the maximum id for each quotation_number.
            // Using MAX(id) ensures we pick a single representative quotation row for each group
            // (commonly the most recent row), which makes pagination stable and consistent
            // with ordering by the numeric part of quotation_number.
            $grouped = (clone $baseBuilder)
                ->getQuery() // switch to base query so aggregate/select works reliably
                ->select('quotation_number', DB::raw('MAX(id) as max_id'))
                ->groupBy('quotation_number')
                ->orderByRaw('CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(quotation_number, \'/\', 2), \'/\', -1) AS UNSIGNED) DESC');

            // Paginate the grouped results (one row per quotation_number)
            $paginatedGroups = DB::table(DB::raw("({$grouped->toSql()}) as grouped"))
                ->mergeBindings($grouped)
                ->select('quotation_number', 'max_id')
                ->paginate(20);

            // If there are no groups, return empty result
            $groupIds = $paginatedGroups->pluck('max_id')->filter()->all();

            if (empty($groupIds)) {
                return response()->json([
                    'message' => 'List of all quotations retrieved successfully',
                    'data' => [
                        'data' => [],
                        'from' => $paginatedGroups->firstItem(),
                        'to' => $paginatedGroups->lastItem(),
                        'total' => $paginatedGroups->total(),
                        'per_page' => $paginatedGroups->perPage(),
                        'current_page' => $paginatedGroups->currentPage(),
                        'last_page' => $paginatedGroups->lastPage(),
                    ]
                ], Response::HTTP_OK);
            }

            // Instead of fetching only representative ids, fetch all versions for the
            // quotation_numbers returned by the paginated groups. This preserves the
            // UI expectation that a quotation_number can appear multiple times (different
            // versions) while keeping pagination stable (one group per page item).
            $groupQuotationNumbers = $paginatedGroups->pluck('quotation_number')->filter()->all();

            if (empty($groupQuotationNumbers)) {
                return response()->json([
                    'message' => 'List of all quotations retrieved successfully',
                    'data' => [
                        'data' => [],
                        'from' => $paginatedGroups->firstItem(),
                        'to' => $paginatedGroups->lastItem(),
                        'total' => $paginatedGroups->total(),
                        'per_page' => $paginatedGroups->perPage(),
                        'current_page' => $paginatedGroups->currentPage(),
                        'last_page' => $paginatedGroups->lastPage(),
                    ]
                ], Response::HTTP_OK);
            }

            // Preserve the ordering of groups (quotation_number order) using FIELD,
            // and within each group order by version ASC so versions appear in sequence.
            $orderedQNumbers = implode(',', array_map(function ($s) {
                return "'" . addslashes($s) . "'";
            }, $groupQuotationNumbers));

            $quotations = Quotation::with('customer')
                ->whereIn('quotation_number', $groupQuotationNumbers)
                ->orderByRaw("FIELD(quotation_number, {$orderedQNumbers})")
                ->orderBy('version', 'ASC')
                ->get();

            $grouped = $quotations->map(function ($quotation) {
                $customer = $quotation->customer;
                $spareParts = [];
                $services = [];

                if ($quotation && $quotation->detailQuotations) {
                    foreach ($quotation->detailQuotations as $detail) {
                        if ($detail->sparepart_id) {
                            $sparepart = $detail->sparepart;
                            $spareParts[] = [
                                'sparepart_id' => $sparepart ? $sparepart->id : '',
                                'sparepart_name' => $sparepart ? $sparepart->sparepart_name : '',
                                'sparepart_number' => $sparepart ? $sparepart->sparepart_number : '',
                                'quantity' => $detail->quantity ?? 0,
                                'unit_price_sell' => $detail->unit_price ?? 0,
                                'total_price' => ($detail->quantity * ($detail->unit_price ?? 0)),
                                'stock' => $detail->is_indent ? 'indent' : 'available'
                            ];
                        } else {
                            $services[] = [
                                'service' => $detail->service ?? '',
                                'unit_price_sell' => $detail->unit_price ?? 0,
                                'quantity' => $detail->quantity ?? 0,
                                'total_price' => ($detail->quantity * ($detail->unit_price ?? 0))
                            ];
                        }
                    }
                }

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
                        'grand_total' => $quotation->grand_total
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
                    'spareparts' => $spareParts,
                    'services' => $services
                ];
            });

            return response()->json([
                'message' => 'List of all quotations retrieved successfully',
                'data' => [
                    'data' => $grouped,
                    'from' => $paginatedGroups->firstItem(),
                    'to' => $paginatedGroups->lastItem(),
                    'total' => $paginatedGroups->total(),
                    'per_page' => $paginatedGroups->perPage(),
                    'current_page' => $paginatedGroups->currentPage(),
                    'last_page' => $paginatedGroups->lastPage(),
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
            // Validate the there is notes
            $request->validate([
                'notes' => 'required|string',
            ]);
            $notes = $request->input('notes');

            $quotations = $this->getAccessedQuotation($request);
            $quotation = $quotations->where('slug', $slug)->lockForUpdate()->first();

            if (!$quotation) {
                return $this->handleNotFound('Quotation not found');
            }

            $latestVersion = $this->getAccessedQuotation($request)
                ->where('quotation_number', $quotation->quotation_number)
                ->max('version');

            // Allow update only if this is the latest version
            if ($quotation->version < $latestVersion) {
                return response()->json([
                    'message' => 'Only the latest version can be updated',
                ], Response::HTTP_BAD_REQUEST);
            }

            if ($quotation->purchaseOrder->first()) {
                return response()->json([
                    'message' => 'Quotation already has a purchase order'
                ], Response::HTTP_BAD_REQUEST);
            }

            $isNeedReview = $quotation->review;
            $isApproved = $quotation->current_status == QuotationController::APPROVE;

            if (!$isNeedReview || !$isApproved) {
                return response()->json([
                    'message' => 'Quotation needs to be reviewed or approved before moving to purchase order'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Update quotation status to Po
            $this->changeStatusToPo($request, $quotation);

            $branchModel = Branch::find($this->ensureQuotationBranchId($quotation));
            $branchCode = $branchModel?->code ?? 'JKT';
            $user = $request->user();
            $userId = $user->id;
            $currentMonth = now()->month;
            $romanMonth = $this->getRomanMonth($currentMonth);
            $year = now()->format('y');

            // Generate purchase order number from quotation number
            try {
                // Expected quotation_number format: QUOT/033/BMJ-MEGAH/SMG/1/07/2025
                $parts = explode('/', $quotation->quotation_number);
                $poNumber = $parts[1] ?? null; // e.g., 033

                if (!$poNumber) {
                    throw new \RuntimeException('Invalid quotation number format.');
                }

                $purchaseOrderNumber = "PO/{$poNumber}/BMJ-MEGAH/{$branchCode}/{$userId}/{$romanMonth}/{$year}";
            } catch (\Throwable $th) {
                // Fallback to sequential PO number with current month and year
                $latestQuotation = Quotation::latest('id')
                    ->lockForUpdate()
                    ->first();
                $lastestPo = $latestQuotation ? $latestQuotation->purchaseOrder : null;
                $nextLatestId = $lastestPo ? $lastestPo->id + 1 : 1;

                $purchaseOrderNumber = "PO/{$nextLatestId}/BMJ-MEGAH/{$branchCode}/{$userId}/{$romanMonth}/{$year}";
            }

            // Create PurchaseOrder with version 1
            $purchaseOrder = PurchaseOrder::create([
                'quotation_id' => $quotation->id,
                'purchase_order_number' => $purchaseOrderNumber,
                'purchase_order_date' => now(),
                'employee_id' => $quotation->employee_id,
                'notes' => $notes,
                'current_status' => PurchaseOrderController::PREPARE,
                'version' => 1
            ]);

            // Handle logic based on quotation type
            if ($quotation->type === self::SPAREPARTS) {
                // Get the spareparts associated with the quotation
                $spareparts = DB::table('detail_quotations')
                    ->where('quotation_id', $quotation->id)
                    ->whereNotNull('sparepart_id')
                    ->get();

                if ($spareparts->isEmpty()) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'No spareparts found for this quotation'
                    ], Response::HTTP_BAD_REQUEST);
                }

                try {
                    // Expected quotation_number format: QUOT/033/BMJ-MEGAH/SMG/1/07/2025
                    $parts = explode('/', $quotation->quotation_number);
                    $boNumber = $parts[1]; // e.g., 033

                    $backOrder = BackOrder::create([
                        'purchase_order_id' => $purchaseOrder->id,
                        'back_order_number' => "BO/{$boNumber}/BMJ-MEGAH/{$branchCode}/{$romanMonth}/{$year}",
                        'current_status' => BackOrderController::PROCESS,
                    ]);
                } catch (\Throwable $th) {
                    // Create BackOrder for Spareparts
                    $lastestBo = BackOrder::latest('id')
                        ->lockForUpdate() // Lock to prevent race condition
                        ->first();
                    $boNumber = $lastestBo ? $lastestBo->id + 1 : 1;
                    $backOrder = BackOrder::create([
                        'purchase_order_id' => $purchaseOrder->id,
                        'back_order_number' => "BO/{$boNumber}/BMJ-MEGAH/{$branchCode}/{$romanMonth}/{$year}",
                        'current_status' => BackOrderController::PROCESS,
                    ]);
                }

                // Check for returned quotation, process return quotation will not reduce sparepart again
                $thereIsQuotationReturned = $quotations->where('is_return', true)->first();

                if (!$thereIsQuotationReturned) {
                    $hasBoSparepart = false;
                    // Process each sparepart
                    foreach ($spareparts as $sparepart) {
                        $sparepartRecord = Sparepart::where('id', $sparepart->sparepart_id)->lockForUpdate()->first();
                        if (!$sparepartRecord) {
                            DB::rollBack();
                            return response()->json([
                                'message' => "Sparepart with ID {$sparepart->sparepart_id} not found"
                            ], Response::HTTP_BAD_REQUEST);
                        }

                        $quotationBranchId = $this->ensureQuotationBranchId($quotation);
                        $branchStock = $this->stockService->ensureStockRecord($sparepartRecord, $quotationBranchId, true);
                        $sparepartTotalUnit = $branchStock->quantity;
                        $sparepartQuantityOrderInPo = $sparepart->quantity;
                        $numberBoInBo = 0;
                        $numberDoInBo = $sparepartQuantityOrderInPo;

                        // Determine BO and DO quantities
                        $sparepartQuantityAfterPo = $sparepartTotalUnit - $sparepartQuantityOrderInPo;
                        $stockIsExistButAfterPoBecomeIndent = $sparepartQuantityAfterPo < 0 && $sparepartTotalUnit >= 0;
                        $stockIsNotExistBeforePo = $sparepartTotalUnit <= 0;

                        if ($stockIsExistButAfterPoBecomeIndent) {
                            $numberBoInBo = ($sparepartQuantityOrderInPo - $sparepartTotalUnit);
                            $numberDoInBo = $sparepartTotalUnit;
                        } elseif ($stockIsNotExistBeforePo) {
                            $numberBoInBo = $sparepartQuantityOrderInPo;
                            $numberDoInBo = 0;
                        }

                        // Decrease the number of sparepart
                        // TODO: What if we set it always 0 if bellow 0 and use better code to handle BO ?
                        // $branchStock->quantity = max(0, $sparepartTotalUnit - $sparepartQuantityOrderInPo);
                        $branchStock->quantity = $sparepartTotalUnit - $sparepartQuantityOrderInPo;

                        $branchStock->save();

                        // Change current status of PO to BO if there is a backorder
                        if ($numberBoInBo) {
                            $purchaseOrder->update(['current_status' => PurchaseOrderController::BO]);
                            $hasBoSparepart = true;
                        }

                        // Create DetailBackOrder entry
                        DetailBackOrder::create([
                            'back_order_id' => $backOrder->id,
                            'sparepart_id' => $sparepart->sparepart_id,
                            'number_delivery_order' => $numberDoInBo,
                            'number_back_order' => $numberBoInBo,
                        ]);
                    }

                    // If no backorder spareparts, update BackOrder to READY
                    if (!$hasBoSparepart) {
                        $backOrder->update(['current_status' => BackOrderController::READY]);
                    }
                } else {
                    // Directly change BO status to ready if this quotation number has quotation that returned.
                    $backOrder->update(['current_status' => BackOrderController::READY]);
                }
            }

            // Commit the transaction
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

            // Paginate all versions but sort by numeric part of quotation_number DESC then by version ASC
            $quotations = $quotationNeedReview
                ->orderByRaw('CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(quotation_number, "/", 2), "/", -1) AS UNSIGNED) DESC')
                ->orderBy('version', 'ASC')
                ->paginate(20)
                ->through(function ($quotation) {
                    $customer = $quotation->customer;
                    $spareParts = [];
                    $services = [];
                    if ($quotation && $quotation->detailQuotations) {
                        foreach ($quotation->detailQuotations as $detail) {
                            if ($detail->sparepart_id) {
                                $sparepart = $detail->sparepart;
                                $spareParts[] = [
                                    'sparepart_id' => $sparepart ? $sparepart->id : '',
                                    'sparepart_name' => $sparepart ? $sparepart->sparepart_name : '',
                                    'sparepart_number' => $sparepart ? $sparepart->sparepart_number : '',
                                    'quantity' => $detail->quantity ?? 0,
                                    'unit_price_sell' => $detail->unit_price ?? 0,
                                    'total_price' => ($detail->quantity * ($detail->unit_price ?? 0)),
                                    'stock' => $detail->is_indent ? 'indent' : 'available'
                                ];
                            } else {
                                $services[] = [
                                    'service' => $detail->service ?? '',
                                    'unit_price_sell' => $detail->unit_price ?? 0,
                                    'quantity' => $detail->quantity ?? 0,
                                    'total_price' => ($detail->quantity * ($detail->unit_price ?? 0))
                                ];
                            }
                        }
                    }

                    return [
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
                            'amount' => $quotation->amount,
                            'discount' => $quotation->discount,
                            'subtotal' => $quotation->subtotal,
                            'ppn' => $quotation->ppn,
                            'grand_total' => $quotation->grand_total
                        ],
                        'current_status' => $quotation->current_status,
                        'status' => $quotation->status,
                        'notes' => $quotation->notes,
                        'spareparts' => $spareParts,
                        'services' => $services,
                        'date' => $quotation->date
                    ];
                });

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

            // To avoid the group-by / paginate mismatch (where paginating distinct quotation_number
            // then fetching rows later produces a different ordering), we paginate groups of
            // quotation_number by selecting a representative id (MAX(id)) per group, then fetch the
            // exact quotation rows for those ids preserving the paginated order. Also order DESC by
            // the numeric part of quotation_number so newest numbers appear first.

            // Build grouped query selecting representative id per quotation_number
            $grouped = (clone $quotationNeedReturn)
                ->getQuery()
                ->select('quotation_number', DB::raw('MAX(id) as max_id'))
                ->groupBy('quotation_number')
                ->orderByRaw('CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(quotation_number, "/", 2), "/", -1) AS UNSIGNED) DESC');

            // Paginate the grouped results (one row per quotation_number)
            $paginatedGroups = DB::table(DB::raw("({$grouped->toSql()}) as grouped"))
                ->mergeBindings($grouped)
                ->select('quotation_number', 'max_id')
                ->paginate(20);

            $groupIds = $paginatedGroups->pluck('max_id')->filter()->all();

            if (empty($groupIds)) {
                return response()->json([
                    'message' => $isNeedReturn ? 'List of all quotations that need to be returned' : 'List of all quotations that do not need to be returned',
                    'data' => [
                        'data' => [],
                        'from' => $paginatedGroups->firstItem(),
                        'to' => $paginatedGroups->lastItem(),
                        'total' => $paginatedGroups->total(),
                        'per_page' => $paginatedGroups->perPage(),
                        'current_page' => $paginatedGroups->currentPage(),
                        'last_page' => $paginatedGroups->lastPage(),
                    ]
                ], Response::HTTP_OK);
            }

            // Fetch exact quotation rows for the current page, preserving order
            $orderedIds = implode(',', $groupIds);
            $quotationsCollection = Quotation::with('customer')
                ->whereIn('id', $groupIds)
                ->orderByRaw("FIELD(id, {$orderedIds})")
                ->get();

            // Map the results to the same shape as before
            $mapped = $quotationsCollection->map(function ($quotation) {
                $customer = $quotation->customer;
                $spareParts = [];
                $services = [];
                if ($quotation && $quotation->detailQuotations) {
                    foreach ($quotation->detailQuotations as $detail) {
                        if ($detail->sparepart_id) {
                            // minimal sparepart mapping (keeps previous behavior)
                            $spareParts[] = [
                                'sparepart_id' => $detail->sparepart_id,
                                'quantity' => $detail->quantity,
                                'unit_price_sell' => $detail->unit_price_sell,
                            ];
                        } else {
                            $services[] = [
                                'service' => $detail->service,
                                'quantity' => $detail->quantity,
                                'unit_price_sell' => $detail->unit_price_sell,
                            ];
                        }
                    }
                }

                return [
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
                        'amount' => $quotation->amount,
                        'discount' => $quotation->discount,
                        'subtotal' => $quotation->subtotal,
                        'ppn' => $quotation->ppn,
                        'grand_total' => $quotation->grand_total
                    ],
                    'current_status' => $quotation->current_status,
                    'status' => $quotation->status,
                    'notes' => $quotation->notes,
                    'spareparts' => $spareParts,
                    'services' => $services,
                    'date' => $quotation->date
                ];
            });

            return response()->json([
                'message' => $isNeedReturn ? 'List of all quotations that need to be reviewed' : 'List of all quotations that do not need to be reviewed',
                'data' => [
                    'data' => $mapped,
                    'from' => $paginatedGroups->firstItem(),
                    'to' => $paginatedGroups->lastItem(),
                    'total' => $paginatedGroups->total(),
                    'per_page' => $paginatedGroups->perPage(),
                    'current_page' => $paginatedGroups->currentPage(),
                    'last_page' => $paginatedGroups->lastPage(),
                ]
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

    public function changeStatusToReturn(Request $request, $id)
    {
        // Start a database transaction
        DB::beginTransaction();

        try {
            $purchaseOrder =  PurchaseOrder::where('id', $id)->lockForUpdate()->firstOrFail();
            $quotation = $purchaseOrder->quotation;

            if ($quotation->type === self::SERVICE) {
                return response()->json([
                    'message' => 'Service quotations cannot be returned'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Get the latest purchase order for this quotation
            $purchaseOrder = $quotation->purchaseOrder()
                ->latest('version')
                ->first();

            if (!$purchaseOrder) {
                return response()->json([
                    'message' => 'No purchase order found for this quotation'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validate the returned spareparts
            $request->validate([
                'returned' => 'required|array',
                'returned.*.sparepart_id' => 'required|integer|exists:spareparts,id',
                'returned.*.quantity' => 'required|integer|min:1',
            ]);


            // Restock returned spareparts
            $returnedItems = $request->input('returned', []);
            $quotationBranchId = $this->ensureQuotationBranchId($quotation);
            foreach ($returnedItems as $item) {
                $sparepart = Sparepart::where('id', $item['sparepart_id'])->lockForUpdate()->first();
                if ($sparepart) {
                    $this->stockService->increase($sparepart, $quotationBranchId, (int) $item['quantity']);
                }
            }

            // Calculated new price for new quotation
            $detailQuotation = $quotation->detailQuotations;
            $detailUpdatedQuotation = [];
            $totaNewAmount = 0;

            // Handle Spareparts or Services based on type
            foreach ($detailQuotation  as $detail) {
                $sparepartId = $detail->sparepart_id;
                $quantity = $detail->quantity;
                $unit_price = $detail->unit_price;

                foreach ($returnedItems as $item) {
                    if ($sparepartId == $item['sparepart_id']) {
                        $quantityReturn = $item['quantity'];
                        $quantity = $quantity - $quantityReturn;
                    }
                }
                // Calculated totalNewAmount after return process
                $totaNewAmount += $quantity * $unit_price;

                // Store updated data for detail_quotation after return process
                array_push($detailUpdatedQuotation, [
                    'sparepart_id' => $sparepartId,
                    'quantity' => $quantity,
                    'unit_price' => $unit_price,
                    'is_return' => false,
                ]);
            }

            // Get the latest discount and PPN from General model
            $general = General::latest()->first();
            $discount = $general ? $general->discount : 0;
            $ppn = $general ? $general->ppn : 0;

            $priceDiscount = $totaNewAmount  * $discount;
            $subTotal = $totaNewAmount  - $priceDiscount;
            $pricePpn = $subTotal  * $ppn;
            $grandTotal = $subTotal + $pricePpn;


            // Create new PO version
            $newVersion = $purchaseOrder->version + 1;
            $newPONumber = $purchaseOrder->purchase_order_number;

            // Create new quotation version
            // Map API contract to database fields
            // calculated new amount
            $quotationData = [
                'quotation_number' => $quotation->quotation_number,
                'type' => $quotation->type,
                'date' => $quotation->date,
                'amount' => $totaNewAmount,
                'discount' => $priceDiscount,
                'subtotal' => $subTotal,
                'ppn' => $pricePpn,
                'grand_total' => $grandTotal,
                'notes' => $quotation->notes,
                'project' => $quotation->quotation_number,
                'employee_id' => $quotation->employee_id,
                'branch_id' => $quotation->branch_id,
            ];

            // Handle versioning using the version field
            $baseQuotationNumber = $quotation->quotation_number;
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

            // Assign the customer ID and employee ID to the quotation
            $quotationData['customer_id'] = $quotation->customer->id;
            $quotationData['employee_id'] = $quotation->employee_id; // Retain original employee_id
            $quotationData['review'] = true;
            $quotationData['current_status'] = QuotationController::PO;

            // Generate a unique slug based on the 'project' field
            $slug = Str::slug($quotationData['project']);
            $quotationData['slug'] = $slug . '-' . Str::random(6); // Add randomness for uniqueness

            // Reassign list of status from previous quotation by filter it first to get PO only
            $quotationData['status'] = $this->filterPoStatus($quotation->status);

            // Create new quotation with the validated data
            $newQuotation = Quotation::create($quotationData);

            $detailQuotation = $quotation->detailQuotations;

            // Create detail_quotations for new Quotation,
            // NOTE: We ignore BackOrder for this step.
            foreach ($detailUpdatedQuotation as $detail) {
                $sparepartId = $detail['sparepart_id'];
                $quantity = $detail['quantity'];
                $unit_price = $detail['unit_price'];

                // Insert into the bridge table
                DB::table('detail_quotations')->insert([
                    'quotation_id' => $newQuotation->id,
                    'sparepart_id' => $sparepartId,
                    'quantity' =>  $quantity,
                    'is_indent' => 0, // This is for return, we declare is_indent true
                    'is_return' => false, // This is detail quotation is created after return, so this one is not consider return, the previous is the one that return
                    'unit_price' => $unit_price,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Create new PurchaseOrder for new Quotation,
            PurchaseOrder::create([
                'quotation_id' => $newQuotation->id,
                'purchase_order_number' => $newPONumber,
                'purchase_order_date' => now(),
                'employee_id' => $purchaseOrder->employee_id,
                'notes' => $purchaseOrder->notes,
                'current_status' => PurchaseOrderController::PREPARE,
                'version' => $newVersion,
            ]);
            // Update quotation status
            $user = $request->user();
            $currentStatus = $quotation->status ?? [];
            if (!is_array($currentStatus)) {
                $currentStatus = [];
            }

            $currentStatus[] = [
                'state' => self::RETURN,
                'employee' => $user->username,
                'timestamp' => now()->toIso8601String(),
            ];

            $quotation->update([
                'status' => $currentStatus,
                'current_status' => self::DONE,
                'is_return' => true,
            ]);

            // Format response
            $formattedQuotation = $this->formatQuotation($quotation);

            DB::commit();

            return response()->json([
                'message' => 'Successfully processed return and created new purchase order version',
                'data' => $formattedQuotation,
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->handleError($th, 'Failed to process return');
        }
    }

    public function declineReturn(Request $request, $slug)
    {
        // Start a database transaction
        DB::beginTransaction();

        try {
            $quotations = $this->getAccessedQuotation($request);
            $quotation = $quotations->where('slug', $slug)->lockForUpdate()->firstOrFail();

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
            $spareParts = [];
            $services = [];
            if ($quotation && $quotation->detailQuotations) {
                foreach ($quotation->detailQuotations as $detail) {
                    if ($detail->sparepart_id) {
                        $sparepart = $detail->sparepart;
                        $spareParts[] = [
                            'sparepart_id' => $sparepart ? $sparepart->id : '',
                            'sparepart_name' => $sparepart ? $sparepart->sparepart_name : '',
                            'sparepart_number' => $sparepart ? $sparepart->sparepart_number : '',
                            'quantity' => $detail->quantity ?? 0,
                            'unit_price_sell' => $detail->unit_price ?? 0,
                            'total_price' => ($detail->quantity * ($detail->unit_price ?? 0)),
                            'stock' => $detail->is_indent ? 'indent' : 'available'
                        ];
                    } else {
                        $services[] = [
                            'service' => $detail->service ?? '',
                            'unit_price_sell' => $detail->unit_price ?? 0,
                            'quantity' => $detail->quantity ?? 0,
                            'total_price' => ($detail->quantity * ($detail->unit_price ?? 0))
                        ];
                    }
                }
            }

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
                    'amount' => $quotation->amount,
                    'discount' => $quotation->discount,
                    'subtotal' => $quotation->subtotal,
                    'ppn' => $quotation->ppn,
                    'grand_total' => $quotation->grand_total
                ],
                'current_status' => $quotation->current_status,
                'status' => $quotation->status,
                'notes' => $quotation->notes,
                'spareparts' => $spareParts,
                'servies' => $services,
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
            $quotation = $quotations->where('slug', $slug)->lockForUpdate()->firstOrFail();

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
            $spareParts = [];
            $services = [];
            if ($quotation && $quotation->detailQuotations) {
                foreach ($quotation->detailQuotations as $detail) {
                    if ($detail->sparepart_id) {
                        $sparepart = $detail->sparepart;
                        $spareParts[] = [
                            'sparepart_id' => $sparepart ? $sparepart->id : '',
                            'sparepart_name' => $sparepart ? $sparepart->sparepart_name : '',
                            'sparepart_number' => $sparepart ? $sparepart->sparepart_number : '',
                            'quantity' => $detail->quantity ?? 0,
                            'unit_price_sell' => $detail->unit_price ?? 0,
                            'total_price' => ($detail->quantity * ($detail->unit_price ?? 0)),
                            'stock' => $detail->is_indent ? 'indent' : 'available'
                        ];
                    } else {
                        $services[] = [
                            'service' => $detail->service ?? '',
                            'unit_price_sell' => $detail->unit_price ?? 0,
                            'quantity' => $detail->quantity ?? 0,
                            'total_price' => ($detail->quantity * ($detail->service_price ?? 0))
                        ];
                    }
                }
            }

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
                    'amount' => $quotation->amount,
                    'discount' => $quotation->discount,
                    'subtotal' => $quotation->subtotal,
                    'ppn' => $quotation->ppn,
                    'grand_total' => $quotation->grand_total
                ],
                'current_status' => $quotation->current_status,
                'status' => $status,
                'notes' => $quotation->notes,
                'spareParts' => $spareParts,
                'services' => $services,
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

    /**
     * Get all quotations without permission check
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAllWithoutPermission()
    {
        try {
            // Get all quotations without permission check
            $quotations = Quotation::with('customer');

            return $quotations;
        } catch (\Throwable $th) {
            return $this->handleError($th, 'Failed to retrieve quotations without permission');
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

    protected function resolveBranchModel(?string $value): ?Branch
    {
        if (!$value) {
            return null;
        }

        $normalized = strtolower($value);

        return Branch::query()
            ->whereRaw('LOWER(name) = ?', [$normalized])
            ->orWhereRaw('LOWER(code) = ?', [$normalized])
            ->first();
    }

    protected function extractBranchCode(?string $identifier): ?string
    {
        if (!$identifier) {
            return null;
        }

        $parts = explode('/', $identifier);

        return $parts[3] ?? null;
    }

    protected function ensureQuotationBranchId(Quotation $quotation): int
    {
        if ($quotation->branch_id) {
            return $quotation->branch_id;
        }

        if ($quotation->relationLoaded('branch') && $quotation->branch) {
            return $quotation->branch->id;
        }

        $branch = $this->resolveBranchModel(optional($quotation->employee)->branch);

        if (!$branch) {
            $branch = $this->resolveBranchModel($this->extractBranchCode($quotation->quotation_number));
        }

        if (!$branch) {
            throw new \RuntimeException('Unable to resolve branch for quotation ' . $quotation->id);
        }

        $quotation->branch_id = $branch->id;
        $quotation->save();

        return $branch->id;
    }

    protected function formatQuotation($quotation)
    {
        $customer = $quotation->customer;
        $spareParts = [];
        $services = [];
        if ($quotation && $quotation->detailQuotations) {
            foreach ($quotation->detailQuotations as $detail) {
                if ($detail->sparepart_id) {
                    $sparepart = $detail->sparepart;
                    $spareParts[] = [
                        'sparepart_id' => $sparepart ? $sparepart->id : '',
                        'sparepart_name' => $sparepart ? $sparepart->sparepart_name : '',
                        'sparepart_number' => $sparepart ? $sparepart->sparepart_number : '',
                        'quantity' => $detail->quantity ?? 0,
                        'unit_price_sell' => $detail->unit_price ?? 0,
                        'total_price' => ($detail->quantity * ($detail->unit_price ?? 0)),
                        'stock' => $detail->is_indent ? 'indent' : 'available'
                    ];
                } else {
                    $services[] = [
                        'service' => $detail->service ?? '',
                        'unit_price_sell' => $detail->unit_price ?? 0,
                        'quantity' => $detail->quantity ?? 0,
                        'total_price' => ($detail->quantity * ($detail->unit_price ?? 0))
                    ];
                }
            }
        }

        // Get the latest discount and PPN from General model
        $general = General::latest()->first();
        $discount = $general ? $general->discount : 0;
        $ppn = $general ? $general->ppn : 0;

        return [
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
                'amount' => $quotation->amount,
                'discount' => $quotation->discount,
                'subtotal' => $quotation->subtotal,
                'ppn' => $quotation->ppn,
                'grand_total' => $quotation->grand_total
            ],
            'current_status' => $quotation->current_status,
            'status' => $quotation->status,
            'notes' => $quotation->notes,
            'branch' => optional($quotation->branch)->name,
            'branch_code' => optional($quotation->branch)->code,
            'discount' => $discount,
            'ppn' => $ppn,
            'spareparts' => $spareParts,
            'services' => $services,
            'date' => $quotation->date
        ];
    }

    /**
     * Filters the status array to keep only entries with state 'Po'.
     *
     * @param array $statusArray The original status array
     * @return array The filtered status array containing only 'Po' state entries
     */
    private function filterPoStatus(array $statusArray): array
    {
        return array_filter($statusArray, function ($status) {
            return isset($status['state']) && $status['state'] === 'Po';
        });
    }
}
