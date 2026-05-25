<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $uid = $this->faker->unique()->userName();

        return [
            'uid' => $uid,
            'name' => $this->faker->name(),
            'email' => $uid.'@example.com',
        ];
    }

    public function globalAdmin(): self
    {
        return $this->state(fn (): array => ['uid' => 'globaladmin']);
    }
}
