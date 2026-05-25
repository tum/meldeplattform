<?php

namespace Database\Factories;

use App\Models\Field;
use App\Models\Topic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Topic>
 */
class TopicFactory extends Factory
{
    protected $model = Topic::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucfirst($this->faker->unique()->word());

        return [
            'name_de' => $name,
            'name_en' => $name,
            'summary_de' => $this->faker->sentence(),
            'summary_en' => $this->faker->sentence(),
            'email' => $this->faker->safeEmail(),
            'contacts' => null,
        ];
    }

    public function withFields(int $count = 2): self
    {
        return $this->afterCreating(function (Topic $topic) use ($count): void {
            Field::factory()
                ->count($count)
                ->for($topic)
                ->sequence(fn ($seq): array => ['position' => $seq->index])
                ->create();
        });
    }
}
