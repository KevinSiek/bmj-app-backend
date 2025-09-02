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
        // The order of seeders is critical to ensure data integrity.
        $this->call([
            // 1. Foundational & Configuration Data
            GeneralSeeder::class,
            AccessRoleSeeder::class, // New: For permissions
            SellerSeeder::class,   // New: For sparepart suppliers

            // 2. Core Entities
            EmployeeSeeder::class,
            CustomerSeeder::class,

            // 3. Inventory Setup
            SparepartSeeder::class,

            // 4. Workflow Simulation (in chronological order)
            QuotationSeeder::class,       // Creates Quotations (some Approved)
            PurchaseOrderSeeder::class,   // Creates POs from Approved Quotations, and BackOrders if needed
            BackOrderSeeder::class,       // Processes some BackOrders
            BuySeeder::class,             // Simulates review of Buy orders created by BackOrderSeeder
            ProformaInvoiceSeeder::class, // Creates PIs from POs and simulates payments
            WorkOrderSeeder::class,       // Creates WOs for fulfilled Service POs
            DeliveryOrderSeeder::class,   // Creates DOs for fulfilled Sparepart POs
            InvoiceSeeder::class,         // Creates final Invoices for paid PIs
        ]);
    }
}
