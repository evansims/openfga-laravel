# Cookbook & Recipes

Welcome to the OpenFGA Laravel Cookbook! This section provides practical recipes and real-world examples to help you implement common authorization patterns with OpenFGA Laravel.

## Available Recipes

### 📋 [Implementing RBAC (Role-Based Access Control)](implementing-rbac.md)

Learn how to implement traditional role-based access control where users are assigned roles and roles have permissions. This recipe covers:

- Setting up role hierarchies
- Managing user-role assignments
- Role-based route protection
- Conditional and temporary roles
- Testing RBAC implementations

**Use this when:** You need a traditional role-based system with clear role definitions and hierarchies.

### 🏢 [Handling Organization/Team Permissions](organization-team-permissions.md)

Implement complex organizational structures with teams, departments, and nested permissions. This recipe demonstrates:

- Multi-level organizational hierarchies
- Team membership with inheritance
- Cross-organization collaboration
- Department-based access control
- Contextual access controls

**Use this when:** You're building multi-tenant applications with complex organizational structures.

## Coming Soon

We're working on additional recipes to cover more authorization patterns:

- **Resource-Based Permissions**: Document ownership with sharing capabilities
- **Attribute-Based Access Control (ABAC)**: Context-aware permissions based on attributes
- **Time-Based Access Control**: Temporary permissions and scheduled access
- **Geographic Access Control**: Location-based permission restrictions
- **API Security Patterns**: Securing REST APIs and GraphQL endpoints
- **Multi-Application SSO**: Sharing permissions across multiple applications

## How to Use These Recipes

Each recipe follows this structure:

1. **Authorization Model**: The OpenFGA model definition
2. **Core Implementation**: PHP classes and services
3. **Eloquent Integration**: Laravel model integration
4. **API Endpoints**: REST API examples
5. **Testing**: Comprehensive test examples
6. **Best Practices**: Performance and security considerations

## Contributing Recipes

Have a common authorization pattern you'd like to share? We welcome contributions! Please:

1. Follow the existing recipe structure
2. Include complete, working examples
3. Add comprehensive tests
4. Document any trade-offs or limitations
5. Submit a pull request

## Getting Help

- Check the main [documentation](../README.md) for basic concepts
- Review the [API Reference](../api-reference.md) for detailed method documentation
- See the [Troubleshooting Guide](../troubleshooting.md) for common issues
- Visit our [GitHub repository](https://github.com/evansims/openfga-laravel) for support

---

**Tip**: Start with the RBAC recipe if you're new to OpenFGA Laravel - it covers the fundamental concepts that apply to all other patterns.