<?php

declare(strict_types=1);

use Illuminate\Http\{JsonResponse, Request, Response};
use Illuminate\Support\Facades\{View};
use OpenFGA\Laravel\Profiling\{OpenFgaProfiler, ProfilingMiddleware};
use OpenFGA\Laravel\Tests\Support\ConfigRestoration;
use OpenFGA\Laravel\Tests\TestCase;

uses(TestCase::class);
uses(ConfigRestoration::class);

describe('ProfilingMiddleware', function (): void {
    beforeEach(function (): void {
        $this->setUpConfigRestoration();
        // Since OpenFgaProfiler is final, we'll use a real instance
        $this->setConfigWithRestore('openfga.profiling.enabled', true);
        $this->profiler = new OpenFgaProfiler;
        $this->middleware = new ProfilingMiddleware($this->profiler);
    });

    afterEach(function (): void {
        $this->tearDownConfigRestoration();
    });

    it('is marked as final and internal', function (): void {
        $reflection = new ReflectionClass(ProfilingMiddleware::class);
        expect($reflection->isFinal())->toBeTrue();
        expect($reflection->getDocComment())->toContain('@internal');
    });

    it('is readonly', function (): void {
        $reflection = new ReflectionClass(ProfilingMiddleware::class);
        expect($reflection->isReadOnly())->toBeTrue();
    });

    describe('handle method', function (): void {
        it('passes request through when response is not injectable', function (): void {
            $request = Request::create(uri: '/api/test');
            $response = new Response('test content');

            $next = fn () => $response;

            $result = $this->middleware->handle($request, $next);

            expect($result)->toBe($response);
            expect($result->getContent())->toBe('test content');
        });

        it('does not inject when profiling is disabled', function (): void {
            $this->setConfigWithRestore('openfga.profiling.inject_web_middleware', true);

            $this->profiler->disable();

            $request = Request::create(
                uri: '/',
                method: 'GET',
            );
            $request->headers->set('Accept', 'text/html');

            $response = new Response('<html><body>Test</body></html>');
            $response->headers->set('Content-Type', 'text/html');

            $next = fn () => $response;

            $result = $this->middleware->handle($request, $next);

            expect($result->getContent())->toBe('<html><body>Test</body></html>');
        });

        it('does not inject when injection is disabled in config', function (): void {
            $this->setConfigWithRestore('openfga.profiling.inject_web_middleware', false);

            $request = Request::create(
                uri: '/',
                method: 'GET',
            );
            $response = new Response('<html><body>Test</body></html>');

            $next = fn () => $response;

            $result = $this->middleware->handle($request, $next);

            expect($result->getContent())->toBe('<html><body>Test</body></html>');
        });

        it('does not inject for AJAX requests', function (): void {
            $this->setConfigWithRestore('openfga.profiling.inject_web_middleware', true);

            $request = Request::create(
                uri: '/',
                method: 'GET',
            );
            $request->headers->set('X-Requested-With', 'XMLHttpRequest');
            $request->headers->set('Accept', 'text/html');

            $response = new Response('<html><body>Test</body></html>');
            $response->headers->set('Content-Type', 'text/html');

            $next = fn () => $response;

            $result = $this->middleware->handle($request, $next);

            expect($result->getContent())->toBe('<html><body>Test</body></html>');
        });

        it('does not inject for PJAX requests', function (): void {
            $this->setConfigWithRestore('openfga.profiling.inject_web_middleware', true);

            $request = Request::create(
                uri: '/',
                method: 'GET',
            );
            $request->headers->set('X-PJAX', 'true');
            $request->headers->set('Accept', 'text/html');

            $response = new Response('<html><body>Test</body></html>');
            $response->headers->set('Content-Type', 'text/html');

            $next = fn () => $response;

            $result = $this->middleware->handle($request, $next);

            expect($result->getContent())->toBe('<html><body>Test</body></html>');
        });

        it('does not inject when request does not accept HTML', function (): void {
            $this->setConfigWithRestore('openfga.profiling.inject_web_middleware', true);

            $request = Request::create(
                uri: '/',
                method: 'GET',
            );
            $request->headers->set('Accept', 'application/json');

            $response = new Response('<html><body>Test</body></html>');
            $response->headers->set('Content-Type', 'text/html');

            $next = fn () => $response;

            $result = $this->middleware->handle($request, $next);

            expect($result->getContent())->toBe('<html><body>Test</body></html>');
        });

        it('does not inject when response is not HTML', function (): void {
            $this->setConfigWithRestore('openfga.profiling.inject_web_middleware', true);

            $request = Request::create(
                uri: '/',
                method: 'GET',
            );
            $request->headers->set('Accept', 'text/html');

            $response = new Response('{"key": "value"}');
            $response->headers->set('Content-Type', 'application/json');

            $next = fn () => $response;

            $result = $this->middleware->handle($request, $next);

            expect($result->getContent())->toBe('{"key": "value"}');
        });

        it('does not inject when response has no body tag', function (): void {
            $this->setConfigWithRestore('openfga.profiling.inject_web_middleware', true);

            $request = Request::create(
                uri: '/',
                method: 'GET',
            );
            $request->headers->set('Accept', 'text/html');

            $response = new Response('<html>No body tag here</html>');
            $response->headers->set('Content-Type', 'text/html');

            $next = fn () => $response;

            $result = $this->middleware->handle($request, $next);

            expect($result->getContent())->toBe('<html>No body tag here</html>');
        });

        it('injects profiler data when all conditions are met', function (): void {
            $this->setConfigWithRestore('openfga.profiling.inject_web_middleware', true);

            View::shouldReceive('make')
                ->with('openfga::profiler', ['profiler' => $this->profiler])
                ->once()
                ->andReturn(Mockery::mock([
                    'render' => '<div>Profiler Data</div>',
                ]));

            $request = Request::create(
                uri: '/',
                method: 'GET',
            );
            $request->headers->set('Accept', 'text/html');

            $response = new Response('<html><body>Test</body></html>');
            $response->headers->set('Content-Type', 'text/html');

            $next = fn () => $response;

            $result = $this->middleware->handle($request, $next);

            expect($result->getContent())->toBe('<html><body>Test<div>Profiler Data</div></body></html>');
        });

        it('handles JsonResponse', function (): void {
            $this->setConfigWithRestore('openfga.profiling.inject_web_middleware', true);

            $request = Request::create(
                uri: '/',
                method: 'GET',
            );
            $request->headers->set('Accept', 'text/html');

            $response = new JsonResponse(['data' => 'test']);
            $response->headers->set('Content-Type', 'text/html'); // Unusual but testing the condition

            $next = fn () => $response;

            $result = $this->middleware->handle($request, $next);

            expect($result)->toBeInstanceOf(JsonResponse::class);
        });

        it('handles non-Response objects from next closure', function (): void {
            $request = Request::create(
                uri: '/',
                method: 'GET',
            );

            $next = fn () => 'not a response';

            $result = $this->middleware->handle($request, $next);

            expect($result)->toBe('not a response');
        });
    });

    describe('edge cases', function (): void {
        it('handles empty content type header', function (): void {
            $this->setConfigWithRestore('openfga.profiling.inject_web_middleware', true);

            $request = Request::create(
                uri: '/',
                method: 'GET',
            );
            $request->headers->set('Accept', 'text/html');

            $response = new Response('<html><body>Test</body></html>');
            // No Content-Type header set

            $next = fn () => $response;

            $result = $this->middleware->handle($request, $next);

            // Should not inject because Content-Type is not 'text/html'
            expect($result->getContent())->toBe('<html><body>Test</body></html>');
        });

        it('handles multiple body tags', function (): void {
            $this->setConfigWithRestore('openfga.profiling.inject_web_middleware', true);

            View::shouldReceive('make')
                ->once()
                ->andReturn(Mockery::mock([
                    'render' => '<div>Profiler</div>',
                ]));

            $request = Request::create(
                uri: '/',
                method: 'GET',
            );
            $request->headers->set('Accept', 'text/html');

            $response = new Response('<html><body>First</body><body>Second</body></html>');
            $response->headers->set('Content-Type', 'text/html');

            $next = fn () => $response;

            $result = $this->middleware->handle($request, $next);

            // All </body> tags are replaced by str_replace
            expect($result->getContent())->toBe('<html><body>First<div>Profiler</div></body><body>Second<div>Profiler</div></body></html>');
        });
    });
});
