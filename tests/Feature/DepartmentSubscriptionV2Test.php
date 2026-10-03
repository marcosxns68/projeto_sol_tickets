<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Services\DepartmentSubscriptions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DepartmentSubscriptionV2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function user(string $name, string $role = 'Usuário interno'): User
    {
        return User::create([
            'name' => $name,
            'email' => uniqid('subscription-').'@sutoorii.test',
            'password' => 'SenhaTeste123!',
            'email_verified_at' => now(),
            'active' => true,
            'role_id' => Role::where('name', $role)->firstOrFail()->id,
        ]);
    }

    public function test_subscription_table_and_service_exist(): void
    {
        $this->assertTrue(Schema::hasTable('department_subscriptions'));
        $this->assertTrue(class_exists(DepartmentSubscriptions::class));
    }

    public function test_super_admin_can_follow_without_artificial_department_membership(): void
    {
        $admin = $this->user('Administrador', 'Super Admin');
        $department = Department::create(['name' => 'Projetos', 'active' => true]);

        $this->assertDatabaseMissing('department_user_access', [
            'user_id' => $admin->id,
            'department_id' => $department->id,
        ]);

        $this->actingAs($admin)->patch('/departamentos/'.$department->id.'/acompanhar', [
            'notify_email' => 1,
            'notify_whatsapp' => 0,
            'notify_push' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('department_subscriptions', [
            'user_id' => $admin->id,
            'department_id' => $department->id,
            'notify_email' => true,
            'notify_whatsapp' => false,
            'notify_push' => true,
        ]);
        $this->assertDatabaseMissing('department_user_access', [
            'user_id' => $admin->id,
            'department_id' => $department->id,
        ]);
    }

    public function test_legacy_follow_preferences_are_backfilled_without_losing_channels_or_seen_marker(): void
    {
        $user = $this->user('Legado');
        $department = Department::create(['name' => 'Legado', 'active' => true]);
        $seenAt = now()->subHour()->startOfSecond();

        DB::table('department_user_access')->insert([
            'user_id' => $user->id,
            'department_id' => $department->id,
            'access_level' => 'view',
            'follow_department' => true,
            'notify_email' => true,
            'notify_whatsapp' => false,
            'notify_push' => true,
            'last_seen_at' => $seenAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::dropIfExists('department_subscriptions');
        $migration = require database_path('migrations/2026_10_02_000004_create_department_subscriptions_table.php');
        $migration->up();

        $this->assertDatabaseHas('department_subscriptions', [
            'user_id' => $user->id,
            'department_id' => $department->id,
            'notify_email' => true,
            'notify_whatsapp' => false,
            'notify_push' => true,
            'last_seen_at' => $seenAt->format('Y-m-d H:i:s'),
        ]);
    }
}
