<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function index(Request $request): View
    {
        // Note: This is a simplified audit log implementation
        // In production, you'd want to use a proper audit package or OpenFGA's built-in audit features
        
        $query = DB::table('permission_audit_logs');

        // Apply filters
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('resource_type')) {
            $query->where('resource_type', $request->resource_type);
        }

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $audits = $query->orderBy('created_at', 'desc')->paginate(50);

        // Get unique values for filters
        $filters = [
            'users' => \App\Models\User::pluck('name', 'id'),
            'resource_types' => DB::table('permission_audit_logs')
                ->distinct()
                ->pluck('resource_type'),
            'actions' => ['grant', 'revoke', 'check', 'list'],
        ];

        return view('admin.audit.index', compact('audits', 'filters'));
    }

    public function show($auditId): View
    {
        $audit = DB::table('permission_audit_logs')
            ->where('id', $auditId)
            ->firstOrFail();

        // Get related information
        $user = \App\Models\User::find($audit->user_id);
        $resource = $this->getResource($audit->resource_type, $audit->resource_id);

        // Get before/after state if available
        $changes = json_decode($audit->changes ?? '{}', true);

        return view('admin.audit.show', compact('audit', 'user', 'resource', 'changes'));
    }

    private function getResource(string $type, $id)
    {
        return match ($type) {
            'organization' => \App\Models\Organization::find($id),
            'department' => \App\Models\Department::find($id),
            'team' => \App\Models\Team::find($id),
            'folder' => \App\Models\Folder::find($id),
            'document' => \App\Models\Document::find($id),
            default => null,
        };
    }
}