<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Customer;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    protected $model = Customer::class;

    public function definition()
    {
        $companyName =$this->faker->company;
        return [
            'slug' =>  Str::slug($companyName). '-' . Str::random(6),
            'company_name' =>  $companyName,
            'office' => $this->faker->word,
            'address' => $this->faker->address,
            'urban_area' => $this->faker->city,
            'subdistrict' => $this->faker->streetName,
            'city' => $this->faker->city,
            'province' => $this->faker->state,
            'postal_code' => $this->faker->postcode,
        ];
    }
}
