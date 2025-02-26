<?php
// database/seeders/WorkOrderSeeder.php
namespace Database\Seeders;

use App\Models\WorkOrder;
use App\Models\Quotation;
use Illuminate\Database\Seeder;

class WorkOrderSeeder extends Seeder
{
    public function run(): void
    {
        WorkOrder::factory(15)->create([
            'id_quotation' => Quotation::inRandomOrder()->first()->id,
            'job_descriptions' => fake()->randomElement([
                'Overhaul generator 2000KVA',
                'Ganti bearing utama generator',
                'Perbaikan sistem kontrol digital',
                'Kalibrasi sistem bahan bakar'
            ]),
            'additional_components' => json_encode([
                'oil_filter' => fake()->boolean(),
                'air_filter' => fake()->boolean(),
                'fuel_pump' => fake()->boolean()
            ])
        ]);
    }
}
