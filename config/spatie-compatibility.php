<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Spatie Laravel Permission Compatibility
    |--------------------------------------------------------------------------
    |
    | This configuration file controls the Spatie Laravel Permission compatibility
    | layer. When enabled, you can use familiar Spatie syntax while leveraging
    | OpenFGA's relationship-based authorization under the hood.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Enable Compatibility Layer
    |--------------------------------------------------------------------------
    |
    | Set this to true to enable Spatie compatibility features including
    | Blade directives, middleware, and model methods.
    |
    */
    'enabled' => env('OPENFGA_SPATIE_COMPATIBILITY', false),

    /*
    |--------------------------------------------------------------------------
    | Permission Mappings
    |--------------------------------------------------------------------------
    |
    | Map Spatie permission names to OpenFGA relations. This allows you to
    | use your existing permission names while OpenFGA handles the
    | authorization logic.
    |
    */
    'permission_mappings' => [
        // Content permissions
        'edit posts' => 'editor',
        'view posts' => 'viewer',
        'delete posts' => 'owner',
        'create posts' => 'editor',
        
        'edit articles' => 'editor',
        'view articles' => 'viewer',
        'delete articles' => 'owner',
        'create articles' => 'editor',
        
        'edit pages' => 'editor',
        'view pages' => 'viewer',
        'delete pages' => 'owner',
        'create pages' => 'editor',

        // User management permissions
        'manage users' => 'admin',
        'edit users' => 'admin',
        'view users' => 'member',
        'delete users' => 'admin',
        'create users' => 'admin',

        // Admin permissions
        'view admin panel' => 'admin',
        'manage settings' => 'admin',
        'view analytics' => 'admin',
        'manage roles' => 'admin',
        'manage permissions' => 'admin',

        // Organization permissions
        'manage organization' => 'admin',
        'view organization' => 'member',
        'edit organization' => 'admin',

        // Department permissions
        'manage department' => 'manager',
        'view department' => 'member',
        'edit department' => 'manager',

        // Team permissions
        'manage team' => 'lead',
        'view team' => 'member',
        'edit team' => 'lead',

        // Document permissions
        'edit documents' => 'editor',
        'view documents' => 'viewer',
        'delete documents' => 'owner',
        'create documents' => 'editor',
        'share documents' => 'owner',

        // Comment permissions
        'create comments' => 'member',
        'edit comments' => 'editor',
        'delete comments' => 'moderator',
        'moderate comments' => 'moderator',
    ],

    /*
    |--------------------------------------------------------------------------
    | Role Mappings
    |--------------------------------------------------------------------------
    |
    | Map Spatie role names to OpenFGA relations. This allows you to use
    | your existing role names with OpenFGA's relationship system.
    |
    */
    'role_mappings' => [
        'super-admin' => 'admin',
        'admin' => 'admin',
        'administrator' => 'admin',
        'manager' => 'manager',
        'supervisor' => 'manager',
        'team-lead' => 'lead',
        'lead' => 'lead',
        'editor' => 'editor',
        'writer' => 'editor',
        'contributor' => 'editor',
        'moderator' => 'moderator',
        'reviewer' => 'moderator',
        'member' => 'member',
        'user' => 'member',
        'viewer' => 'viewer',
        'guest' => 'viewer',
        'subscriber' => 'viewer',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Context
    |--------------------------------------------------------------------------
    |
    | When no specific context is provided for role/permission checks,
    | this default context will be used. This is typically an organization
    | or application-level context.
    |
    */
    'default_context' => 'organization:main',

    /*
    |--------------------------------------------------------------------------
    | Permission Inference Rules
    |--------------------------------------------------------------------------
    |
    | When a permission is not explicitly mapped, these rules help infer
    | the appropriate OpenFGA relation based on the permission name.
    |
    */
    'inference_rules' => [
        // Actions that typically require owner permissions
        'owner_actions' => ['delete', 'destroy', 'remove', 'archive'],
        
        // Actions that typically require editor permissions
        'editor_actions' => ['edit', 'update', 'modify', 'create', 'store', 'publish'],
        
        // Actions that typically require viewer permissions
        'viewer_actions' => ['view', 'read', 'list', 'index', 'show'],
        
        // Actions that typically require admin permissions
        'admin_actions' => ['manage', 'admin', 'control', 'configure'],
        
        // Actions that typically require moderator permissions
        'moderator_actions' => ['moderate', 'approve', 'reject', 'review'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resource Type Mappings
    |--------------------------------------------------------------------------
    |
    | Map resource types mentioned in permissions to OpenFGA object types.
    | This helps with automatic object resolution.
    |
    */
    'resource_mappings' => [
        'post' => 'post',
        'posts' => 'post',
        'article' => 'article',
        'articles' => 'article',
        'page' => 'page',
        'pages' => 'page',
        'user' => 'user',
        'users' => 'user',
        'document' => 'document',
        'documents' => 'document',
        'comment' => 'comment',
        'comments' => 'comment',
        'team' => 'team',
        'teams' => 'team',
        'department' => 'department',
        'departments' => 'department',
        'organization' => 'organization',
        'organizations' => 'organization',
    ],

    /*
    |--------------------------------------------------------------------------
    | Blade Directives
    |--------------------------------------------------------------------------
    |
    | Configure which Spatie-compatible Blade directives should be registered.
    | You can disable specific directives if they conflict with existing ones.
    |
    */
    'blade_directives' => [
        'hasrole' => true,
        'hasanyrole' => true,
        'hasallroles' => true,
        'unlessrole' => true,
        'haspermission' => true,
        'hasanypermission' => true,
        'hasallpermissions' => true,
        'unlesspermission' => true,
        'role' => true,
        'permission' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Middleware Aliases
    |--------------------------------------------------------------------------
    |
    | Configure middleware aliases for Spatie compatibility. Set to true to
    | register the middleware with these aliases, or set to false to skip.
    |
    */
    'middleware_aliases' => [
        'role' => true,
        'permission' => true,
        'role_or_permission' => false, // Not implemented yet
    ],

    /*
    |--------------------------------------------------------------------------
    | Automatic User Trait
    |--------------------------------------------------------------------------
    |
    | When enabled, the SpatieCompatible trait will be automatically added
    | to the User model if it doesn't already have Spatie traits.
    |
    */
    'auto_add_trait' => env('OPENFGA_AUTO_ADD_SPATIE_TRAIT', false),

    /*
    |--------------------------------------------------------------------------
    | Migration Support
    |--------------------------------------------------------------------------
    |
    | Configuration for migrating from Spatie Laravel Permission to OpenFGA.
    |
    */
    'migration' => [
        // Whether to preserve original Spatie tables during migration
        'preserve_spatie_tables' => true,
        
        // Batch size for migration operations
        'batch_size' => 100,
        
        // Whether to verify migration results
        'verify_migration' => true,
        
        // Tables to migrate from (if using custom table names)
        'tables' => [
            'roles' => 'roles',
            'permissions' => 'permissions',
            'model_has_permissions' => 'model_has_permissions',
            'model_has_roles' => 'model_has_roles',
            'role_has_permissions' => 'role_has_permissions',
        ],
    ],
];