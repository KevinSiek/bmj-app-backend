<?php

namespace Database\Seeders;

use App\Models\Quotation;
use App\Models\DetailQuotation;
use App\Models\Sparepart;
use Illuminate\Database\Seeder;

class QuotationSeeder extends Seeder
{
    public function run(): void
    {
        Quotation::factory(30)
            ->has(DetailQuotation::factory()->count(5)->state(function (array $attributes, Quotation $quotation) {
                return [
                    'sparepart_id' => Sparepart::inRandomOrder()->first()->id,
                    'quantity' => fake()->numberBetween(1, 5),
                    'is_indent' => fake()->numberBetween(0, 1),
                    'is_return' => fake()->numberBetween(0, 1),
                    'unit_price' => fake()->numberBetween(10000, 50000),
                ];
            }), 'detailQuotations')
            ->create([
                'quotation_number' => fn() => sprintf(
                    '%03d/QUOT/BMJ-MEGAH/P/%s/%d',
                    fake()->numberBetween(1, 999),
                    strtoupper(fake()->monthName()),
                    now()->year
                ),
                'notes' => fn() => fake()->randomElement([
                    'Pemasangan generator di lokasi pelanggan',
                    'Perbaikan sistem kelistrikan generator',
                    'Penggantian sparepart utama',
                    'Maintenance rutin generator'
                ]),
                'project' => fn() => fake()->randomElement([
                    'Pemasangan Generator',
                    'Maintenance Generator',
                    'Pengadaan Sparepart',
                    'Overhaul Generator'
                ]),
                'type' => fn() => fake()->randomElement([
                    'Spareparts',
                    'Service',
                ]),
                'current_status' => fn() => fake()->randomElement([
                    'Ready',
                    'Not ready',
                ]),
                'status' => fn() => [
                    [
                        'state' => 'Po',
                        'timestamp' => now()->format('d/m/Y'),
                        'employee' => "Po Name"
                    ],
                    [
                        'state' => 'Pi',
                        'timestamp' => now()->format('d/m/Y'),
                        'employee' => "Pi Name"
                    ],
                    [
                        'state' => 'Inventory',
                        'timestamp' => now()->format('d/m/Y'),
                        'employee' => "Inventory Name"
                    ],
                    [
                        'state' => 'Paid',
                        'timestamp' => now()->format('d/m/Y'),
                        'employee' => "Paid Name"
                    ]
                ]
            ]);
    }
}
