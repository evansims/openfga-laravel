<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use OpenFGA\Laravel\Contracts\{ManagerInterface};
use OpenFGA\Laravel\Http\Middleware\RequiresAnyPermission;
use OpenFGA\Laravel\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(TestCase::class);

// Import shared test helpers
use function OpenFGA\Laravel\Tests\Support\{createAuthObject, createAuthUser, createRegularAuthenticatable};

describe('RequiresAnyPermission', function (): void {
    beforeEach(function (): void {
        $this->mockManager = Mockery::mock(ManagerInterface::class);
        $this->middleware = new RequiresAnyPermission($this->mockManager);
        $this->request = Request::create(uri: '/test');
        $this->next = fn (Request $request): Response => new Response('OK');
    });

    describe('authentication checks', function (): void {
        it('aborts with 401 when user not authenticated', function (): void {
            Auth::shouldReceive('check')->once()->andReturnFalse();

            expect(fn () => $this->middleware->handle($this->request, $this->next, 'read', 'write'))
                ->toThrow(HttpException::class, 'Authentication required');
        });

        it('throws exception when no authenticated user', function (): void {
            Auth::shouldReceive('check')->once()->andReturnTrue();
            $this->request->setUserResolver(fn () => null);

            expect(fn () => $this->middleware->handle($this->request, $this->next, 'view', 'document:123'))
                ->toThrow(InvalidArgumentException::class, 'No authenticated user found');
        });

        it('throws exception when no relations provided', function (): void {
            Auth::shouldReceive('check')->once()->andReturnTrue();

            expect(fn () => $this->middleware->handle($this->request, $this->next))
                ->toThrow(InvalidArgumentException::class, 'At least one relation must be specified');
        });
    });

    describe('permission checks', function (): void {
        it('aborts with 403 when user has no permissions', function (): void {
            $user = createAuthUser('user:123');

            $route = new Route(
                methods: ['GET'],
                uri: '/documents/{document}',
                action: [],
            );
            $route->bind($this->request);
            $route->setParameter('document', '456');
            $this->request->setRouteResolver(fn () => $route);

            Auth::shouldReceive('check')->once()->andReturnTrue();
            $this->request->setUserResolver(fn () => $user);

            $this->mockManager->shouldReceive('check')
                ->with('user:123', 'read', 'document:456')
                ->once()
                ->andReturnFalse();

            $this->mockManager->shouldReceive('check')
                ->with('user:123', 'write', 'document:456')
                ->once()
                ->andReturnFalse();

            $this->mockManager->shouldReceive('check')
                ->with('user:123', 'delete', 'document:456')
                ->once()
                ->andReturnFalse();

            expect(fn () => $this->middleware->handle($this->request, $this->next, 'read', 'write', 'delete'))
                ->toThrow(HttpException::class, 'Insufficient permissions. Required any of: read, write, delete on document:456');
        });

        it('allows access when user has first permission', function (): void {
            $user = createAuthUser('user:123');

            $route = new Route(
                methods: ['GET'],
                uri: '/documents/{document}',
                action: [],
            );
            $route->bind($this->request);
            $route->setParameter('document', '456');
            $this->request->setRouteResolver(fn () => $route);

            Auth::shouldReceive('check')->once()->andReturnTrue();
            $this->request->setUserResolver(fn () => $user);

            $this->mockManager->shouldReceive('check')
                ->with('user:123', 'read', 'document:456')
                ->once()
                ->andReturnTrue();

            // Should not check additional permissions once one passes
            $this->mockManager->shouldNotReceive('check')
                ->with('user:123', 'write', 'document:456');

            $response = $this->middleware->handle($this->request, $this->next, 'read', 'write');

            expect($response)->toBeMiddlewareResponse()
                ->and($response->getContent())->toBe('OK');
        });

        it('allows access when user has last permission', function (): void {
            $user = createAuthUser('user:123');

            $route = new Route(
                methods: ['GET'],
                uri: '/documents/{document}',
                action: [],
            );
            $route->bind($this->request);
            $route->setParameter('document', '456');
            $this->request->setRouteResolver(fn () => $route);

            Auth::shouldReceive('check')->once()->andReturnTrue();
            $this->request->setUserResolver(fn () => $user);

            $this->mockManager->shouldReceive('check')
                ->with('user:123', 'read', 'document:456')
                ->once()
                ->andReturnFalse();

            $this->mockManager->shouldReceive('check')
                ->with('user:123', 'write', 'document:456')
                ->once()
                ->andReturnFalse();

            $this->mockManager->shouldReceive('check')
                ->with('user:123', 'admin', 'document:456')
                ->once()
                ->andReturnTrue();

            $response = $this->middleware->handle($this->request, $this->next, 'read', 'write', 'admin');

            expect($response)->toBeMiddlewareResponse()
                ->and($response->getContent())->toBe('OK');
        });

        it('allows access when user has second permission', function (): void {
            $user = createAuthUser('user:123');

            $route = new Route(
                methods: ['GET'],
                uri: '/documents/{document}',
                action: [],
            );
            $route->bind($this->request);
            $route->setParameter('document', '456');
            $this->request->setRouteResolver(fn () => $route);

            Auth::shouldReceive('check')->once()->andReturnTrue();
            $this->request->setUserResolver(fn () => $user);

            $this->mockManager->shouldReceive('check')
                ->with('user:123', 'read', 'document:456')
                ->once()
                ->andReturnFalse();

            $this->mockManager->shouldReceive('check')
                ->with('user:123', 'write', 'document:456')
                ->once()
                ->andReturnTrue();

            // Should not check additional permissions once one passes
            $this->mockManager->shouldNotReceive('check')
                ->with('user:123', 'delete', 'document:456');

            $response = $this->middleware->handle($this->request, $this->next, 'read', 'write', 'delete');

            expect($response)->toBeMiddlewareResponse()
                ->and($response->getContent())->toBe('OK');
        });

        it('checks permissions in order until one succeeds', function (): void {
            $user = createAuthUser('user:123');

            $route = new Route(
                methods: ['GET'],
                uri: '/documents/{document}',
                action: [],
            );
            $route->bind($this->request);
            $route->setParameter('document', '456');
            $this->request->setRouteResolver(fn () => $route);

            Auth::shouldReceive('check')->once()->andReturnTrue();
            $this->request->setUserResolver(fn () => $user);

            // Simulate checking permissions in order: viewer (fail), editor (fail), admin (succeed)
            $this->mockManager->shouldReceive('check')
                ->with('user:123', 'viewer', 'document:456')
                ->once()
                ->andReturnFalse();

            $this->mockManager->shouldReceive('check')
                ->with('user:123', 'editor', 'document:456')
                ->once()
                ->andReturnFalse();

            $this->mockManager->shouldReceive('check')
                ->with('user:123', 'admin', 'document:456')
                ->once()
                ->andReturnTrue();

            // Should not check remaining permissions
            $this->mockManager->shouldNotReceive('check')
                ->with('user:123', 'owner', 'document:456');

            $response = $this->middleware->handle($this->request, $this->next, 'viewer', 'editor', 'admin', 'owner');

            expect($response)->toBeMiddlewareResponse()
                ->and($response->getContent())->toBe('OK');
        });

        it('fails when single permission not met', function (): void {
            $user = createAuthUser('user:123');

            $route = new Route(
                methods: ['GET'],
                uri: '/system/{system}',
                action: [],
            );
            $route->bind($this->request);
            $route->setParameter('system', 'global');
            $this->request->setRouteResolver(fn () => $route);

            Auth::shouldReceive('check')->once()->andReturnTrue();
            $this->request->setUserResolver(fn () => $user);

            $this->mockManager->shouldReceive('check')
                ->with('user:123', 'admin', 'system:global')
                ->once()
                ->andReturnFalse();

            expect(fn () => $this->middleware->handle($this->request, $this->next, 'admin'))
                ->toThrow(HttpException::class, 'Insufficient permissions. Required any of: admin on system:global');
        });

        it('handles single permission requirement', function (): void {
            $user = createAuthUser('user:123');

            $route = new Route(
                methods: ['GET'],
                uri: '/system/{system}',
                action: [],
            );
            $route->bind($this->request);
            $route->setParameter('system', 'global');
            $this->request->setRouteResolver(fn () => $route);

            Auth::shouldReceive('check')->once()->andReturnTrue();
            $this->request->setUserResolver(fn () => $user);

            $this->mockManager->shouldReceive('check')
                ->with('user:123', 'admin', 'system:global')
                ->once()
                ->andReturnTrue();

            $response = $this->middleware->handle($this->request, $this->next, 'admin');

            expect($response)->toBeMiddlewareResponse()
                ->and($response->getContent())->toBe('OK');
        });
    });

    describe('object resolution', function (): void {
        it('infers object type from parameter names', function (): void {
            $user = createAuthUser('user:123');

            $route = new Route(
                methods: ['GET'],
                uri: '/projects/{projectId}/tasks/{task_id}',
                action: [],
            );
            $route->bind($this->request);
            $route->setParameter('projectId', '100');
            $route->setParameter('task_id', '200');
            $this->request->setRouteResolver(fn () => $route);

            Auth::shouldReceive('check')->once()->andReturnTrue();
            $this->request->setUserResolver(fn () => $user);

            // Should use the first parameter found (projectId -> project:100)
            $this->mockManager->shouldReceive('check')
                ->with('user:123', 'manage', 'project:100')
                ->once()
                ->andReturnTrue();

            $response = $this->middleware->handle($this->request, $this->next, 'manage');

            expect($response)->toBeMiddlewareResponse()
                ->and($response->getContent())->toBe('OK');
        });

        it('resolves object from route model', function (): void {
            $user = createAuthUser('user:123');
            $document = createAuthObject('document:789');

            $route = new Route(
                methods: ['GET'],
                uri: '/documents/{document}',
                action: [],
            );
            $route->bind($this->request);
            $route->setParameter('document', $document);
            $this->request->setRouteResolver(fn () => $route);

            Auth::shouldReceive('check')->once()->andReturnTrue();
            $this->request->setUserResolver(fn () => $user);

            $this->mockManager->shouldReceive('check')
                ->with('user:123', 'edit', 'document:789')
                ->once()
                ->andReturnFalse();

            $this->mockManager->shouldReceive('check')
                ->with('user:123', 'publish', 'document:789')
                ->once()
                ->andReturnTrue();

            $response = $this->middleware->handle($this->request, $this->next, 'edit', 'publish');

            expect($response)->toBeMiddlewareResponse()
                ->and($response->getContent())->toBe('OK');
        });

        it('resolves object from route parameter with camelCase Id suffix', function (): void {
            $user = createAuthUser('user:123');

            $route = new Route(
                methods: ['GET'],
                uri: '/folders/{folderId}',
                action: [],
            );
            $route->bind($this->request);
            $route->setParameter('folderId', '789');
            $this->request->setRouteResolver(fn () => $route);

            Auth::shouldReceive('check')->once()->andReturnTrue();
            $this->request->setUserResolver(fn () => $user);

            $this->mockManager->shouldReceive('check')
                ->with('user:123', 'access', 'folder:789')
                ->once()
                ->andReturnFalse();

            $this->mockManager->shouldReceive('check')
                ->with('user:123', 'modify', 'folder:789')
                ->once()
                ->andReturnTrue();

            $response = $this->middleware->handle($this->request, $this->next, 'access', 'modify');

            expect($response)->toBeMiddlewareResponse()
                ->and($response->getContent())->toBe('OK');
        });

        it('resolves object from route parameter with id suffix', function (): void {
            $user = createAuthUser('user:123');

            $route = new Route(
                methods: ['GET'],
                uri: '/documents/{document_id}',
                action: [],
            );
            $route->bind($this->request);
            $route->setParameter('document_id', '456');
            $this->request->setRouteResolver(fn () => $route);

            Auth::shouldReceive('check')->once()->andReturnTrue();
            $this->request->setUserResolver(fn () => $user);

            $this->mockManager->shouldReceive('check')
                ->with('user:123', 'view', 'document:456')
                ->once()
                ->andReturnTrue();

            $response = $this->middleware->handle($this->request, $this->next, 'view', 'download');

            expect($response)->toBeMiddlewareResponse()
                ->and($response->getContent())->toBe('OK');
        });

        describe('user resolution', function (): void {
            it('resolves user from regular model', function (): void {
                $user = createRegularAuthenticatable(456);

                $route = new Route(
                    methods: ['GET'],
                    uri: '/documents/{document}',
                    action: [],
                );
                $route->bind($this->request);
                $route->setParameter('document', '123');
                $this->request->setRouteResolver(fn () => $route);

                Auth::shouldReceive('check')->once()->andReturnTrue();
                $this->request->setUserResolver(fn () => $user);

                $this->mockManager->shouldReceive('check')
                    ->with('user:456', 'view', 'document:123')
                    ->once()
                    ->andReturnTrue();

                $response = $this->middleware->handle($this->request, $this->next, 'view', 'print');

                expect($response)->toBeMiddlewareResponse()
                    ->and($response->getContent())->toBe('OK');
            });
        });

        describe('error handling', function (): void {
            it('throws exception when no resolvable objects found', function (): void {
                $user = createAuthUser('user:123');

                $route = new Route(
                    methods: ['GET'],
                    uri: '/dashboard',
                    action: [],
                );
                $route->bind($this->request);
                $this->request->setRouteResolver(fn () => $route);

                Auth::shouldReceive('check')->once()->andReturnTrue();
                $this->request->setUserResolver(fn () => $user);

                expect(fn () => $this->middleware->handle($this->request, $this->next, 'access', 'manage'))
                    ->toThrow(InvalidArgumentException::class, 'Could not resolve authorization object from route parameters');
            });

            it('throws exception when no route found', function (): void {
                $user = createAuthUser('user:123');

                Auth::shouldReceive('check')->once()->andReturnTrue();
                $this->request->setUserResolver(fn () => $user);
                $this->request->setRouteResolver(fn () => null);

                expect(fn () => $this->middleware->handle($this->request, $this->next, 'read', 'write'))
                    ->toThrow(InvalidArgumentException::class, 'No route found for object resolution');
            });
        });

        it('uses parameter name as object type by default', function (): void {
            $user = createAuthUser('user:123');

            $route = new Route(
                methods: ['GET'],
                uri: '/posts/{post}',
                action: [],
            );
            $route->bind($this->request);
            $route->setParameter('post', '999');
            $this->request->setRouteResolver(fn () => $route);

            Auth::shouldReceive('check')->once()->andReturnTrue();
            $this->request->setUserResolver(fn () => $user);

            $this->mockManager->shouldReceive('check')
                ->with('user:123', 'read', 'post:999')
                ->once()
                ->andReturnFalse();

            $this->mockManager->shouldReceive('check')
                ->with('user:123', 'comment', 'post:999')
                ->once()
                ->andReturnTrue();

            $response = $this->middleware->handle($this->request, $this->next, 'read', 'comment');

            expect($response)->toBeMiddlewareResponse()
                ->and($response->getContent())->toBe('OK');
        });
    });
});
