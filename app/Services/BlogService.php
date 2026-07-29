<?php

namespace App\Services;

use App\Models\Blog;
use App\Models\BlogImage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BlogService
{
    /**
     * Filesystem disk images are written to.
     *
     * `public` writes to storage/app/public and needs `php artisan storage:link`.
     * Switch to `s3` once the app runs on more than one instance -- EC2 local
     * disks don't survive the instance.
     */
    public const IMAGE_DISK = 'public';

    /** Directory within the disk that uploads land in. */
    public const IMAGE_DIRECTORY = 'blog-images';

    /**
     * @return LengthAwarePaginator<int, Blog>
     */
    public function paginate(int $perPage = 15, ?string $search = null, ?string $tag = null): LengthAwarePaginator
    {
        return Blog::query()
            ->with('images')
            // whereLike() defaults to case-insensitive and lets the grammar pick
            // the right operator per driver. A plain `like` is case-SENSITIVE on
            // Postgres, so `?search=laravel` would miss "Laravel ...".
            ->when($search, fn ($query, $term) => $query->whereLike('title', "%{$term}%"))
            ->when($tag, fn ($query, $value) => $query->whereJsonContains('tags', $value))
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>  $images
     */
    public function create(array $data, array $images = []): Blog
    {
        return DB::transaction(function () use ($data, $images) {
            $blog = Blog::create([
                'title' => $data['title'],
                'description' => $data['description'],
                'tags' => $data['tags'] ?? [],
            ]);

            $this->storeImages($blog, $images);

            return $blog->load('images');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>  $images
     * @param  array<int, int>  $removeImageIds
     */
    public function update(Blog $blog, array $data, array $images = [], array $removeImageIds = []): Blog
    {
        return DB::transaction(function () use ($blog, $data, $images, $removeImageIds) {
            // array_intersect_key keeps absent fields absent, so a PATCH with a
            // partial body doesn't null out columns it never mentioned.
            $blog->update(array_intersect_key($data, array_flip(['title', 'description', 'tags'])));

            if ($removeImageIds !== []) {
                // Constrained to this blog's images so one blog can't delete
                // another's by guessing IDs.
                $blog->images()->whereIn('id', $removeImageIds)->get()
                    ->each(fn (BlogImage $image) => $this->deleteImage($image));
            }

            $this->storeImages($blog, $images);

            return $blog->refresh()->load('images');
        });
    }

    public function delete(Blog $blog): void
    {
        DB::transaction(function () use ($blog) {
            // The FK cascades the rows; the files on disk are ours to clean up.
            $blog->images->each(fn (BlogImage $image) => $this->deleteImage($image));

            $blog->delete();
        });
    }

    /**
     * @param  array<int, UploadedFile>  $images
     */
    private function storeImages(Blog $blog, array $images): void
    {
        if ($images === []) {
            return;
        }

        $nextOrder = (int) $blog->images()->max('sort_order');

        foreach ($images as $image) {
            $path = $image->store(self::IMAGE_DIRECTORY, self::IMAGE_DISK);

            $blog->images()->create([
                'path' => $path,
                'disk' => self::IMAGE_DISK,
                'original_name' => $image->getClientOriginalName(),
                'sort_order' => ++$nextOrder,
            ]);
        }
    }

    private function deleteImage(BlogImage $image): void
    {
        Storage::disk($image->disk)->delete($image->path);

        $image->delete();
    }
}
