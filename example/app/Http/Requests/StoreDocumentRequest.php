<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenFGA\Laravel\Facades\OpenFga;

class StoreDocumentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Check if user can create documents in the specified location
        if ($this->folder_id) {
            return OpenFga::check(
                user: $this->user()->authorizationUser(),
                relation: 'editor',
                object: "folder:{$this->folder_id}"
            );
        }

        if ($this->team_id) {
            return OpenFga::check(
                user: $this->user()->authorizationUser(),
                relation: 'member',
                object: "team:{$this->team_id}"
            );
        }

        if ($this->organization_id) {
            return OpenFga::check(
                user: $this->user()->authorizationUser(),
                relation: 'member',
                object: "organization:{$this->organization_id}"
            );
        }

        // User must specify a parent location
        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'type' => 'required|in:text,markdown,richtext',
            'folder_id' => 'nullable|exists:folders,id|required_without_all:team_id,organization_id',
            'team_id' => 'nullable|exists:teams,id|required_without_all:folder_id,organization_id',
            'organization_id' => 'nullable|exists:organizations,id|required_without_all:folder_id,team_id',
            'status' => 'sometimes|in:draft,published,archived',
            'tags' => 'sometimes|array',
            'tags.*' => 'string|max:50',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Please provide a title for the document.',
            'content.required' => 'Document content cannot be empty.',
            'type.required' => 'Please specify the document type.',
            'type.in' => 'Invalid document type. Must be text, markdown, or richtext.',
            'folder_id.required_without_all' => 'Please specify where to create the document.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Set default status if not provided
        if (!$this->has('status')) {
            $this->merge([
                'status' => 'draft',
            ]);
        }

        // Clean up tags
        if ($this->has('tags') && is_string($this->tags)) {
            $this->merge([
                'tags' => array_map('trim', explode(',', $this->tags)),
            ]);
        }
    }
}