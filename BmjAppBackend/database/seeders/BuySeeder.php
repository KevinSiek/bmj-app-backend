<?php

namespace Database\Seeders;

use App\Http\Controllers\BuyController;
use App\Models\Buy;
use Illuminate\Database\Seeder;

class BuySeeder extends Seeder
{
    public function run(): void
    {
        // This seeder simulates the review process of Buy orders created by BackOrderSeeder
        $buysToReview = Buy::where('current_status', BuyController::WAIT_REVIEW)->get();

        foreach ($buysToReview as $buy) {
            $chance = rand(1, 10);
            if ($chance <= 8) { // 80% get approved
                $buy->update([
                    'current_status' => BuyController::APPROVE,
                    'review' => true
                ]);

                // 50% of approved ones get marked as Done
                if (rand(0, 1) == 1) {
                    $buy->update(['current_status' => BuyController::DONE]);
                }
            } else { // 20% get rejected
                 $buy->update([
                    'current_status' => BuyController::DECLINE,
                    'review' => true
                ]);
            }
        }
    }
}
