<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $search = trim($validated['search'] ?? '');
        $users = User::query()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return view('admin.users.index', compact('users', 'search'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(['user', 'admin'])],
        ]);

        $user = DB::transaction(function () use ($request, $validated) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Str::random(64),
            ]);
            $user->role = $validated['role'];
            $user->save();

            AdminAuditLog::create([
                'administrator_id' => $request->user()->id,
                'affected_user_id' => $user->id,
                'action' => 'user.created',
            ]);

            return $user;
        });

        $this->record($request, $user, 'user.password_reset_requested');
        Password::sendResetLink(['email' => $user->email]);

        return to_route('admin.users.index')
            ->with('status', "Account created for {$user->email}. A password setup link has been sent.");
    }

    public function updateStatus(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['active', 'suspended'])],
        ]);

        if ($request->user()->is($user) && $validated['status'] === 'suspended') {
            return back()->withErrors(['status' => 'You cannot suspend your own administrator account.']);
        }

        DB::transaction(function () use ($request, $user, $validated) {
            if ($user->status === $validated['status']) {
                return;
            }

            $user->forceFill(['status' => $validated['status']])->save();
            $action = $validated['status'] === 'suspended' ? 'user.suspended' : 'user.reactivated';
            $this->record($request, $user, $action);
        });

        return back()->with('status', "{$user->name}'s account is now {$validated['status']}.");
    }

    public function sendPasswordReset(Request $request, User $user): RedirectResponse
    {
        $this->record($request, $user, 'user.password_reset_requested');
        $status = Password::sendResetLink(['email' => $user->email]);

        return back()->with('status', __($status));
    }

    private function record(Request $request, User $user, string $action): void
    {
        AdminAuditLog::create([
            'administrator_id' => $request->user()->id,
            'affected_user_id' => $user->id,
            'action' => $action,
        ]);
    }
}
