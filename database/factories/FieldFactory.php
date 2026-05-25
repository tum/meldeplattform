<?php

namespace Database\Factories;

use App\Models\Field;
use App\Models\Topic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Field>
 */
class FieldFactory extends Factory
{
    protected $model = Field::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** @var string $words */
        $words = $this->faker->words(2, true);
        $name = ucfirst($words);

        return [
            'topic_id' => Topic::factory(),
            'name_de' => $name,
            'name_en' => $name,
            'description_de' => null,
            'description_en' => null,
            'type' => 'text',
            'required' => false,
            'choices' => null,
            'position' => 0,
        ];
    }
}
