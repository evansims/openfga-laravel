<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Database;

use Closure;
use Exception;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\App;
use InvalidArgumentException;
use OpenFGA\ClientInterface;
use OpenFGA\Exceptions\ClientThrowable;
use OpenFGA\Laravel\Exceptions\ConnectionException;
use OpenFGA\Laravel\OpenFgaManager;
use OpenFGA\Models\Collections\TupleKeys;
use OpenFGA\Models\TupleKey;

use function count;
use function sprintf;

/**
 * Base class for migrations that manage OpenFGA authorization tuples.
 *
 * This abstract class extends Laravel's Migration to handle permission grants
 * and revocations as part of your database migrations. It provides a structured
 * way to version control your authorization model changes alongside schema changes,
 * ensuring permissions stay in sync with your application's evolution. Includes
 * automatic rollback support to maintain consistency.
 */
abstract class PermissionMigration extends Migration
{
    /**
     * The OpenFGA manager instance.
     */
    protected OpenFgaManager $manager;

    /**
     * Permissions to be granted during migration.
     *
     * @var array<int, array{user: string, relation: string, object: string}>
     */
    protected array $permissions = [];

    /**
     * Permissions to be revoked during rollback.
     *
     * @var array<int, array{user: string, relation: string, object: string}>
     */
    protected array $rollbackPermissions = [];

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
     * Reverse the migrations.
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     */
    public function down(): void
    {
        $this->defineRollbackPermissions();
        $this->applyRollback();
    }

    /**
     * Run the migrations.
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     */
    public function up(): void
    {
        $this->definePermissions();
        $this->applyPermissions();
    }

    /**
     * Define the permissions to be granted.
     * Override this method in your migration.
     */
    abstract protected function definePermissions(): void;

    /**
     * Apply the defined permissions.
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     */
    protected function applyPermissions(): void
    {
        if ([] === $this->permissions) {
            return;
        }

        $tuples = new TupleKeys;

        foreach ($this->permissions as $permission) {
            $tuples->add(new TupleKey(
                $permission['user'],
                $permission['relation'],
                $permission['object'],
            ));
        }

        $this->manager->write($tuples);

        $this->info(sprintf('Granted %d permissions', count($this->permissions)));
    }

    /**
     * Apply the rollback permissions.
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     */
    protected function applyRollback(): void
    {
        if ([] === $this->rollbackPermissions) {
            return;
        }

        $tuples = new TupleKeys;

        foreach ($this->rollbackPermissions as $rollbackPermission) {
            $tuples->add(new TupleKey(
                $rollbackPermission['user'],
                $rollbackPermission['relation'],
                $rollbackPermission['object'],
            ));
        }

        $this->manager->write(null, $tuples);

        $this->info(sprintf('Revoked %d permissions', count($this->rollbackPermissions)));
    }

    /**
     * Define the permissions to be revoked on rollback.
     * By default, this will revoke all permissions granted in definePermissions.
     */
    protected function defineRollbackPermissions(): void
    {
        $this->rollbackPermissions = $this->permissions;
    }

    /**
     * Add a permission to be granted.
     *
     * @param string $user     The user identifier
     * @param string $relation The relation/permission
     * @param string $object   The object identifier
     */
    protected function grant(string $user, string $relation, string $object): void
    {
        $this->permissions[] = [
            'user' => $user,
            'relation' => $relation,
            'object' => $object,
        ];
    }

    /**
     * Add multiple permissions to be granted.
     *
     * @param array<int, array{user: string, relation: string, object: string}> $permissions
     */
    protected function grantMany(array $permissions): void
    {
        foreach ($permissions as $permission) {
            $this->grant(
                $permission['user'],
                $permission['relation'],
                $permission['object'],
            );
        }
    }

    /**
     * Grant permissions for multiple users on the same object.
     *
     * @param array<string> $users    The user identifiers
     * @param string        $relation The relation/permission
     * @param string        $object   The object identifier
     */
    protected function grantToMany(array $users, string $relation, string $object): void
    {
        foreach ($users as $user) {
            $this->grant($user, $relation, $object);
        }
    }

    /**
     * Output information to the console if available.
     *
     * @param string $message
     */
    protected function info(string $message): void
    {
        if (App::runningInConsole()) {
            echo $message . PHP_EOL;
        }
    }

    /**
     * Add a permission to be revoked on rollback.
     *
     * @param string $user     The user identifier
     * @param string $relation The relation/permission
     * @param string $object   The object identifier
     */
    protected function revokeOnRollback(string $user, string $relation, string $object): void
    {
        $this->rollbackPermissions[] = [
            'user' => $user,
            'relation' => $relation,
            'object' => $object,
        ];
    }

    /**
     * Execute a callback with a specific connection.
     *
     * @param string                         $connection The connection name
     * @param Closure(ClientInterface): void $callback   The callback to execute
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     */
    protected function usingConnection(string $connection, Closure $callback): void
    {
        $callback($this->manager->connection($connection));
    }
}
