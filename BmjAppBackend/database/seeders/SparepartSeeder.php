<?php

namespace Database\Seeders;

use App\Http\Controllers\EmployeeController;
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

        Sparepart::factory(50)->make()->each(function ($sparepart) use ($sellers) {
            $baseBuyPrice = fake()->numberBetween(50000, 20000000);
            $margin = fake()->randomFloat(2, 0.2, 0.5); // 20% to 50% margin

            $sparepart->unit_price_buy = $baseBuyPrice; // Base buy price
            $sparepart->unit_price_sell = $baseBuyPrice * (1 + $margin);
            $sparepart->total_unit = fake()->randomElement([0, rand(1, 5), rand(6, 20), rand(21, 100)]); // Varied stock levels
            $sparepart->branch = fake()->randomElement([EmployeeController::SEMARANG, EmployeeController::JAKARTA]);
            $sparepart->slug = Str::slug($sparepart->sparepart_name) . '-' . strtolower(Str::random(6));
            $sparepart->save();

            // Create DetailSparepart records (buy prices from different sellers)
            $selectedSellers = $sellers->random(rand(1, 3))->unique();
            foreach ($selectedSellers as $seller) {
                DetailSparepart::create([
                    'sparepart_id' => $sparepart->id,
                    'seller_id' => $seller->id,
                    // Price from this seller varies slightly
                    'unit_price' => $baseBuyPrice * fake()->randomFloat(2, 0.95, 1.05),
                    'quantity' => $sparepart->total_unit, // Assume seller has enough stock
                ]);
            }
        });
    }
}
