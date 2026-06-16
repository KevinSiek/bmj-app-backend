<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Models\Branch;
use Illuminate\Http\Request;
use App\Models\BackOrder;
use App\Models\Buy;
use App\Models\DetailBuy;
use App\Models\DetailQuotation;
use App\Models\PurchaseOrder;
use App\Models\Sparepart;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\SparepartStockService;

class BackOrderController extends Controller
{
    // Status constants
    const PROCESS = 'Process';
    const READY = 'Ready';
    const REJECTED = 'Rejected';

    const ALLOWED_PROCESS_ROLES = ['Director', 'Inventory Purchase', 'Inventory Admin', 'Head Inventory'];

    protected $quotationController;
    protected SparepartStockService $stockService;
    public function __construct(QuotationController $quotationController, SparepartStockService $stockService)
    {
        $this->quotationController = $quotationController;
        $this->stockService = $stockService;
    }

    public function getAll(Request $request)
    {
        try {
            // Get query parameters
            $q = $request->query('search');
            $month = $request->query('month');
            $year = $request->query('year');

            // Initialize the query builder
            $backOrderQuery = $this->getAccessedBackOrder($request);

            // Filter BackOrders with non-zero number_back_order in detailBackOrders
            $backOrderQuery->whereHas('detailBackOrders', function ($query) {
                $query->where('number_back_order', '>', 0);
            });

            // Apply search term filter if 'q' is provided
            if ($q) {
                $backOrderQuery->where(function ($query) use ($q) {
                    $query->where('back_order_number', 'like', "%$q%")
                        ->orWhere('current_status', 'like', "%$q%")
                        ->orWhereHas('purchaseOrder', function ($qry) use ($q) {
                            $qry->where('purchase_order_number', 'like', "%$q%");
                        });
                });
            }

            // Apply date filter if 'month' and 'year' are provided
            if ($month && $year) {
                $monthNumber = date('m', strtotime($month));
                $startDate = "{$year}-{$monthNumber}-01";
                $endDate = date("Y-m-t", strtotime($startDate));

                $backOrderQuery->whereBetween('created_at', [$startDate, $endDate]);
            }

            // Build grouped pagination to keep stable pages while returning all versions per back_order_number
            $grouped = (clone $backOrderQuery)
                ->getQuery()
                ->select('back_order_number', DB::raw('MAX(id) as max_id'))
                ->groupBy('back_order_number')
                ->orderByRaw('CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(back_order_number, \'/\', 2), \'/\', -1) AS UNSIGNED) DESC');

            $paginatedGroups = DB::table(DB::raw("({$grouped->toSql()}) as grouped"))
                ->mergeBindings($grouped)
                ->select('back_order_number', 'max_id')
                ->paginate(20);

            $groupNumbers = $paginatedGroups->pluck('back_order_number')->filter()->all();

            if (empty($groupNumbers)) {
                return response()->json([
                    'message' => 'List of all back orders retrieved successfully',
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

            // Fetch all BackOrder rows for the paginated group numbers and eager-load relationships
            $backOrdersCollection = BackOrder::with([
                'purchaseOrder.quotation',
                'purchaseOrder.quotation.customer',
                'detailBackOrders.sparepart.branchStocks.branch',
            ])->whereIn('back_order_number', $groupNumbers)
                ->get();

            // Order by the page group order then by created_at desc (newest first) and id asc within group
            $ordered = $backOrdersCollection->sortBy(function ($bo) use ($groupNumbers) {
                $groupIndex = array_search($bo->back_order_number, $groupNumbers);
                $timestamp = $bo->created_at ? strtotime($bo->created_at) : 0;
                // Use array to perform lexicographic comparison: [groupIndex, -timestamp, id]
                return [
                    $groupIndex === false ? PHP_INT_MAX : $groupIndex,
                    -intval($timestamp),
                    intval($bo->id ?? 0)
                ];
            })->values();

            // Map to API shape
            $formattedBackOrders = $ordered->map(function ($backOrder) {
                return $this->formatBackOrder($backOrder);
            });

            // Return paginated response with transformed data
            return response()->json([
                'message' => 'List of all back orders retrieved successfully',
                'data' => [
                    'data' => $formattedBackOrders,
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

    /**
     * Get a single back order by ID.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function get(Request $request, $id)
    {
        try {
            // Initialize the query builder with access control
            $backOrderQuery = $this->getAccessedBackOrder($request);

            // Find the back order by ID with all required relationships
            $backOrder = $backOrderQuery->with([
                'purchaseOrder',
                'purchaseOrder.quotation',
                'purchaseOrder.quotation.customer',
                'detailBackOrders.sparepart',
            ])->findOrFail($id);

            // Format the back order to match the API contract
            $formattedBackOrder = $this->formatBackOrder($backOrder);

            return response()->json([
                'message' => 'Back order retrieved successfully',
                'data' => $formattedBackOrder
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    /**
     * Format a back order to match the API contract.
     *
     * @param BackOrder $backOrder
     * @return array
     */
    private function formatBackOrder(BackOrder $backOrder)
    {
        $quotation = $backOrder->purchaseOrder?->quotation;
        $branchId = $this->resolveQuotationBranchId($quotation);

        return [
            'id' => $backOrder->id,
            'back_order_number' => $backOrder->back_order_number,
            'current_status' => $backOrder->current_status,
            'purchase_order' => [
                'purchase_order_number' => $backOrder->purchaseOrder?->purchase_order_number,
                'purchase_order_date' => $backOrder->purchaseOrder?->purchase_order_date,
                'type' => $backOrder->purchaseOrder?->quotation?->type,
            ],
            'deliveryOrder' => [
                'no' => $backOrder->detailBackOrders->first()?->number_delivery_order ?? '',
                'date' => $backOrder->created_at->toDateString(), // Assuming delivery date is same as back order creation
                'ship_mode' => '', // Placeholder as no direct mapping exists
            ],
            'customer' => [
                'company_name' => $backOrder->purchaseOrder?->quotation?->customer?->company_name ?? '',
                'address' => $backOrder->purchaseOrder?->quotation?->customer?->address ?? '',
                'city' => $backOrder->purchaseOrder?->quotation?->customer?->city ?? '',
                'province' => $backOrder->purchaseOrder?->quotation?->customer?->province ?? '',
                'office' => $backOrder->purchaseOrder?->quotation?->customer?->office ?? '',
                'urban' => $backOrder->purchaseOrder?->quotation?->customer?->urban ?? '',
                'subdistrict' => $backOrder->purchaseOrder?->quotation?->customer?->subdistrict ?? '',
                'postal_code' => $backOrder->purchaseOrder?->quotation?->customer?->postal_code ?? '',
                'npwp' => $backOrder->purchaseOrder?->quotation?->customer?->npwp ?? '', // Assuming nullable
                'delivery' => $backOrder->purchaseOrder?->quotation?->customer?->delivery ?? '', // Assuming nullable
            ],
            'notes' => $backOrder->notes ?? '',
            'spareparts' => $backOrder->detailBackOrders->map(function ($detail) use ($branchId) {
                $order = $detail->number_back_order + $detail->number_delivery_order;
                $totalUnit = 0;

                if ($branchId && $detail->sparepart) {
                    $totalUnit = $this->stockService->getQuantity($detail->sparepart, $branchId);
                }

                return [
                    'sparepart_name' => $detail->sparepart?->sparepart_name ?? '',
                    'sparepart_number' => $detail->sparepart?->sparepart_number ?? '',
                    'unit_price_sell' => $detail->sparepart?->unit_price_sell ?? 0, // Assuming in Sparepart
                    'total_price' => ($detail->quantity ?? 1) * ($detail->sparepart?->unit_price_sell ?? 0),
                    // 'total_unit' => $totalUnit,
                    'total_unit' => $this->formatSparepartStocks($detail->sparepart),
                    'order' => $order ?? 0,
                    'delivery_order' => $detail->number_delivery_order ?? '',
                    'back_order' => $detail->number_back_order ?? '',
                ];
            })->toArray(),
        ];
    }

    public function analyze(Request $request, $id)
    {
        try {
            $user = $request->user();
            if (!in_array($user->role, self::ALLOWED_PROCESS_ROLES)) {
                return $this->handleForbidden('You are not authorized to analyze back orders');
            }

            $backOrder = $this->getAccessedBackOrder($request)
                ->with(['detailBackOrders.sparepart.branchStocks', 'purchaseOrder.quotation'])
                ->find($id);

            if (!$backOrder) {
                return $this->handleNotFound('Back order not found');
            }

            $quotation = $backOrder->purchaseOrder?->quotation;
            $branchId = $this->resolveQuotationBranchId($quotation);

            $missingItems = [];

            foreach ($backOrder->detailBackOrders as $detail) {
                if ($detail->number_back_order <= 0) continue;

                $sparepart = $detail->sparepart;
                if (!$sparepart) continue;

                $availableStock = 0;
                if ($branchId) {
                    $availableStock = $this->stockService->getQuantity($sparepart, $branchId);
                }

                if ($availableStock < $detail->number_back_order) {
                    $missingItems[] = [
                        'sparepart_name' => $sparepart->sparepart_name,
                        'sparepart_number' => $sparepart->sparepart_number,
                        'required' => $detail->number_back_order,
                        'available' => $availableStock
                    ];
                }
            }

            if (!empty($missingItems)) {
                return response()->json([
                    'message' => 'Quantity is not enough please contact purchasing to add the stock',
                    'missing_items' => $missingItems
                ], Response::HTTP_BAD_REQUEST);
            }

            return response()->json([
                'message' => 'Stock is sufficient',
            ], Response::HTTP_OK);

        } catch (\Throwable $th) {
            return $this->handleError($th, 'Failed to analyze back order');
        }
    }

    public function process(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $user = $request->user();
            if (!in_array($user->role, self::ALLOWED_PROCESS_ROLES)) {
                return $this->handleForbidden('You are not authorized to process back orders');
            }

            $backOrder = $this->getAccessedBackOrder($request)
                ->with([
                    'detailBackOrders.sparepart.branchStocks.branch',
                    'purchaseOrder.quotation'
                ])
                ->lockForUpdate()
                ->find($id);

            if (!$backOrder) {
                DB::rollBack();
                return $this->handleNotFound('Back order not found');
            }

            if (in_array($backOrder->current_status, [self::READY, self::REJECTED])) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Back order already processed or rejected'
                ], Response::HTTP_BAD_REQUEST);
            }

            $quotation = $backOrder->purchaseOrder?->quotation;
            $branchId = $this->resolveQuotationBranchId($quotation);

            $purchaseOrder = PurchaseOrder::lockForUpdate()->find($backOrder->purchase_order_id);
            if (!$purchaseOrder) {
                throw new \Exception('Purchase order not found for back order #' . $backOrder->back_order_number);
            }

            $quotation = Quotation::lockForUpdate()->find($purchaseOrder->quotation_id);
            if (!$quotation) {
                throw new \Exception('Quotation not found for purchase order #' . $purchaseOrder->purchase_order_number);
            }

            // First pass: Validate stock is still sufficient to prevent race conditions
            foreach ($backOrder->detailBackOrders as $detailBackOrder) {
                if ($detailBackOrder->number_back_order <= 0) continue;
                $sparepart = Sparepart::find($detailBackOrder->sparepart_id);
                $availableStock = $this->stockService->getQuantity($sparepart, $branchId);
                if ($availableStock < $detailBackOrder->number_back_order) {
                    throw new \Exception("Quantity is not enough please contact purchasing to add the stock for {$sparepart->sparepart_name}");
                }
            }

            // Second pass: Decrement stock since we are officially fulfilling this Back Order
            foreach ($backOrder->detailBackOrders as $detailBackOrder) {
                if ($detailBackOrder->number_back_order <= 0) continue;

                $sparepart = Sparepart::lockForUpdate()->find($detailBackOrder->sparepart_id);
                if (!$sparepart) {
                    throw new \Exception("Sparepart with ID {$detailBackOrder->sparepart_id} not found.");
                }

                if ($branchId && $sparepart) {
                    $this->stockService->decrease(
                        $sparepart,
                        $branchId,
                        (int) $detailBackOrder->number_back_order,
                        'BackOrder',
                        $backOrder->id,
                        $user->id,
                        'BackOrder processed'
                    );
                }

                if ($quotation) {
                    $detailQuotation = DetailQuotation::where('quotation_id', $quotation->id)
                        ->where('sparepart_id', $detailBackOrder->sparepart_id)
                        ->lockForUpdate()
                        ->first();

                    if ($detailQuotation && $detailQuotation->is_indent) {
                        $detailQuotation->is_indent = false;
                        $detailQuotation->save();
                    }
                }
            }

            $backOrder->current_status = self::READY;
            $backOrder->save();

            $purchaseOrder->current_status = PurchaseOrderController::PREPARE;
            $purchaseOrder->save();

            $currentQuotationStatus = $quotation->status ?? [];
            if (!is_array($currentQuotationStatus)) {
                $currentQuotationStatus = [];
            }

            $currentQuotationStatus[] = [
                'state' => QuotationController::Inventory,
                'employee' => $user->username,
                'timestamp' => now()->toIso8601String(),
            ];

            $quotation->status = $currentQuotationStatus;
            $quotation->current_status = QuotationController::Inventory;
            $quotation->save();

            DB::commit();

            return response()->json([
                'message' => 'Back order processed successfully',
                'data' => $backOrder->load([
                    'detailBackOrders.sparepart.branchStocks.branch'
                ])
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->handleError($th, 'Failed to process back order');
        }
    }

    protected function getAccessedBackOrder($request)
    {
        try {
            $user = $request->user();
            $userId = $user->id;
            $role = $user->role;

            $query = BackOrder::query();

            // Only allow back orders for authorized users
            if ($role == 'Marketing') {
                $query->whereHas('purchaseOrder', function ($q) use ($userId) {
                    $q->where('employee_id', $userId);
                });
            }
            return $query;
        } catch (\Throwable $th) {
            // Return empty query builder
            return BackOrder::whereNull('id');
        }
    }

    protected function formatSparepartStocks(?Sparepart $sparepart): array
    {
        if (!$sparepart) {
            return [];
        }

        $branchStocks = $sparepart->relationLoaded('branchStocks')
            ? $sparepart->branchStocks
            : $sparepart->branchStocks()->with('branch')->get();

        return $branchStocks->map(function ($stock) {
            return [
                'name' => $stock->branch->name ?? '',
                'stock' => (int) ($stock->quantity ?? 0),
            ];
        })->values()->toArray();
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

    protected function resolveQuotationBranchId(?Quotation $quotation): ?int
    {
        if (!$quotation) {
            return null;
        }

        if ($quotation->branch_id) {
            return $quotation->branch_id;
        }

        if ($quotation->relationLoaded('branch') && $quotation->branch) {
            return $quotation->branch->id;
        }

        $branch = optional($quotation->employee)->branch
            ?? $this->resolveBranchModel($this->extractBranchCode($quotation->quotation_number));

        if (!$branch) {
            return null;
        }

        $quotation->branch_id = $branch->id;
        $quotation->save();

        return $branch->id;
    }

    // Helper methods for consistent error handling
    protected function handleError(\Throwable $th, $message = 'Internal server error')
    {
        // Preserve Laravel HTTP semantics: not-found / validation / auth / http exceptions
        // must surface with their real status code, not be flattened into a generic 500 here.
        if ($th instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
            || $th instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
            || $th instanceof \Illuminate\Validation\ValidationException
            || $th instanceof \Illuminate\Auth\Access\AuthorizationException) {
            throw $th;
        }

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

    protected function handleForbidden($message = 'Forbidden')
    {
        return response()->json([
            'message' => $message
        ], Response::HTTP_FORBIDDEN);
    }
}
