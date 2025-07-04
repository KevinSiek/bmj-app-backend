<?php
// database/seeders/PurchaseOrderSeeder.php
namespace Database\Seeders;

use App\Models\General;
use Illuminate\Database\Seeder;

class GeneralSeeder extends Seeder
{
    public function run(): void
    {
        General::factory(5)->create();
    }
}
