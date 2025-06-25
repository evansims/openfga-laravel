<div id="openfga-profiler" style="background: #f8f9fa; border-top: 2px solid #dee2e6; padding: 10px; font-family: monospace; font-size: 12px;">
    <details>
        <summary style="cursor: pointer; font-weight: bold;">
            OpenFGA Profiler - {{ $profiler->getProfiles()->count() }} operations ({{ number_format($profiler->getSummary()['total_time'], 2) }}ms)
        </summary>
        <div style="margin-top: 10px;">
            @php
                $summary = $profiler->getSummary();
            @endphp
            
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #e9ecef;">
                        <th style="padding: 5px; text-align: left; border: 1px solid #dee2e6;">Operation</th>
                        <th style="padding: 5px; text-align: right; border: 1px solid #dee2e6;">Count</th>
                        <th style="padding: 5px; text-align: right; border: 1px solid #dee2e6;">Total (ms)</th>
                        <th style="padding: 5px; text-align: right; border: 1px solid #dee2e6;">Avg (ms)</th>
                        <th style="padding: 5px; text-align: right; border: 1px solid #dee2e6;">Min (ms)</th>
                        <th style="padding: 5px; text-align: right; border: 1px solid #dee2e6;">Max (ms)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($summary['operations'] as $operation => $stats)
                        <tr>
                            <td style="padding: 5px; border: 1px solid #dee2e6;">{{ $operation }}</td>
                            <td style="padding: 5px; text-align: right; border: 1px solid #dee2e6;">{{ $stats['count'] }}</td>
                            <td style="padding: 5px; text-align: right; border: 1px solid #dee2e6;">{{ number_format($stats['total_time'], 2) }}</td>
                            <td style="padding: 5px; text-align: right; border: 1px solid #dee2e6;">{{ number_format($stats['avg_time'], 2) }}</td>
                            <td style="padding: 5px; text-align: right; border: 1px solid #dee2e6;">{{ number_format($stats['min_time'], 2) }}</td>
                            <td style="padding: 5px; text-align: right; border: 1px solid #dee2e6;">{{ number_format($stats['max_time'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            
            @if($profiler->getSlowQueries()->isNotEmpty())
                <h4 style="margin-top: 15px; color: #dc3545;">Slow Queries (> {{ config('openfga.profiling.slow_query_threshold', 100) }}ms)</h4>
                <ul style="margin: 5px 0; padding-left: 20px;">
                    @foreach($profiler->getSlowQueries() as $query)
                        <li style="margin: 3px 0;">
                            {{ $query->getOperation() }} - {{ number_format($query->getDuration(), 2) }}ms
                            @if($query->getCacheStatus())
                                <span style="color: {{ $query->getCacheStatus() === 'hit' ? '#28a745' : '#6c757d' }};">(cache {{ $query->getCacheStatus() }})</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </details>
</div>