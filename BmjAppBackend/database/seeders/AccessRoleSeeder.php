<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Accesses;
use App\Models\Employee;
use App\Models\DetailAccesses;
use Illuminate\Support\Facades\Hash;

class AccessRoleSeeder extends Seeder
{
    public function run()
    {
        $menuRoles = [
            [
                'path' => '/director',
                'name' => 'Director',
                'feature' => [
                    'dashboard',
                    'quotation',
                    'purchase_order',
                    'proforma_invoice',
                    'invoice',
                    'spareparts',
                    'back_order',
                    'purchase',
                    'employee',
                    'work_order'
                ],
            ],
            [
                'path' => '/marketing',
                'name' => 'Marketing',
                'feature' => [
                    'quotation',
                    'purchase_order'
                ],
            ],
            [
                'path' => '/inventory',
                'name' => 'Inventory',
                'feature' => [
                    'purchase_order',
                    'spareparts',
                    'back_order',
                    'purchase'
                ],
            ],
            [
                'path' => '/finance',
                'name' => 'Finance',
                'feature' => [
                    'quotation',
                    'purchase_order',
                    'proforma_invoice',
                    'invoice'
                ],
            ],
            [
                'path' => '/service',
                'name' => 'Service',
                'feature' => [
                    'purchase_order',
                    'back_order',
                    'work_order'
                ],
            ],
        ];

        // Create all unique access features
        $allFeatures = collect($menuRoles)
            ->pluck('feature')
            ->flatten()
            ->unique()
            ->values();

        $allFeatures->each(function ($feature) {
            Accesses::firstOrCreate(['access' => $feature]);
        });

        // Create demo employees and their access mappings
        foreach ($menuRoles as $role) {
            $employee = Employee::create([
                'name' => "Demo {$role['name']}",
                'role' => $role['name'],
                'email' => strtolower($role['name']),
                'password' => Hash::make('password'),
                'temp_password' => null,
                'temp_pass_already_use' => true,
            ]);

            $accessIds = Accesses::whereIn('access', $role['feature'])
                ->pluck('id');

            foreach ($accessIds as $accessId) {
                DetailAccesses::create([
                    'accesses_id' => $accessId,
                    'id_employee' => $employee->id,
                ]);
            }
        }
    }
}
