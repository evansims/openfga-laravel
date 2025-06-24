<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Database;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\{BindingResolutionException, Container};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use OpenFGA\Exceptions\ClientThrowable;
use OpenFGA\Laravel\OpenFgaManager;
use OpenFGA\Models\Collections\TupleKeys;
use OpenFGA\Models\TupleKey;

use function count;
use function is_string;
use function sprintf;

/**
 * Base seeder class for seeding OpenFGA permissions.
 *
 * @property Command|null   $command
 * @property Container|null $container
 */
abstract class PermissionSeeder extends Seeder
{
    /**
     * The OpenFGA manager instance.
     */
    protected OpenFgaManager $manager;

    /**
     * Constructor.
     *
     * @param ?OpenFgaManager $manager
     */
    public function __construct(?OpenFgaManager $manager = null)
    {
        /** @var OpenFgaManager $resolvedManager */
        $resolvedManager = $manager ?? App::make(OpenFgaManager::class);
        $this->manager = $resolvedManager;
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->seedPermissions();
    }

    /**
     * Define and seed the permissions.
     * Override this method in your seeder.
     */
    abstract protected function seedPermissions(): void;

    /**
     * Clear all permissions for an object.
     *
     * @param string $object The object identifier
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     */
    protected function clearPermissionsFor(string $object): void
    {
        // This is a simplified implementation
        // In a real scenario, you'd need to query existing permissions first
        if (null !== $this->command) {
            $this->command->warn('Note: clearPermissionsFor requires querying existing permissions. Implement based on your needs.');
        }
    }

    /**
     * Grant a permission.
     *
     * @param string $user     The user identifier
     * @param string $relation The relation/permission
     * @param string $object   The object identifier
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     */
    protected function grant(string $user, string $relation, string $object): void
    {
        $this->manager->grant($user, $relation, $object);

        if (null !== $this->command) {
            $this->command->info(sprintf('Granted %s on %s to %s', $relation, $object, $user));
        }
    }

    /**
     * Create default admin permissions for a resource.
     *
     * @param string $adminUser The admin user identifier
     * @param string $object    The object identifier
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     */
    protected function grantAdminPermissions(string $adminUser, string $object): void
    {
        $this->grantRelations($adminUser, ['owner', 'editor', 'viewer'], $object);
    }

    /**
     * Grant permissions based on a model collection.
     *
     * @param iterable<Model> $models   The models to process
     * @param string          $user     The user identifier
     * @param string          $relation The relation/permission
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     */
    protected function grantForModels(iterable $models, string $user, string $relation): void
    {
        $permissions = [];

        foreach ($models as $model) {
            if (method_exists($model, 'authorizationObject')) {
                /** @var mixed $authObject */
                $authObject = $model->authorizationObject();

                if (is_string($authObject)) {
                    $permissions[] = [
                        'user' => $user,
                        'relation' => $relation,
                        'object' => $authObject,
                    ];
                }
            }
        }

        if ([] !== $permissions) {
            $this->grantMany($permissions);
        }
    }

    /**
     * Grant permissions in batch.
     *
     * @param array<int, array{user: string, relation: string, object: string}> $permissions
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     */
    protected function grantMany(array $permissions): void
    {
        if ([] === $permissions) {
            return;
        }

        $tuples = new TupleKeys;

        foreach ($permissions as $permission) {
            $tuples->add(new TupleKey(
                $permission['user'],
                $permission['relation'],
                $permission['object'],
            ));
        }

        $this->manager->write($tuples);

        if (null !== $this->command) {
            $this->command->info(sprintf('Granted %d permissions', count($permissions)));
        }
    }

    /**
     * Grant multiple relations to a user on an object.
     *
     * @param string        $user      The user identifier
     * @param array<string> $relations The relations/permissions
     * @param string        $object    The object identifier
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     */
    protected function grantRelations(string $user, array $relations, string $object): void
    {
        $permissions = [];

        foreach ($relations as $relation) {
            $permissions[] = [
                'user' => $user,
                'relation' => $relation,
                'object' => $object,
            ];
        }

        $this->grantMany($permissions);
    }

    /**
     * Grant permissions for multiple users on the same object.
     *
     * @param array<string> $users    The user identifiers
     * @param string        $relation The relation/permission
     * @param string        $object   The object identifier
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     */
    protected function grantToMany(array $users, string $relation, string $object): void
    {
        $permissions = [];

        foreach ($users as $user) {
            $permissions[] = [
                'user' => $user,
                'relation' => $relation,
                'object' => $object,
            ];
        }

        $this->grantMany($permissions);
    }
}
