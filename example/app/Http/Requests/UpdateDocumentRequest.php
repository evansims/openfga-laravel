<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenFGA\Laravel\Facades\OpenFga;

class UpdateDocumentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // User must have editor permission on the document
        return OpenFga::check(
            user: $this->user()->authorizationUser(),
            relation: 'editor',
            object: "document:{$this->route('document')->id}"
        );
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'title' => 'sometimes|required|string|max:255',
            'content' => 'sometimes|required|string',
            'type' => 'sometimes|required|in:text,markdown,richtext',
            'status' => 'sometimes|in:draft,published,archived',
            'tags' => 'sometimes|array',
            'tags.*' => 'string|max:50',
            'version_notes' => 'sometimes|string|max:500',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Document title cannot be empty.',
            'content.required' => 'Document content cannot be empty.',
            'type.in' => 'Invalid document type. Must be text, markdown, or richtext.',
            'status.in' => 'Invalid status. Must be draft, published, or archived.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Clean up tags if provided as string
        if ($this->has('tags') && is_string($this->tags)) {
            $this->merge([
                'tags' => array_map('trim', explode(',', $this->tags)),
            ]);
        }

        // Track version if content is being updated
        if ($this->has('content')) {
            $document = $this->route('document');
            if ($document->content !== $this->content) {
                $this->merge([
                    'version' => $document->version + 1,
                    'previous_version' => $document->version,
                ]);
            }
        }
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Additional validation logic if needed
            if ($this->has('status') && $this->status === 'published') {
                $document = $this->route('document');
                
                // Check if user has permission to publish
                $canPublish = OpenFga::check(
                    user: $this->user()->authorizationUser(),
                    relation: 'owner',
                    object: "document:{$document->id}"
                );

                if (!$canPublish) {
                    $validator->errors()->add('status', 'You do not have permission to publish this document.');
                }
            }
        });
    }
}