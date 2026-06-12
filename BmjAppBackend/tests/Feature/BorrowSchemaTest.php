<?php

namespace Tests\Feature;

use App\Models\Borrow;
use App\Models\DetailBorrow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BorrowSchemaTest extends TestCase
{
    use RefreshDatabase;
    use BorrowTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBorrowWorld();
    }

    public function test_borrow_links_to_service_po_and_lines_accept_null_quantity_return(): void
    {
        $sparepart = $this->makeSparepart();
        $po = $this->makePurchaseOrder('Service');
        $employee = $this->makeEmployee('Marketing');

        $borrow = Borrow::create([
            'borrow_number' => 'BOR/1/BMJ-MEGAH/JKT/1/VI/2026',
            'branch_id' => $this->branch->id,
            'employee_id' => $employee->id,
            'purchase_order_id' => $po->id,
            'current_status' => 'Created',
            'notes' => 'test',
        ]);

        DetailBorrow::create([
            'borrow_id' => $borrow->id,
            'sparepart_id' => $sparepart->id,
            'quantity' => 5,
        ]);

        $this->assertSame('Service', $borrow->purchaseOrder->quotation->type);
        $this->assertNotNull($borrow->purchaseOrder->workOrder);
        $this->assertNull($borrow->detailBorrows()->first()->quantity_return);
        $this->assertNull($borrow->sparepartPo);
    }

    public function test_deleting_a_borrow_cascades_detail_lines(): void
    {
        $sparepart = $this->makeSparepart();
        $po = $this->makePurchaseOrder('Service');
        $employee = $this->makeEmployee('Marketing');

        $borrow = Borrow::create([
            'borrow_number' => 'BOR/2/BMJ-MEGAH/JKT/1/VI/2026',
            'branch_id' => $this->branch->id,
            'employee_id' => $employee->id,
            'purchase_order_id' => $po->id,
            'current_status' => 'Created',
        ]);

        DetailBorrow::create([
            'borrow_id' => $borrow->id,
            'sparepart_id' => $sparepart->id,
            'quantity' => 1,
        ]);

        $borrow->delete();

        $this->assertSame(0, DetailBorrow::where('borrow_id', $borrow->id)->count());
    }
}
