<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Borrow;
use App\Models\DetailBorrow;
use App\Models\Branch;
use App\Models\PurchaseOrder;
use App\Models\Sparepart;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use App\Services\SparepartStockService;

class BorrowController extends Controller
{
    // Lifecycle: Created -> Approved -> Borrowed (Send decreases stock) ->
    // Returned (Kembali) -> Done (reconciliation increases returned stock).
    // Side-exits: Rejected (reviewer, from Created), Cancelled (Marketing, from Created).
    const CREATED = 'Created';
    const APPROVED = 'Approved';
    const BORROWED = 'Borrowed';
    const RETURNED = 'Returned';
    const DONE = 'Done';
    const REJECTED = 'Rejected';
    const CANCELLED = 'Cancelled';

    protected SparepartStockService $stockService;

    public function __construct(SparepartStockService $stockService)
    {
        $this->stockService = $stockService;
    }

    public function getAll(Request $request)
    {
        try {
            $query = $this->getAccessedBorrow($request)
                ->with(['detailBorrows.sparepart', 'branch', 'employee', 'purchaseOrder.quotation', 'purchaseOrder.workOrder']);

            if ($request->query('sort_date') === 'asc') {
                $query->orderBy('created_at', 'asc');
            } else {
                $query->latest('id');
            }

            $borrows = $query->paginate(20);

            $formatted = $borrows->getCollection()->map(function ($borrow) {
                return $this->formatBorrow($borrow);
            });

            return response()->json([
                'message' => 'List of all borrows retrieved successfully',
                'data' => [
                    'data' => $formatted,
                    'from' => $borrows->firstItem(),
                    'to' => $borrows->lastItem(),
                    'total' => $borrows->total(),
                    'per_page' => $borrows->perPage(),
                    'current_page' => $borrows->currentPage(),
                    'last_page' => $borrows->lastPage(),
                ]
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    /**
     * Searchable, paginated PO picker for the Pinjaman form.
     * type=Service backs the request's PO link (embeds the PO's Work Order);
     * type=Spareparts backs reconciliation (embeds the quotation's sparepart lines
     * so the frontend can validate a shortfall is covered).
     */
    public function purchaseOrderOptions(Request $request)
    {
        $validated = $request->validate([
            'type' => 'required|in:Service,Spareparts',
            'search' => 'nullable|string',
            'page' => 'nullable|integer|min:1',
        ]);

        try {
            $type = $validated['type'];
            $search = $validated['search'] ?? null;

            $query = PurchaseOrder::query()
                ->with(['quotation.detailQuotations.sparepart', 'workOrder'])
                ->whereHas('quotation', fn ($q) => $q->where('type', $type));

            if ($search) {
                $like = '%' . $search . '%';
                $query->where(function ($q) use ($like) {
                    $q->where('po_number', 'like', $like)
                        ->orWhere('purchase_order_number', 'like', $like);
                });
            }

            // Keep only the latest version of each purchase_order_number, portably.
            $latest = $query->orderBy('version', 'desc')
                ->get()
                ->unique('purchase_order_number')
                ->sortByDesc('id')
                ->values();

            $perPage = 20;
            $page = $validated['page'] ?? 1;
            $items = $latest->forPage($page, $perPage)->values();

            return response()->json([
                'message' => 'Purchase order options retrieved successfully',
                'data' => [
                    'data' => $items->map(fn ($po) => $this->formatPurchaseOrderOption($po, $type)),
                    'current_page' => (int) $page,
                    'per_page' => $perPage,
                    'total' => $latest->count(),
                    'last_page' => (int) max(1, ceil($latest->count() / $perPage)),
                ],
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    protected function formatPurchaseOrderOption(PurchaseOrder $po, string $type): array
    {
        $base = [
            'id' => $po->id,
            'purchase_order_number' => $po->purchase_order_number,
            'po_number' => $po->po_number ?? '',
            'purchase_order_date' => $po->purchase_order_date,
            'version' => (int) $po->version,
        ];

        if ($type === 'Service') {
            $wo = $po->workOrder;
            $base['work_order'] = [
                'id' => $wo?->id,
                'work_order_number' => $wo?->work_order_number ?? '',
                'worker' => $wo?->worker ?? '',
                'current_status' => $wo?->current_status ?? '',
            ];
        } else {
            $base['spareparts'] = $po->quotation
                ? $po->quotation->detailQuotations
                    ->filter(fn ($d) => $d->sparepart_id)
                    ->map(fn ($d) => [
                        'sparepart_id' => $d->sparepart_id,
                        'sparepart_name' => $d->sparepart?->sparepart_name ?? '',
                        'sparepart_number' => $d->sparepart?->sparepart_number ?? '',
                        'quantity' => (int) $d->quantity,
                    ])->values()->toArray()
                : [];
        }

        return $base;
    }

    public function get(Request $request, $id)
    {
        try {
            $borrow = $this->getAccessedBorrow($request)
                ->with(['detailBorrows.sparepart', 'branch', 'purchaseOrder.quotation', 'purchaseOrder.workOrder'])
                ->findOrFail($id);

            return response()->json([
                'message' => 'Borrow retrieved successfully',
                'data' => $this->formatBorrow($borrow)
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'purchaseOrderId' => 'required|exists:purchase_orders,id',
            'notes' => 'required|string',
            'spareparts' => 'required|array|min:1',
            'spareparts.*.sparepartId' => 'required|exists:spareparts,id|distinct',
            'spareparts.*.quantity' => 'required|integer|min:1',
        ]);

        DB::beginTransaction();

        try {
            $user = $request->user();
            $branch = $this->resolveUserBranch($user);

            if (!$branch) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Branch not found for the current user.',
                ], Response::HTTP_BAD_REQUEST);
            }

            $purchaseOrder = PurchaseOrder::with('quotation')->find($request->input('purchaseOrderId'));
            if (!$this->isServicePurchaseOrder($purchaseOrder)) {
                DB::rollBack();
                return response()->json([
                    'message' => 'The selected purchase order must be of type Service.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Reject up front if any requested quantity exceeds the branch's current stock,
            // so a borrow that could never be sent is never created in the first place.
            $lines = $request->input('spareparts');
            $spareparts = Sparepart::whereIn('id', collect($lines)->pluck('sparepartId'))->get()->keyBy('id');
            foreach ($lines as $line) {
                $sparepart = $spareparts->get($line['sparepartId']);
                $available = $this->stockService->getQuantity($sparepart, $branch->id);
                if ($available < $line['quantity']) {
                    DB::rollBack();
                    return $this->statusError("Insufficient stock for {$sparepart->sparepart_name} ({$sparepart->sparepart_number}) in branch {$branch->name}: available {$available}, requested {$line['quantity']}");
                }
            }

            $borrow = Borrow::create([
                'borrow_number' => $this->generateBorrowNumber($branch, $user),
                'branch_id' => $branch->id,
                'employee_id' => $user->id,
                'purchase_order_id' => $purchaseOrder->id,
                'current_status' => self::CREATED,
                'status' => [
                    [
                        'state' => self::CREATED,
                        'employee' => $user->username,
                        'timestamp' => now()->toIso8601String(),
                    ],
                ],
                'notes' => $request->input('notes'),
            ]);

            foreach ($request->input('spareparts') as $line) {
                DetailBorrow::create([
                    'borrow_id' => $borrow->id,
                    'sparepart_id' => $line['sparepartId'],
                    'quantity' => $line['quantity'],
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Borrow created successfully',
                'data' => $this->formatBorrow($borrow->fresh(['detailBorrows.sparepart', 'branch', 'purchaseOrder.quotation', 'purchaseOrder.workOrder']))
            ], Response::HTTP_CREATED);
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->handleError($th, 'Failed to create borrow');
        }
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'purchaseOrderId' => 'required|exists:purchase_orders,id',
            'notes' => 'required|string',
            'spareparts' => 'required|array|min:1',
            'spareparts.*.sparepartId' => 'required|exists:spareparts,id|distinct',
            'spareparts.*.quantity' => 'required|integer|min:1',
        ]);

        DB::beginTransaction();

        try {
            $borrow = $this->getAccessedBorrow($request)->lockForUpdate()->find($id);

            if (!$borrow) {
                DB::rollBack();
                return $this->handleNotFound('Borrow not found');
            }

            if (!$this->ownsBorrow($request, $borrow)) {
                DB::rollBack();
                return $this->handleForbidden('You can only modify your own borrow');
            }

            if ($borrow->current_status !== self::CREATED) {
                DB::rollBack();
                return $this->statusError('Only a Created borrow can be edited');
            }

            $purchaseOrder = PurchaseOrder::with('quotation')->find($request->input('purchaseOrderId'));
            if (!$this->isServicePurchaseOrder($purchaseOrder)) {
                DB::rollBack();
                return response()->json([
                    'message' => 'The selected purchase order must be of type Service.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $borrow->purchase_order_id = $purchaseOrder->id;
            $borrow->notes = $request->input('notes');
            $borrow->save();

            // Delete-and-recreate the line items (BMJ pattern).
            $borrow->detailBorrows()->delete();
            foreach ($request->input('spareparts') as $line) {
                DetailBorrow::create([
                    'borrow_id' => $borrow->id,
                    'sparepart_id' => $line['sparepartId'],
                    'quantity' => $line['quantity'],
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Borrow updated successfully',
                'data' => $this->formatBorrow($borrow->fresh(['detailBorrows.sparepart', 'branch', 'purchaseOrder.quotation', 'purchaseOrder.workOrder']))
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->handleError($th, 'Failed to update borrow');
        }
    }

    public function approve(Request $request, $id)
    {
        return $this->transition($request, $id, self::CREATED, self::APPROVED, 'Only a Created borrow can be approved', 'Borrow approved');
    }

    public function reject(Request $request, $id)
    {
        $request->validate(['notes' => 'required|string']);

        return $this->transition(
            $request,
            $id,
            self::CREATED,
            self::REJECTED,
            'Only a Created borrow can be rejected',
            'Borrow rejected',
            fn (Borrow $borrow) => $borrow->reject_notes = $request->input('notes')
        );
    }

    public function cancel(Request $request, $id)
    {
        return $this->transition($request, $id, self::CREATED, self::CANCELLED, 'Only a Created borrow can be cancelled', 'Borrow cancelled', null, true);
    }

    /**
     * Shared status-only transition guarded on a single from-status. No stock effect;
     * stock-bearing transitions (send/done) implement their own flow. When $requireOwner
     * is set, only the creator (or Director) may run it — used by the Marketing-owned
     * transitions (cancel, kembali).
     */
    protected function transition(Request $request, $id, string $from, string $to, string $error, string $reason, ?callable $mutate = null, bool $requireOwner = false)
    {
        DB::beginTransaction();

        try {
            $borrow = $this->getAccessedBorrow($request)->lockForUpdate()->find($id);

            if (!$borrow) {
                DB::rollBack();
                return $this->handleNotFound('Borrow not found');
            }

            if ($requireOwner && !$this->ownsBorrow($request, $borrow)) {
                DB::rollBack();
                return $this->handleForbidden('You can only modify your own borrow');
            }

            if ($borrow->current_status !== $from) {
                DB::rollBack();
                return $this->statusError($error);
            }

            if ($mutate) {
                $mutate($borrow);
            }

            $this->appendStatus($borrow, $to, $request);

            DB::commit();

            return response()->json([
                'message' => $reason,
                'data' => $this->formatBorrow($borrow->fresh(['detailBorrows.sparepart', 'branch', 'purchaseOrder.quotation', 'purchaseOrder.workOrder']))
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->handleError($th, 'Failed to update borrow');
        }
    }

    protected function isServicePurchaseOrder(?PurchaseOrder $purchaseOrder): bool
    {
        return $purchaseOrder && $purchaseOrder->quotation && $purchaseOrder->quotation->type === 'Service';
    }

    /**
     * Send = signed physical handover. Decreases branch stock for every line, all-or-nothing,
     * then moves Approved -> Borrowed. Mirrors the lock-and-check pattern stock decrements use
     * elsewhere so a short line aborts the whole transaction (TOCTOU-safe).
     */
    public function send(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $borrow = $this->getAccessedBorrow($request)
                ->with('detailBorrows.sparepart')
                ->lockForUpdate()
                ->find($id);

            if (!$borrow) {
                DB::rollBack();
                return $this->handleNotFound('Borrow not found');
            }

            if ($borrow->current_status !== self::APPROVED) {
                DB::rollBack();
                return $this->statusError('Only an Approved borrow can be sent');
            }

            $branchName = $borrow->branch?->name ?? $borrow->branch_id;

            foreach ($borrow->detailBorrows as $detail) {
                if (!$detail->sparepart) {
                    throw new \RuntimeException("Sparepart with ID {$detail->sparepart_id} not found.");
                }

                $record = $this->stockService->ensureStockRecord($detail->sparepart, $borrow->branch_id, true);

                if ($record->quantity < $detail->quantity) {
                    DB::rollBack();
                    return $this->statusError("Insufficient stock for {$detail->sparepart->sparepart_name} ({$detail->sparepart->sparepart_number}) in branch {$branchName}: available {$record->quantity}, requested {$detail->quantity}");
                }
            }

            foreach ($borrow->detailBorrows as $detail) {
                $this->stockService->decrease(
                    $detail->sparepart,
                    $borrow->branch_id,
                    (int) $detail->quantity,
                    'Borrow',
                    $borrow->id,
                    $request->user()->id,
                    'Borrow sent (handover)'
                );
            }

            $this->appendStatus($borrow, self::BORROWED, $request);

            DB::commit();

            return response()->json([
                'message' => 'Borrow sent',
                'data' => $this->formatBorrow($borrow->fresh(['detailBorrows.sparepart', 'branch', 'purchaseOrder.quotation', 'purchaseOrder.workOrder']))
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->handleError($th, 'Failed to send borrow');
        }
    }

    public function kembali(Request $request, $id)
    {
        $request->validate(['notes' => 'required|string']);

        return $this->transition(
            $request,
            $id,
            self::BORROWED,
            self::RETURNED,
            'Only a Borrowed borrow can be returned',
            'Borrow returned',
            fn (Borrow $borrow) => $borrow->return_notes = $request->input('notes'),
            true
        );
    }

    /**
     * Done = reconciliation. Inventory records the actual returned quantity per line
     * (0..borrowed); returned units restock the branch. Any shortfall (returned < borrowed)
     * means those units were sold and must be justified by a Spareparts-type PO that covers
     * the missing quantities. Moves Returned -> Done.
     */
    public function done(Request $request, $id)
    {
        $request->validate([
            'returned' => 'required|array|min:1',
            'returned.*.sparepartId' => 'required|exists:spareparts,id',
            'returned.*.quantityReturn' => 'required|integer|min:0',
            'sparepartPoId' => 'nullable|exists:purchase_orders,id',
        ]);

        DB::beginTransaction();

        try {
            $borrow = $this->getAccessedBorrow($request)
                ->with('detailBorrows.sparepart')
                ->lockForUpdate()
                ->find($id);

            if (!$borrow) {
                DB::rollBack();
                return $this->handleNotFound('Borrow not found');
            }

            if ($borrow->current_status !== self::RETURNED) {
                DB::rollBack();
                return $this->statusError('Only a Returned borrow can be completed');
            }

            $returnedBySparepart = collect($request->input('returned'))
                ->keyBy('sparepartId');

            // Validate every line is present and within range; compute shortfalls.
            $shortfalls = [];
            foreach ($borrow->detailBorrows as $detail) {
                if (!$returnedBySparepart->has($detail->sparepart_id)) {
                    DB::rollBack();
                    return $this->statusError("Missing returned quantity for sparepart {$detail->sparepart_id}");
                }

                $qtyReturn = (int) $returnedBySparepart[$detail->sparepart_id]['quantityReturn'];
                if ($qtyReturn > $detail->quantity) {
                    DB::rollBack();
                    return $this->statusError("Returned quantity exceeds borrowed quantity for {$detail->sparepart?->sparepart_name}");
                }

                $missing = $detail->quantity - $qtyReturn;
                if ($missing > 0) {
                    $shortfalls[$detail->sparepart_id] = $missing;
                }
            }

            // Shortfall requires a covering Spareparts-type PO.
            $sparepartPoId = $request->input('sparepartPoId');
            if (!empty($shortfalls)) {
                $covered = $this->validateShortfallPo($sparepartPoId, $shortfalls);
                if ($covered !== true) {
                    DB::rollBack();
                    return $this->statusError($covered);
                }
                $borrow->sparepart_po_id = $sparepartPoId;
            }

            // Persist returned quantities and restock.
            foreach ($borrow->detailBorrows as $detail) {
                $qtyReturn = (int) $returnedBySparepart[$detail->sparepart_id]['quantityReturn'];
                $detail->quantity_return = $qtyReturn;
                $detail->save();

                // If there's a covering PO, the PO already debited the shortfall. We must credit
                // the full borrowed amount (returned + shortfall) back to inventory to prevent
                // double-debiting.
                $creditQty = $qtyReturn;
                $shortfall = $detail->quantity - $qtyReturn;
                if ($shortfall > 0 && $sparepartPoId) {
                    $creditQty += $shortfall;
                }

                if ($creditQty > 0 && $detail->sparepart) {
                    $this->stockService->increase(
                        $detail->sparepart,
                        $borrow->branch_id,
                        $creditQty,
                        'Borrow',
                        $borrow->id,
                        $request->user()->id,
                        'Borrow returned (reconciliation)' . ($shortfall > 0 ? " + offset $shortfall for PO debit" : '')
                    );
                }
            }

            $this->appendStatus($borrow, self::DONE, $request);

            DB::commit();

            return response()->json([
                'message' => 'Borrow completed',
                'data' => $this->formatBorrow($borrow->fresh(['detailBorrows.sparepart', 'branch', 'purchaseOrder.quotation', 'purchaseOrder.workOrder']))
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->handleError($th, 'Failed to complete borrow');
        }
    }

    /**
     * @param array<int,int> $shortfalls sparepart_id => missing quantity
     * @return true|string  true if covered, else an error message
     */
    protected function validateShortfallPo($sparepartPoId, array $shortfalls)
    {
        if (!$sparepartPoId) {
            return 'A Sparepart purchase order is required when returned quantity is less than borrowed.';
        }

        $po = PurchaseOrder::with('quotation.detailQuotations')->find($sparepartPoId);
        if (!$po || !$po->quotation || $po->quotation->type !== 'Spareparts') {
            return 'The reconciliation purchase order must be of type Spareparts.';
        }

        $available = $po->quotation->detailQuotations
            ->filter(fn ($d) => $d->sparepart_id)
            ->groupBy('sparepart_id')
            ->map(fn ($lines) => (int) $lines->sum('quantity'));

        foreach ($shortfalls as $sparepartId => $missing) {
            if (($available[$sparepartId] ?? 0) < $missing) {
                return 'The selected Sparepart purchase order does not cover the missing quantities.';
            }
        }

        return true;
    }

    protected function getAccessedBorrow($request)
    {
        // Role gating is handled at the route; all accessing roles see every borrow.
        // Per-borrow ownership (for Marketing-owned mutations) is enforced via ownsBorrow().
        return Borrow::query();
    }

    /**
     * The creator owns their borrow; Director may act on any. Used to keep one Marketing
     * user from editing/cancelling/returning another Marketing user's borrow.
     */
    protected function ownsBorrow(Request $request, Borrow $borrow): bool
    {
        $user = $request->user();

        return $borrow->employee_id === $user->id
            || strtolower($user->role) === 'director';
    }

    protected function appendStatus(Borrow $borrow, string $state, Request $request): void
    {
        $user = $request->user();
        $trail = $borrow->status ?? [];
        if (!is_array($trail)) {
            $trail = [];
        }

        $trail[] = [
            'state' => $state,
            'employee' => $user->username,
            'timestamp' => now()->toIso8601String(),
        ];

        $borrow->status = $trail;
        $borrow->current_status = $state;
        $borrow->save();
    }

    protected function formatBorrow(Borrow $borrow): array
    {
        $po = $borrow->purchaseOrder;
        $wo = $po?->workOrder;

        return [
            'id' => $borrow->id,
            'borrow_number' => $borrow->borrow_number,
            'branch' => [
                'id' => $borrow->branch?->id,
                'name' => $borrow->branch?->name,
            ],
            'purchase_order' => [
                'id' => $po?->id,
                'po_number' => $po?->po_number ?? '',
                'purchase_order_number' => $po?->purchase_order_number ?? '',
                'type' => $po?->quotation?->type ?? '',
            ],
            'work_order' => [
                'id' => $wo?->id,
                'work_order_number' => $wo?->work_order_number ?? '',
                'worker' => $wo?->worker ?? '',
            ],
            'sparepart_po_id' => $borrow->sparepart_po_id,
            'current_status' => $borrow->current_status,
            'status' => $borrow->status ?? [],
            'notes' => $borrow->notes ?? '',
            'return_notes' => $borrow->return_notes ?? '',
            'reject_notes' => $borrow->reject_notes ?? '',
            'spareparts' => $borrow->detailBorrows->map(function ($detail) use ($borrow) {
                $stockInBranch = 0;
                if ($detail->sparepart) {
                    $stockInBranch = $this->stockService->getQuantity($detail->sparepart, $borrow->branch_id);
                }

                return [
                    'sparepart_id' => $detail->sparepart_id,
                    'sparepart_name' => $detail->sparepart?->sparepart_name ?? '',
                    'sparepart_number' => $detail->sparepart?->sparepart_number ?? '',
                    'quantity' => $detail->quantity,
                    'quantity_return' => $detail->quantity_return,
                    'stock_in_branch' => $stockInBranch,
                ];
            })->toArray(),
        ];
    }

    protected function statusError(string $message)
    {
        return response()->json(['message' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    protected function resolveUserBranch($user): ?Branch
    {
        if (!$user || !$user->branch) {
            return null;
        }

        return $user->branch;
    }

    protected function generateBorrowNumber(Branch $branch, $user): string
    {
        $currentMonth = now()->month;
        $romanMonth = $this->getRomanMonth($currentMonth);
        $year = now()->format('Y');
        $branchCode = $branch->code ?? 'JKT';
        $userId = $user->id;

        // Monthly-resetting sequence for this branch, taken under lock to avoid duplicate numbers.
        $latest = Borrow::query()
            ->where('branch_id', $branch->id)
            ->whereMonth('created_at', $currentMonth)
            ->whereYear('created_at', $year)
            ->latest('id')
            ->lockForUpdate()
            ->first();

        if (!$latest) {
            $seq = 1;
        } else {
            $parts = explode('/', $latest->borrow_number);
            $seq = ((int) ($parts[1] ?? 0)) + 1;
        }

        return "BOR/{$seq}/BMJ-MEGAH/{$branchCode}/{$userId}/{$romanMonth}/{$year}";
    }

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
