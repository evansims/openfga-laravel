<?php

declare(strict_types=1);

namespace OpenFga\Laravel\Profiling;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

final readonly class ProfilingMiddleware
{
    public function __construct(
        private OpenFgaProfiler $profiler,
    ) {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        if ($this->shouldInjectProfiler($request, $response)) {
            $this->injectProfilerData($response);
        }

        return $response;
    }

    private function injectProfilerData($response): void
    {
        $content = $response->getContent();

        if (str_contains((string) $content, '</body>')) {
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

    private function shouldInjectProfiler(Request $request, $response): bool
    {
        return config('openfga.profiling.inject_web_middleware', false)
            && $this->profiler->isEnabled()
            && $request->acceptsHtml()
            && 'text/html' === $response->headers->get('Content-Type', '')
            && ! $request->ajax()
            && ! $request->pjax();
    }
}
