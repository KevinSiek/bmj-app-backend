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
                'email' => 'testing@gmail.com',
                'slug'=>'Testing-1',
                'role' => 'director',
                'fullname' => 'Testing',
                'username'=>'username -Testing',
                'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', // password
            ]
        ]);

        DB::table('employees')->insert([
            [
                'email' => 'testing2@gmail.com',
                'slug'=>'Testing2-2',
                'role' => 'director',
                'fullname' => 'Testing2',
                'username'=>'username -Testing2',
                'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', // password
            ]
        ]);

        DB::table('employees')->insert([
            [
                'email' => 'testing3@gmail.com',
                'slug'=>'Testing3-3',
                'role' => 'director',
                'fullname' => 'Testing3',
                'username'=>'username -Testing3',
                'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', // password
            ]
        ]);
    }
}
