<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        $permission = Permission::firstOrCreate(
            ['key' => 'tickets.create_integration'],
            ['name' => 'Criar tickets para integrações', 'group' => 'tickets']
        );

        foreach (Role::whereIn('name', ['Super Admin', 'Gestor'])->get() as $role) {
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
    }

    public function down(): void
    {
        $permission = Permission::where('key', 'tickets.create_integration')->first();
        if ($permission) {
            $permission->roles?->each(fn ($role) => $role->permissions()->detach($permission->id));
            $permission->delete();
        }
    }
};
