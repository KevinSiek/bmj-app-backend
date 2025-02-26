<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Employee;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Employee>
 */
class EmployeeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    protected $model = Employee::class;

    public function definition()
    {
        return [
            'name' => $this->faker->name,
            'role' => $this->faker->jobTitle,
            'email' => $this->faker->unique()->email,
            'password' => bcrypt('password'),
            'temp_password' => $this->faker->password,
            'temp_pass_already_use' => $this->faker->boolean,
        ];
    }
}
