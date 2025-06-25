<?php

use App\Http\Controllers\DocumentController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\FolderController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes - OpenFGA Laravel Example
|--------------------------------------------------------------------------
|
| These routes demonstrate real-world usage of OpenFGA middleware and
| authorization patterns in a Laravel application.
|
*/

Route::get('/', function () {
    return view('welcome');
});

// Authentication routes
Auth::routes();

// Protected routes requiring authentication
Route::middleware(['auth'])->group(function () {
    
    // Dashboard - shows user's accessible content
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');

    // Document routes with granular permissions
    Route::prefix('documents')->name('documents.')->group(function () {
        
        // List documents - no additional permission needed (filtered by access)
        Route::get('/', [DocumentController::class, 'index'])->name('index');
        
        // Create document - check if user can create in specified location
        Route::get('/create', [DocumentController::class, 'create'])->name('create');
        Route::post('/', [DocumentController::class, 'store'])->name('store');
        
        // Document-specific routes with permission middleware
        Route::middleware(['openfga:viewer,document:{document}'])->group(function () {
            Route::get('/{document}', [DocumentController::class, 'show'])->name('show');
            Route::post('/{document}/duplicate', [DocumentController::class, 'duplicate'])->name('duplicate');
        });
        
        Route::middleware(['openfga:editor,document:{document}'])->group(function () {
            Route::get('/{document}/edit', [DocumentController::class, 'edit'])->name('edit');
            Route::put('/{document}', [DocumentController::class, 'update'])->name('update');
            Route::post('/{document}/publish', [DocumentController::class, 'publish'])->name('publish');
        });
        
        Route::middleware(['openfga:owner,document:{document}'])->group(function () {
            Route::delete('/{document}', [DocumentController::class, 'destroy'])->name('destroy');
            Route::post('/{document}/share', [DocumentController::class, 'share'])->name('share');
            Route::delete('/{document}/access', [DocumentController::class, 'removeAccess'])->name('removeAccess');
            Route::get('/{document}/access', [DocumentController::class, 'accessList'])->name('accessList');
        });
    });

    // Organization routes with hierarchical permissions
    Route::prefix('organizations')->name('organizations.')->group(function () {
        
        // List organizations user has access to
        Route::get('/', [OrganizationController::class, 'index'])->name('index');
        
        // Organization-specific routes
        Route::middleware(['openfga:member,organization:{organization}'])->group(function () {
            Route::get('/{organization}', [OrganizationController::class, 'show'])->name('show');
            Route::get('/{organization}/members', [OrganizationController::class, 'members'])->name('members');
        });
        
        Route::middleware(['openfga:manager,organization:{organization}'])->group(function () {
            Route::get('/{organization}/edit', [OrganizationController::class, 'edit'])->name('edit');
            Route::put('/{organization}', [OrganizationController::class, 'update'])->name('update');
            Route::post('/{organization}/departments', [OrganizationController::class, 'createDepartment'])->name('createDepartment');
        });
        
        Route::middleware(['openfga:admin,organization:{organization}'])->group(function () {
            Route::delete('/{organization}', [OrganizationController::class, 'destroy'])->name('destroy');
            Route::post('/{organization}/users', [OrganizationController::class, 'addUser'])->name('addUser');
            Route::delete('/{organization}/users/{user}', [OrganizationController::class, 'removeUser'])->name('removeUser');
            Route::get('/{organization}/settings', [OrganizationController::class, 'settings'])->name('settings');
        });
    });

    // Team routes with role-based access
    Route::prefix('teams')->name('teams.')->group(function () {
        
        Route::get('/', [TeamController::class, 'index'])->name('index');
        
        Route::middleware(['openfga:member,team:{team}'])->group(function () {
            Route::get('/{team}', [TeamController::class, 'show'])->name('show');
            Route::get('/{team}/documents', [TeamController::class, 'documents'])->name('documents');
        });
        
        Route::middleware(['openfga:lead,team:{team}'])->group(function () {
            Route::post('/{team}/members', [TeamController::class, 'addMember'])->name('addMember');
            Route::delete('/{team}/members/{user}', [TeamController::class, 'removeMember'])->name('removeMember');
        });
    });

    // Folder routes with inherited permissions
    Route::prefix('folders')->name('folders.')->group(function () {
        
        Route::get('/', [FolderController::class, 'index'])->name('index');
        
        Route::middleware(['openfga:viewer,folder:{folder}'])->group(function () {
            Route::get('/{folder}', [FolderController::class, 'show'])->name('show');
        });
        
        Route::middleware(['openfga:editor,folder:{folder}'])->group(function () {
            Route::post('/{folder}/documents', [FolderController::class, 'createDocument'])->name('createDocument');
            Route::post('/{folder}/folders', [FolderController::class, 'createSubfolder'])->name('createSubfolder');
        });
        
        Route::middleware(['openfga:admin,folder:{folder}'])->group(function () {
            Route::delete('/{folder}', [FolderController::class, 'destroy'])->name('destroy');
            Route::post('/{folder}/share', [FolderController::class, 'share'])->name('share');
        });
    });

    // API routes for AJAX requests
    Route::prefix('api')->name('api.')->group(function () {
        
        // Check permissions endpoint for dynamic UI updates
        Route::post('/permissions/check', function (Illuminate\Http\Request $request) {
            $request->validate([
                'checks' => 'required|array',
                'checks.*.user' => 'required|string',
                'checks.*.relation' => 'required|string',
                'checks.*.object' => 'required|string',
            ]);

            $results = OpenFGA\Laravel\Facades\OpenFga::batchCheck($request->checks);
            
            return response()->json([
                'results' => $results
            ]);
        })->name('permissions.check');
        
        // Search users for sharing (with permission check)
        Route::get('/users/search', function (Illuminate\Http\Request $request) {
            $request->validate([
                'q' => 'required|string|min:2',
                'organization_id' => 'sometimes|exists:organizations,id'
            ]);

            $query = App\Models\User::where('name', 'like', "%{$request->q}%")
                ->orWhere('email', 'like', "%{$request->q}%");

            // If organization specified, filter to organization members
            if ($request->organization_id) {
                $org = App\Models\Organization::find($request->organization_id);
                if ($org && $org->check(auth()->user(), 'member')) {
                    $query->whereHas('organizations', function ($q) use ($request) {
                        $q->where('organization_id', $request->organization_id);
                    });
                }
            }

            return response()->json([
                'users' => $query->limit(10)->get(['id', 'name', 'email'])
            ]);
        })->name('users.search');
    });
});

// Admin routes requiring special permissions
Route::prefix('admin')->name('admin.')->middleware(['auth', 'openfga:admin,system:global'])->group(function () {
    
    Route::get('/', function () {
        return view('admin.dashboard');
    })->name('dashboard');
    
    // System-wide organization management
    Route::resource('organizations', OrganizationController::class)->except(['show']);
    
    // User management
    Route::get('/users', [App\Http\Controllers\Admin\UserController::class, 'index'])->name('users.index');
    Route::get('/users/{user}', [App\Http\Controllers\Admin\UserController::class, 'show'])->name('users.show');
    
    // Permission audit trails
    Route::get('/audit', [App\Http\Controllers\Admin\AuditController::class, 'index'])->name('audit.index');
    Route::get('/audit/{audit}', [App\Http\Controllers\Admin\AuditController::class, 'show'])->name('audit.show');
});

// Routes demonstrating multiple permission requirements
Route::middleware(['auth'])->group(function () {
    
    // Requires ANY of the specified permissions
    Route::middleware(['openfga.any:admin|manager,organization:{organization}'])->group(function () {
        Route::get('/organizations/{organization}/reports', [OrganizationController::class, 'reports'])->name('organizations.reports');
    });
    
    // Requires ALL of the specified permissions  
    Route::middleware(['openfga.all:editor,document:{document}', 'openfga.all:member,team:{team}'])->group(function () {
        Route::post('/documents/{document}/teams/{team}/assign', [DocumentController::class, 'assignToTeam'])->name('documents.assignToTeam');
    });
    
    // Dynamic permission checking in route closure
    Route::get('/dynamic-access/{resource}/{id}', function ($resource, $id) {
        $user = auth()->user();
        
        // Check permission dynamically based on resource type
        $hasAccess = match ($resource) {
            'document' => OpenFGA\Laravel\Facades\OpenFga::check($user->authorizationUser(), 'viewer', "document:{$id}"),
            'organization' => OpenFGA\Laravel\Facades\OpenFga::check($user->authorizationUser(), 'member', "organization:{$id}"),
            'team' => OpenFGA\Laravel\Facades\OpenFga::check($user->authorizationUser(), 'member', "team:{$id}"),
            default => false
        };
        
        if (!$hasAccess) {
            abort(403, 'Access denied');
        }
        
        return view('dynamic.access', compact('resource', 'id'));
    })->name('dynamic.access');
});