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
            'quotation_id' => Quotation::inRandomOrder()->first()->id,
            'job_descriptions' => fake()->randomElement([
                'Overhaul generator 2000KVA',
                'Ganti bearing utama generator',
                'Perbaikan sistem kontrol digital',
                'Kalibrasi sistem bahan bakar',
                ''
            ]),
            'spareparts' => fake()->randomElement([
                'Overhaul generator 2000KVA',
                'Ganti bearing utama generator',
                'Perbaikan sistem kontrol digital',
                'Kalibrasi sistem bahan bakar',
                ''
            ]),
            'backup_sparepart' => fake()->randomElement([
                'backup Overhaul generator 2000KVA',
                'backup Ganti bearing utama generator',
                'backup Perbaikan sistem kontrol digital',
                'backup Kalibrasi sistem bahan bakar',
                ''
            ]),
            'scope' => fake()->randomElement([
                'scope 1',
                'scope 2',
                'scope 3',
                ''
            ]),
            'vaccine' => fake()->randomElement([
                'Sinovac',
                'Astra Se-1',
                'Nusantara 2',
                ''
            ]),
            'apd' => fake()->randomElement([
                'Masker N95',
                'APD lengkap',
                'Masker Kain',
                ''
            ]),
            'peduli_lindungi' => fake()->randomElement([
                'Surat',
                'Aplikasi',
                ''
            ]),
        ]);
    }
}
