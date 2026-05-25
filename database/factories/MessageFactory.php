<?php

namespace Database\Factories;

use App\Models\Message;
use App\Models\Report;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'report_id' => Report::factory(),
            'content' => $this->faker->paragraph(),
            'is_admin' => false,
        ];
    }
}
