<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SparepartMovement;
use App\Models\DetailSparepartMovement;
use App\Models\Sparepart;
use App\Services\SparepartStockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SparepartMovementController extends Controller
{
    protected $sparepartStockService;

    // State
    const CREATED = 'Created';
    const SENT = 'Send';
    const RECEIVED = 'Received';
    const CANCELLED = 'Cancelled';

    public function __construct(SparepartStockService $sparepartStockService)
    {
        $this->sparepartStockService = $sparepartStockService;
    }

    public function index(Request $request)
    {
        $query = SparepartMovement::with('employee');

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('movement_number', 'LIKE', "%{$search}%")
                  ->orWhere('source_branch', 'LIKE', "%{$search}%")
                  ->orWhere('target_branch', 'LIKE', "%{$search}%");
        }

        $query->orderBy('created_at', 'desc');

        $movements = $query->paginate($request->input('per_page', 10));

        return response()->json($movements);
    }

    public function show($id)
    {
        $movement = SparepartMovement::with(['employee', 'detailSparepartMovements.sparepart'])
            ->findOrFail($id);

        return response()->json($movement);
    }

    public function store(Request $request)
    {
        $request->validate([
            'source_branch' => 'required|string',
            'target_branch' => 'required|string',
            'details' => 'required|array|min:1',
            'details.*.sparepart_id' => 'required|exists:spareparts,id',
            'details.*.quantity' => 'required|integer|min:1',
            'reason' => 'nullable|string',
        ]);

        $user = $request->user();

        // Check stock availability in source branch BEFORE creating the movement
        foreach ($request->details as $item) {
            $sparepart = Sparepart::findOrFail($item['sparepart_id']);
            if (!$this->sparepartStockService->hasSufficientStock($sparepart, $request->source_branch, $item['quantity'])) {
                return response()->json([
                    'message' => 'Insufficient stock for sparepart: ' . $sparepart->sparepart_name
                ], 400);
            }
        }

        try {
            DB::beginTransaction();

            $date = date('Ymd');
            $lastMovement = SparepartMovement::whereDate('created_at', date('Y-m-d'))->count();
            $number = str_pad($lastMovement + 1, 3, '0', STR_PAD_LEFT);
            $movementNumber = 'SM-' . $date . '-' . $number;

            $movement = SparepartMovement::create([
                'movement_number' => $movementNumber,
                'employee_id' => $user->id,
                'source_branch' => $request->source_branch,
                'target_branch' => $request->target_branch,
                'current_status' => 'Created',
                'status' => [
                    ['state' => 'Created', 'timestamp' => now(), 'employee' => $user->fullname]
                ],
                'reason' => $request->reason,
            ]);

            foreach ($request->details as $item) {
                DetailSparepartMovement::create([
                    'sparepart_movement_id' => $movement->id,
                    'sparepart_id' => $item['sparepart_id'],
                    'quantity' => $item['quantity'],
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Stock Movement created successfully',
                'data' => $movement->load('detailSparepartMovements.sparepart')
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to create Stock Movement: ' . $e->getMessage()], 500);
        }
    }

    public function send(Request $request, $id)
    {
        $movement = SparepartMovement::with('detailSparepartMovements')->findOrFail($id);
        $user = $request->user();

        if ($movement->current_status !== 'Created') {
            return response()->json(['message' => 'Only Created movement can be sent'], 400);
        }

        if ($movement->source_branch !== $user->branch->name && $user->role !== 'Director' && $user->role !== 'Head Inventory') {
             return response()->json(['message' => 'Only source branch inventory can send this movement'], 403);
        }

        try {
            DB::beginTransaction();

            // Decrease stock in source branch
            foreach ($movement->detailSparepartMovements as $detail) {
                $sparepart = Sparepart::findOrFail($detail->sparepart_id);

                if (!$this->sparepartStockService->hasSufficientStock($sparepart, $movement->source_branch, $detail->quantity)) {
                    throw new \Exception('Insufficient stock for sparepart: ' . $sparepart->sparepart_name . ' in source branch.');
                }

                $this->sparepartStockService->decrease(
                    $sparepart,
                    $movement->source_branch,
                    $detail->quantity,
                    'SparepartMovement',
                    $movement->id,
                    $user->id,
                    'Stock Transfer to ' . $movement->target_branch
                );
            }

            $movement->current_status = 'Send';
            $statusArray = $movement->status ?? [];
            $statusArray[] = ['state' => 'Send', 'timestamp' => now(), 'employee' => $user->fullname];
            $movement->status = $statusArray;
            $movement->save();

            DB::commit();

            return response()->json(['message' => 'Stock Movement sent successfully', 'data' => $movement]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to send Stock Movement: ' . $e->getMessage()], 400);
        }
    }

    public function cancel(Request $request, $id)
    {
        $movement = SparepartMovement::findOrFail($id);
        $user = $request->user();

        if ($movement->current_status !== 'Created') {
            return response()->json(['message' => 'Only Created movement can be cancelled'], 400);
        }

        if ($movement->source_branch !== $user->branch->name && $user->role !== 'Director' && $user->role !== 'Head Inventory') {
             return response()->json(['message' => 'Only source branch inventory can cancel this movement'], 403);
        }

        $movement->current_status = 'Cancelled';
        $statusArray = $movement->status ?? [];
        $statusArray[] = ['state' => 'Cancelled', 'timestamp' => now(), 'employee' => $user->fullname];
        $movement->status = $statusArray;
        $movement->save();

        return response()->json(['message' => 'Stock Movement cancelled successfully', 'data' => $movement]);
    }

    public function receive(Request $request, $id)
    {
        $movement = SparepartMovement::with('detailSparepartMovements')->findOrFail($id);
        $user = $request->user();

        if ($movement->current_status !== 'Send') {
            return response()->json(['message' => 'Only Send movement can be received'], 400);
        }

        if ($movement->target_branch !== $user->branch->name && $user->role !== 'Director' && $user->role !== 'Head Inventory') {
            return response()->json(['message' => 'Only target branch inventory can receive this movement'], 403);
        }

        try {
            DB::beginTransaction();

            $movement->current_status = 'Received';
            $statusArray = $movement->status ?? [];
            $statusArray[] = ['state' => 'Received', 'timestamp' => now(), 'employee' => $user->fullname];
            $movement->status = $statusArray;
            $movement->save();

            // Increase stock in target branch
            foreach ($movement->detailSparepartMovements as $detail) {
                $sparepart = Sparepart::findOrFail($detail->sparepart_id);

                $this->sparepartStockService->increase(
                    $sparepart,
                    $movement->target_branch,
                    $detail->quantity,
                    'SparepartMovement',
                    $movement->id,
                    $user->id,
                    'Stock Transfer from ' . $movement->source_branch
                );
            }

            DB::commit();

            return response()->json(['message' => 'Stock Movement received and stock updated successfully', 'data' => $movement]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to receive Stock Movement: ' . $e->getMessage()], 400);
        }
    }
}
