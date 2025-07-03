<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\{Team, User};
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\View\View;

class TeamController extends Controller
{
    public function index(): View
    {
        // Get teams where user is a member, lead, or manager via department
        $teams = Team::query()
            ->whereHas('users', function ($query) {
                $query->where('user_id', auth()->id());
            })
            ->orWhereHas('department', function ($query) {
                $query->whereHas('users', function ($q) {
                    $q->where('user_id', auth()->id());
                });
            })
            ->with(['department', 'users'])
            ->paginate(10);

        return view('teams.index', compact('teams'));
    }

    public function show(Team $team): View
    {
        $team->load(['department', 'users', 'documents']);
        
        $stats = [
            'members' => $team->users()->count(),
            'documents' => $team->documents()->count(),
            'folders' => $team->folders()->count(),
        ];

        return view('teams.show', compact('team', 'stats'));
    }

    public function documents(Team $team): View
    {
        $documents = $team->documents()
            ->with('owner')
            ->latest()
            ->paginate(20);

        return view('teams.documents', compact('team', 'documents'));
    }

    public function addMember(Request $request, Team $team): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'role' => 'required|in:member,lead',
        ]);

        $user = User::findOrFail($validated['user_id']);
        
        // Add user to team in database
        $team->users()->syncWithoutDetaching([$user->id]);
        
        // Grant OpenFGA permission
        $team->grant($user, $validated['role']);

        return redirect()
            ->route('teams.show', $team)
            ->with('success', 'Member added successfully.');
    }

    public function removeMember(Team $team, User $user): RedirectResponse
    {
        // Remove user from team
        $team->users()->detach($user->id);
        
        // Revoke permissions
        foreach (['member', 'lead'] as $role) {
            $team->revoke($user, $role);
        }

        return redirect()
            ->route('teams.show', $team)
            ->with('success', 'Member removed successfully.');
    }
}