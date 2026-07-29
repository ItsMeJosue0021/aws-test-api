<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBlogRequest;
use App\Http\Requests\UpdateBlogRequest;
use App\Http\Resources\BlogResource;
use App\Models\Blog;
use App\Services\BlogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class BlogController extends Controller
{
    public function __construct(
        private readonly BlogService $blogs,
    ) {}

    /**
     * GET /api/blogs
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $blogs = $this->blogs->paginate(
            perPage: min((int) $request->integer('per_page', 15), 100),
            search: $request->string('search')->trim()->value() ?: null,
            tag: $request->string('tag')->trim()->value() ?: null,
        );

        return BlogResource::collection($blogs);
    }

    /**
     * POST /api/blogs
     */
    public function store(StoreBlogRequest $request): JsonResponse
    {
        $blog = $this->blogs->create(
            data: $request->validated(),
            images: $request->file('images', []),
        );

        return BlogResource::make($blog)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * GET /api/blogs/{blog}
     */
    public function show(Blog $blog): BlogResource
    {
        return BlogResource::make($blog->load('images'));
    }

    /**
     * PATCH /api/blogs/{blog}
     *
     * Sending files requires POST with `_method=PATCH` -- PHP does not parse
     * multipart bodies on PATCH requests.
     */
    public function update(UpdateBlogRequest $request, Blog $blog): BlogResource
    {
        $blog = $this->blogs->update(
            blog: $blog,
            data: $request->validated(),
            images: $request->file('images', []),
            removeImageIds: $request->input('remove_image_ids', []),
        );

        return BlogResource::make($blog);
    }

    /**
     * DELETE /api/blogs/{blog}
     */
    public function destroy(Blog $blog): Response
    {
        $this->blogs->delete($blog);

        return response()->noContent();
    }
}
