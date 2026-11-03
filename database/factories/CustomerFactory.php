<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'nama'  => strtoupper(fake()->unique()->name()),
            'no_hp' => '08' . fake()->unique()->numerify('##########'),
        ];
    }

    /** Pelanggan hasil import BPJS: belum punya nomor HP. */
    public function incompleteProfile(): static
    {
        return $this->state(fn () => ['no_hp' => null]);
    }
}
