<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use OpenFGA\Laravel\Contracts\{ManagerInterface};
use OpenFGA\Laravel\Http\Middleware\RequiresAllPermissions;
use OpenFGA\Laravel\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(TestCase::class);

// Import shared test helpers
use function OpenFGA\Laravel\Tests\Support\{createTestUser, routeToObjectId, setupRoute};

// Helper functions are imported from Support directory

// Datasets for different test scenarios
dataset('user_scenarios', [
    'basic_user' => ['user:123', 123],
    'admin_user' => ['user:456', 456],
    'special_user' => ['user:789', 789],
]);

dataset('route_scenarios', [
    'document' => ['/documents/{document}', 'document', '456'],
    'project' => ['/projects/{projectId}', 'projectId', '100'],
    'folder' => ['/folders/{folderId}', 'folderId', '789'],
]);

dataset('permission_scenarios', [
    'single_permission' => [['read']],
    'dual_permissions' => [['read', 'write']],
    'multiple_permissions' => [['read', 'write', 'delete']],
]);

describe('RequiresAllPermissions Middleware', function (): void {
    beforeEach(function (): void {
        $this->mockManager = Mockery::mock(ManagerInterface::class);
        $this->middleware = new RequiresAllPermissions($this->mockManager);
        $this->request = Request::create(uri: '/test');
        $this->next = static fn (Request $request): Response => new Response('OK');
    });

    it('aborts with 401 when user not authenticated', function (): void {
        Auth::shouldReceive('check')->andReturn(false);

        expect(fn () => $this->middleware->handle($this->request, $this->next, 'read', 'write'))
            ->toThrow(HttpException::class, 'Authentication required');
    });

    it('aborts with 403 when user missing all permissions', function (string $authId, int $identifier, string $routePath, string $paramName, string $paramValue, array $permissions): void {
        $user = createTestUser($authId, $identifier);
        setupRoute($this->request, $routePath, $paramName, $paramValue);

        Auth::shouldReceive('check')->andReturn(true);
        $this->request->setUserResolver(static fn (): object => $user);

        $objectId = routeToObjectId($routePath, $paramName, $paramValue);

        foreach ($permissions as $permission) {
            $this->mockManager->shouldReceive('check')
                ->with($authId, $permission, $objectId)
                ->once()
                ->andReturn(false);
        }

        $expectedMessage = 'Insufficient permissions. Missing: ' . implode(separator: ', ', array: $permissions) . ' on ' . $objectId;

        expect(fn () => $this->middleware->handle($this->request, $this->next, ...$permissions))
            ->toThrow(HttpException::class, $expectedMessage);
    })->with([
        // [authId, identifier, routePath, paramName, paramValue, permissions]
        ['user:123', 123, '/documents/{document}', 'document', '456', ['read']],
        ['user:123', 123, '/documents/{document}', 'document', '456', ['read', 'write']],
        ['user:456', 456, '/projects/{project}', 'project', '100', ['read', 'write', 'delete']],
        ['user:789', 789, '/folders/{folder}', 'folder', '789', ['admin']],
    ]);

    it('passes when user has all required permissions', function (string $authId, int $identifier, string $routePath, string $paramName, string $paramValue, array $permissions): void {
        $user = createTestUser($authId, $identifier);
        setupRoute($this->request, $routePath, $paramName, $paramValue);

        Auth::shouldReceive('check')->andReturn(true);
        $this->request->setUserResolver(static fn (): object => $user);

        $objectId = routeToObjectId($routePath, $paramName, $paramValue);

        foreach ($permissions as $permission) {
            $this->mockManager->shouldReceive('check')
                ->with($authId, $permission, $objectId)
                ->once()
                ->andReturn(true);
        }

        $response = $this->middleware->handle($this->request, $this->next, ...$permissions);

        expect($response->getContent())->toBe('OK');
    })->with([
        ['user:123', 123, '/documents/{document}', 'document', '456', ['read']],
        ['user:456', 456, '/projects/{project}', 'project', '100', ['read', 'write']],
        ['user:789', 789, '/folders/{folder}', 'folder', '789', ['admin', 'write']],
    ]);

    it('passes when user has some permissions but not all', function (): void {
        $user = createTestUser('user:123', 123);
        setupRoute($this->request, '/documents/{document}', 'document', '456');

        Auth::shouldReceive('check')->andReturn(true);
        $this->request->setUserResolver(static fn (): object => $user);

        $this->mockManager->shouldReceive('check')
            ->with('user:123', 'read', 'document:456')
            ->once()
            ->andReturn(true);

        $this->mockManager->shouldReceive('check')
            ->with('user:123', 'write', 'document:456')
            ->once()
            ->andReturn(false);

        expect(fn () => $this->middleware->handle($this->request, $this->next, 'read', 'write'))
            ->toThrow(HttpException::class, 'Insufficient permissions. Missing: write on document:456');
    });

    // Note: Complex authorization object and empty string edge cases are covered by integration tests

    it('handles simple route parameters', function (): void {
        $user = createTestUser('user:admin', 999);
        setupRoute($this->request, '/documents/{document}', 'document', '123');

        Auth::shouldReceive('check')->andReturn(true);
        $this->request->setUserResolver(static fn (): object => $user);

        $this->mockManager->shouldReceive('check')
            ->with('user:admin', 'edit', 'document:123')
            ->once()
            ->andReturn(true);

        $response = $this->middleware->handle($this->request, $this->next, 'edit');

        expect($response->getContent())->toBe('OK');
    });
});
