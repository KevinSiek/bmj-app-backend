<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\BranchSparepart;
use App\Models\DetailSparepart;
use App\Models\Seller;
use App\Models\Sparepart;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SparepartSeeder extends Seeder
{
    public function run(): void
    {
        $sellers = Seller::all();
        if ($sellers->isEmpty()) {
            $this->call(SellerSeeder::class);
            $sellers = Seller::all();
        }

        $branches = Branch::all();
        if ($branches->isEmpty()) {
            $this->call(BranchSeeder::class);
        }

        $branches = Branch::all();

        Sparepart::factory(50)->make()->each(function ($sparepart) use ($sellers, $branches) {
            $baseBuyPrice = fake()->numberBetween(50000, 20000000);
            $margin = fake()->randomFloat(2, 0.2, 0.5); // 20% to 50% margin

            $sparepart->unit_price_buy = $baseBuyPrice; // Base buy price
            $sparepart->unit_price_sell = $baseBuyPrice * (1 + $margin);
            $sparepart->slug = Str::slug($sparepart->sparepart_name) . '-' . strtolower(Str::random(6));
            $sparepart->save();

            $totalQuantity = 0;
            foreach ($branches as $branch) {
                $quantity = fake()->randomElement([0, rand(1, 5), rand(6, 20), rand(21, 100)]);
                $totalQuantity += $quantity;

                BranchSparepart::updateOrCreate(
                    [
                        'sparepart_id' => $sparepart->id,
                        'branch_id' => $branch->id,
                    ],
                    ['quantity' => $quantity]
                );
            }

            // Create DetailSparepart records (buy prices from different sellers)
            $selectedSellers = $sellers->random(rand(1, 3))->unique();
            foreach ($selectedSellers as $seller) {
                DetailSparepart::create([
                    'sparepart_id' => $sparepart->id,
                    'seller_id' => $seller->id,
                    // Price from this seller varies slightly
                    'unit_price' => $baseBuyPrice * fake()->randomFloat(2, 0.95, 1.05),
                    'quantity' => $totalQuantity, // Assume seller has enough stock across branches
                ]);
            }
        });

        // Add a guaranteed sparepart for E2E tests with high stock
        $e2eSparepart = Sparepart::create([
            'sparepart_number' => 'E2E-SPAREPART-001',
            'sparepart_name' => 'E2E Guaranteed Stock Sparepart',
            'slug' => 'e2e-guaranteed-stock-sparepart',
            'unit_price_buy' => 100000,
            'unit_price_sell' => 150000,
        ]);
        foreach ($branches as $branch) {
            BranchSparepart::create([
                'sparepart_id' => $e2eSparepart->id,
                'branch_id' => $branch->id,
                'quantity' => 100000 // Very high stock so it never runs out
            ]);
        }
        DetailSparepart::create([
            'sparepart_id' => $e2eSparepart->id,
            'seller_id' => $sellers->first()->id,
            'unit_price' => 100000,
            'quantity' => 100000,
        ]);

        // Add a low stock sparepart for BackOrder tests
        $e2eLowStock = Sparepart::create([
            'sparepart_number' => 'E2E-LOW-001',
            'sparepart_name' => 'E2E Low Stock Sparepart',
            'slug' => 'e2e-low-stock-sparepart',
            'unit_price_buy' => 50000,
            'unit_price_sell' => 75000,
        ]);
        foreach ($branches as $branch) {
            BranchSparepart::create([
                'sparepart_id' => $e2eLowStock->id,
                'branch_id' => $branch->id,
                'quantity' => 10 // Low stock to trigger indent easily
            ]);
        }
        DetailSparepart::create([
            'sparepart_id' => $e2eLowStock->id,
            'seller_id' => $sellers->first()->id,
            'unit_price' => 50000,
            'quantity' => 10,
        ]);
    }
}
