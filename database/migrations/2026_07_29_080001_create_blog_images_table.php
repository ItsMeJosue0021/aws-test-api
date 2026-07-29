<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained()->cascadeOnDelete();
            // Path relative to the disk root, e.g. blog-images/abc123.jpg
            $table->string('path');
            // Stored per-row so switching the app to S3 later doesn't orphan
            // images already written to the local disk.
            $table->string('disk')->default('public');
            $table->string('original_name')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['blog_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_images');
    }
};
