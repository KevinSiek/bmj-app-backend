<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            GeneralSeeder::class,
            CustomerSeeder::class,
            AccessRoleSeeder::class,
            SparepartSeeder::class,
            QuotationSeeder::class,
            PurchaseOrderSeeder::class,
            ProformaInvoiceSeeder::class,
            InvoiceSeeder::class,
            DeliveryOrderSeeder::class,
            WorkOrderSeeder::class,
            BackOrderSeeder::class,
            BuySeeder::class,
            EmployeeSeeder::class
        ]);
    }
}
