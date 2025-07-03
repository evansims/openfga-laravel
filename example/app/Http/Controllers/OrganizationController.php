<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\{Department, Organization, User};
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\View\View;

class OrganizationController extends Controller
{
    public function index(): View
    {
        $organizations = Organization::query()
            ->whereHas('users', function ($query) {
                $query->where('user_id', auth()->id());
            })
            ->with('departments')
            ->paginate(10);

        return view('organizations.index', compact('organizations'));
    }

    public function show(Organization $organization): View
    {
        $organization->load(['departments', 'users']);
        $stats = [
            'departments' => $organization->departments()->count(),
            'teams' => $organization->departments()->withCount('teams')->get()->sum('teams_count'),
            'members' => $organization->users()->count(),
        ];

        return view('organizations.show', compact('organization', 'stats'));
    }

    public function members(Organization $organization): View
    {
        $members = $organization->users()
            ->with('departments')
            ->paginate(20);

        return view('organizations.members', compact('organization', 'members'));
    }

    public function edit(Organization $organization): View
    {
        return view('organizations.edit', compact('organization'));
    }

    public function update(Request $request, Organization $organization): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $organization->update($validated);

        return redirect()
            ->route('organizations.show', $organization)
            ->with('success', 'Organization updated successfully.');
    }

    public function createDepartment(Request $request, Organization $organization): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $department = $organization->departments()->create($validated);
        
        // Grant the current user manager role on the new department
        $department->grant(auth()->user(), 'manager');

        return redirect()
            ->route('organizations.show', $organization)
            ->with('success', 'Department created successfully.');
    }

    public function destroy(Organization $organization): RedirectResponse
    {
        $organization->delete();

        return redirect()
            ->route('organizations.index')
            ->with('success', 'Organization deleted successfully.');
    }

    public function addUser(Request $request, Organization $organization): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'role' => 'required|in:member,manager,admin',
        ]);

        $user = User::findOrFail($validated['user_id']);
        
        // Add user to organization in database
        $organization->users()->syncWithoutDetaching([$user->id]);
        
        // Grant OpenFGA permission
        $organization->grant($user, $validated['role']);

        return redirect()
            ->route('organizations.members', $organization)
            ->with('success', 'User added successfully.');
    }

    public function removeUser(Organization $organization, User $user): RedirectResponse
    {
        // Remove user from organization
        $organization->users()->detach($user->id);
        
        // Revoke all permissions
        foreach (['member', 'manager', 'admin'] as $role) {
            $organization->revoke($user, $role);
        }

        return redirect()
            ->route('organizations.members', $organization)
            ->with('success', 'User removed successfully.');
    }

    public function settings(Organization $organization): View
    {
        return view('organizations.settings', compact('organization'));
    }

    public function reports(Organization $organization): View
    {
        $stats = [
            'total_documents' => $organization->documents()->count(),
            'total_folders' => $organization->folders()->count(),
            'active_users' => $organization->users()->where('last_login_at', '>=', now()->subDays(30))->count(),
            'storage_used' => $organization->documents()->sum('size_bytes'),
        ];

        return view('organizations.reports', compact('organization', 'stats'));
    }
}