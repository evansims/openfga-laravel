<?php

declare(strict_types=1);
describe('Example Application Controllers', function (): void {
    $examplePath = __DIR__ . '/../../example';

    it('DocumentController has all required methods', function () use ($examplePath): void {
        $content = file_get_contents($examplePath . '/app/Http/Controllers/DocumentController.php');
        
        expect($content)
            ->toContain('public function index(')
            ->toContain('public function create(')
            ->toContain('public function store(')
            ->toContain('public function show(')
            ->toContain('public function edit(')
            ->toContain('public function update(')
            ->toContain('public function destroy(')
            ->toContain('public function share(')
            ->toContain('public function duplicate(')
            ->toContain('public function publish(')
            ->toContain('public function removeAccess(')
            ->toContain('public function accessList(')
            ->toContain('use App\Http\Requests\StoreDocumentRequest;')
            ->toContain('use App\Http\Requests\UpdateDocumentRequest;');
    });

    it('OrganizationController has proper CRUD methods', function () use ($examplePath): void {
        $content = file_get_contents($examplePath . '/app/Http/Controllers/OrganizationController.php');
        
        expect($content)
            ->toContain('public function index(): View')
            ->toContain('public function show(Organization $organization): View')
            ->toContain('public function edit(Organization $organization): View')
            ->toContain('public function update(Request $request, Organization $organization): RedirectResponse')
            ->toContain('public function destroy(Organization $organization): RedirectResponse')
            ->toContain('public function members(Organization $organization): View')
            ->toContain('public function createDepartment(Request $request, Organization $organization): RedirectResponse')
            ->toContain('public function addUser(Request $request, Organization $organization): RedirectResponse')
            ->toContain('public function removeUser(Organization $organization, User $user): RedirectResponse')
            ->toContain('public function settings(Organization $organization): View')
            ->toContain('public function reports(Organization $organization): View');
    });

    it('TeamController has member management methods', function () use ($examplePath): void {
        $content = file_get_contents($examplePath . '/app/Http/Controllers/TeamController.php');
        
        expect($content)
            ->toContain('public function index(): View')
            ->toContain('public function show(Team $team): View')
            ->toContain('public function documents(Team $team): View')
            ->toContain('public function addMember(Request $request, Team $team): RedirectResponse')
            ->toContain('public function removeMember(Team $team, User $user): RedirectResponse');
    });

    it('FolderController handles hierarchical operations', function () use ($examplePath): void {
        $content = file_get_contents($examplePath . '/app/Http/Controllers/FolderController.php');
        
        expect($content)
            ->toContain('public function index(): View')
            ->toContain('public function show(Folder $folder): View')
            ->toContain('public function createDocument(Request $request, Folder $folder): RedirectResponse')
            ->toContain('public function createSubfolder(Request $request, Folder $folder): RedirectResponse')
            ->toContain('public function destroy(Folder $folder): RedirectResponse')
            ->toContain('public function share(Request $request, Folder $folder): RedirectResponse')
            ->toContain('private function getBreadcrumbs(Folder $folder): array')
            ->toContain('use OpenFGA\Laravel\Facades\OpenFga;');
    });

    it('Admin UserController has permission checking', function () use ($examplePath): void {
        $content = file_get_contents($examplePath . '/app/Http/Controllers/Admin/UserController.php');
        
        expect($content)
            ->toContain('namespace App\Http\Controllers\Admin;')
            ->toContain('public function index(): View')
            ->toContain('public function show(User $user): View')
            ->toContain('private function getUserPermissions(User $user): array')
            ->toContain('private function getSharedDocumentCount(User $user): int')
            ->toContain('OpenFga::check(')
            ->toContain('OpenFga::listObjects(');
    });

    it('Admin AuditController handles audit logs', function () use ($examplePath): void {
        $content = file_get_contents($examplePath . '/app/Http/Controllers/Admin/AuditController.php');
        
        expect($content)
            ->toContain('namespace App\Http\Controllers\Admin;')
            ->toContain('public function index(Request $request): View')
            ->toContain('public function show($auditId): View')
            ->toContain('private function getResource(string $type, $id)')
            ->toContain('DB::table(\'permission_audit_logs\')');
    });

    it('controllers use authorization correctly', function () use ($examplePath): void {
        $documentController = file_get_contents($examplePath . '/app/Http/Controllers/DocumentController.php');
        
        expect($documentController)
            ->toContain('OpenFga::batchCheck(')
            ->toContain('->check(')
            ->toContain('whereUserCan(')
            ->toContain('batchCheckDocumentPermissions(');
        
        // Check that models use grant/revoke
        $documentModel = file_get_contents($examplePath . '/app/Models/Document.php');
        expect($documentModel)
            ->toContain('->grant(')
            ->toContain('->revoke(');
    });

    it('controllers validate input properly', function () use ($examplePath): void {
        $organizationController = file_get_contents($examplePath . '/app/Http/Controllers/OrganizationController.php');
        
        expect($organizationController)
            ->toContain('$request->validate([')
            ->toContain("'name' => 'required|string|max:255'")
            ->toContain("'user_id' => 'required|exists:users,id'")
            ->toContain("'role' => 'required|in:member,manager,admin'");
    });
});