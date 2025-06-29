<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Support;

use Illuminate\Support\Facades\Config;

use function array_key_exists;

/**
 * Trait for saving and restoring configuration values in tests.
 */
trait ConfigRestoration
{
    /**
     * Storage for original config values.
     *
     * @var array<string, mixed>
     */
    protected array $originalConfigValues = [];

    /**
     * Restore all saved config values.
     */
    protected function restoreOriginalConfig(): void
    {
        foreach ($this->originalConfigValues as $key => $value) {
            Config::set($key, $value);
        }

        $this->originalConfigValues = [];
    }

    /**
     * Set a config value and store the original for restoration.
     *
     * @param string $key
     * @param mixed  $value
     */
    protected function setConfigWithRestore(string $key, mixed $value): void
    {
        if (! array_key_exists($key, $this->originalConfigValues)) {
            $this->originalConfigValues[$key] = Config::get($key);
        }

        Config::set($key, $value);
    }

    /**
     * Setup config restoration in beforeEach if needed.
     */
    protected function setUpConfigRestoration(): void
    {
        $this->originalConfigValues = [];
    }

    /**
     * Teardown config restoration in afterEach.
     */
    protected function tearDownConfigRestoration(): void
    {
        $this->restoreOriginalConfig();
    }
}
