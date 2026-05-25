<?php

namespace Database\Factories;

use App\Models\Report;
use App\Models\Topic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    protected $model = Report::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'topic_id' => Topic::factory(),
            'creator' => null,
            // reporter_token, administrator_token, state are auto-populated
            // by Report::booted().
        ];
    }
}
