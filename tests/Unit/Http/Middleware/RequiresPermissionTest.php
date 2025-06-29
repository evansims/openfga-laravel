<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use OpenFGA\Laravel\Contracts\{ManagerInterface};
use OpenFGA\Laravel\Http\Middleware\{OpenFgaMiddleware, RequiresPermission};
use OpenFGA\Laravel\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(TestCase::class);

// Import shared test helpers
use function OpenFGA\Laravel\Tests\Support\{createAuthUser, createMiddlewareTestUser};

// Datasets for testing various user types and permission scenarios
dataset('middleware_permissions', [
    'viewer on document' => ['viewer', 'document:123', null, 'user:123'],
    'admin on post' => ['admin', 'post:789', null, 'user:456'],
    'owner on company' => ['owner', 'company:acme', 'admin-connection', 'user:999'],
    'editor on file' => ['editor', 'file:report.pdf', null, 'user:111'],
    'member on group' => ['member', 'group:engineering', 'main', 'user:222'],
]);

dataset('user_ids', [
    'simple numeric' => [123, 'user:123'],
    'uuid format' => ['550e8400-e29b-41d4-a716-446655440000', 'user:550e8400-e29b-41d4-a716-446655440000'],
    'email format' => ['test@example.com', 'user:test@example.com'],
    'special chars' => ['user-with_special.chars+123', 'user:user-with_special.chars+123'],
]);

// Helper function is imported from Support directory

describe('RequiresPermission middleware', function (): void {
    beforeEach(function (): void {
        $this->mockManager = Mockery::mock(ManagerInterface::class);
        $openFgaMiddleware = new OpenFgaMiddleware($this->mockManager);
        $this->middleware = new RequiresPermission($openFgaMiddleware);

        $this->request = Request::create(uri: '/test');
        $this->next = fn (Request $request): Response => new Response('OK');
    });

    describe('permission delegation', function (): void {
        it('delegates to openfga middleware with connection', function (): void {
            $user = createAuthUser('user:999');

            Auth::shouldReceive('check')->once()->andReturnTrue();
            $this->request->setUserResolver(fn () => $user);

            $this->mockManager->shouldReceive('check')
                ->once()
                ->with('user:999', 'owner', 'company:acme', [], [], 'admin-connection')
                ->andReturnTrue();

            $response = $this->middleware->handle($this->request, $this->next, 'owner', 'company:acme', 'admin-connection');

            expect($response)
                ->toBeMiddlewareResponse()
                ->and($response->getContent())->toBe('OK');
        });

        it('delegates with explicit object', function (): void {
            $user = createMiddlewareTestUser(123, 'user:123');

            Auth::shouldReceive('check')->once()->andReturnTrue();
            $this->request->setUserResolver(fn () => $user);

            $this->mockManager->shouldReceive('check')
                ->once()
                ->with('user:123', 'viewer', 'document:123', [], [], null)
                ->andReturnTrue();

            $response = $this->middleware->handle($this->request, $this->next, 'viewer', 'document:123');

            expect($response)
                ->toBeMiddlewareResponse()
                ->and($response->getContent())->toBe('OK');
        });

        it('resolves object from route parameters', function (): void {
            $user = createAuthUser('user:456');

            $route = new Route(
                methods: ['GET'],
                uri: '/posts/{post}',
                action: [],
            );
            $route->bind($this->request);
            $route->setParameter('post', '789');
            $this->request->setRouteResolver(fn () => $route);

            Auth::shouldReceive('check')->once()->andReturnTrue();
            $this->request->setUserResolver(fn () => $user);

            $this->mockManager->shouldReceive('check')
                ->once()
                ->with('user:456', 'admin', 'post:789', [], [], null)
                ->andReturnTrue();

            $response = $this->middleware->handle($this->request, $this->next, 'admin');

            expect($response)
                ->toBeMiddlewareResponse()
                ->and($response->getContent())->toBe('OK');
        });

        it('handles various permission scenarios', function (string $relation, string $object, ?string $connection, string $userId): void {
            $user = createMiddlewareTestUser(substr(string: $userId, offset: 5), $userId);

            Auth::shouldReceive('check')->once()->andReturnTrue();
            $this->request->setUserResolver(fn () => $user);

            $this->mockManager->shouldReceive('check')
                ->once()
                ->with($userId, $relation, $object, [], [], $connection)
                ->andReturnTrue();

            $args = array_filter(array: [$relation, $object, $connection], callback: fn ($v) => null !== $v);
            $response = $this->middleware->handle($this->request, $this->next, ...$args);

            expect($response)
                ->toBeMiddlewareResponse()
                ->and($response->getContent())->toBe('OK');
        })->with('middleware_permissions');

        it('supports different user ID formats', function (mixed $id, string $authId): void {
            $user = createMiddlewareTestUser($id, $authId);

            Auth::shouldReceive('check')->once()->andReturnTrue();
            $this->request->setUserResolver(fn () => $user);

            $this->mockManager->shouldReceive('check')
                ->once()
                ->with($authId, 'viewer', 'document:test', [], [], null)
                ->andReturnTrue();

            $response = $this->middleware->handle($this->request, $this->next, 'viewer', 'document:test');

            expect($response)
                ->toBeMiddlewareResponse();
        })->with('user_ids');
    });

    describe('middleware structure', function (): void {
        it('has correct constructor dependency', function (): void {
            $reflection = new ReflectionClass(RequiresPermission::class);
            $constructor = $reflection->getConstructor();

            expect($constructor)->not->toBeNull();

            $parameters = $constructor->getParameters();
            expect($parameters)
                ->toHaveCount(1)
                ->and($parameters[0])
                ->getName()->toBe('middleware')
                ->getType()?->getName()->toBe(OpenFgaMiddleware::class);
        });

        it('is a readonly class', function (): void {
            expect(RequiresPermission::class)
                ->toBeReadonly();
        });
    });

    describe('error handling', function (): void {
        it('preserves exceptions from openfga middleware', function (): void {
            Auth::shouldReceive('check')->once()->andReturnFalse();

            expect(fn () => $this->middleware->handle($this->request, $this->next, 'viewer', 'document:123'))
                ->toThrow(HttpException::class, 'Authentication required');
        });

        it('denies access when permission check fails', function (): void {
            $user = createMiddlewareTestUser(123, 'user:123');

            Auth::shouldReceive('check')->once()->andReturnTrue();
            $this->request->setUserResolver(fn () => $user);

            $this->mockManager->shouldReceive('check')
                ->once()
                ->with('user:123', 'admin', 'document:456', [], [], null)
                ->andReturnFalse();

            expect(fn () => $this->middleware->handle($this->request, $this->next, 'admin', 'document:456'))
                ->toThrow(HttpException::class);
        });
    });

    // Removed performance tests - middleware performance is negligible and environment-dependent
});
