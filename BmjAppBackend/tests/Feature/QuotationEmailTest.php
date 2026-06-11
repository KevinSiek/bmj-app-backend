<?php

namespace Tests\Feature;

use App\Models\Sparepart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotationEmailTest extends TestCase
{
    use RefreshDatabase;
    use BorrowTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBorrowWorld();
    }

    /**
     * Minimal valid Spareparts quotation payload. Override the customer block per test.
     *
     * @param array<string, mixed> $customerOverrides
     */
    private function quotationPayload(Sparepart $sparepart, array $customerOverrides = []): array
    {
        $customer = array_merge([
            'companyName' => 'PT Email Test',
            'office' => 'HQ',
            'address' => 'Jl. Test 1',
            'urban' => 'Urban',
            'subdistrict' => 'Sub',
            'city' => 'Semarang',
            'province' => 'Jateng',
            'postalCode' => 50111,
            'email' => 'sales@emailtest.com',
        ], $customerOverrides);

        return [
            'project' => [
                'type' => 'Spareparts',
                'branch' => 'Jakarta',
                'date' => now()->toDateString(),
            ],
            'customer' => $customer,
            'spareparts' => [
                [
                    'sparepartId' => $sparepart->id,
                    'quantity' => 1,
                    'unitPriceSell' => 100000,
                ],
            ],
            'price' => [
                'amount' => 100000,
                'totalDiscountPercent' => 0,
            ],
        ];
    }

    public function test_quotation_create_persists_customer_email(): void
    {
        $this->actingAsRole('Director');
        $sparepart = $this->makeSparepart();

        $response = $this->postJson('/api/quotation', $this->quotationPayload($sparepart));

        $response->assertSuccessful();
        $this->assertDatabaseHas('customers', [
            'company_name' => 'PT Email Test',
            'email' => 'sales@emailtest.com',
        ]);
    }

    public function test_quotation_create_rejects_invalid_email(): void
    {
        $this->actingAsRole('Director');
        $sparepart = $this->makeSparepart();

        $response = $this->postJson(
            '/api/quotation',
            $this->quotationPayload($sparepart, ['email' => 'notanemail'])
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['customer.email']);
    }

    public function test_quotation_create_allows_empty_email(): void
    {
        $this->actingAsRole('Director');
        $sparepart = $this->makeSparepart();

        $response = $this->postJson(
            '/api/quotation',
            $this->quotationPayload($sparepart, ['email' => ''])
        );

        $response->assertSuccessful();
    }
}
