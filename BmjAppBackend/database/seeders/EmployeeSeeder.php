<?php
// database/seeders/BuySeeder.php
namespace Database\Seeders;

use App\Http\Controllers\EmployeeController;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EmployeeSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('employees')->insert([
            [
                'email' => 'testingDirector@gmail.com',
                'slug' => 'Testing-Director',
                'branch' => EmployeeController::SEMARANG,
                'role' => 'Director',
                'fullname' => 'Fullname Director',
                'username' => 'username -Director',
                'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', // password
            ]
        ]);

        DB::table('employees')->insert([
            [
                'email' => 'testingMarketing@gmail.com',
                'slug' => 'Testing2-Marketing',
                'branch' => EmployeeController::SEMARANG,
                'role' => 'Marketing',
                'fullname' => 'Fullname Marketing',
                'username' => 'username -Marketing',
                'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', // password
            ]
        ]);

        DB::table('employees')->insert([
            [
                'email' => 'testingInventory@gmail.com',
                'slug' => 'Testing3-Inventory',
                'branch' => EmployeeController::SEMARANG,
                'role' => 'Inventory',
                'fullname' => 'Fullname Inventory',
                'username' => 'username -Inventory',
                'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', // password
            ]
        ]);

        DB::table('employees')->insert([
            [
                'email' => 'testingFinance@gmail.com',
                'slug' => 'Testing3-Finance',
                'branch' => EmployeeController::SEMARANG,
                'role' => 'Finance',
                'fullname' => 'Fullname Finance',
                'username' => 'username -Finance',
                'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', // password
            ]
        ]);

        DB::table('employees')->insert([
            [
                'email' => 'testingService@gmail.com',
                'slug' => 'Testing3-Service',
                'branch' => EmployeeController::JAKARTA,
                'role' => 'Service',
                'fullname' => 'Fullname Service',
                'username' => 'username -Service',
                'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', // password
            ]
        ]);
    }
}
