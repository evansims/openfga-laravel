<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Testing;

use Closure;

use function sprintf;

/**
 * Trait for using mock scenarios in tests.
 */
trait UsesMockScenarios
{
    protected ?MockScenarios $scenarios = null;

    /**
     * Assert scenario expectations.
     *
     * @param string  $user
     * @param string  $relation
     * @param string  $object
     * @param bool    $expected
     * @param ?string $message
     */
    protected function assertScenarioPermission(
        string $user,
        string $relation,
        string $object,
        bool $expected = true,
        ?string $message = null,
    ): void {
        $result = $this->getFakeOpenFga()->check($user, $relation, $object);

        $message ??= sprintf(
            'Expected %s to %s %s on %s',
            $user,
            $expected ? 'have' : 'not have',
            $relation,
            $object,
        );

        $this->assertEquals($expected, $result, $message);
    }

    /**
     * Assert multiple scenario permissions.
     *
     * @param array $assertions
     */
    protected function assertScenarioPermissions(array $assertions): void
    {
        foreach ($assertions as $assertion) {
            $this->assertScenarioPermission(
                $assertion['user'],
                $assertion['relation'],
                $assertion['object'],
                $assertion['expected'] ?? true,
                $assertion['message'] ?? null,
            );
        }
    }

    /**
     * Create a custom scenario.
     *
     * @param Closure $setup
     */
    protected function customScenario(Closure $setup): MockScenarios
    {
        if (! $this->scenarios) {
            $fake = $this->getFakeOpenFga();
            $this->scenarios = new MockScenarios($fake);
        }

        return $this->scenarios->custom($setup);
    }

    /**
     * Add additional permissions to current scenario.
     *
     * @param string $user
     * @param string $relation
     * @param string $object
     */
    protected function grantInScenario(string $user, string $relation, string $object): void
    {
        $this->getFakeOpenFga()->grant($user, $relation, $object);
    }

    /**
     * Add multiple permissions to current scenario.
     *
     * @param array $permissions
     */
    protected function grantMultipleInScenario(array $permissions): void
    {
        foreach ($permissions as $permission) {
            $this->grantInScenario(
                $permission['user'],
                $permission['relation'],
                $permission['object'],
            );
        }
    }

    /**
     * Mock a specific check result in scenario.
     *
     * @param string $user
     * @param string $relation
     * @param string $object
     * @param bool   $result
     */
    protected function mockCheckInScenario(
        string $user,
        string $relation,
        string $object,
        bool $result,
    ): void {
        $this->getFakeOpenFga()->mockCheck($user, $relation, $object, $result);
    }

    /**
     * Set up a mock scenario.
     *
     * @param string $scenario
     */
    protected function scenario(string $scenario): MockScenarios
    {
        if (! $this->scenarios) {
            $fake = $this->getFakeOpenFga();
            $this->scenarios = new MockScenarios($fake);
        }

        return $this->scenarios->{$scenario}();
    }

    /**
     * Set up multiple scenarios.
     *
     * @param array $scenarios
     */
    protected function scenarios(array $scenarios): MockScenarios
    {
        if (! $this->scenarios) {
            $fake = $this->getFakeOpenFga();
            $this->scenarios = new MockScenarios($fake);
        }

        return $this->scenarios->combine($scenarios);
    }

    /**
     * Test with API access control setup.
     */
    protected function withApiAccessControlScenario(): void
    {
        $this->scenario('apiAccessControl');
    }

    /**
     * Common test scenarios as methods.
     */

    /**
     * Test with basic user-document permissions.
     */
    protected function withBasicUserDocumentScenario(): void
    {
        $this->scenario('basicUserDocument');
    }

    /**
     * Test with collaborative editing setup.
     */
    protected function withCollaborativeEditingScenario(): void
    {
        $this->scenario('collaborativeEditing');
    }

    /**
     * Test with content moderation setup.
     */
    protected function withContentModerationScenario(): void
    {
        $this->scenario('contentModeration');
    }

    /**
     * Test with file system setup.
     */
    protected function withFileSystemScenario(): void
    {
        $this->scenario('fileSystem');
    }

    /**
     * Test with multi-tenant setup.
     */
    protected function withMultiTenantScenario(): void
    {
        $this->scenario('multiTenant');
    }

    /**
     * Test with organization hierarchy.
     */
    protected function withOrganizationHierarchy(): void
    {
        $this->scenario('organizationHierarchy');
    }

    /**
     * Test with workflow approval setup.
     */
    protected function withWorkflowApprovalScenario(): void
    {
        $this->scenario('workflowApproval');
    }
}
