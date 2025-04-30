<?php

namespace Database\Seeders;

use App\Models\WoUnit;
use Illuminate\Database\Seeder;

class WoUnitSeeder extends Seeder
{
    public function run(): void
    {
        WoUnit::factory(30)->create();
    }
}
