<?php

namespace Database\Factories;

use App\Models\Blog;
use App\Models\BlogImage;
use App\Services\BlogService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Creates the database row only -- it does not write a file to disk, so
 * `url()` on the result will point at nothing. BlogSeeder writes real
 * placeholder files; use that if you need working URLs.
 *
 * @extends Factory<BlogImage>
 */
class BlogImageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::random(40).'.jpg';

        return [
            'blog_id' => Blog::factory(),
            'path' => BlogService::IMAGE_DIRECTORY.'/'.$name,
            'disk' => BlogService::IMAGE_DISK,
            'original_name' => fake()->slug(3).'.jpg',
            'sort_order' => 0,
        ];
    }
}
