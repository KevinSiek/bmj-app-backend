<?php
// database/seeders/BuySeeder.php
namespace Database\Seeders;

use App\Http\Controllers\EmployeeController;
use App\Models\Branch;
use App\Models\Employee;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class EmployeeSeeder extends Seeder
{
    public function run(): void
    {
        $branchMap = Branch::all()->keyBy(fn($b) => strtolower($b->name));

        $employees = [
            // Directors
            ['fullname' => 'Director Jakarta', 'role' => 'Director', 'branch' => EmployeeController::JAKARTA, 'email' => 'director.jkt@bmj.com', 'username' => 'director_jkt'],
            ['fullname' => 'Director Semarang', 'role' => 'Director', 'branch' => EmployeeController::SEMARANG, 'email' => 'director.smg@bmj.com', 'username' => 'director_smg'],
            // Marketing
            ['fullname' => 'Citra Kirana', 'role' => 'Marketing', 'branch' => EmployeeController::JAKARTA, 'email' => 'citra.k@bmj.com', 'username' => 'citra_k'],
            ['fullname' => 'Marketing Jakarta', 'role' => 'Marketing', 'branch' => EmployeeController::JAKARTA, 'email' => 'marketing.jkt@bmj.com', 'username' => 'marketing_jkt'],
            ['fullname' => 'Marketing Semarang', 'role' => 'Marketing', 'branch' => EmployeeController::SEMARANG, 'email' => 'marketing.smg@bmj.com', 'username' => 'marketing_smg'],
            ['fullname' => 'Dewi Lestari', 'role' => 'Marketing', 'branch' => EmployeeController::JAKARTA, 'email' => 'dewi.l@bmj.com', 'username' => 'dewi_l'],
            ['fullname' => 'Agus Salim', 'role' => 'Marketing', 'branch' => EmployeeController::SEMARANG, 'email' => 'agus.s@bmj.com', 'username' => 'agus_s'],
            ['fullname' => 'Rina Wati', 'role' => 'Marketing', 'branch' => EmployeeController::SEMARANG, 'email' => 'rina.w@bmj.com', 'username' => 'rina_w'],
            // Inventory
            ['fullname' => 'Eko Prasetyo', 'role' => 'Inventory Admin', 'branch' => EmployeeController::JAKARTA, 'email' => 'eko.p@bmj.com', 'username' => 'eko_p'],
            ['fullname' => 'Inventory Admin Semarang', 'role' => 'Inventory Admin', 'branch' => EmployeeController::SEMARANG, 'email' => 'inventory.admin.smg@bmj.com', 'username' => 'inventory_admin_smg'],
            ['fullname' => 'Inventory Admin Jakarta', 'role' => 'Inventory Admin', 'branch' => EmployeeController::JAKARTA, 'email' => 'inventory.admin.jkt@bmj.com', 'username' => 'inventory_admin_jkt'],
            ['fullname' => 'Indah Setiawati', 'role' => 'Inventory Purchase', 'branch' => EmployeeController::SEMARANG, 'email' => 'indah.s@bmj.com', 'username' => 'indah_s'],
            ['fullname' => 'Inventory Purchase Jakarta', 'role' => 'Inventory Purchase', 'branch' => EmployeeController::JAKARTA, 'email' => 'inventory.purchase.jkt@bmj.com', 'username' => 'inventory_purchase_jkt'],
            ['fullname' => 'Inventory Purchase Semarang', 'role' => 'Inventory Purchase', 'branch' => EmployeeController::SEMARANG, 'email' => 'inventory.purchase.smg@bmj.com', 'username' => 'inventory_purchase_smg'],
            ['fullname' => 'Hendra Wijaya', 'role' => 'Head Inventory', 'branch' => EmployeeController::JAKARTA, 'email' => 'headinv.jkt@bmj.com', 'username' => 'headinv_jkt'],
            ['fullname' => 'Head Inventory Semarang', 'role' => 'Head Inventory', 'branch' => EmployeeController::SEMARANG, 'email' => 'head.inventory.smg@bmj.com', 'username' => 'head_inventory_smg'],
            ['fullname' => 'Head Inventory Jakarta', 'role' => 'Head Inventory', 'branch' => EmployeeController::JAKARTA, 'email' => 'head.inventory.jkt@bmj.com', 'username' => 'head_inventory_jkt'],

            // Finance
            ['fullname' => 'Fajar Nugroho', 'role' => 'Finance', 'branch' => EmployeeController::JAKARTA, 'email' => 'fajar.n@bmj.com', 'username' => 'fajar_n'],
            ['fullname' => 'Finance Jakarta', 'role' => 'Finance', 'branch' => EmployeeController::JAKARTA, 'email' => 'finance.jkt@bmj.com', 'username' => 'finance_jkt'],
            ['fullname' => 'Finance Semarang', 'role' => 'Finance', 'branch' => EmployeeController::SEMARANG, 'email' => 'finance.smg@bmj.com', 'username' => 'finance_smg'],
            ['fullname' => 'Gita Permata', 'role' => 'Finance', 'branch' => EmployeeController::SEMARANG, 'email' => 'gita.p@bmj.com', 'username' => 'gita_p'],
            // Service
            ['fullname' => 'Service Jakarta', 'role' => 'Service', 'branch' => EmployeeController::JAKARTA, 'email' => 'service.jkt@bmj.com', 'username' => 'service_jkt'],
            ['fullname' => 'Service Semarang', 'role' => 'Service', 'branch' => EmployeeController::SEMARANG, 'email' => 'service.smg@bmj.com', 'username' => 'service_smg'],
            ['fullname' => 'Hadi Santoso', 'role' => 'Service', 'branch' => EmployeeController::JAKARTA, 'email' => 'hadi.s@bmj.com', 'username' => 'hadi_s'],
            ['fullname' => 'Joko Widodo', 'role' => 'Service', 'branch' => EmployeeController::SEMARANG, 'email' => 'joko.w@bmj.com', 'username' => 'joko_w'],
        ];

        foreach ($employees as $emp) {
            $branch = $branchMap[strtolower($emp['branch'])] ?? null;
            Employee::create([
                'fullname' => $emp['fullname'],
                'branch_id' => $branch?->id,
                'slug' => Str::slug($emp['fullname']) . '-' . strtolower(Str::random(4)),
                'role' => $emp['role'],
                'email' => $emp['email'],
                'username' => $emp['username'],
                'password' => Hash::make('password'),
                'temp_password' => null,
                'temp_pass_already_use' => true,
            ]);
        }
    }
}
