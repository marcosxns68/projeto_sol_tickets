<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Role;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use App\Services\DepartmentAccess;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DepartmentAccessV2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function user(string $name): User
    {
        $role = Role::create(['name' => $name.' '.uniqid(), 'active' => true]);

        return User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).uniqid().'@sutoorii.test',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123',
            'role_id' => $role->id,
            'active' => true,
        ]);
    }

    private function ticket(Department $department): Ticket
    {
        return Ticket::create([
            'number' => Ticket::nextNumber(),
            'origin' => 'internal',
            'title' => 'Ticket de departamento',
            'description' => 'Descrição',
            'priority' => 'normal',
            'status_id' => Status::system('new')->id,
            'department_id' => $department->id,
        ]);
    }

    private function associate(User $user, Department $department, string $level, bool $follow = false): void
    {
        if (!Schema::hasTable('department_user_access')) {
            $this->fail('A tabela department_user_access ainda não existe.');
        }

        DB::table('department_user_access')->insert([
            'user_id' => $user->id,
            'department_id' => $department->id,
            'access_level' => $level,
            'follow_department' => $follow,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_department_access_service_exists(): void
    {
        $this->assertTrue(class_exists(DepartmentAccess::class));
    }

    public function test_send_access_can_target_department_but_cannot_view_its_tickets(): void
    {
        $department = Department::create(['name' => 'Financeiro']);
        $user = $this->user('Pessoa Envio');
        $this->associate($user, $department, 'send');
        $ticket = $this->ticket($department);

        $access = app(DepartmentAccess::class);
        $this->assertTrue($access->canSend($user, $department));
        $this->assertFalse($access->canView($user, $department));
        $this->actingAs($user)->get(route('tickets.show', $ticket))->assertForbidden();
    }

    public function test_view_access_can_open_every_ticket_in_department(): void
    {
        $department = Department::create(['name' => 'Suporte']);
        $user = $this->user('Pessoa Visualiza');
        $this->associate($user, $department, 'view');
        $ticket = $this->ticket($department);

        $access = app(DepartmentAccess::class);
        $this->assertTrue($access->canSend($user, $department));
        $this->assertTrue($access->canView($user, $department));
        $this->assertFalse($access->canEdit($user, $department));
        $this->actingAs($user)->get(route('tickets.show', $ticket))->assertOk();
    }

    public function test_edit_access_is_progressive(): void
    {
        $department = Department::create(['name' => 'Desenvolvimento']);
        $user = $this->user('Pessoa Edita');
        $this->associate($user, $department, 'edit');

        $access = app(DepartmentAccess::class);
        $this->assertTrue($access->canSend($user, $department));
        $this->assertTrue($access->canView($user, $department));
        $this->assertTrue($access->canEdit($user, $department));
    }

    public function test_legacy_department_id_remains_viewable_for_compatibility(): void
    {
        $department = Department::create(['name' => 'Legado']);
        $user = $this->user('Pessoa Legada');
        $user->update(['department_id' => $department->id]);

        $access = app(DepartmentAccess::class);
        $this->assertTrue($access->canSend($user->fresh(), $department));
        $this->assertTrue($access->canView($user->fresh(), $department));
    }
}
