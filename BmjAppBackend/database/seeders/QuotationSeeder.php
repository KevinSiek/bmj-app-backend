<?php

namespace Database\Seeders;

use App\Models\Quotation;
use App\Models\DetailQuotation;
use App\Models\Sparepart;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\General;
use Illuminate\Database\Seeder;
use Carbon\Carbon;
use Illuminate\Support\Str;

class QuotationSeeder extends Seeder
{
    public function run(): void
    {
        $marketingEmployees = Employee::where('role', 'Marketing')->get();
        $director = Employee::where('role', 'Director')->first();
        $customers = Customer::all();
        $spareparts = Sparepart::all();
        $generalSettings = General::first();
        $ppn = $generalSettings->ppn ?? 0.11;
        $discountRate = $generalSettings->discount ?? 0.05;

        // Seed data for the last 2 years (approx 730 days)
        for ($i = 730; $i >= 0; $i--) {
            // Create 0 to 3 quotations per day
            for ($j = 0; $j < rand(0, 3); $j++) {
                $creationDate = now()->subDays($i)->addHours(rand(8, 17));
                $marketing = $marketingEmployees->random();
                $customer = $customers->random();
                $type = fake()->randomElement(['Service', 'Spareparts']);

                $quotationData = [
                    // FIX: Add a temporary placeholder to satisfy the NOT NULL constraint on creation.
                    // This will be overwritten by the correct value after the record is created.
                    'quotation_number' => 'TEMP-' . Str::uuid(),
                    'slug' => 'temp-slug-' . Str::uuid(),
                    'project' => 'temp-project',
                    'customer_id' => $customer->id,
                    'employee_id' => $marketing->id,
                    'type' => $type,
                    'version' => 1,
                    // FIX: Add placeholders for all required price fields.
                    'amount' => 0,
                    'discount' => 0,
                    'subtotal' => 0,
                    'ppn' => 0,
                    'grand_total' => 0,
                    'review' => false,
                    'is_return' => false,
                    'date' => $creationDate->toDateString(),
                    'created_at' => $creationDate,
                    'updated_at' => $creationDate,
                ];

                $quotation = Quotation::create($quotationData);

                // Create Details and Calculate Price
                $amount = 0;
                $details = [];
                $itemCount = rand(2, 6);

                if ($type === 'Spareparts') {
                    if ($spareparts->count() < $itemCount) continue; // Skip if not enough spareparts to sample
                    $selectedSpareparts = $spareparts->random($itemCount);
                    foreach ($selectedSpareparts as $sp) {
                        $quantity = rand(1, 5);
                        $unitPrice = $sp->unit_price_sell * rand(95, 105) / 100; // slight price variation
                        $amount += $quantity * $unitPrice;
                        $details[] = [
                            'quotation_id' => $quotation->id,
                            'sparepart_id' => $sp->id,
                            'quantity' => $quantity,
                            'unit_price' => $unitPrice,
                            'is_indent' => $quantity > $sp->total_unit,
                        ];
                    }
                } else { // Service
                    for ($k = 0; $k < $itemCount; $k++) {
                        $quantity = 1;
                        $unitPrice = fake()->randomElement([500000, 1250000, 2500000]);
                        $amount += $quantity * $unitPrice;
                        $details[] = [
                            'quotation_id' => $quotation->id,
                            'service' => 'Service Task ' . ($k + 1),
                            'quantity' => $quantity,
                            'unit_price' => $unitPrice,
                        ];
                    }
                }
                DetailQuotation::insert($details);

                // Finalize Quotation Price and Status
                $discount = $amount * $discountRate;
                $subtotal = $amount - $discount;
                $ppnAmount = $subtotal * $ppn;
                $grandTotal = $subtotal + $ppnAmount;

                $quotation->amount = $amount;
                $quotation->discount = $discount;
                $quotation->subtotal = $subtotal;
                $quotation->ppn = $ppnAmount;
                $quotation->grand_total = $grandTotal;
                $quotation->quotation_number = sprintf('QUOT/%04d/BMJ/%s/%s/%s', $quotation->id, $marketing->branch == 'Jakarta' ? 'JKT' : 'SMG', $creationDate->format('m'), $creationDate->format('Y'));
                $quotation->project = $quotation->quotation_number;
                $quotation->slug = Str::slug($quotation->quotation_number) . '-' . strtolower(Str::random(6));

                // Simulate Review Process
                $statusHistory = [];
                $approvalChance = rand(1, 10);
                if ($approvalChance <= 7) { // 70% chance of being approved
                    $quotation->review = true;
                    $quotation->current_status = 'Approved';
                    $approvalDate = $creationDate->copy()->addDays(rand(1, 3));
                    $statusHistory[] = ['state' => 'Approved', 'employee' => $director->username, 'timestamp' => $approvalDate->toIso8601String()];
                    $quotation->updated_at = $approvalDate;
                } elseif ($approvalChance <= 9) { // 20% chance of being rejected
                    $quotation->review = true;
                    $quotation->current_status = 'Rejected';
                } else { // 10% chance of remaining on review
                    $quotation->current_status = 'On Review';
                }
                $quotation->status = $statusHistory;

                $quotation->save();
            }
        }
    }
}
