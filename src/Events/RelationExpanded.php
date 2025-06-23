<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event fired when a relation is expanded.
 */
class RelationExpanded
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param string $object The object identifier
     * @param string $relation The relation being expanded
     * @param array<string, mixed> $result The expansion result
     * @param string|null $connection The connection used
     * @param float $duration The duration of the operation in seconds
     * @param array<string, mixed> $context Additional context
     */
    public function __construct(
        public readonly string $object,
        public readonly string $relation,
        public readonly array $result,
        public readonly ?string $connection = null,
        public readonly float $duration = 0.0,
        public readonly array $context = []
    ) {
    }

    /**
     * Extract all users from the expansion result.
     *
     * @return array<string>
     */
    public function getUsers(): array
    {
        $users = [];
        $this->extractUsers($this->result, $users);
        return array_unique($users);
    }

    /**
     * Recursively extract users from the tree structure.
     */
    private function extractUsers(array $node, array &$users): void
    {
        if (isset($node['tree'])) {
            $this->extractUsers($node['tree'], $users);
        }

        if (isset($node['root'])) {
            $this->extractUsers($node['root'], $users);
        }

        if (isset($node['leaf']['users'])) {
            foreach ($node['leaf']['users'] as $user) {
                $users[] = $user;
            }
        }

        if (isset($node['union']['nodes'])) {
            foreach ($node['union']['nodes'] as $unionNode) {
                $this->extractUsers($unionNode, $users);
            }
        }

        if (isset($node['intersection']['nodes'])) {
            foreach ($node['intersection']['nodes'] as $intersectionNode) {
                $this->extractUsers($intersectionNode, $users);
            }
        }

        if (isset($node['difference']['base'])) {
            $this->extractUsers($node['difference']['base'], $users);
        }
    }
}