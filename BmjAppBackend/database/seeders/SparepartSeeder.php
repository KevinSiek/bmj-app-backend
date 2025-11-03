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
    }
}
