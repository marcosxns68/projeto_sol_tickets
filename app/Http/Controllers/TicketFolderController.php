<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\TicketFolder;
use App\Services\DepartmentAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TicketFolderController extends Controller
{
    public function store(Request $request, Department $department, DepartmentAccess $access)
    {
        abort_if($department->isTriage(), 403, 'A Triagem não possui pastas.');
        abort_unless(
            $request->user()->hasPermission('departments.manage') || $access->canEdit($request->user(), $department),
            403
        );

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('ticket_folders', 'id')->where(
                    fn ($query) => $query->where('department_id', $department->id)
                ),
            ],
        ]);

        $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
        $position = (int) TicketFolder::query()
            ->where('department_id', $department->id)
            ->where('parent_id', $parentId)
            ->max('position') + 1;

        TicketFolder::create([
            'department_id' => $department->id,
            'parent_id' => $parentId,
            'name' => trim($data['name']),
            'position' => $position,
        ]);

        return back()->with('success', $parentId ? 'Subpasta criada.' : 'Pasta criada.');
    }
}
