<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\View;

use Illuminate\Support\Facades\Auth;
use OpenFGA\Laravel\OpenFgaManager;

/**
 * Helper class for generating JavaScript variables and functions for OpenFGA.
 */
class JavaScriptHelper
{
    /**
     * Create a new JavaScript helper instance.
     */
    public function __construct(
        protected OpenFgaManager $manager
    ) {}

    /**
     * Generate JavaScript variables with user permissions.
     *
     * @param array<string> $objects List of objects to check permissions for
     * @param array<string> $relations List of relations to check
     * @param string|null   $connection OpenFGA connection to use
     *
     * @return string
     */
    public function generatePermissionsScript(array $objects, array $relations, ?string $connection = null): string
    {
        if (!Auth::check()) {
            return 'window.OpenFGA = { permissions: {}, user: null };';
        }

        $user = Auth::user();
        $userId = $this->resolveUserId($user);
        $permissions = [];

        foreach ($objects as $object) {
            $permissions[$object] = [];
            foreach ($relations as $relation) {
                $permissions[$object][$relation] = $this->manager
                    ->connection($connection)
                    ->check($userId, $relation, $object);
            }
        }

        $userData = [
            'id' => $userId,
            'auth_id' => $user->getAuthIdentifier(),
        ];

        $script = 'window.OpenFGA = ' . json_encode([
            'permissions' => $permissions,
            'user' => $userData,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . ';';

        return $script;
    }

    /**
     * Generate JavaScript helper functions.
     *
     * @return string
     */
    public function generateHelperFunctions(): string
    {
        return <<<'JS'
window.OpenFGA = window.OpenFGA || {};

/**
 * Check if current user has permission
 */
window.OpenFGA.can = function(relation, object) {
    if (!window.OpenFGA.permissions || !window.OpenFGA.permissions[object]) {
        return false;
    }
    return window.OpenFGA.permissions[object][relation] === true;
};

/**
 * Check if current user does NOT have permission
 */
window.OpenFGA.cannot = function(relation, object) {
    return !window.OpenFGA.can(relation, object);
};

/**
 * Check if current user has any of the given permissions
 */
window.OpenFGA.canAny = function(relations, object) {
    for (let i = 0; i < relations.length; i++) {
        if (window.OpenFGA.can(relations[i], object)) {
            return true;
        }
    }
    return false;
};

/**
 * Check if current user has all of the given permissions
 */
window.OpenFGA.canAll = function(relations, object) {
    for (let i = 0; i < relations.length; i++) {
        if (!window.OpenFGA.can(relations[i], object)) {
            return false;
        }
    }
    return true;
};

/**
 * Get current user information
 */
window.OpenFGA.getUser = function() {
    return window.OpenFGA.user;
};

/**
 * Check if user is authenticated
 */
window.OpenFGA.isAuthenticated = function() {
    return window.OpenFGA.user !== null;
};

/**
 * Toggle element visibility based on permission
 */
window.OpenFGA.toggleByPermission = function(element, relation, object, showIfTrue = true) {
    const hasPermission = window.OpenFGA.can(relation, object);
    const shouldShow = showIfTrue ? hasPermission : !hasPermission;
    
    if (typeof element === 'string') {
        element = document.querySelector(element);
    }
    
    if (element) {
        element.style.display = shouldShow ? '' : 'none';
    }
};

/**
 * Enable/disable element based on permission
 */
window.OpenFGA.toggleEnabledByPermission = function(element, relation, object, enableIfTrue = true) {
    const hasPermission = window.OpenFGA.can(relation, object);
    const shouldEnable = enableIfTrue ? hasPermission : !hasPermission;
    
    if (typeof element === 'string') {
        element = document.querySelector(element);
    }
    
    if (element) {
        element.disabled = !shouldEnable;
    }
};
JS;
    }

    /**
     * Generate a complete JavaScript setup.
     *
     * @param array<string> $objects List of objects to check permissions for
     * @param array<string> $relations List of relations to check
     * @param string|null   $connection OpenFGA connection to use
     *
     * @return string
     */
    public function generate(array $objects = [], array $relations = [], ?string $connection = null): string
    {
        $script = $this->generateHelperFunctions() . "\n\n";
        
        if (!empty($objects) && !empty($relations)) {
            $script .= $this->generatePermissionsScript($objects, $relations, $connection);
        }

        return $script;
    }

    /**
     * Generate a blade directive for including the JavaScript.
     *
     * @param array<string> $objects List of objects to check permissions for
     * @param array<string> $relations List of relations to check
     * @param string|null   $connection OpenFGA connection to use
     *
     * @return string
     */
    public function bladeDirective(array $objects = [], array $relations = [], ?string $connection = null): string
    {
        $script = $this->generate($objects, $relations, $connection);
        
        return "<script>\n{$script}\n</script>";
    }

    /**
     * Resolve the user ID for OpenFGA.
     *
     * @param mixed $user
     *
     * @return string
     */
    protected function resolveUserId($user): string
    {
        if (method_exists($user, 'authorizationUser')) {
            return $user->authorizationUser();
        }

        if (method_exists($user, 'getAuthorizationUserId')) {
            return $user->getAuthorizationUserId();
        }

        return 'user:' . $user->getAuthIdentifier();
    }
}