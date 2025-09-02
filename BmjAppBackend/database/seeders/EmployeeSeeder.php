<?php
// database/seeders/BuySeeder.php
namespace Database\Seeders;

use App\Http\Controllers\EmployeeController;
use App\Models\Employee;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class EmployeeSeeder extends Seeder
{
    public function run(): void
    {
        $employees = [
            // Directors
            ['fullname' => 'Budi Hartono', 'role' => 'Director', 'branch' => EmployeeController::JAKARTA, 'email' => 'director.jkt@bmj.com', 'username' => 'director_jkt'],
            ['fullname' => 'Susilo Bambang', 'role' => 'Director', 'branch' => EmployeeController::SEMARANG, 'email' => 'director.smg@bmj.com', 'username' => 'director_smg'],
            // Marketing
            ['fullname' => 'Citra Kirana', 'role' => 'Marketing', 'branch' => EmployeeController::JAKARTA, 'email' => 'citra.k@bmj.com', 'username' => 'citra_k'],
            ['fullname' => 'Dewi Lestari', 'role' => 'Marketing', 'branch' => EmployeeController::JAKARTA, 'email' => 'dewi.l@bmj.com', 'username' => 'dewi_l'],
            ['fullname' => 'Agus Salim', 'role' => 'Marketing', 'branch' => EmployeeController::SEMARANG, 'email' => 'agus.s@bmj.com', 'username' => 'agus_s'],
            ['fullname' => 'Rina Wati', 'role' => 'Marketing', 'branch' => EmployeeController::SEMARANG, 'email' => 'rina.w@bmj.com', 'username' => 'rina_w'],
            // Inventory
            ['fullname' => 'Eko Prasetyo', 'role' => 'Inventory', 'branch' => EmployeeController::JAKARTA, 'email' => 'eko.p@bmj.com', 'username' => 'eko_p'],
            ['fullname' => 'Indah Setiawati', 'role' => 'Inventory', 'branch' => EmployeeController::SEMARANG, 'email' => 'indah.s@bmj.com', 'username' => 'indah_s'],
            // Finance
            ['fullname' => 'Fajar Nugroho', 'role' => 'Finance', 'branch' => EmployeeController::JAKARTA, 'email' => 'fajar.n@bmj.com', 'username' => 'fajar_n'],
            ['fullname' => 'Gita Permata', 'role' => 'Finance', 'branch' => EmployeeController::SEMARANG, 'email' => 'gita.p@bmj.com', 'username' => 'gita_p'],
            // Service
            ['fullname' => 'Hadi Santoso', 'role' => 'Service', 'branch' => EmployeeController::JAKARTA, 'email' => 'hadi.s@bmj.com', 'username' => 'hadi_s'],
            ['fullname' => 'Joko Widodo', 'role' => 'Service', 'branch' => EmployeeController::SEMARANG, 'email' => 'joko.w@bmj.com', 'username' => 'joko_w'],
        ];

        foreach ($employees as $emp) {
            Employee::create([
                'fullname' => $emp['fullname'],
                'branch' => $emp['branch'],
                'slug' => Str::slug($emp['fullname']) . '-' . strtolower(Str::random(4)),
                'role' => $emp['role'],
                'email' => $emp['email'],
                'username' => $emp['username'],
                'password' => Hash::make('password'), // Common password for all seeded users
                'temp_password' => null,
                'temp_pass_already_use' => true,
            ]);
        }
    }
}
