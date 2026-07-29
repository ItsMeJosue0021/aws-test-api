<?php

use App\Models\Blog;
use App\Models\BlogImage;
use App\Services\BlogService;
use Database\Seeders\BlogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(BlogService::IMAGE_DISK);
});

it('creates a blog with tags and images', function () {
    $response = $this->postJson('/api/blogs', [
        'title' => 'Deploying Laravel to EC2',
        'description' => 'Notes from the first deploy.',
        'tags' => ['aws', 'laravel'],
        'images' => [UploadedFile::fake()->image('cover.jpg')],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.title', 'Deploying Laravel to EC2')
        ->assertJsonPath('data.tags', ['aws', 'laravel'])
        ->assertJsonCount(1, 'data.images');

    $blog = Blog::sole();
    expect($blog->images)->toHaveCount(1);

    Storage::disk(BlogService::IMAGE_DISK)->assertExists($blog->images->first()->path);
});

it('rejects invalid payloads', function () {
    $this->postJson('/api/blogs', ['title' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['title', 'description']);
});

it('lists blogs with their images', function () {
    $this->postJson('/api/blogs', [
        'title' => 'First',
        'description' => 'Body',
        'images' => [UploadedFile::fake()->image('a.jpg')],
    ])->assertCreated();

    $this->getJson('/api/blogs')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonCount(1, 'data.0.images')
        ->assertJsonStructure(['data', 'links', 'meta']);
});

it('filters blogs by tag', function () {
    $this->postJson('/api/blogs', ['title' => 'A', 'description' => 'x', 'tags' => ['aws']]);
    $this->postJson('/api/blogs', ['title' => 'B', 'description' => 'y', 'tags' => ['php']]);

    $this->getJson('/api/blogs?tag=aws')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'A');
});

it('shows a single blog', function () {
    $blog = Blog::create(['title' => 'Solo', 'description' => 'Body', 'tags' => []]);

    $this->getJson("/api/blogs/{$blog->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $blog->id);
});

it('returns 404 for a missing blog', function () {
    $this->getJson('/api/blogs/999')->assertNotFound();
});

it('updates only the fields it is given', function () {
    $blog = Blog::create([
        'title' => 'Original',
        'description' => 'Keep me',
        'tags' => ['keep'],
    ]);

    $this->patchJson("/api/blogs/{$blog->id}", ['title' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('data.title', 'Renamed')
        ->assertJsonPath('data.description', 'Keep me')
        ->assertJsonPath('data.tags', ['keep']);
});

it('appends images on update and removes the requested ones', function () {
    $this->postJson('/api/blogs', [
        'title' => 'Gallery',
        'description' => 'Body',
        'images' => [UploadedFile::fake()->image('one.jpg')],
    ])->assertCreated();

    $blog = Blog::sole();
    $firstImage = $blog->images->first();

    $this->patchJson("/api/blogs/{$blog->id}", [
        'remove_image_ids' => [$firstImage->id],
    ])->assertOk()->assertJsonCount(0, 'data.images');

    Storage::disk(BlogService::IMAGE_DISK)->assertMissing($firstImage->path);
});

it('seeds 20 blogs with real image files on disk', function () {
    $this->seed(BlogSeeder::class);

    expect(Blog::count())->toBe(20);

    // Every seeded row must point at a file that actually exists, otherwise
    // the API hands out URLs that 404.
    BlogImage::all()->each(
        fn (BlogImage $image) => Storage::disk($image->disk)->assertExists($image->path)
    );

    expect(Blog::has('images')->count())->toBeGreaterThan(0);
});

it('deletes a blog and its image files', function () {
    $this->postJson('/api/blogs', [
        'title' => 'Doomed',
        'description' => 'Body',
        'images' => [UploadedFile::fake()->image('gone.jpg')],
    ])->assertCreated();

    $blog = Blog::sole();
    $path = $blog->images->first()->path;

    $this->deleteJson("/api/blogs/{$blog->id}")->assertNoContent();

    expect(Blog::count())->toBe(0);
    $this->assertDatabaseCount('blog_images', 0);
    Storage::disk(BlogService::IMAGE_DISK)->assertMissing($path);
});
