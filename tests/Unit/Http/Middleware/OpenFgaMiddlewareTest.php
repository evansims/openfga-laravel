<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use OpenFGA\Laravel\Contracts\{ManagerInterface};
use OpenFGA\Laravel\Http\Middleware\OpenFgaMiddleware;
use OpenFGA\Laravel\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(TestCase::class);

// Import shared test helpers
use function OpenFGA\Laravel\Tests\Support\{createAuthObject, createAuthUser};

describe('OpenFgaMiddleware', function (): void {
    beforeEach(function (): void {
        $this->mockManager = Mockery::mock(ManagerInterface::class);
        $this->middleware = new OpenFgaMiddleware($this->mockManager);
        $this->request = Request::create(uri: '/test');
        $this->next = static fn (Request $request): Response => new Response('OK');
    });

    describe('authentication checks', function (): void {
        it('aborts with 401 when user not authenticated', function (): void {
            Auth::shouldReceive('check')->once()->andReturnFalse();

            expect(fn () => $this->middleware->handle($this->request, $this->next, 'viewer'))
                ->toThrow(HttpException::class, 'Authentication required');
        });

        it('throws exception when no authenticated user', function (): void {
            Auth::shouldReceive('check')->once()->andReturnTrue();
            $this->request->setUserResolver(static fn (): null => null);

            expect(fn () => $this->middleware->handle($this->request, $this->next, 'view', 'document:123'))
                ->toThrow(InvalidArgumentException::class, 'No authenticated user found');
        });
    });

    describe('permission checks', function (): void {
        it('aborts with 403 when user lacks permission', function (): void {
            $user = createAuthUser('user:123');

            Auth::shouldReceive('check')->once()->andReturnTrue();
            $this->request->setUserResolver(static fn (): object => $user);

            $this->mockManager->shouldReceive('check')
                ->once()
                ->with('user:123', 'admin', 'document:456', [], [], null)
                ->andReturnFalse();

            expect(fn () => $this->middleware->handle($this->request, $this->next, 'admin', 'document:456'))
                ->toThrow(HttpException::class, 'Insufficient permissions. Required: admin on document:456');
        });

        it('allows access when user has permission', function (): void {
            $user = createAuthUser('user:123');

            Auth::shouldReceive('check')->once()->andReturnTrue();
            $this->request->setUserResolver(static fn (): object => $user);

            $this->mockManager->shouldReceive('check')
                ->once()
                ->with('user:123', 'viewer', 'document:456', [], [], null)
                ->andReturnTrue();

            $response = $this->middleware->handle($this->request, $this->next, 'viewer', 'document:456');

            expect($response)->toBeMiddlewareResponse()
                ->and($response->getContent())->toBe('OK');
        });

        it('uses custom connection', function (): void {
            $user = createAuthUser('user:123');

            Auth::shouldReceive('check')->once()->andReturnTrue();
            $this->request->setUserResolver(static fn (): object => $user);

            $this->mockManager->shouldReceive('check')
                ->once()
                ->with('user:123', 'admin', 'system:global', [], [], 'admin-connection')
                ->andReturnTrue();

            $response = $this->middleware->handle($this->request, $this->next, 'admin', 'system:global', 'admin-connection');

            expect($response)->toBeMiddlewareResponse()
                ->and($response->getContent())->toBe('OK');
        });
    });

    describe('object resolution', function (): void {
        it('prioritizes explicit object over route resolution', function (): void {
            $user = createAuthUser('user:123');
            $document = createAuthObject('document:999'); // This should be ignored

            $route = new Route(
                methods: ['GET'],
                uri: '/documents/{document}',
                action: [],
            );
            $route->bind($this->request);
            $route->setParameter('document', $document);

            $this->request->setRouteResolver(static fn (): Route => $route);

            Auth::shouldReceive('check')->once()->andReturnTrue();
            $this->request->setUserResolver(static fn (): object => $user);

            $this->mockManager->shouldReceive('check')
                ->once()
                ->with('user:123', 'edit', 'folder:123', [], [], null) // Uses explicit object
                ->andReturnTrue();

            $response = $this->middleware->handle($this->request, $this->next, 'edit', 'folder:123');

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

            $this->request->setRouteResolver(static fn (): Route => $route);

            Auth::shouldReceive('check')->once()->andReturnTrue();
            $this->request->setUserResolver(static fn (): object => $user);

            $this->mockManager->shouldReceive('check')
                ->once()
                ->with('user:123', 'edit', 'document:789', [], [], null)
                ->andReturnTrue();

            $response = $this->middleware->handle($this->request, $this->next, 'edit');

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

            $this->request->setRouteResolver(static fn (): Route => $route);

            Auth::shouldReceive('check')->once()->andReturnTrue();
            $this->request->setUserResolver(static fn (): object => $user);

            $this->mockManager->shouldReceive('check')
                ->once()
                ->with('user:123', 'access', 'folder:789', [], [], null)
                ->andReturnTrue();

            $response = $this->middleware->handle($this->request, $this->next, 'access');

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

            $this->request->setRouteResolver(static fn (): Route => $route);

            Auth::shouldReceive('check')->once()->andReturnTrue();
            $this->request->setUserResolver(static fn (): object => $user);

            $this->mockManager->shouldReceive('check')
                ->once()
                ->with('user:123', 'view', 'document:456', [], [], null)
                ->andReturnTrue();

            $response = $this->middleware->handle($this->request, $this->next, 'view');

            expect($response)->toBeMiddlewareResponse()
                ->and($response->getContent())->toBe('OK');
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

            $this->request->setRouteResolver(static fn (): Route => $route);

            Auth::shouldReceive('check')->once()->andReturnTrue();
            $this->request->setUserResolver(static fn (): object => $user);

            $this->mockManager->shouldReceive('check')
                ->once()
                ->with('user:123', 'read', 'post:999', [], [], null)
                ->andReturnTrue();

            $response = $this->middleware->handle($this->request, $this->next, 'read');

            expect($response)->toBeMiddlewareResponse()
                ->and($response->getContent())->toBe('OK');
        });

        it('throws exception when no resolvable objects found', function (): void {
            $user = createAuthUser('user:123');

            $route = new Route(
                methods: ['GET'],
                uri: '/dashboard',
                action: [],
            );
            $route->bind($this->request);

            $this->request->setRouteResolver(static fn (): Route => $route);

            Auth::shouldReceive('check')->once()->andReturnTrue();
            $this->request->setUserResolver(static fn (): object => $user);

            expect(fn () => $this->middleware->handle($this->request, $this->next, 'access'))
                ->toThrow(InvalidArgumentException::class, 'Could not resolve authorization object from route parameters');
        });

        it('throws exception when no route found', function (): void {
            $user = createAuthUser('user:123');

            Auth::shouldReceive('check')->once()->andReturnTrue();
            $this->request->setUserResolver(static fn (): object => $user);
            $this->request->setRouteResolver(static fn (): null => null);

            expect(fn () => $this->middleware->handle($this->request, $this->next, 'read'))
                ->toThrow(InvalidArgumentException::class, 'No route found for object resolution');
        });
    });

    describe('user resolution', function (): void {
        it('resolves user from regular model', function (): void {
            $user = new class extends Model implements Authenticatable {
                public function getAuthIdentifier(): mixed
                {
                    return 456;
                }

                public function getAuthIdentifierName(): string
                {
                    return 'id';
                }

                public function getAuthPassword(): string
                {
                    return '';
                }

                public function getAuthPasswordName(): string
                {
                    return 'password';
                }

                public function getRememberToken(): ?string
                {
                    return null;
                }

                public function getRememberTokenName(): string
                {
                    return '';
                }

                public function setRememberToken($value): void
                {
                }
            };

            Auth::shouldReceive('check')->once()->andReturnTrue();
            $this->request->setUserResolver(static fn (): object => $user);

            $this->mockManager->shouldReceive('check')
                ->once()
                ->with('user:456', 'view', 'document:123', [], [], null)
                ->andReturnTrue();

            $response = $this->middleware->handle($this->request, $this->next, 'view', 'document:123');

            expect($response)->toBeMiddlewareResponse()
                ->and($response->getContent())->toBe('OK');
        });
    });
});
