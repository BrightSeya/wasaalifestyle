<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminAuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_newest_first_paginated_logs_and_filter_by_action(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Audit Admin']);
        foreach (range(1, 21) as $number) {
            $affectedUser = User::factory()->create(['name' => "Affected {$number}"]);
            AdminAuditLog::create([
                'administrator_id' => $admin->id,
                'affected_user_id' => $affectedUser->id,
                'action' => $number === 21 ? 'user.suspended' : 'user.created',
            ]);
        }

        $this->actingAs($admin)
            ->get(route('admin.audit-logs.index'))
            ->assertOk()
            ->assertSee('Administrator activity')
            ->assertSee('page=2', false)
            ->assertSee('Audit Admin')
            ->assertSeeInOrder(['Affected 21', 'Affected 20']);

        $this->get(route('admin.audit-logs.index', ['action' => 'user.suspended']))
            ->assertOk()
            ->assertSee('1 event(s)')
            ->assertSee('Account suspended')
            ->assertSee('Affected 21')
            ->assertDontSee('Affected 20');
    }

    public function test_account_creation_status_changes_and_successful_reset_are_recorded_without_secrets(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Audit Target',
            'email' => 'audit-target@example.test',
            'role' => 'user',
        ])->assertRedirect();

        $target = User::where('email', 'audit-target@example.test')->firstOrFail();
        $this->patch(route('admin.users.status', $target), ['status' => 'suspended'])->assertRedirect();
        $this->patch(route('admin.users.status', $target), ['status' => 'active'])->assertRedirect();

        Carbon::setTestNow(now()->addMinutes(2));
        $this->post(route('admin.users.password-reset', $target))->assertRedirect();
        Carbon::setTestNow();

        $logs = AdminAuditLog::where('affected_user_id', $target->id)->orderBy('id')->get();
        $this->assertSame([
            'user.created',
            'user.password_reset_requested',
            'user.suspended',
            'user.reactivated',
            'user.password_reset_requested',
        ], $logs->pluck('action')->all());

        foreach ($logs as $log) {
            $this->assertSame($admin->id, $log->administrator_id);
            $this->assertSame($target->id, $log->affected_user_id);
            $this->assertArrayNotHasKey('password', $log->getAttributes());
            $this->assertArrayNotHasKey('token', $log->getAttributes());
        }

        $this->assertDatabaseHas('admin_audit_logs', [
            'administrator_id' => $admin->id,
            'affected_user_id' => $target->id,
            'action' => 'user.password_reset_requested',
        ]);
        $this->assertNotNull($logs->first()->created_at);
    }

    public function test_non_admin_cannot_view_audit_logs(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.audit-logs.index'))->assertForbidden();
    }
}
