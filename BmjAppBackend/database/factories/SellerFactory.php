<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Seller;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Seller>
 */
class SellerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    protected $model = Seller::class;

    public function definition()
    {
        return [
            'name' => $this->faker->company,
            'type' => $this->faker->randomElement(['Distributor', 'Retailer', 'Manufacturer']),
        ];
    }
}
