<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

use function is_array;
use function is_string;

/**
 * Event fired when a relation is expanded.
 */
final class RelationExpanded
{
    use Dispatchable;

    use InteractsWithSockets;

    use SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param string               $object     The object identifier
     * @param string               $relation   The relation being expanded
     * @param array<string, mixed> $result     The expansion result
     * @param string|null          $connection The connection used
     * @param float                $duration   The duration of the operation in seconds
     * @param array<string, mixed> $context    Additional context
     */
    public function __construct(
        public readonly string $object,
        public readonly string $relation,
        public readonly array $result,
        public readonly ?string $connection = null,
        public readonly float $duration = 0.0,
        public readonly array $context = [],
    ) {
    }

    /**
     * Extract all users from the expansion result.
     *
     * @return array<string>
     */
    public function getUsers(): array
    {
        /** @var array<string> $users */
        $users = [];
        $this->extractUsers($this->result, $users);

        return array_unique($users);
    }

    /**
     * Recursively extract users from the tree structure.
     *
     * @param array<string, mixed> $node
     * @param array<string>        $users
     */
    private function extractUsers(array $node, array &$users): void
    {
        if (isset($node['tree']) && is_array($node['tree'])) {
            /** @var array<string, mixed> $tree */
            $tree = $node['tree'];
            $this->extractUsers($tree, $users);
        }

        if (isset($node['root']) && is_array($node['root'])) {
            /** @var array<string, mixed> $root */
            $root = $node['root'];
            $this->extractUsers($root, $users);
        }

        if (isset($node['leaf']) && is_array($node['leaf']) && isset($node['leaf']['users']) && is_array($node['leaf']['users'])) {
            foreach ($node['leaf']['users'] as $user) {
                if (is_string($user)) {
                    $users[] = $user;
                }
            }
        }

        if (isset($node['union']) && is_array($node['union']) && isset($node['union']['nodes']) && is_array($node['union']['nodes'])) {
            foreach ($node['union']['nodes'] as $unionNode) {
                if (is_array($unionNode)) {
                    /** @var array<string, mixed> $unionNode */
                    $this->extractUsers($unionNode, $users);
                }
            }
        }

        if (isset($node['intersection']) && is_array($node['intersection']) && isset($node['intersection']['nodes']) && is_array($node['intersection']['nodes'])) {
            foreach ($node['intersection']['nodes'] as $intersectionNode) {
                if (is_array($intersectionNode)) {
                    /** @var array<string, mixed> $intersectionNode */
                    $this->extractUsers($intersectionNode, $users);
                }
            }
        }

        if (isset($node['difference']) && is_array($node['difference']) && isset($node['difference']['base']) && is_array($node['difference']['base'])) {
            /** @var array<string, mixed> $base */
            $base = $node['difference']['base'];
            $this->extractUsers($base, $users);
        }
    }
}
