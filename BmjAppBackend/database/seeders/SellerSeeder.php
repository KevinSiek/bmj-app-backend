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
            ['name' => 'PT Sparepart Jaya', 'type' => 'Distributor'],
            ['name' => 'CV Mesin Handal', 'type' => 'Supplier'],
            ['name' => 'Sumber Teknik', 'type' => 'Retailer'],
            ['name' => 'Generator Parts Indonesia', 'type' => 'Distributor'],
            ['name' => 'Dunia Diesel', 'type' => 'Supplier'],
            ['name' => 'Central Auto Parts', 'type' => 'Retailer'],
            ['name' => 'Mega Teknik Mandiri', 'type' => 'Distributor'],
            ['name' => 'Bintang Jaya Diesel', 'type' => 'Supplier'],
            ['name' => 'Asia Parts Supply', 'type' => 'Importer'],
            ['name' => ' Nusantara Parts', 'type' => 'Supplier'],
        ];

        foreach ($sellers as $seller) {
            Seller::create($seller);
        }
    }
}
