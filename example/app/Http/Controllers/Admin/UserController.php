<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\View\View;
use OpenFGA\Laravel\Facades\OpenFga;

class UserController extends Controller
{
    public function index(): View
    {
        $users = User::query()
            ->withCount(['organizations', 'teams', 'ownedDocuments'])
            ->latest()
            ->paginate(20);

        return view('admin.users.index', compact('users'));
    }

    public function show(User $user): View
    {
        $user->load(['organizations', 'teams', 'ownedDocuments']);

        // Get user's permissions across different resources
        $permissions = $this->getUserPermissions($user);
        
        // Get activity stats
        $stats = [
            'organizations' => $user->organizations()->count(),
            'teams' => $user->teams()->count(),
            'owned_documents' => $user->ownedDocuments()->count(),
            'shared_documents' => $this->getSharedDocumentCount($user),
            'last_login' => $user->last_login_at,
        ];

        return view('admin.users.show', compact('user', 'permissions', 'stats'));
    }

    private function getUserPermissions(User $user): array
    {
        $permissions = [
            'organizations' => [],
            'teams' => [],
            'documents' => [],
        ];

        // Get organization permissions
        foreach ($user->organizations as $org) {
            $roles = [];
            foreach (['admin', 'manager', 'member'] as $role) {
                if (OpenFga::check($user->authorizationUser(), $role, "organization:{$org->id}")) {
                    $roles[] = $role;
                }
            }
            $permissions['organizations'][$org->id] = [
                'name' => $org->name,
                'roles' => $roles,
            ];
        }

        // Get team permissions  
        foreach ($user->teams as $team) {
            $roles = [];
            foreach (['lead', 'member'] as $role) {
                if (OpenFga::check($user->authorizationUser(), $role, "team:{$team->id}")) {
                    $roles[] = $role;
                }
            }
            $permissions['teams'][$team->id] = [
                'name' => $team->name,
                'department' => $team->department->name,
                'roles' => $roles,
            ];
        }

        return $permissions;
    }

    private function getSharedDocumentCount(User $user): int
    {
        // Get documents where user has access but is not owner
        $accessibleDocs = OpenFga::listObjects(
            user: $user->authorizationUser(),
            type: 'document',
            relation: 'viewer'
        );

        $docIds = collect($accessibleDocs['objects'] ?? [])
            ->map(fn($obj) => str_replace('document:', '', $obj))
            ->filter(fn($id) => is_numeric($id));

        return \App\Models\Document::whereIn('id', $docIds)
            ->where('owner_id', '!=', $user->id)
            ->count();
    }
}