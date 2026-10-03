<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Ticket;
use Illuminate\Support\Collection;

class TicketFolderTree
{
    public function forDepartment(Department $department): array
    {
        return $this->forDepartments(collect([$department]))[(int) $department->id] ?? [
            'root_tickets' => collect(),
            'folders' => [],
            'ticket_count' => 0,
        ];
    }

    public function forDepartments(Collection $departments): array
    {
        $departmentIds = $departments->pluck('id')->map(fn ($id) => (int) $id)->values();
        if ($departmentIds->isEmpty()) {
            return [];
        }

        $folders = \App\Models\TicketFolder::query()
            ->whereIn('department_id', $departmentIds)
            ->orderBy('position')
            ->orderBy('name')
            ->get();

        $tickets = Ticket::query()
            ->whereIn('department_id', $departmentIds)
            ->activeForBox()
            ->with(['status', 'assignee', 'labels'])
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_at')
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $foldersByDepartment = $folders->groupBy(fn ($folder) => (int) $folder->department_id);
        $ticketsByDepartment = $tickets->groupBy(fn ($ticket) => (int) $ticket->department_id);
        $result = [];

        foreach ($departments as $department) {
            $departmentId = (int) $department->id;
            $departmentFolders = $foldersByDepartment->get($departmentId, collect());
            $departmentTickets = $ticketsByDepartment->get($departmentId, collect());
            $foldersByParent = $departmentFolders->groupBy(
                fn ($folder) => $folder->parent_id === null ? 0 : (int) $folder->parent_id
            );
            $ticketsByFolder = $departmentTickets
                ->whereNotNull('folder_id')
                ->groupBy(fn ($ticket) => (int) $ticket->folder_id);

            $result[$departmentId] = [
                'root_tickets' => $departmentTickets->whereNull('folder_id')->values(),
                'folders' => $this->buildNodes($foldersByParent, $ticketsByFolder, 0),
                'ticket_count' => $departmentTickets->count(),
            ];
        }

        return $result;
    }

    private function buildNodes(
        Collection $foldersByParent,
        Collection $ticketsByFolder,
        int $parentKey,
        array $ancestors = [],
    ): array {
        $nodes = [];

        foreach ($foldersByParent->get($parentKey, collect()) as $folder) {
            $folderId = (int) $folder->id;
            if (isset($ancestors[$folderId])) {
                continue;
            }

            $path = $ancestors;
            $path[$folderId] = true;
            $nodes[] = [
                'folder' => $folder,
                'tickets' => $ticketsByFolder->get($folderId, collect())->values(),
                'children' => $this->buildNodes($foldersByParent, $ticketsByFolder, $folderId, $path),
            ];
        }

        return $nodes;
    }
}
