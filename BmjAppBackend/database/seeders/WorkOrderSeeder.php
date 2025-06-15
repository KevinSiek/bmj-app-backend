<?php

namespace Database\Seeders;

use App\Models\WorkOrder;
use App\Models\WoUnit;
use Illuminate\Database\Seeder;

class WorkOrderSeeder extends Seeder
{
    /**
     * Run the seeder.
     */
    public function run(): void
    {
        WorkOrder::factory(15)->create()->each(function ($workOrder) {
            // Create 1-3 wo_units for each work order
            WoUnit::factory()->count(rand(1, 3))->create(['id_wo' => $workOrder->id]);
        });
    }
}
