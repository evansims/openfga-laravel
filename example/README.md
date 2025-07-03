# OpenFGA Laravel Example Application

This example demonstrates a comprehensive Document Management System built with Laravel and OpenFGA. It showcases real-world authorization patterns and best practices using the OpenFGA Laravel package.

## Overview

The example application implements a document management system with the following features:

- **Multi-tenant Organization Structure** - Organizations, departments, teams
- **Document Management** - Create, edit, share, and organize documents
- **Granular Permissions** - Fine-grained access control using OpenFGA
- **Role-based Access** - Admin, Manager, Editor, Viewer roles
- **Team Collaboration** - Team-based document sharing and collaboration

## Authorization Model

The application uses a comprehensive OpenFGA authorization model that supports:

### Object Types
- `organization` - Top-level tenant
- `department` - Organizational units
- `team` - Work groups within departments
- `document` - Individual documents
- `folder` - Document containers
- `user` - System users

### Relations
- `owner` - Full control over an object
- `admin` - Administrative access
- `manager` - Management-level access
- `editor` - Can modify content
- `viewer` - Read-only access
- `member` - Basic membership

### Inheritance Patterns
- Organization admins inherit permissions to all departments
- Department managers inherit permissions to all teams
- Team members inherit basic document access
- Document editors can always view documents

## Quick Start

You have two options for setting up the example application:

### Option A: Docker Setup (Recommended)

The easiest way to get started is using Docker, which sets up the complete environment automatically:

```bash
# Run the Docker setup script
./docker-setup.sh
```

This will:
- Start all required services (Laravel, MySQL, OpenFGA, Redis, Mailhog)
- Create and configure the OpenFGA store and authorization model
- Run database migrations and seed example data
- Configure all environment variables automatically

After setup, access the services at:
- Laravel Application: http://localhost:8000
- OpenFGA Playground: http://localhost:3000
- Mailhog (Email testing): http://localhost:8025

### Option B: Manual Setup

If you prefer to set up manually or already have Laravel installed:

### 1. Install Dependencies

```bash
composer require evansms/openfga-laravel
```

### 2. Configure OpenFGA

```bash
# Copy environment configuration
cp .env.example .env

# Set OpenFGA connection details
OPENFGA_URL=http://localhost:8080
OPENFGA_STORE_ID=your-store-id
OPENFGA_MODEL_ID=your-model-id
```

### 3. Run Migrations

```bash
php artisan migrate
php artisan db:seed --class=ExampleSeeder
```

### 4. Start the Application

```bash
php artisan serve
```

## Key Implementation Examples

### Model Integration

```php
// Document model with OpenFGA integration
class Document extends Model
{
    use HasAuthorization;

    protected function authorizationRelations(): array
    {
        return ['owner', 'editor', 'viewer'];
    }
}
```

### Route Protection

```php
// Protect routes with OpenFGA middleware
Route::middleware(['auth', 'openfga:editor,document:{document}'])
    ->group(function () {
        Route::put('/documents/{document}', [DocumentController::class, 'update']);
    });
```

### Blade Templates

```blade
@can('edit', $document)
    <a href="{{ route('documents.edit', $document) }}" class="btn btn-primary">
        Edit Document
    </a>
@endcan

@canany(['admin', 'manager'], $organization)
    <div class="admin-panel">
        <!-- Admin content -->
    </div>
@endcanany
```

### Permission Queries

```php
// Find all documents a user can edit
$editableDocuments = Document::whereUserCan($user, 'editor')->get();

// Get team members who can view a document
$viewers = $document->getUsersWithRelation('viewer');

// Check multiple permissions efficiently
$permissions = OpenFga::batchCheck([
    [$user, 'view', $document],
    [$user, 'edit', $document],
    [$user, 'admin', $organization]
]);
```

## File Structure

```
example/
├── README.md                          # This file
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── DocumentController.php  # Document management
│   │   │   ├── OrganizationController.php # Organization admin
│   │   │   └── TeamController.php      # Team management
│   │   └── Requests/
│   │       ├── StoreDocumentRequest.php
│   │       └── UpdateDocumentRequest.php
│   ├── Models/
│   │   ├── User.php                   # User model with auth
│   │   ├── Organization.php           # Organization model
│   │   ├── Department.php             # Department model
│   │   ├── Team.php                   # Team model
│   │   ├── Document.php               # Document model
│   │   └── Folder.php                 # Folder model
│   └── Policies/
│       ├── DocumentPolicy.php         # Laravel policy integration
│       └── OrganizationPolicy.php     # Organization policies
├── database/
│   ├── migrations/                    # Database migrations
│   ├── seeders/
│   │   ├── ExampleSeeder.php          # Demo data seeder
│   │   └── PermissionSeeder.php       # Permission setup
│   └── factories/                     # Model factories
├── resources/views/
│   ├── layouts/
│   │   └── app.blade.php              # Main layout
│   ├── documents/
│   │   ├── index.blade.php            # Document listing
│   │   ├── show.blade.php             # Document view
│   │   └── edit.blade.php             # Document editor
│   └── organizations/
│       └── dashboard.blade.php        # Admin dashboard
├── routes/
│   └── web.php                        # Web routes with middleware
├── config/
│   └── openfga.php                    # OpenFGA configuration
└── tests/
    ├── Feature/
    │   ├── DocumentManagementTest.php  # End-to-end tests
    │   └── PermissionInheritanceTest.php # Permission tests
    └── Unit/
        └── AuthorizationTest.php       # Unit tests
```

## Testing

The example includes comprehensive tests demonstrating:

- Unit testing with FakeOpenFga
- Feature testing with real permissions
- Performance testing for authorization queries
- Integration testing with Laravel features

```bash
# Run all tests
php artisan test

# Run specific test suites
php artisan test --testsuite=Feature
php artisan test --filter=DocumentManagementTest
```

## Authorization Model DSL

The example includes the complete OpenFGA authorization model:

```
model
  schema 1.1

type user

type organization
  relations
    define admin: [user]
    define member: [user] or admin

type department
  relations
    define parent: [organization]
    define admin: admin from parent
    define manager: [user] or admin
    define member: [user] or manager

type team
  relations
    define parent: [department]
    define admin: admin from parent
    define manager: manager from parent
    define lead: [user] or manager
    define member: [user] or lead

type folder
  relations
    define parent: [organization, department, team]
    define admin: admin from parent
    define manager: manager from parent
    define editor: [user] or manager
    define viewer: [user] or editor

type document
  relations
    define parent: [folder, team, department, organization]
    define admin: admin from parent
    define owner: [user] or admin
    define editor: [user] or owner or manager from parent
    define viewer: [user] or editor
```

## Performance Considerations

The example demonstrates performance best practices:

- **Caching**: Automatic permission caching with cache warming
- **Batch Operations**: Efficient bulk permission checks and grants
- **Lazy Loading**: Deferred permission loading for better performance
- **Query Optimization**: Optimized database queries with permission filtering

## Security Features

- **Input Validation**: All requests validated before permission checks
- **CSRF Protection**: Laravel CSRF protection enabled
- **Rate Limiting**: API rate limiting with permission-aware limits
- **Audit Logging**: Complete audit trail of permission changes

## Deployment

The example includes deployment considerations:

- **Environment Configuration**: Production-ready OpenFGA settings
- **Database Optimization**: Indexed queries for permission checks
- **Caching Strategy**: Redis/Memcached configuration for permissions
- **Monitoring**: Performance monitoring and alerting

## Docker Management

If you're using the Docker setup, here are useful commands:

### Container Management
```bash
# View all containers and their status
docker compose ps

# View logs from all services
docker compose logs -f

# View logs from specific service
docker compose logs -f app
docker compose logs -f openfga

# Stop all containers
docker compose down

# Stop and remove all data (full reset)
docker compose down -v

# Restart a specific service
docker compose restart app
```

### Development Commands
```bash
# Run artisan commands
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed

# Access application shell
docker compose exec app sh

# Access MySQL
docker compose exec db mysql -u openfga -p

# Clear Laravel caches
docker compose exec app php artisan cache:clear
docker compose exec app php artisan config:clear
docker compose exec app php artisan view:clear
```

### OpenFGA Commands
```bash
# Check OpenFGA health
curl http://localhost:8080/healthz

# List stores
curl http://localhost:8080/stores

# Access OpenFGA Playground
open http://localhost:3000
```

## Next Steps

After exploring this example:

1. **Customize the Model**: Adapt the authorization model to your needs
2. **Extend Permissions**: Add application-specific relations and types
3. **Integrate APIs**: Connect with your existing authentication system
4. **Scale Performance**: Implement advanced caching and batching strategies

## Support

For questions about this example or the OpenFGA Laravel package:

- [Package Documentation](../docs/)
- [OpenFGA Documentation](https://openfga.dev/docs)
- [GitHub Issues](https://github.com/evansms/openfga-laravel/issues)
- [Community Slack](https://openfga.dev/community)
