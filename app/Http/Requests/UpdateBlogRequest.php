<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBlogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `sometimes` means a field is only validated when present, so PATCH with a
     * partial body works. Omitting `tags` leaves them untouched; sending an
     * empty array clears them.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'required', 'string'],

            'tags' => ['sometimes', 'nullable', 'array', 'max:10'],
            'tags.*' => ['required', 'string', 'max:50', 'distinct'],

            // Uploaded images are appended to the existing set.
            'images' => ['sometimes', 'nullable', 'array', 'max:5'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],

            // IDs of existing images to delete in this request.
            'remove_image_ids' => ['sometimes', 'array'],
            'remove_image_ids.*' => ['integer', 'exists:blog_images,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'images.*.max' => 'Each image must be 5 MB or smaller.',
            'tags.*.distinct' => 'Tags must be unique.',
        ];
    }
}
