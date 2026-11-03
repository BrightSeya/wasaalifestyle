<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'action' => ['nullable', 'string', Rule::in(AdminAuditLog::ACTIONS)],
        ]);

        $action = $validated['action'] ?? '';
        $logs = AdminAuditLog::query()
            ->with(['administrator:id,name,email', 'affectedUser:id,name,email'])
            ->forAction($action ?: null)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.audit-logs.index', compact('logs', 'action'));
    }
}
