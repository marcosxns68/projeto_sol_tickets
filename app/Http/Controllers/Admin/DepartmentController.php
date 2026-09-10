<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeAccess($request);

        return view('admin.departments.index', [
            'departments' => Department::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeAccess($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:departments,name'],
            'active' => ['nullable', 'boolean'],
        ]);

        $department = Department::create([
            'name' => trim($data['name']),
            'active' => $request->boolean('active'),
        ]);

        $this->audit($request, $department, 'department.created', null, $department->only(['name', 'active']));

        return redirect()->route('admin.departments.index')->with('success', 'Departamento criado.');
    }

    public function edit(Request $request, Department $department)
    {
        $this->authorizeAccess($request);

        return view('admin.departments.edit', compact('department'));
    }

    public function update(Request $request, Department $department)
    {
        $this->authorizeAccess($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('departments', 'name')->ignore($department->id)],
            'active' => ['nullable', 'boolean'],
        ]);

        $old = $department->only(['name', 'active']);
        $department->update([
            'name' => trim($data['name']),
            'active' => $request->boolean('active'),
        ]);

        $this->audit($request, $department, 'department.updated', $old, $department->fresh()->only(['name', 'active']));

        return redirect()->route('admin.departments.index')->with('success', 'Departamento atualizado.');
    }

    private function authorizeAccess(Request $request): void
    {
        abort_unless($request->user()->hasPermission('departments.manage'), 403);
    }

    private function audit(Request $request, Department $department, string $event, ?array $old, array $new): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'auditable_type' => Department::class,
            'auditable_id' => $department->id,
            'event' => $event,
            'old_values' => $old ? json_encode($old, JSON_UNESCAPED_UNICODE) : null,
            'new_values' => json_encode($new, JSON_UNESCAPED_UNICODE),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
