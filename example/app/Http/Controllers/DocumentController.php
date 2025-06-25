<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentRequest;
use App\Http\Requests\UpdateDocumentRequest;
use App\Models\Document;
use App\Models\Folder;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use OpenFGA\Laravel\Facades\OpenFga;

class DocumentController extends Controller
{
    /**
     * Display a listing of documents the user can access.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        
        // Get documents user can view, with eager loading for performance
        $documents = Document::whereUserCan($user, 'viewer')
            ->with(['owner', 'folder', 'team'])
            ->when($request->search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                      ->orWhere('content', 'like', "%{$search}%");
                });
            })
            ->when($request->status, function ($query, $status) {
                $query->where('status', $status);
            })
            ->orderBy('updated_at', 'desc')
            ->paginate(15);

        // Batch check permissions for efficient rendering
        $documentIds = $documents->pluck('id');
        $permissions = $this->batchCheckDocumentPermissions($user, $documentIds);

        return view('documents.index', compact('documents', 'permissions'));
    }

    /**
     * Show the form for creating a new document.
     */
    public function create(Request $request)
    {
        $user = Auth::user();
        
        // Get folders where user can create documents
        $folders = Folder::whereUserCan($user, 'editor')->get();
        
        // Get teams where user is a member
        $teams = Team::whereUserCan($user, 'member')->get();

        $selectedFolder = $request->folder_id ? 
            Folder::find($request->folder_id) : null;
        $selectedTeam = $request->team_id ? 
            Team::find($request->team_id) : null;

        return view('documents.create', compact('folders', 'teams', 'selectedFolder', 'selectedTeam'));
    }

    /**
     * Store a newly created document.
     */
    public function store(StoreDocumentRequest $request)
    {
        $user = Auth::user();
        
        // Verify user can create in the specified location
        if ($request->folder_id) {
            $folder = Folder::findOrFail($request->folder_id);
            $this->authorize('create-document-in-folder', $folder);
        }

        if ($request->team_id) {
            $team = Team::findOrFail($request->team_id);
            $this->authorize('create-document-in-team', $team);
        }

        $document = Document::create([
            'title' => $request->title,
            'content' => $request->content,
            'excerpt' => $request->excerpt,
            'status' => $request->status ?? 'draft',
            'owner_id' => $user->id,
            'folder_id' => $request->folder_id,
            'team_id' => $request->team_id,
            'metadata' => $request->metadata ?? [],
        ]);

        // Share with team members if created in team context
        if ($document->team_id) {
            $this->shareWithTeamMembers($document);
        }

        return redirect()
            ->route('documents.show', $document)
            ->with('success', 'Document created successfully!');
    }

    /**
     * Display the specified document.
     */
    public function show(Document $document)
    {
        // Check if user can view this document
        $this->authorize('view', $document);

        $user = Auth::user();
        
        // Get user's permissions on this document
        $permissions = [
            'can_view' => $document->check($user, 'viewer'),
            'can_edit' => $document->check($user, 'editor'),
            'can_own' => $document->check($user, 'owner'),
        ];

        // Get related documents user can access
        $relatedDocuments = collect();
        if ($document->folder_id) {
            $relatedDocuments = Document::where('folder_id', $document->folder_id)
                ->where('id', '!=', $document->id)
                ->whereUserCan($user, 'viewer')
                ->limit(5)
                ->get();
        }

        // Get document access statistics (for owners)
        $accessStats = null;
        if ($permissions['can_own']) {
            $accessStats = $document->getStats();
        }

        return view('documents.show', compact('document', 'permissions', 'relatedDocuments', 'accessStats'));
    }

    /**
     * Show the form for editing the document.
     */
    public function edit(Document $document)
    {
        $this->authorize('update', $document);

        $user = Auth::user();
        
        // Get folders where user can move this document
        $folders = Folder::whereUserCan($user, 'editor')->get();
        
        // Get teams where user can move this document
        $teams = Team::whereUserCan($user, 'member')->get();

        return view('documents.edit', compact('document', 'folders', 'teams'));
    }

    /**
     * Update the specified document.
     */
    public function update(UpdateDocumentRequest $request, Document $document)
    {
        $this->authorize('update', $document);

        $document->update($request->validated());

        return redirect()
            ->route('documents.show', $document)
            ->with('success', 'Document updated successfully!');
    }

    /**
     * Remove the specified document.
     */
    public function destroy(Document $document)
    {
        $this->authorize('delete', $document);

        $document->delete();

        return redirect()
            ->route('documents.index')
            ->with('success', 'Document deleted successfully!');
    }

    /**
     * Share document with a user.
     */
    public function share(Request $request, Document $document)
    {
        $this->authorize('share', $document);

        $request->validate([
            'user_id' => 'required|exists:users,id',
            'permission' => 'required|in:viewer,editor,owner',
        ]);

        $user = User::findOrFail($request->user_id);
        $document->shareWith($user, $request->permission);

        return back()->with('success', "Document shared with {$user->name}!");
    }

    /**
     * Remove user access to document.
     */
    public function removeAccess(Request $request, Document $document)
    {
        $this->authorize('share', $document);

        $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $user = User::findOrFail($request->user_id);
        $document->removeAccess($user);

        return back()->with('success', "Access removed for {$user->name}!");
    }

    /**
     * Duplicate a document.
     */
    public function duplicate(Document $document)
    {
        $this->authorize('view', $document);

        $user = Auth::user();
        $copy = $document->duplicate($user);

        return redirect()
            ->route('documents.edit', $copy)
            ->with('success', 'Document duplicated successfully!');
    }

    /**
     * Publish a document.
     */
    public function publish(Document $document)
    {
        $this->authorize('publish', $document);

        $document->publish();

        return back()->with('success', 'Document published successfully!');
    }

    /**
     * Get document access list for management.
     */
    public function accessList(Document $document)
    {
        $this->authorize('manage-access', $document);

        $accessUsers = $document->getAccessibleUsers();
        
        // Get actual User models for the response
        $userIds = collect($accessUsers)->pluck('user_id');
        $users = User::whereIn('id', $userIds)->get()->keyBy('id');
        
        $accessList = collect($accessUsers)->map(function ($access) use ($users) {
            return [
                'user' => $users[$access['user_id']] ?? null,
                'permissions' => $access['permissions'],
            ];
        })->filter(fn($item) => $item['user']);

        return response()->json($accessList);
    }

    /**
     * Batch check document permissions for efficient UI rendering.
     */
    private function batchCheckDocumentPermissions(User $user, $documentIds): array
    {
        $checks = [];
        foreach ($documentIds as $docId) {
            $checks[] = [$user->authorizationUser(), 'viewer', "document:{$docId}"];
            $checks[] = [$user->authorizationUser(), 'editor', "document:{$docId}"];
            $checks[] = [$user->authorizationUser(), 'owner', "document:{$docId}"];
        }

        $results = OpenFga::batchCheck($checks);
        
        // Organize results by document ID
        $permissions = [];
        $index = 0;
        foreach ($documentIds as $docId) {
            $permissions[$docId] = [
                'can_view' => $results[$index++] ?? false,
                'can_edit' => $results[$index++] ?? false,
                'can_own' => $results[$index++] ?? false,
            ];
        }

        return $permissions;
    }

    /**
     * Share document with all team members when created in team context.
     */
    private function shareWithTeamMembers(Document $document): void
    {
        if (!$document->team) {
            return;
        }

        $teamMembers = $document->team->getUsersWithRelation('member');
        
        foreach ($teamMembers as $memberObj) {
            $userId = str_replace('user:', '', $memberObj);
            if ($userId != $document->owner_id) {
                $user = User::find($userId);
                if ($user) {
                    $document->shareWith($user, 'viewer');
                }
            }
        }
    }
}