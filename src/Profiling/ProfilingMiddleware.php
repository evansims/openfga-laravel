<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Profiling;

use Closure;
use Illuminate\Http\{JsonResponse, Request, Response};
use Illuminate\Support\Facades\View;
use InvalidArgumentException;

use function is_string;

/**
 * @internal
 */
final readonly class ProfilingMiddleware
{
    public function __construct(
        private OpenFgaProfiler $profiler,
    ) {
    }

    /**
     * @param Closure(Request): mixed $next
     * @param Request                 $request
     *
     * @throws InvalidArgumentException
     */
    public function handle(Request $request, Closure $next): mixed
    {
        /** @var mixed $response */
        $response = $next($request);

        if (($response instanceof Response || $response instanceof JsonResponse)
            && $this->shouldInjectProfiler($request, $response)) {
            $this->injectProfilerData($response);
        }

        return $response;
    }

    /**
     * @param JsonResponse|Response $response
     *
     * @throws InvalidArgumentException
     */
    private function injectProfilerData(Response | JsonResponse $response): void
    {
        $content = $response->getContent();

        if (is_string($content) && str_contains($content, '</body>')) {
            $profilerHtml = View::make('openfga::profiler', [
                'profiler' => $this->profiler,
            ])->render();

            $content = str_replace(
                '</body>',
                $profilerHtml . '</body>',
                $content,
            );

            $response->setContent($content);
        }
    }

    /**
     * @param Request               $request
     * @param JsonResponse|Response $response
     */
    private function shouldInjectProfiler(Request $request, JsonResponse | Response $response): bool
    {
        return true === config('openfga.profiling.inject_web_middleware', false)
            && $this->profiler->isEnabled()
            && $request->acceptsHtml()
            && 'text/html' === $response->headers->get('Content-Type', '')
            && ! $request->ajax()
            && ! $request->pjax();
    }
}
