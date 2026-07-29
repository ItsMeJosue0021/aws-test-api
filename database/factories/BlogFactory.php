<?php

namespace Database\Factories;

use App\Models\Blog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Blog>
 */
class BlogFactory extends Factory
{
    /**
     * Drawn from a fixed pool rather than random words so `?tag=laravel`
     * reliably returns rows when you're testing the filter.
     *
     * @var list<string>
     */
    public const TAGS = [
        'aws', 'laravel', 'php', 'deployment', 'ec2',
        'nginx', 'postgres', 'devops', 'api', 'tutorial',
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => rtrim(fake()->sentence(random_int(4, 8)), '.'),
            'description' => fake()->paragraphs(random_int(2, 4), true),
            'tags' => fake()->randomElements(self::TAGS, random_int(1, 3)),
        ];
    }

    /**
     * A blog with no tags, for testing the empty case.
     */
    public function untagged(): static
    {
        return $this->state(fn (array $attributes) => [
            'tags' => [],
        ]);
    }
}
