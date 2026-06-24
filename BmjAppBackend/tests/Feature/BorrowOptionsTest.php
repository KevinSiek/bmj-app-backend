<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BorrowOptionsTest extends TestCase
{
    use RefreshDatabase;
    use BorrowTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBorrowWorld();
    }

    public function test_service_option_list_returns_only_service_pos_with_work_order(): void
    {
        $this->actingAsRole('Marketing');

        $service = $this->makePurchaseOrder('Service');
        $this->makePurchaseOrder('Spareparts');

        $response = $this->getJson('/api/borrow/options/purchase-orders?type=Service');

        $response->assertOk();
        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertSame($service->id, $data[0]['id']);
        $this->assertNotEmpty($data[0]['work_order']['work_order_number']);
        $this->assertArrayHasKey('last_page', $response->json('data'));
    }

    public function test_spareparts_option_list_returns_only_spareparts_pos_with_line_items(): void
    {
        $this->actingAsRole('Inventory Admin');

        $sparepart = $this->makeSparepart();
        $spare = $this->makePurchaseOrder('Spareparts', [
            ['sparepart' => $sparepart, 'quantity' => 7],
        ]);
        $this->makePurchaseOrder('Service');

        $response = $this->getJson('/api/borrow/options/purchase-orders?type=Spareparts');

        $response->assertOk();
        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertSame($spare->id, $data[0]['id']);
        $this->assertSame($sparepart->id, $data[0]['spareparts'][0]['sparepart_id']);
        $this->assertSame(7, $data[0]['spareparts'][0]['quantity']);
    }

    public function test_search_matches_po_number_substring(): void
    {
        $this->actingAsRole('Marketing');

        $po = $this->makePurchaseOrder('Service');
        $this->makePurchaseOrder('Service');

        $needle = substr($po->po_number, 0, 6);
        $response = $this->getJson('/api/borrow/options/purchase-orders?type=Service&search=' . urlencode($needle));

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($po->id));
    }

    public function test_only_latest_version_of_a_po_appears_once(): void
    {
        $this->actingAsRole('Marketing');

        $v1 = $this->makePurchaseOrder('Service');
        // Second version shares purchase_order_number but gets its own unique po_number.
        $v1->replicate()->fill([
            'version' => 2,
            'po_number' => 'CUST-V2-' . $v1->id,
        ])->save();

        $response = $this->getJson('/api/borrow/options/purchase-orders?type=Service');

        $response->assertOk();
        $rows = collect($response->json('data.data'))
            ->where('purchase_order_number', $v1->purchase_order_number);
        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows->first()['version']);
    }

    public function test_invalid_type_is_rejected(): void
    {
        $this->actingAsRole('Marketing');

        $this->getJson('/api/borrow/options/purchase-orders?type=Nonsense')
            ->assertStatus(422);

        $this->getJson('/api/borrow/options/purchase-orders')
            ->assertStatus(422);
    }

    public function test_finance_cannot_access_borrow_options(): void
    {
        $this->actingAsRole('Finance');

        $this->getJson('/api/borrow/options/purchase-orders?type=Service')
            ->assertStatus(403);
    }

    public function test_inventory_and_marketing_can_access(): void
    {
        $this->actingAsRole('Marketing');
        $this->getJson('/api/borrow/options/purchase-orders?type=Service')->assertOk();

        $this->actingAsRole('Head Inventory');
        $this->getJson('/api/borrow/options/purchase-orders?type=Service')->assertOk();
    }
}
