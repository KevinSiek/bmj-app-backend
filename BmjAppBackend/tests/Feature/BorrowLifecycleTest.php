<?php

namespace Tests\Feature;

use App\Models\Borrow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BorrowLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use BorrowTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBorrowWorld();
    }

    public function test_marketing_creates_borrow_against_service_po(): void
    {
        $this->actingAsRole('Marketing');
        $sparepart = $this->makeSparepart();
        $po = $this->makePurchaseOrder('Service');

        $response = $this->postJson('/api/borrow', $this->makeBorrowPayload($po, [
            ['sparepart' => $sparepart, 'quantity' => 3],
        ]));

        $response->assertCreated();
        $this->assertSame('Created', $response->json('data.current_status'));
        $this->assertSame($po->id, $response->json('data.purchase_order.id'));
        $this->assertNotEmpty($response->json('data.work_order.work_order_number'));
        $borrow = Borrow::first();
        $this->assertSame($po->id, $borrow->purchase_order_id);
        $this->assertCount(1, $borrow->status);
    }

    public function test_create_rejects_spareparts_type_po(): void
    {
        $this->actingAsRole('Marketing');
        $sparepart = $this->makeSparepart();
        $po = $this->makePurchaseOrder('Spareparts');

        $this->postJson('/api/borrow', $this->makeBorrowPayload($po, [
            ['sparepart' => $sparepart, 'quantity' => 1],
        ]))->assertStatus(422);
    }

    public function test_create_requires_notes(): void
    {
        $this->actingAsRole('Marketing');
        $sparepart = $this->makeSparepart();
        $po = $this->makePurchaseOrder('Service');

        $payload = $this->makeBorrowPayload($po, [['sparepart' => $sparepart, 'quantity' => 1]]);
        unset($payload['notes']);

        $this->postJson('/api/borrow', $payload)->assertStatus(422);
    }

    public function test_head_inventory_and_director_can_approve(): void
    {
        $borrow = $this->createdBorrow();

        $this->actingAsRole('Head Inventory');
        $this->postJson("/api/borrow/approve/{$borrow->id}")->assertOk();
        $this->assertSame('Approved', $borrow->fresh()->current_status);

        $borrow2 = $this->createdBorrow();
        $this->actingAsRole('Director');
        $this->postJson("/api/borrow/approve/{$borrow2->id}")->assertOk();
        $this->assertSame('Approved', $borrow2->fresh()->current_status);
    }

    public function test_reject_requires_notes_and_is_terminal(): void
    {
        $borrow = $this->createdBorrow();
        $this->actingAsRole('Head Inventory');

        $this->postJson("/api/borrow/reject/{$borrow->id}", [])->assertStatus(422);

        $this->postJson("/api/borrow/reject/{$borrow->id}", ['notes' => 'Not available'])->assertOk();
        $this->assertSame('Rejected', $borrow->fresh()->current_status);
        $this->assertSame('Not available', $borrow->fresh()->reject_notes);

        // Terminal: cannot approve a rejected borrow.
        $this->postJson("/api/borrow/approve/{$borrow->id}")->assertStatus(422);
    }

    public function test_marketing_cannot_approve(): void
    {
        $borrow = $this->createdBorrow();
        $this->actingAsRole('Marketing');
        $this->postJson("/api/borrow/approve/{$borrow->id}")->assertStatus(403);
    }

    public function test_inventory_admin_cannot_create(): void
    {
        $this->actingAsRole('Inventory Admin');
        $sparepart = $this->makeSparepart();
        $po = $this->makePurchaseOrder('Service');

        $this->postJson('/api/borrow', $this->makeBorrowPayload($po, [
            ['sparepart' => $sparepart, 'quantity' => 1],
        ]))->assertStatus(403);
    }

    public function test_marketing_can_cancel_while_created(): void
    {
        $borrow = $this->createdBorrow();
        $this->actingAsCreator();
        $this->postJson("/api/borrow/cancel/{$borrow->id}")->assertOk();
        $this->assertSame('Cancelled', $borrow->fresh()->current_status);
    }

    public function test_marketing_cannot_cancel_after_approval(): void
    {
        $borrow = $this->createdBorrow();
        $this->actingAsRole('Head Inventory');
        $this->postJson("/api/borrow/approve/{$borrow->id}")->assertOk();

        $this->actingAsCreator();
        $this->postJson("/api/borrow/cancel/{$borrow->id}")->assertStatus(422);
    }

    public function test_update_blocked_after_approval(): void
    {
        $borrow = $this->createdBorrow();
        $this->actingAsRole('Head Inventory');
        $this->postJson("/api/borrow/approve/{$borrow->id}")->assertOk();

        $this->actingAsCreator();
        $sparepart = $this->makeSparepart();
        $this->putJson("/api/borrow/{$borrow->id}", [
            'purchaseOrderId' => $borrow->purchase_order_id,
            'notes' => 'changed',
            'spareparts' => [['sparepartId' => $sparepart->id, 'quantity' => 2]],
        ])->assertStatus(422);
    }

    public function test_create_rejects_duplicate_sparepart_lines(): void
    {
        $this->actingAsRole('Marketing');
        $sparepart = $this->makeSparepart();
        $po = $this->makePurchaseOrder('Service');

        $this->postJson('/api/borrow', $this->makeBorrowPayload($po, [
            ['sparepart' => $sparepart, 'quantity' => 1],
            ['sparepart' => $sparepart, 'quantity' => 2],
        ]))->assertStatus(422);
    }

    public function test_marketing_cannot_mutate_another_marketing_users_borrow(): void
    {
        $borrow = $this->createdBorrow();

        // A different Marketing user.
        $this->actingAsRole('Marketing');
        $sparepart = $this->makeSparepart();

        $this->postJson("/api/borrow/cancel/{$borrow->id}")->assertStatus(403);
        $this->putJson("/api/borrow/{$borrow->id}", [
            'purchaseOrderId' => $borrow->purchase_order_id,
            'notes' => 'changed',
            'spareparts' => [['sparepartId' => $sparepart->id, 'quantity' => 1]],
        ])->assertStatus(403);

        $this->assertSame('Created', $borrow->fresh()->current_status);
    }

    public function test_director_can_mutate_any_borrow(): void
    {
        $borrow = $this->createdBorrow();

        $this->actingAsRole('Director');
        $this->postJson("/api/borrow/cancel/{$borrow->id}")->assertOk();
        $this->assertSame('Cancelled', $borrow->fresh()->current_status);
    }

    /** Creator (Marketing) of the borrow built by createdBorrow. */
    protected ?\App\Models\Employee $creator = null;

    /** Build a Created borrow through the real endpoint as a Marketing user. */
    protected function createdBorrow(): Borrow
    {
        $this->creator = $this->actingAsRole('Marketing');
        $sparepart = $this->makeSparepart(10);
        $po = $this->makePurchaseOrder('Service');

        $this->postJson('/api/borrow', $this->makeBorrowPayload($po, [
            ['sparepart' => $sparepart, 'quantity' => 2],
        ]))->assertCreated();

        return Borrow::latest('id')->first();
    }

    protected function actingAsCreator(): void
    {
        \Laravel\Sanctum\Sanctum::actingAs($this->creator);
    }
}
