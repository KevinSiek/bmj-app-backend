<?php
// database/seeders/BuySeeder.php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EmployeeSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('employees')->insert([
            [
                'email' => 'testingDirector@gmail.com',
                'slug'=>'Testing-Director',
                'role' => 'Director',
                'fullname' => 'Fullname Director',
                'username'=>'username -Director',
                'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', // password
            ]
        ]);

        DB::table('employees')->insert([
            [
                'email' => 'testingMarketing@gmail.com',
                'slug'=>'Testing2-Marketing',
                'role' => 'Marketing',
                'fullname' => 'Fullname Marketing',
                'username'=>'username -Marketing',
                'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', // password
            ]
        ]);

        DB::table('employees')->insert([
            [
                'email' => 'testingInventory@gmail.com',
                'slug'=>'Testing3-Inventory',
                'role' => 'Inventory',
                'fullname' => 'Fullname Inventory',
                'username'=>'username -Inventory',
                'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', // password
            ]
        ]);

        DB::table('employees')->insert([
            [
                'email' => 'testingFinance@gmail.com',
                'slug'=>'Testing3-Finance',
                'role' => 'Finance',
                'fullname' => 'Fullname Finance',
                'username'=>'username -Finance',
                'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', // password
            ]
        ]);

        DB::table('employees')->insert([
            [
                'email' => 'testingService@gmail.com',
                'slug'=>'Testing3-Service',
                'role' => 'Service',
                'fullname' => 'Fullname Service',
                'username'=>'username -Service',
                'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', // password
            ]
        ]);
    }
}
