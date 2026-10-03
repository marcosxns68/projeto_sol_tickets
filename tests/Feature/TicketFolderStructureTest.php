<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\TicketFolder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TicketFolderStructureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_department_description_and_ticket_folder_schema_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('departments', 'description'));
        $this->assertTrue(Schema::hasTable('ticket_folders'));
        $this->assertTrue(Schema::hasColumn('tickets', 'folder_id'));
    }

    public function test_root_folder_subfolder_and_ticket_root_are_supported(): void
    {
        $department = Department::create(['name' => 'Desenvolvimento', 'description' => 'Projetos e produto', 'active' => true]);
        $root = TicketFolder::create(['department_id' => $department->id, 'name' => 'Afialo']);
        $child = TicketFolder::create(['department_id' => $department->id, 'parent_id' => $root->id, 'name' => 'Agenda']);

        $ticket = Ticket::create([
            'number' => Ticket::nextNumber(),
            'origin' => 'internal',
            'title' => 'Ticket raiz',
            'description' => 'Teste',
            'priority' => 'normal',
            'status_id' => Status::system('new')->id,
            'department_id' => $department->id,
            'folder_id' => null,
        ]);

        $this->assertSame($department->id, $root->department->id);
        $this->assertSame($root->id, $child->parent->id);
        $this->assertTrue($root->children->contains($child));
        $this->assertNull($ticket->folder);
        $this->assertTrue($department->folders->contains($root));
    }

    public function test_folder_belongs_to_department_helper_rejects_cross_department_usage(): void
    {
        $a = Department::create(['name' => 'A', 'active' => true]);
        $b = Department::create(['name' => 'B', 'active' => true]);
        $folder = TicketFolder::create(['department_id' => $a->id, 'name' => 'Pasta']);

        $this->assertTrue($folder->belongsToDepartment($a));
        $this->assertFalse($folder->belongsToDepartment($b));
    }
}
