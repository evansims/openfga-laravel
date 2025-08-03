<?php

declare(strict_types=1);
describe('Example Application Request Classes', function (): void {
    $examplePath = __DIR__ . '/../../example';

    it('StoreDocumentRequest has authorization checks', function () use ($examplePath): void {
        $content = file_get_contents($examplePath . '/app/Http/Requests/StoreDocumentRequest.php');

        expect($content)
            ->toContain('class StoreDocumentRequest extends FormRequest')
            ->toContain('public function authorize(): bool')
            ->toContain('public function rules(): array')
            ->toContain('public function messages(): array')
            ->toContain('OpenFga::check(')
            ->toContain('if ($this->folder_id)')
            ->toContain('if ($this->team_id)')
            ->toContain('if ($this->organization_id)')
            ->toContain("relation: 'editor'")
            ->toContain("relation: 'member'");
    });

    it('StoreDocumentRequest has proper validation rules', function () use ($examplePath): void {
        $content = file_get_contents($examplePath . '/app/Http/Requests/StoreDocumentRequest.php');

        expect($content)
            ->toContain("'title' => 'required|string|max:255'")
            ->toContain("'content' => 'required|string'")
            ->toContain("'type' => 'required|in:text,markdown,richtext'")
            ->toContain("'folder_id' => 'nullable|exists:folders,id|required_without_all:team_id,organization_id'")
            ->toContain("'team_id' => 'nullable|exists:teams,id|required_without_all:folder_id,organization_id'")
            ->toContain("'organization_id' => 'nullable|exists:organizations,id|required_without_all:folder_id,team_id'")
            ->toContain("'status' => 'sometimes|in:draft,published,archived'")
            ->toContain("'tags' => 'sometimes|array'")
            ->toContain("'tags.*' => 'string|max:50'");
    });

    it('UpdateDocumentRequest checks editor permissions', function () use ($examplePath): void {
        $content = file_get_contents($examplePath . '/app/Http/Requests/UpdateDocumentRequest.php');

        expect($content)
            ->toContain('class UpdateDocumentRequest extends FormRequest')
            ->toContain('public function authorize(): bool')
            ->toContain('OpenFga::check(')
            ->toContain('$this->route(\'document\')')
            ->toContain("relation: 'editor'")
            ->toContain("object: \"document:{\$this->route('document')->id}\"");
    });

    it('UpdateDocumentRequest has version tracking', function () use ($examplePath): void {
        $content = file_get_contents($examplePath . '/app/Http/Requests/UpdateDocumentRequest.php');

        expect($content)
            ->toContain('protected function prepareForValidation(): void')
            ->toContain('if ($this->has(\'content\'))')
            ->toContain('$document->version + 1')
            ->toContain("'version' =>")
            ->toContain("'previous_version' =>");
    });

    it('UpdateDocumentRequest validates publish permission', function () use ($examplePath): void {
        $content = file_get_contents($examplePath . '/app/Http/Requests/UpdateDocumentRequest.php');

        expect($content)
            ->toContain('public function withValidator($validator): void')
            ->toContain("if (\$this->has('status') && \$this->status === 'published')")
            ->toContain('$canPublish = OpenFga::check(')
            ->toContain("relation: 'owner'")
            ->toContain('if (!$canPublish)')
            ->toContain("->add('status', 'You do not have permission to publish this document.')");
    });

    it('request classes have custom error messages', function () use ($examplePath): void {
        $storeContent = file_get_contents($examplePath . '/app/Http/Requests/StoreDocumentRequest.php');
        $updateContent = file_get_contents($examplePath . '/app/Http/Requests/UpdateDocumentRequest.php');

        expect($storeContent)
            ->toContain('public function messages(): array')
            ->toContain("'title.required' => 'Please provide a title for the document.'")
            ->toContain("'content.required' => 'Document content cannot be empty.'");

        expect($updateContent)
            ->toContain('public function messages(): array')
            ->toContain("'title.required' => 'Document title cannot be empty.'")
            ->toContain("'content.required' => 'Document content cannot be empty.'");
    });

    it('StoreDocumentRequest handles tag preparation', function () use ($examplePath): void {
        $content = file_get_contents($examplePath . '/app/Http/Requests/StoreDocumentRequest.php');

        expect($content)
            ->toContain('protected function prepareForValidation(): void')
            ->toContain("if (!\$this->has('status'))")
            ->toContain("'status' => 'draft'")
            ->toContain("if (\$this->has('tags') && is_string(\$this->tags))")
            ->toContain("array_map('trim', explode(',', \$this->tags))");
    });
});
