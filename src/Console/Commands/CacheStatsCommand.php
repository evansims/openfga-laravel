<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use OpenFGA\Laravel\OpenFgaManager;
use RuntimeException;

use function sprintf;

final class CacheStatsCommand extends Command
{
    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Display OpenFGA cache statistics';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'openfga:cache:stats
                            {--reset : Reset cache statistics}
                            {--json : Output as JSON}';

    /**
     * Execute the console command.
     *
     * @param OpenFgaManager $manager
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function handle(OpenFgaManager $manager): int
    {
        $readThroughCache = $manager->getReadThroughCache();

        if (true === $this->option('reset')) {
            $readThroughCache->resetStats();
            $this->info('Cache statistics have been reset.');

            return 0;
        }

        $stats = $readThroughCache->getStats();

        if (true === $this->option('json')) {
            $jsonOutput = json_encode($stats, JSON_PRETTY_PRINT);

            if (false !== $jsonOutput) {
                $this->output->writeln($jsonOutput);
            }

            return 0;
        }

        $this->info('OpenFGA Cache Statistics');
        $this->info('------------------------');
        $this->line('Cache Hits: ' . $stats['hits']);
        $this->line('Cache Misses: ' . $stats['misses']);
        $this->line(sprintf('Hit Rate: %s%%', $stats['hit_rate']));

        if ($stats['hits'] + $stats['misses'] === 0) {
            $this->comment('No cache activity recorded yet.');
        }

        return 0;
    }
}
