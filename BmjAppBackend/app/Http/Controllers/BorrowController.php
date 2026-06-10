<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Borrow;
use App\Models\DetailBorrow;
use App\Models\Branch;
use App\Models\Sparepart;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use App\Services\SparepartStockService;

class BorrowController extends Controller
{
    // Lifecycle: Created (draft, no stock effect) -> Borrowed (decrease stock) ->
    // Returned (increase stock back). Cancelled from Created has no stock effect;
    // Cancelled from Borrowed reverses the decrease.
    const CREATED = 'Created';
    const BORROWED = 'Borrowed';
    const RETURNED = 'Returned';
    const CANCELLED = 'Cancelled';

    protected SparepartStockService $stockService;

    public function __construct(SparepartStockService $stockService)
    {
        $this->stockService = $stockService;
    }

    public function getAll(Request $request)
    {
        try {
            $borrows = $this->getAccessedBorrow($request)
                ->with(['detailBorrows.sparepart', 'branch', 'employee'])
                ->latest('id')
                ->paginate(20);

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

    public function get(Request $request, $id)
    {
        try {
            $borrow = $this->getAccessedBorrow($request)
                ->with(['detailBorrows.sparepart', 'branch'])
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
            'borrowerName' => 'required|string',
            'notes' => 'nullable|string',
            'spareparts' => 'required|array',
            'spareparts.*.sparepartId' => 'required|exists:spareparts,id',
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

            $borrow = Borrow::create([
                'borrow_number' => $this->generateBorrowNumber($branch, $user),
                'branch_id' => $branch->id,
                'employee_id' => $user->id,
                'borrower_name' => $request->input('borrowerName'),
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
                'data' => $borrow->load(['detailBorrows.sparepart', 'branch'])
            ], Response::HTTP_CREATED);
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->handleError($th, 'Failed to create borrow');
        }
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'borrowerName' => 'required|string',
            'notes' => 'nullable|string',
            'spareparts' => 'required|array',
            'spareparts.*.sparepartId' => 'required|exists:spareparts,id',
            'spareparts.*.quantity' => 'required|integer|min:1',
        ]);

        DB::beginTransaction();

        try {
            $borrow = $this->getAccessedBorrow($request)->lockForUpdate()->find($id);

            if (!$borrow) {
                DB::rollBack();
                return $this->handleNotFound('Borrow not found');
            }

            if ($borrow->current_status !== self::CREATED) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Only a Created borrow can be edited'
                ], Response::HTTP_BAD_REQUEST);
            }

            $borrow->borrower_name = $request->input('borrowerName');
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
                'data' => $borrow->load(['detailBorrows.sparepart', 'branch'])
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->handleError($th, 'Failed to update borrow');
        }
    }

    public function borrow(Request $request, $id)
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

            if ($borrow->current_status !== self::CREATED) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Only a Created borrow can be borrowed'
                ], Response::HTTP_BAD_REQUEST);
            }

            $branch = $borrow->branch;
            $branchName = $branch?->name ?? $borrow->branch_id;

            // All-or-nothing: lock every line's stock row and verify sufficiency before any
            // decrement, so a short line aborts the whole transaction (TOCTOU-safe).
            foreach ($borrow->detailBorrows as $detail) {
                if (!$detail->sparepart) {
                    throw new \RuntimeException("Sparepart with ID {$detail->sparepart_id} not found.");
                }

                $record = $this->stockService->ensureStockRecord($detail->sparepart, $borrow->branch_id, true);

                if ($record->quantity < $detail->quantity) {
                    DB::rollBack();
                    return response()->json([
                        'message' => "Insufficient stock for {$detail->sparepart->sparepart_name} ({$detail->sparepart->sparepart_number}) in branch {$branchName}: available {$record->quantity}, requested {$detail->quantity}"
                    ], Response::HTTP_UNPROCESSABLE_ENTITY);
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
                    'Borrow borrowed'
                );
            }

            $this->appendStatus($borrow, self::BORROWED, $request);

            DB::commit();

            return response()->json([
                'message' => 'Borrow moved to Borrowed',
                'data' => $borrow->load(['detailBorrows.sparepart', 'branch'])
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->handleError($th, 'Failed to borrow');
        }
    }

    public function returnItems(Request $request, $id)
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

            if ($borrow->current_status !== self::BORROWED) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Only a Borrowed borrow can be returned'
                ], Response::HTTP_BAD_REQUEST);
            }

            foreach ($borrow->detailBorrows as $detail) {
                if ($detail->sparepart) {
                    $this->stockService->increase(
                        $detail->sparepart,
                        $borrow->branch_id,
                        (int) $detail->quantity,
                        'Borrow',
                        $borrow->id,
                        $request->user()->id,
                        'Borrow returned'
                    );
                }
            }

            $this->appendStatus($borrow, self::RETURNED, $request);

            DB::commit();

            return response()->json([
                'message' => 'Borrow returned successfully',
                'data' => $borrow->load(['detailBorrows.sparepart', 'branch'])
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->handleError($th, 'Failed to return borrow');
        }
    }

    public function cancel(Request $request, $id)
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

            if (in_array($borrow->current_status, [self::RETURNED, self::CANCELLED])) {
                DB::rollBack();
                return response()->json([
                    'message' => 'A Returned or Cancelled borrow cannot be cancelled'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Only a Borrowed borrow has decremented stock to reverse.
            if ($borrow->current_status === self::BORROWED) {
                foreach ($borrow->detailBorrows as $detail) {
                    if ($detail->sparepart) {
                        $this->stockService->increase(
                            $detail->sparepart,
                            $borrow->branch_id,
                            (int) $detail->quantity,
                            'Borrow',
                            $borrow->id,
                            $request->user()->id,
                            'Borrow cancelled'
                        );
                    }
                }
            }

            $this->appendStatus($borrow, self::CANCELLED, $request);

            DB::commit();

            return response()->json([
                'message' => 'Borrow cancelled successfully',
                'data' => $borrow->load(['detailBorrows.sparepart', 'branch'])
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->handleError($th, 'Failed to cancel borrow');
        }
    }

    protected function getAccessedBorrow($request)
    {
        try {
            // Role gating is handled at the route; all accessing roles see every borrow.
            return Borrow::query();
        } catch (\Throwable $th) {
            return Borrow::whereNull('id');
        }
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
        return [
            'id' => $borrow->id,
            'borrow_number' => $borrow->borrow_number,
            'branch' => [
                'id' => $borrow->branch?->id,
                'name' => $borrow->branch?->name,
            ],
            'borrower_name' => $borrow->borrower_name,
            'current_status' => $borrow->current_status,
            'status' => $borrow->status ?? [],
            'notes' => $borrow->notes ?? '',
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
                    'stock_in_branch' => $stockInBranch,
                ];
            })->toArray(),
        ];
    }

    protected function resolveUserBranch($user): ?Branch
    {
        if (!$user || !$user->branch) {
            return null;
        }

        $normalized = strtolower($user->branch);

        return Branch::query()
            ->whereRaw('LOWER(name) = ?', [$normalized])
            ->orWhereRaw('LOWER(code) = ?', [$normalized])
            ->first();
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
