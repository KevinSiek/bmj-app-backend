<?php
namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        Customer::factory(50)->create([
            'urban' => fake()->citySuffix(),
            'subdistrict' => fake()->city(),
            'province' => fake()->state(),
            'postal_code' => fake()->postcode(),
        ]);
    }
}
