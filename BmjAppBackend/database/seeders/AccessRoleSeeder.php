<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Accesses;
use App\Models\Employee;
use App\Models\DetailAccesses;

class AccessRoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Clear existing data
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DetailAccesses::truncate();
        Accesses::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        // Define all possible features/accesses in the system
        $features = [
            'dashboard',
            'quotation',
            'purchase-order',
            'work-order',
            'delivery-order',
            'proforma-invoice',
            'invoice',
            'back-order',
            'buy',
            'sparepart',
            'customer',
            'employee',
            'settings'
        ];

        foreach ($features as $feature) {
            Accesses::create(['access' => $feature]);
        }

        // Define which roles get which features
        $roleAccesses = [
            'Director' => $features, // Director gets all features
            'Marketing' => ['dashboard', 'quotation', 'purchase-order', 'customer'],
            'Inventory' => ['dashboard', 'back-order', 'buy', 'sparepart', 'delivery-order'],
            'Finance' => ['dashboard', 'proforma-invoice', 'invoice', 'purchase-order'],
            'Service' => ['dashboard', 'work-order'],
        ];

        $employees = Employee::all();
        $accesses = Accesses::all()->keyBy('access');

        foreach ($employees as $employee) {
            if (isset($roleAccesses[$employee->role])) {
                $featuresForRole = $roleAccesses[$employee->role];
                foreach ($featuresForRole as $featureName) {
                    if (isset($accesses[$featureName])) {
                        DetailAccesses::create([
                            'id_employee' => $employee->id,
                            'accesses_id' => $accesses[$featureName]->id,
                        ]);
                    }
                }
            }
        }
    }
}
