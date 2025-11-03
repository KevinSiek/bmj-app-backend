<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\{Employee,Accesses,DetailAccesses};

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DetailBuy>
 */
class DetailAccessesFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    protected $model = DetailAccesses::class;

    public function definition()
    {
        return [
            'id_employee' => Employee::factory(),
            'accesses_id' => Accesses::factory(),
        ];
    }
}
