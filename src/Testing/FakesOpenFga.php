<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Testing;

use OpenFGA\Laravel\Contracts\ManagerInterface;
use OpenFGA\Laravel\OpenFgaManager;
use OpenFGA\Models\Collections\TupleKeysInterface;

/**
 * Trait for faking OpenFGA in tests.
 *
 * @api
 */
trait FakesOpenFga // @phpstan-ignore trait.unused
{
    /**
     * The fake OpenFGA instance.
     */
    protected ?FakeOpenFga $fakeOpenFga = null;

    /**
     * Assert that no permission checks were performed.
     *
     * @param ?string $message
     */
    protected function assertNoPermissionChecks(?string $message = null): void
    {
        $this->assertPermissionCheckCount(0, $message);
    }

    /**
     * Assert the number of permission checks performed.
     *
     * @param int     $count
     * @param ?string $message
     */
    protected function assertPermissionCheckCount(int $count, ?string $message = null): void
    {
        if (! $this->fakeOpenFga) {
            $this->fail('OpenFGA fake is not active. Call fakeOpenFga() first.');
        }

        $this->fakeOpenFga->assertCheckCount($count, $message);
    }

    /**
     * Assert that a permission check was performed.
     *
     * @param string  $user
     * @param string  $relation
     * @param string  $object
     * @param ?string $message
     */
    protected function assertPermissionChecked(string $user, string $relation, string $object, ?string $message = null): void
    {
        if (! $this->fakeOpenFga) {
            $this->fail('OpenFGA fake is not active. Call fakeOpenFga() first.');
        }

        $this->fakeOpenFga->assertChecked($user, $relation, $object, $message);
    }

    /**
     * Assert that a permission was granted.
     *
     * @param string  $user
     * @param string  $relation
     * @param string  $object
     * @param ?string $message
     */
    protected function assertPermissionGranted(string $user, string $relation, string $object, ?string $message = null): void
    {
        if (! $this->fakeOpenFga) {
            $this->fail('OpenFGA fake is not active. Call fakeOpenFga() first.');
        }

        $this->fakeOpenFga->assertGranted($user, $relation, $object, $message);
    }

    /**
     * Assert that a permission check was not performed.
     *
     * @param string  $user
     * @param string  $relation
     * @param string  $object
     * @param ?string $message
     */
    protected function assertPermissionNotChecked(string $user, string $relation, string $object, ?string $message = null): void
    {
        if (! $this->fakeOpenFga) {
            $this->fail('OpenFGA fake is not active. Call fakeOpenFga() first.');
        }

        $this->fakeOpenFga->assertNotChecked($user, $relation, $object, $message);
    }

    /**
     * Assert that a permission was not granted.
     *
     * @param string  $user
     * @param string  $relation
     * @param string  $object
     * @param ?string $message
     */
    protected function assertPermissionNotGranted(string $user, string $relation, string $object, ?string $message = null): void
    {
        if (! $this->fakeOpenFga) {
            $this->fail('OpenFGA fake is not active. Call fakeOpenFga() first.');
        }

        $this->fakeOpenFga->assertNotGranted($user, $relation, $object, $message);
    }

    /**
     * Replace the OpenFGA manager with a fake implementation.
     */
    protected function fakeOpenFga(): FakeOpenFga
    {
        $this->fakeOpenFga = new FakeOpenFga;

        // Create a wrapper manager that delegates to our fake
        $fakeManager = new readonly class($this->fakeOpenFga) implements ManagerInterface {
            public function __construct(private FakeOpenFga $fake)
            {
            }

            public function check(string $user, string $relation, string $object, array $contextualTuples = [], array $context = [], ?string $connection = null): bool
            {
                return $this->fake->check($user, $relation, $object);
            }

            public function grant(string|array $user, string $relation, string $object, ?string $connection = null): bool
            {
                return $this->fake->grant($user, $relation, $object);
            }

            public function revoke(string|array $user, string $relation, string $object, ?string $connection = null): bool
            {
                return $this->fake->revoke($user, $relation, $object);
            }

            public function listObjects(string $user, string $relation, string $type, array $contextualTuples = [], array $context = [], ?string $connection = null): array
            {
                return $this->fake->listObjects($user, $relation, $type);
            }

            public function expand(string $object, string $relation, ?string $connection = null): array
            {
                return $this->fake->expand($object, $relation);
            }

            public function batchCheck(array $checks, ?string $connection = null): array
            {
                return $this->fake->batchCheck($checks);
            }

            public function listUsers(string $object, string $relation, array $userFilters = [], array $contextualTuples = [], array $context = [], ?string $connection = null): array
            {
                return $this->fake->listUsers($object, $relation);
            }

            public function writeBatch(array $writes = [], array $deletes = [], ?string $connection = null): void
            {
                $this->fake->writeBatch($writes, $deletes);
            }

            public function connection(?string $name = null): self
            {
                return $this;
            }

            public function query(?string $connection = null): object
            {
                return new class($this->fake) {
                    public function __construct(private readonly FakeOpenFga $fake)
                    {
                    }

                    private ?string $user = null;

                    private ?string $relation = null;

                    private ?string $object = null;

                    public function for(string $user): self
                    {
                        $this->user = $user;

                        return $this;
                    }

                    public function can(string $relation): self
                    {
                        $this->relation = $relation;

                        return $this;
                    }

                    public function on(string $object): self
                    {
                        $this->object = $object;

                        return $this;
                    }

                    public function check(): bool
                    {
                        return $this->fake->check($this->user, $this->relation, $this->object);
                    }
                };
            }

            public function listRelations(string $user, string $object, array $relations = [], array $contextualTuples = [], array $context = [], ?string $connection = null,): array
            {
                // TODO: Implement listRelations() method.
            }

            public function write(?TupleKeysInterface $writes = null, ?TupleKeysInterface $deletes = null, ?string $connection = null,): bool
            {
                // TODO: Implement write() method.
            }
        };

        // Replace the manager in the container
        $this->app->instance(OpenFgaManager::class, $fakeManager);
        $this->app->instance('openfga.manager', $fakeManager);

        return $this->fakeOpenFga;
    }

    /**
     * Get the fake OpenFGA instance.
     */
    protected function getFakeOpenFga(): ?FakeOpenFga
    {
        return $this->fakeOpenFga;
    }
}
