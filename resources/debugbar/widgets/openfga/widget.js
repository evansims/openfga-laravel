(function ($) {
    var csscls = PhpDebugBar.utils.makecsscls('phpdebugbar-widgets-');

    /**
     * Widget for OpenFGA collector
     */
    var OpenFgaWidget = PhpDebugBar.Widgets.OpenFgaWidget = PhpDebugBar.Widget.extend({

        className: csscls('openfga'),

        render: function () {
            this.$el.empty();
            
            var data = this.get('data');
            
            if (!data || data.length === 0) {
                this.$el.append('<div class="' + csscls('empty') + '">No OpenFGA queries</div>');
                return;
            }

            var $list = $('<ul class="' + csscls('list') + '"></ul>');
            
            // Summary
            var $summary = $('<li class="' + csscls('list-item') + ' ' + csscls('openfga-summary') + '"></li>');
            $summary.append('<strong>Summary:</strong> ');
            $summary.append(data.nb_queries + ' queries, ');
            $summary.append(data.nb_checks + ' checks, ');
            $summary.append(data.nb_writes + ' writes, ');
            $summary.append(data.nb_expansions + ' expansions');
            $summary.append(' (' + data.duration_str + ')');
            
            if (data.cache_hit_rate !== undefined) {
                $summary.append(' - Cache hit rate: ' + data.cache_hit_rate + '%');
            }
            
            $list.append($summary);

            // Checks
            if (data.checks && data.checks.length > 0) {
                var $checksHeader = $('<li class="' + csscls('list-item') + ' ' + csscls('openfga-header') + '"><strong>Permission Checks:</strong></li>');
                $list.append($checksHeader);
                
                $.each(data.checks, function (i, check) {
                    var $item = $('<li class="' + csscls('list-item') + '"></li>');
                    
                    if (check.type === 'batch') {
                        $item.append('<span class="' + csscls('openfga-type') + '">BATCH</span> ');
                        $item.append(check.count + ' checks, ' + check.results + ' allowed');
                    } else {
                        var resultClass = check.result ? 'allowed' : 'denied';
                        $item.append('<span class="' + csscls('openfga-result-' + resultClass) + '">' + 
                                    (check.result ? '✓' : '✗') + '</span> ');
                        $item.append(check.user + ' → ' + check.relation + ' → ' + check.object);
                    }
                    
                    $item.append(' <span class="' + csscls('duration') + '">(' + 
                                (check.duration * 1000).toFixed(2) + 'ms)</span>');
                    
                    $list.append($item);
                });
            }

            // Writes
            if (data.writes && data.writes.length > 0) {
                var $writesHeader = $('<li class="' + csscls('list-item') + ' ' + csscls('openfga-header') + '"><strong>Write Operations:</strong></li>');
                $list.append($writesHeader);
                
                $.each(data.writes, function (i, write) {
                    var $item = $('<li class="' + csscls('list-item') + '"></li>');
                    $item.append('<span class="' + csscls('openfga-type') + '">WRITE</span> ');
                    $item.append(write.writes + ' writes, ' + write.deletes + ' deletes');
                    $item.append(' <span class="' + csscls('duration') + '">(' + 
                                (write.duration * 1000).toFixed(2) + 'ms)</span>');
                    $list.append($item);
                });
            }

            // Expansions
            if (data.expansions && data.expansions.length > 0) {
                var $expansionsHeader = $('<li class="' + csscls('list-item') + ' ' + csscls('openfga-header') + '"><strong>Expansions:</strong></li>');
                $list.append($expansionsHeader);
                
                $.each(data.expansions, function (i, expansion) {
                    var $item = $('<li class="' + csscls('list-item') + '"></li>');
                    $item.append('<span class="' + csscls('openfga-type') + '">EXPAND</span> ');
                    $item.append(expansion.relation + ' → ' + expansion.object);
                    $item.append(' (' + expansion.users + ' users)');
                    $item.append(' <span class="' + csscls('duration') + '">(' + 
                                (expansion.duration * 1000).toFixed(2) + 'ms)</span>');
                    $list.append($item);
                });
            }

            // Other queries
            if (data.queries && data.queries.length > 0) {
                var $queriesHeader = $('<li class="' + csscls('list-item') + ' ' + csscls('openfga-header') + '"><strong>Other Operations:</strong></li>');
                $list.append($queriesHeader);
                
                $.each(data.queries, function (i, query) {
                    var $item = $('<li class="' + csscls('list-item') + '"></li>');
                    $item.append('<span class="' + csscls('openfga-type') + '">' + 
                                query.operation.toUpperCase() + '</span> ');
                    
                    if (query.params.error) {
                        $item.append('<span class="' + csscls('error') + '">Error: ' + 
                                    query.params.error + '</span>');
                    } else {
                        $item.append(JSON.stringify(query.params.arguments));
                    }
                    
                    $item.append(' <span class="' + csscls('duration') + '">(' + 
                                (query.duration * 1000).toFixed(2) + 'ms)</span>');
                    $list.append($item);
                });
            }

            this.$el.append($list);
        }
    });

})(PhpDebugBar.$);