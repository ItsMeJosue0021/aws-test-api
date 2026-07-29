<?php

namespace Database\Seeders;

use App\Models\Blog;
use App\Services\BlogService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BlogSeeder extends Seeder
{
    private const BLOG_COUNT = 20;

    /** Max images attached to any one blog. */
    private const MAX_IMAGES = 3;

    public function run(): void
    {
        // Generating placeholder JPEGs needs GD. Without it, seed the blogs
        // anyway rather than failing the whole deploy.
        $withImages = extension_loaded('gd');

        if (! $withImages) {
            $this->command?->warn('GD extension not loaded -- seeding blogs without images.');
        }

        Blog::factory()
            ->count(self::BLOG_COUNT)
            ->create()
            ->each(function (Blog $blog) use ($withImages) {
                if (! $withImages) {
                    return;
                }

                // Roughly a third get no images, so the empty-gallery case is
                // represented in the seed data too.
                $count = random_int(0, self::MAX_IMAGES);

                for ($i = 1; $i <= $count; $i++) {
                    $this->attachPlaceholderImage($blog, $i);
                }
            });

        $this->command?->info(sprintf(
            'Seeded %d blogs (%d images).',
            self::BLOG_COUNT,
            Blog::query()->withCount('images')->get()->sum('images_count'),
        ));
    }

    private function attachPlaceholderImage(Blog $blog, int $sortOrder): void
    {
        $path = BlogService::IMAGE_DIRECTORY.'/'.Str::random(40).'.jpg';

        Storage::disk(BlogService::IMAGE_DISK)->put(
            $path,
            $this->placeholderJpeg("{$blog->id} - {$sortOrder}"),
        );

        $blog->images()->create([
            'path' => $path,
            'disk' => BlogService::IMAGE_DISK,
            'original_name' => Str::slug($blog->title).'-'.$sortOrder.'.jpg',
            'sort_order' => $sortOrder,
        ]);
    }

    /**
     * A solid-colour 800x450 JPEG with a label, returned as a binary string.
     * Real bytes on disk, so the generated URLs actually resolve.
     */
    private function placeholderJpeg(string $label): string
    {
        $image = imagecreatetruecolor(800, 450);

        $background = imagecolorallocate(
            $image,
            random_int(30, 120),
            random_int(30, 120),
            random_int(80, 180),
        );
        imagefilledrectangle($image, 0, 0, 800, 450, $background);

        $foreground = imagecolorallocate($image, 255, 255, 255);
        imagestring($image, 5, 20, 20, "BLOG {$label}", $foreground);

        ob_start();
        imagejpeg($image, null, 80);
        $binary = (string) ob_get_clean();

        imagedestroy($image);

        return $binary;
    }
}
