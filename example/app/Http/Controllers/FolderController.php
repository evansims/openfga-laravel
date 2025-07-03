<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\{Document, Folder, User};
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\View\View;
use OpenFGA\Laravel\Facades\OpenFga;

class FolderController extends Controller
{
    public function index(): View
    {
        // Get folders the user has access to
        $userAuth = auth()->user()->authorizationUser();
        
        // Get accessible folder IDs
        $accessibleFolders = OpenFga::listObjects(
            user: $userAuth,
            type: 'folder',
            relation: 'viewer'
        );

        $folders = Folder::query()
            ->whereIn('id', $accessibleFolders['objects'] ?? [])
            ->with(['parent', 'documents'])
            ->paginate(20);

        return view('folders.index', compact('folders'));
    }

    public function show(Folder $folder): View
    {
        $folder->load(['parent', 'children', 'documents']);
        
        $breadcrumbs = $this->getBreadcrumbs($folder);
        $stats = [
            'subfolders' => $folder->children()->count(),
            'documents' => $folder->documents()->count(),
            'total_size' => $folder->documents()->sum('size_bytes'),
        ];

        return view('folders.show', compact('folder', 'breadcrumbs', 'stats'));
    }

    public function createDocument(Request $request, Folder $folder): RedirectResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'type' => 'required|in:text,markdown,richtext',
        ]);

        $document = $folder->documents()->create([
            ...$validated,
            'owner_id' => auth()->id(),
            'size_bytes' => strlen($validated['content']),
        ]);

        // Grant owner permission to creator
        $document->grant(auth()->user(), 'owner');

        return redirect()
            ->route('documents.show', $document)
            ->with('success', 'Document created successfully.');
    }

    public function createSubfolder(Request $request, Folder $folder): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $subfolder = $folder->children()->create([
            ...$validated,
            'parent_type' => get_class($folder->parent),
            'parent_id' => $folder->parent_id,
        ]);

        // Creator gets admin permission on the new folder
        $subfolder->grant(auth()->user(), 'admin');

        return redirect()
            ->route('folders.show', $subfolder)
            ->with('success', 'Subfolder created successfully.');
    }

    public function destroy(Folder $folder): RedirectResponse
    {
        // Check if folder is empty
        if ($folder->children()->exists() || $folder->documents()->exists()) {
            return redirect()
                ->route('folders.show', $folder)
                ->with('error', 'Cannot delete non-empty folder.');
        }

        $parent = $folder->parent;
        $folder->delete();

        return redirect()
            ->route('folders.show', $parent)
            ->with('success', 'Folder deleted successfully.');
    }

    public function share(Request $request, Folder $folder): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'permission' => 'required|in:viewer,editor,admin',
        ]);

        $user = User::findOrFail($validated['user_id']);
        $folder->grant($user, $validated['permission']);

        return redirect()
            ->route('folders.show', $folder)
            ->with('success', 'Folder shared successfully.');
    }

    private function getBreadcrumbs(Folder $folder): array
    {
        $breadcrumbs = [];
        $current = $folder;

        while ($current) {
            array_unshift($breadcrumbs, [
                'name' => $current->name,
                'url' => route('folders.show', $current),
            ]);
            $current = $current->parent;
        }

        return $breadcrumbs;
    }
}