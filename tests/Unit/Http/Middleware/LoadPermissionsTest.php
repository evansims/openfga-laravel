<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use OpenFGA\Laravel\Contracts\{ManagerInterface};
use OpenFGA\Laravel\Http\Middleware\LoadPermissions;
use OpenFGA\Laravel\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;

uses(TestCase::class);

// Import shared test helpers
use function OpenFGA\Laravel\Tests\Support\{createAuthObject, createAuthUser, createPlainModel, createRegularAuthenticatable};

describe('LoadPermissions', function (): void {
    beforeEach(function (): void {
        $this->mockManager = Mockery::mock(ManagerInterface::class);
        $this->middleware = new LoadPermissions($this->mockManager);
        $this->request = Request::create(uri: '/test');
        $this->next = fn (Request $request): Response => new Response('OK');
    });

    describe('object handling', function (): void {
        it('deduplicates identical objects', function (): void {
            $user = createAuthUser('user:123');
            $document1 = createAuthObject('document:456');
            $document2 = createAuthObject('document:456'); // Same object ID

            $route = new Route(
                methods: ['GET'],
                uri: '/documents/{document1}/related/{document2}',
                action: [],
            );
            $route->bind($this->request);
            $route->setParameter('document1', $document1);
            $route->setParameter('document2', $document2);
            $this->request->setRouteResolver(fn () => $route);

            Auth::shouldReceive('check')->once()->andReturnTrue();
            $this->request->setUserResolver(fn () => $user);

            $this->mockManager->shouldReceive('batchCheck')
                ->once()
                ->with([
                    ['user' => 'user:123', 'relation' => 'read', 'object' => 'document:456'],
                ]);

            $response = $this->middleware->handle($this->request, $this->next, 'read');

            expect($response)->toBeMiddlewareResponse()
                ->and($response->getContent())->toBe('OK');
        });

        it('handles model without authorization object', function (): void {
            $user = createAuthUser('user:123');
            $plainModel = createPlainModel('posts', 456);

            $route = new Route(
                methods: ['GET'],
                uri: '/posts/{post}',
                action: [],
            );
            $route->bind($this->request);
            $route->setParameter('post', $plainModel);
            $this->request->setRouteResolver(fn () => $route);

            Auth::shouldReceive('check')->once()->andReturnTrue();
            $this->request->setUserResolver(fn () => $user);

            $this->mockManager->shouldReceive('batchCheck')
                ->once()
                ->with([
                    ['user' => 'user:123', 'relation' => 'read', 'object' => 'posts:456'],
                ]);

            $response = $this->middleware->handle($this->request, $this->next, 'read');

            expect($response)->toBeMiddlewareResponse()
                ->and($response->getContent())->toBe('OK');
        });
    });

    describe('error handling', function (): void {
        it('handles null user gracefully', function (): void {
            Auth::shouldReceive('check')->once()->andReturnTrue();
            $this->request->setUserResolver(fn () => null);

            $this->mockManager->shouldNotReceive('batchCheck');

            $response = $this->middleware->handle($this->request, $this->next, 'read');

            expect($response)->toBeMiddlewareResponse()
                ->and($response->getContent())->toBe('OK');
        });

        it('handles request without route', function (): void {
            $user = createAuthUser('user:123');

            Auth::shouldReceive('check')->once()->andReturnTrue();
            $this->request->setUserResolver(fn () => $user);
            $this->request->setRouteResolver(fn () => null);

            $this->mockManager->shouldNotReceive('batchCheck');

            $response = $this->middleware->handle($this->request, $this->next, 'read');

            expect($response)->toBeMiddlewareResponse()
                ->and($response->getContent())->toBe('OK');
        });

        it('handles route with non-model parameters', function (): void {
            $user = createAuthUser('user:123');

            $route = new Route(
                methods: ['GET'],
                uri: '/search/{query}',
                action: [],
            );
            $route->bind($this->request);
            $route->setParameter('query', 'test search');
            $this->request->setRouteResolver(fn () => $route);

            Auth::shouldReceive('check')->once()->andReturnTrue();
            $this->request->setUserResolver(fn () => $user);

            $this->mockManager->shouldNotReceive('batchCheck');

            $response = $this->middleware->handle($this->request, $this->next, 'read');

            expect($response)->toBeMiddlewareResponse()
                ->and($response->getContent())->toBe('OK');
        });

        it('handles route without parameters', function (): void {
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

            $this->mockManager->shouldNotReceive('batchCheck');

            $response = $this->middleware->handle($this->request, $this->next, 'read');

            expect($response)->toBeMiddlewareResponse()
                ->and($response->getContent())->toBe('OK');
        });
    });

    describe('permission preloading', function (): void {
        it('preloads permissions for multiple objects', function (): void {
            $user = createAuthUser('user:123');
            $document = createAuthObject('document:456');
            $folder = createAuthObject('folder:789');

            $route = new Route(
                methods: ['GET'],
                uri: '/documents/{document}/folders/{folder}',
                action: [],
            );
            $route->bind($this->request);
            $route->setParameter('document', $document);
            $route->setParameter('folder', $folder);
            $this->request->setRouteResolver(fn () => $route);

            Auth::shouldReceive('check')->once()->andReturnTrue();
            $this->request->setUserResolver(fn () => $user);

            $this->mockManager->shouldReceive('batchCheck')
                ->once()
                ->with([
                    ['user' => 'user:123', 'relation' => 'read', 'object' => 'document:456'],
                    ['user' => 'user:123', 'relation' => 'read', 'object' => 'folder:789'],
                    ['user' => 'user:123', 'relation' => 'write', 'object' => 'document:456'],
                    ['user' => 'user:123', 'relation' => 'write', 'object' => 'folder:789'],
                    ['user' => 'user:123', 'relation' => 'delete', 'object' => 'document:456'],
                    ['user' => 'user:123', 'relation' => 'delete', 'object' => 'folder:789'],
                ]);

            $response = $this->middleware->handle($this->request, $this->next, 'read', 'write', 'delete');

            expect($response)->toBeMiddlewareResponse()
                ->and($response->getContent())->toBe('OK');
        });

        it('preloads permissions for single object', function (): void {
            $user = createAuthUser('user:123');
            $document = createAuthObject('document:456');

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

            $this->mockManager->shouldReceive('batchCheck')
                ->once()
                ->with([
                    ['user' => 'user:123', 'relation' => 'read', 'object' => 'document:456'],
                    ['user' => 'user:123', 'relation' => 'write', 'object' => 'document:456'],
                ]);

            $response = $this->middleware->handle($this->request, $this->next, 'read', 'write');

            expect($response)->toBeMiddlewareResponse()
                ->and($response->getContent())->toBe('OK');
        });
    });

    describe('user resolution', function (): void {
        it('resolves user from regular model', function (): void {
            $user = createRegularAuthenticatable(789);
            $document = createAuthObject('document:456');

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

            $this->mockManager->shouldReceive('batchCheck')
                ->once()
                ->with([
                    ['user' => 'user:789', 'relation' => 'view', 'object' => 'document:456'],
                ]);

            $response = $this->middleware->handle($this->request, $this->next, 'view');

            expect($response)->toBeMiddlewareResponse()
                ->and($response->getContent())->toBe('OK');
        });
    });

    describe('skip conditions', function (): void {
        it('skips preloading when no objects found', function (): void {
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

            $this->mockManager->shouldNotReceive('batchCheck');

            $response = $this->middleware->handle($this->request, $this->next, 'read', 'write');

            expect($response)->toBeMiddlewareResponse()
                ->and($response->getContent())->toBe('OK');
        });

        it('skips preloading when no relations provided', function (): void {
            Auth::shouldReceive('check')->once()->andReturnTrue();

            $this->mockManager->shouldNotReceive('batchCheck');

            $response = $this->middleware->handle($this->request, $this->next);

            expect($response)->toBeMiddlewareResponse()
                ->and($response->getContent())->toBe('OK');
        });

        it('skips preloading when user not authenticated', function (): void {
            Auth::shouldReceive('check')->once()->andReturnFalse();

            $this->mockManager->shouldNotReceive('batchCheck');

            $response = $this->middleware->handle($this->request, $this->next, 'read', 'write');

            expect($response)->toBeMiddlewareResponse()
                ->and($response->getContent())->toBe('OK');
        });
    });
});
