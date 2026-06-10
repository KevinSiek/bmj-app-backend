<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\BranchSparepart;
use App\Models\Sparepart;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class SparepartStockService
{
    /**
     * Resolve branch input (model, id, code, or name) to a branch id.
     */
    public function resolveBranchId($branch): int
    {
        if ($branch instanceof Branch) {
            return (int) $branch->id;
        }

        if (is_numeric($branch)) {
            return (int) $branch;
        }

        if (is_string($branch)) {
            $normalized = strtolower($branch);
            $branchModel = Branch::query()
                ->whereRaw('LOWER(name) = ?', [$normalized])
                ->orWhereRaw('LOWER(code) = ?', [$normalized])
                ->first();

            if ($branchModel) {
                return (int) $branchModel->id;
            }
        }

        throw new ModelNotFoundException('Branch could not be resolved.');
    }

    /**
     * Get current quantity for a sparepart in the given branch.
     */
    public function getQuantity(Sparepart $sparepart, $branch): int
    {
        $branchId = $this->resolveBranchId($branch);

        return (int) BranchSparepart::query()
            ->where('sparepart_id', $sparepart->id)
            ->where('branch_id', $branchId)
            ->value('quantity') ?? 0;
    }

    /**
     * Ensure there is a stock record for the sparepart & branch.
     */
    public function ensureStockRecord(Sparepart $sparepart, $branch, bool $lockForUpdate = false): BranchSparepart
    {
        $branchId = $this->resolveBranchId($branch);

        $query = BranchSparepart::query()
            ->where('sparepart_id', $sparepart->id)
            ->where('branch_id', $branchId);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $record = $query->first();

        if (!$record) {
            $record = BranchSparepart::create([
                'sparepart_id' => $sparepart->id,
                'branch_id' => $branchId,
                'quantity' => 0,
            ]);

            if ($lockForUpdate) {
                $record = $this->ensureStockRecord($sparepart, $branchId, true);
            }
        }

        return $record;
    }

    /**
     * Increase stock quantity for the given sparepart & branch.
     *
     * Context params (sourceType/sourceId/employeeId/reason) are optional so existing callers keep
     * working; when present they label the ledger row written for this movement.
     */
    public function increase(
        Sparepart $sparepart,
        $branch,
        int $amount,
        ?string $sourceType = null,
        $sourceId = null,
        ?int $employeeId = null,
        ?string $reason = null
    ): BranchSparepart {
        if ($amount <= 0) {
            return $this->ensureStockRecord($sparepart, $branch);
        }

        $record = $this->ensureStockRecord($sparepart, $branch, true);
        $record->quantity += $amount;
        $record->save();

        $this->logMovement($sparepart, $record->branch_id, $amount, $sourceType, $sourceId, $employeeId, $reason);

        return $record;
    }

    /**
     * Decrease stock quantity. Stock is ALLOWED to go negative — a negative quantity is the
     * running indent (units owed). Shortfalls are tracked separately as BackOrders, so the
     * stock column is permitted to drop below zero on automatic decrements.
     */
    public function decrease(
        Sparepart $sparepart,
        $branch,
        int $amount,
        ?string $sourceType = null,
        $sourceId = null,
        ?int $employeeId = null,
        ?string $reason = null
    ): BranchSparepart {
        if ($amount <= 0) {
            return $this->ensureStockRecord($sparepart, $branch);
        }

        $record = $this->ensureStockRecord($sparepart, $branch, true);
        $record->quantity = $record->quantity - $amount;
        $record->save();

        $this->logMovement($sparepart, $record->branch_id, -$amount, $sourceType, $sourceId, $employeeId, $reason);

        return $record;
    }

    /**
     * Append one row to the stock_movements ledger. Runs inside the caller's transaction.
     */
    public function logMovement(
        Sparepart $sparepart,
        $branch,
        int $delta,
        ?string $sourceType = null,
        $sourceId = null,
        ?int $employeeId = null,
        ?string $reason = null
    ): StockMovement {
        return StockMovement::create([
            'sparepart_id' => $sparepart->id,
            'branch_id' => $this->resolveBranchId($branch),
            'delta' => $delta,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'reason' => $reason,
            'employee_id' => $employeeId,
        ]);
    }

    /**
     * Check if requested quantity is available.
     */
    public function hasSufficientStock(Sparepart $sparepart, $branch, int $requiredQuantity): bool
    {
        if ($requiredQuantity <= 0) {
            return true;
        }

        return $this->getQuantity($sparepart, $branch) >= $requiredQuantity;
    }
}
