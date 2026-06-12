<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchSparepart;
use App\Models\Customer;
use App\Models\DetailQuotation;
use App\Models\Employee;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\Sparepart;
use App\Models\WorkOrder;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

trait BorrowTestHelpers
{
    protected Branch $branch;

    protected function setUpBorrowWorld(): void
    {
        // The branches migration seeds Jakarta/Semarang, so reuse the seeded row.
        $this->branch = Branch::where('name', 'Jakarta')->firstOrFail();
    }

    protected function makeEmployee(string $role): Employee
    {
        return Employee::factory()->create([
            'role' => $role,
            'branch' => 'Jakarta',
            'must_change_password' => false,
        ]);
    }

    protected function actingAsRole(string $role): Employee
    {
        $employee = $this->makeEmployee($role);
        Sanctum::actingAs($employee);

        return $employee;
    }

    protected function makeSparepart(int $stock = 0): Sparepart
    {
        $sparepart = Sparepart::factory()->create();

        if ($stock > 0) {
            BranchSparepart::create([
                'sparepart_id' => $sparepart->id,
                'branch_id' => $this->branch->id,
                'quantity' => $stock,
            ]);
        }

        return $sparepart;
    }

    protected function stockOf(Sparepart $sparepart): int
    {
        return (int) BranchSparepart::query()
            ->where('sparepart_id', $sparepart->id)
            ->where('branch_id', $this->branch->id)
            ->value('quantity');
    }

    /**
     * A latest-version PO of the given quotation type ('Service' or 'Spareparts').
     * Service POs get a WorkOrder; lines become DetailQuotations on the quotation.
     *
     * @param array<int, array{sparepart: Sparepart, quantity: int}> $lines
     */
    protected function makePurchaseOrder(string $type, array $lines = [], int $version = 1): PurchaseOrder
    {
        $employee = Employee::factory()->create(['must_change_password' => false]);
        $customer = Customer::factory()->create();

        $quotation = Quotation::create([
            'quotation_number' => 'Q-' . Str::random(8),
            'version' => $version,
            'slug' => Str::slug('q-' . Str::random(8)),
            'customer_id' => $customer->id,
            'project' => 'Test project',
            'type' => $type,
            'date' => now()->toDateString(),
            'amount' => 0,
            'discount' => 0,
            'subtotal' => 0,
            'ppn' => 0,
            'grand_total' => 0,
            'employee_id' => $employee->id,
            'branch_id' => $this->branch->id,
            'current_status' => 'Po',
            'review' => false,
        ]);

        foreach ($lines as $line) {
            DetailQuotation::create([
                'quotation_id' => $quotation->id,
                'sparepart_id' => $line['sparepart']->id,
                'quantity' => $line['quantity'],
                'unit_price' => 100,
            ]);
        }

        $po = PurchaseOrder::create([
            'quotation_id' => $quotation->id,
            'purchase_order_number' => 'PO/' . Str::random(8),
            'po_number' => 'CUST-' . Str::random(6),
            'purchase_order_date' => now()->toDateString(),
            'payment_due' => now()->addDays(30)->toDateString(),
            'employee_id' => $employee->id,
            'current_status' => 'Prepare',
            'version' => $version,
        ]);

        if ($type === 'Service') {
            WorkOrder::create([
                'purchase_order_id' => $po->id,
                'work_order_number' => 'WO/' . Str::random(8),
                'current_status' => 'On Progress',
            ]);
        }

        return $po;
    }

    /** @param array<int, array{sparepart: Sparepart, quantity: int}> $lines */
    protected function makeBorrowPayload(PurchaseOrder $po, array $lines): array
    {
        return [
            'purchaseOrderId' => $po->id,
            'notes' => 'Borrow for service job',
            'spareparts' => array_map(fn ($line) => [
                'sparepartId' => $line['sparepart']->id,
                'quantity' => $line['quantity'],
            ], $lines),
        ];
    }
}
