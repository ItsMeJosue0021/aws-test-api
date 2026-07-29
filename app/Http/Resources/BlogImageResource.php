<?php

namespace App\Http\Resources;

use App\Models\BlogImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BlogImage
 */
class BlogImageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url(),
            'original_name' => $this->original_name,
            'sort_order' => $this->sort_order,
        ];
    }
}
