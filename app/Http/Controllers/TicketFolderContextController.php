<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketFolder;
use App\Services\DepartmentAccess;
use Illuminate\Http\Request;

class TicketFolderContextController extends Controller
{
    public function department(Request $request, Department $department, DepartmentAccess $access)
    {
        abort_unless($access->canView($request->user(), $department), 403);

        return response()->json([
            'department_id' => (int) $department->id,
            'folders' => $this->folderOptions($department),
        ]);
    }

    public function ticket(Request $request, Ticket $ticket, DepartmentAccess $access)
    {
        $user = $request->user();
        abort_unless(Ticket::visibleTo($user)->whereKey($ticket->id)->exists(), 403);

        $department = $ticket->department;
        if (!$department || !$access->canView($user, $department)) {
            return response()->json([
                'department_id' => $ticket->department_id ? (int) $ticket->department_id : null,
                'folder_id' => null,
                'folders' => [],
                'folder_access' => false,
            ]);
        }

        return response()->json([
            'department_id' => (int) $department->id,
            'folder_id' => $ticket->folder_id ? (int) $ticket->folder_id : null,
            'folders' => $this->folderOptions($department),
            'folder_access' => true,
        ]);
    }

    private function folderOptions(Department $department): array
    {
        if ($department->isTriage()) {
            return [];
        }

        $folders = TicketFolder::query()
            ->where('department_id', $department->id)
            ->orderBy('position')
            ->orderBy('name')
            ->get()
            ->keyBy('id');

        return $folders->values()->map(function (TicketFolder $folder) use ($folders) {
            $parts = [$folder->name];
            $parentId = $folder->parent_id;
            $visited = [(int) $folder->id => true];
            $depth = 0;

            while ($parentId && $depth < 50) {
                $parentId = (int) $parentId;
                if (isset($visited[$parentId]) || !$folders->has($parentId)) {
                    break;
                }

                $visited[$parentId] = true;
                $parent = $folders->get($parentId);
                array_unshift($parts, $parent->name);
                $parentId = $parent->parent_id;
                $depth++;
            }

            return [
                'id' => (int) $folder->id,
                'parent_id' => $folder->parent_id ? (int) $folder->parent_id : null,
                'path' => implode(' / ', $parts),
            ];
        })->all();
    }
}
