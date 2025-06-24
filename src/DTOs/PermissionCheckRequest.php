<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\DTOs;

use Illuminate\Contracts\Auth\Authenticatable;

use function is_string;
use function sprintf;

/**
 * Data Transfer Object for permission check requests.
 *
 * Replaces associative arrays to provide better type safety and structure.
 */
final readonly class PermissionCheckRequest
{
    /**
     * Create a new permission check request.
     *
     * @param string                $userId           User identifier for the check
     * @param string                $relation         Permission relation to check
     * @param string                $object           Object identifier to check permission on
     * @param array<string, mixed>  $context          Additional context for the check
     * @param array<string, string> $contextualTuples Contextual tuples to include
     * @param string|null           $connection       OpenFGA connection to use
     * @param bool                  $cached           Whether to use cached results
     * @param float|null            $duration         Duration of the check in seconds
     */
    public function __construct(
        public string $userId,
        public string $relation,
        public string $object,
        public array $context = [],
        public array $contextualTuples = [],
        public ?string $connection = null,
        public bool $cached = false,
        public ?float $duration = null,
    ) {
    }

    /**
     * Create from authenticatable user and arguments.
     *
     * @param Authenticatable       $user
     * @param string                $relation
     * @param string                $object
     * @param array<string, mixed>  $context
     * @param array<string, string> $contextualTuples
     * @param string|null           $connection
     */
    public static function fromUser(
        Authenticatable $user,
        string $relation,
        string $object,
        array $context = [],
        array $contextualTuples = [],
        ?string $connection = null,
    ): self {
        $userId = self::resolveUserId($user);

        return new self(
            userId: $userId,
            relation: $relation,
            object: $object,
            context: $context,
            contextualTuples: $contextualTuples,
            connection: $connection,
        );
    }

    /**
     * Convert to array for logging.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'user' => $this->userId,
            'relation' => $this->relation,
            'object' => $this->object,
            'context' => $this->context,
            'contextual_tuples' => $this->contextualTuples,
            'connection' => $this->connection,
            'cached' => $this->cached,
            'duration' => $this->duration,
        ];
    }

    /**
     * Get a string representation of the permission check.
     */
    public function toString(): string
    {
        return sprintf(
            '%s can %s on %s',
            $this->userId,
            $this->relation,
            $this->object,
        );
    }

    /**
     * Create with cached result.
     *
     * @param bool   $cached
     * @param ?float $duration
     */
    public function withCached(bool $cached, ?float $duration = null): self
    {
        return new self(
            userId: $this->userId,
            relation: $this->relation,
            object: $this->object,
            context: $this->context,
            contextualTuples: $this->contextualTuples,
            connection: $this->connection,
            cached: $cached,
            duration: $duration,
        );
    }

    /**
     * Resolve user ID from authenticatable user.
     *
     * @param Authenticatable $user
     */
    private static function resolveUserId(Authenticatable $user): string
    {
        // Check for authorization methods first
        if (method_exists($user, 'authorizationUser')) {
            $result = $user->authorizationUser();

            if (is_string($result)) {
                return $result;
            }
        }

        if (method_exists($user, 'getAuthorizationUserId')) {
            $result = $user->getAuthorizationUserId();

            if (is_string($result)) {
                return $result;
            }
        }

        // Fall back to auth identifier
        $identifier = $user->getAuthIdentifier();

        if (null === $identifier || (! is_string($identifier) && ! is_numeric($identifier))) {
            return 'user:unknown';
        }

        return 'user:' . (string) $identifier;
    }
}
