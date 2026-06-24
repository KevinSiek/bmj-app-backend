<?php

namespace Tests\Feature;

use App\Models\Borrow;
use App\Models\DetailBorrow;
use App\Models\Sparepart;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BorrowStockTest extends TestCase
{
    use RefreshDatabase;
    use BorrowTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBorrowWorld();
    }

    public function test_send_decreases_stock_and_moves_to_borrowed(): void
    {
        [$borrow, $sparepart] = $this->approvedBorrow(['quantity' => 4, 'stock' => 10]);

        $this->actingAsRole('Inventory Admin');
        $this->postJson("/api/borrow/send/{$borrow->id}")->assertOk();

        $this->assertSame('Borrowed', $borrow->fresh()->current_status);
        $this->assertSame(6, $this->stockOf($sparepart));
        $this->assertSame(1, StockMovement::where('source_type', 'Borrow')->where('delta', -4)->count());
    }

    public function test_send_is_all_or_nothing_on_insufficient_stock(): void
    {
        $this->actingAsRole('Marketing');
        $ok = $this->makeSparepart(10);
        $short = $this->makeSparepart(1);
        $po = $this->makePurchaseOrder('Service');
        $this->postJson('/api/borrow', $this->makeBorrowPayload($po, [
            ['sparepart' => $ok, 'quantity' => 3],
            ['sparepart' => $short, 'quantity' => 5],
        ]))->assertCreated();
        $borrow = Borrow::latest('id')->first();
        $this->actingAsRole('Head Inventory');
        $this->postJson("/api/borrow/approve/{$borrow->id}")->assertOk();

        $this->actingAsRole('Inventory Admin');
        $this->postJson("/api/borrow/send/{$borrow->id}")->assertStatus(422);

        // No partial decrement: both stocks untouched, status still Approved.
        $this->assertSame(10, $this->stockOf($ok));
        $this->assertSame(1, $this->stockOf($short));
        $this->assertSame('Approved', $borrow->fresh()->current_status);
    }

    public function test_send_blocked_when_not_approved(): void
    {
        [$borrow] = $this->createdBorrow(['quantity' => 1, 'stock' => 5]);
        $this->actingAsRole('Inventory Admin');
        $this->postJson("/api/borrow/send/{$borrow->id}")->assertStatus(422);
    }

    public function test_kembali_requires_notes_and_moves_to_returned(): void
    {
        $borrow = $this->borrowedBorrow(['quantity' => 2, 'stock' => 5])[0];

        \Laravel\Sanctum\Sanctum::actingAs($this->creator);
        $this->postJson("/api/borrow/kembali/{$borrow->id}", [])->assertStatus(422);

        $this->postJson("/api/borrow/kembali/{$borrow->id}", ['notes' => 'all back'])->assertOk();
        $this->assertSame('Returned', $borrow->fresh()->current_status);
        $this->assertSame('all back', $borrow->fresh()->return_notes);
    }

    public function test_done_full_return_restores_stock(): void
    {
        [$borrow, $sparepart] = $this->returnedBorrow(['quantity' => 4, 'stock' => 10]);
        // After send: stock 6.

        $this->actingAsRole('Inventory Admin');
        $detail = $borrow->detailBorrows()->first();
        $this->postJson("/api/borrow/done/{$borrow->id}", [
            'returned' => [['sparepartId' => $sparepart->id, 'quantityReturn' => 4]],
        ])->assertOk();

        $this->assertSame('Done', $borrow->fresh()->current_status);
        $this->assertSame(10, $this->stockOf($sparepart));
        $this->assertSame(4, $detail->fresh()->quantity_return);
    }

    public function test_done_with_shortfall_requires_covering_sparepart_po(): void
    {
        [$borrow, $sparepart] = $this->returnedBorrow(['quantity' => 5, 'stock' => 10]);
        // Returned only 3 of 5 -> shortfall 2.

        $coveringPo = $this->makePurchaseOrder('Spareparts', [
            ['sparepart' => $sparepart, 'quantity' => 2],
        ]);

        $this->actingAsRole('Inventory Admin');
        $this->postJson("/api/borrow/done/{$borrow->id}", [
            'returned' => [['sparepartId' => $sparepart->id, 'quantityReturn' => 3]],
            'sparepartPoId' => $coveringPo->id,
        ])->assertOk();

        $this->assertSame('Done', $borrow->fresh()->current_status);
        // Stock after send was 5; +3 returned = 8.
        $this->assertSame(8, $this->stockOf($sparepart));
        $this->assertSame($coveringPo->id, $borrow->fresh()->sparepart_po_id);
    }

    public function test_done_shortfall_without_po_is_rejected(): void
    {
        [$borrow, $sparepart] = $this->returnedBorrow(['quantity' => 5, 'stock' => 10]);

        $this->actingAsRole('Inventory Admin');
        $this->postJson("/api/borrow/done/{$borrow->id}", [
            'returned' => [['sparepartId' => $sparepart->id, 'quantityReturn' => 3]],
        ])->assertStatus(422);

        $this->assertSame('Returned', $borrow->fresh()->current_status);
    }

    public function test_done_rejects_service_type_sparepart_po(): void
    {
        [$borrow, $sparepart] = $this->returnedBorrow(['quantity' => 5, 'stock' => 10]);
        $servicePo = $this->makePurchaseOrder('Service');

        $this->actingAsRole('Inventory Admin');
        $this->postJson("/api/borrow/done/{$borrow->id}", [
            'returned' => [['sparepartId' => $sparepart->id, 'quantityReturn' => 3]],
            'sparepartPoId' => $servicePo->id,
        ])->assertStatus(422);
    }

    public function test_done_rejects_sparepart_po_that_does_not_cover_shortfall(): void
    {
        [$borrow, $sparepart] = $this->returnedBorrow(['quantity' => 5, 'stock' => 10]);
        // Shortfall is 2, but covering PO only has 1.
        $weakPo = $this->makePurchaseOrder('Spareparts', [
            ['sparepart' => $sparepart, 'quantity' => 1],
        ]);

        $this->actingAsRole('Inventory Admin');
        $this->postJson("/api/borrow/done/{$borrow->id}", [
            'returned' => [['sparepartId' => $sparepart->id, 'quantityReturn' => 3]],
            'sparepartPoId' => $weakPo->id,
        ])->assertStatus(422);

        $this->assertSame('Returned', $borrow->fresh()->current_status);
    }

    public function test_done_rejects_quantity_return_over_borrowed(): void
    {
        [$borrow, $sparepart] = $this->returnedBorrow(['quantity' => 2, 'stock' => 10]);

        $this->actingAsRole('Inventory Admin');
        $this->postJson("/api/borrow/done/{$borrow->id}", [
            'returned' => [['sparepartId' => $sparepart->id, 'quantityReturn' => 5]],
        ])->assertStatus(422);
    }

    public function test_full_lifecycle_writes_matching_ledger_pairs(): void
    {
        [$borrow, $sparepart] = $this->returnedBorrow(['quantity' => 4, 'stock' => 10]);

        $this->actingAsRole('Inventory Admin');
        $this->postJson("/api/borrow/done/{$borrow->id}", [
            'returned' => [['sparepartId' => $sparepart->id, 'quantityReturn' => 4]],
        ])->assertOk();

        $decrease = StockMovement::where('source_type', 'Borrow')->where('delta', '<', 0)->sum('delta');
        $increase = StockMovement::where('source_type', 'Borrow')->where('delta', '>', 0)->sum('delta');
        $this->assertSame(-4, (int) $decrease);
        $this->assertSame(4, (int) $increase);
    }

    // --- builders that drive the real endpoints up to a given status ---

    /** Creator (Marketing) of the borrow built by createdBorrow, so kembali can act as them. */
    protected ?\App\Models\Employee $creator = null;

    /** @return array{0: Borrow, 1: Sparepart} */
    protected function createdBorrow(array $opts): array
    {
        $this->creator = $this->actingAsRole('Marketing');
        $sparepart = $this->makeSparepart($opts['stock']);
        $po = $this->makePurchaseOrder('Service');
        $this->postJson('/api/borrow', $this->makeBorrowPayload($po, [
            ['sparepart' => $sparepart, 'quantity' => $opts['quantity']],
        ]))->assertCreated();

        return [Borrow::latest('id')->first(), $sparepart];
    }

    protected function approvedBorrow(array $opts): array
    {
        [$borrow, $sparepart] = $this->createdBorrow($opts);
        $this->actingAsRole('Head Inventory');
        $this->postJson("/api/borrow/approve/{$borrow->id}")->assertOk();

        return [$borrow, $sparepart];
    }

    protected function borrowedBorrow(array $opts): array
    {
        [$borrow, $sparepart] = $this->approvedBorrow($opts);
        $this->actingAsRole('Inventory Admin');
        $this->postJson("/api/borrow/send/{$borrow->id}")->assertOk();

        return [$borrow, $sparepart];
    }

    protected function returnedBorrow(array $opts): array
    {
        [$borrow, $sparepart] = $this->borrowedBorrow($opts);
        \Laravel\Sanctum\Sanctum::actingAs($this->creator);
        $this->postJson("/api/borrow/kembali/{$borrow->id}", ['notes' => 'returned'])->assertOk();

        return [$borrow, $sparepart];
    }
}
