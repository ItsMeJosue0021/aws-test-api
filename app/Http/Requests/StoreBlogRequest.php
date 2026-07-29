<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBlogRequest extends FormRequest
{
    public function authorize(): bool
    {
        // No auth on this endpoint yet -- it exists to smoke-test the deploy.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],

            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['required', 'string', 'max:50', 'distinct'],

            // Send as multipart/form-data when uploading.
            'images' => ['nullable', 'array', 'max:5'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
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
