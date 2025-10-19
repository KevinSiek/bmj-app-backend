<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Seller;

class SellerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $sellers = [
            ['name' => 'PT Sparepart Jaya', 'type' => 'Distributor', 'code' => '1'],
            ['name' => 'CV Mesin Handal', 'type' => 'Supplier', 'code' => '2'],
            ['name' => 'Sumber Teknik', 'type' => 'Retailer', 'code' => '3'],
        ];

        foreach ($sellers as $seller) {
            Seller::create($seller);
        }
    }
}
