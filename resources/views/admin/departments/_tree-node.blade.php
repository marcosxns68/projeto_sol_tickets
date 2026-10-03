@php
    $folder = $node['folder'];
    $priorityStyles = [
        'low' => ['label' => 'Baixa', 'color' => '#2F855A'],
        'normal' => ['label' => 'Normal', 'color' => '#6D28D9'],
        'high' => ['label' => 'Alta', 'color' => '#D97706'],
        'urgent' => ['label' => 'Urgente', 'color' => '#DC2626'],
    ];
@endphp
<div class="tree-folder-node" style="--tree-depth: {{ $depth }}" data-tree-node>
    <div class="tree-folder-row">
        <button type="button" class="tree-main-toggle" data-tree-toggle aria-expanded="false">
            <span class="tree-chevron" aria-hidden="true">›</span>
            <span class="tree-folder-icon" aria-hidden="true">▰</span>
            <span class="tree-copy">
                <strong>{{ $folder->name }}</strong>
                <small>{{ $node['tickets']->count() + collect($node['children'])->sum(fn($child) => $child['tickets']->count()) }} item(ns) neste nível</small>
            </span>
        </button>
        @if($canEdit)
        <details class="tree-action-menu">
            <summary aria-label="Adicionar em {{ $folder->name }}">+</summary>
            <div class="tree-popover">
                <a href="{{ route('tickets.create', ['department' => $department->id, 'folder' => $folder->id]) }}">Nova tarefa</a>
                <button type="button" data-tree-panel-target="folder-create-{{ $folder->id }}">Nova pasta</button>
            </div>
        </details>
        @endif
    </div>

    @if($canEdit)
    <div class="tree-inline-panel" id="folder-create-{{ $folder->id }}" hidden>
        <form method="post" action="{{ route('departments.folders.store', $department) }}" class="tree-compact-form">
            @csrf
            <input type="hidden" name="parent_id" value="{{ $folder->id }}">
            <label>Nome da subpasta<input name="name" required maxlength="160" placeholder="Ex.: Bugs, Versão 2.0"></label>
            <div class="tree-inline-actions"><button class="secondary-button compact" type="button" data-tree-panel-close>Cancelar</button><button class="button compact" type="submit">Criar pasta</button></div>
        </form>
    </div>
    @endif

    <div class="tree-children" data-tree-children hidden>
        @foreach($node['tickets'] as $ticket)
            @php($priority = $priorityStyles[$ticket->priority] ?? $priorityStyles['normal'])
            <a class="tree-ticket-row" href="{{ route('tickets.show', $ticket) }}">
                <span class="ticket-priority-dot" data-priority="{{ $ticket->priority }}" style="--priority-color:{{ $priority['color'] }}" aria-label="Prioridade: {{ $priority['label'] }}" title="Prioridade: {{ $priority['label'] }}"></span>
                <span class="tree-ticket-copy"><strong>#{{ $ticket->number }} · {{ $ticket->title }}</strong><small>{{ $ticket->assignee?->name ?? 'Não atribuído' }}@if($ticket->due_at) · {{ $ticket->due_at->format('d/m/Y') }}@endif</small></span>
            </a>
        @endforeach

        @foreach($node['children'] as $childNode)
            @include('admin.departments._tree-node', [
                'node' => $childNode,
                'department' => $department,
                'depth' => $depth + 1,
                'canEdit' => $canEdit,
            ])
        @endforeach

        @if($node['tickets']->isEmpty() && empty($node['children']))
            <div class="tree-empty-row">Pasta vazia</div>
        @endif
    </div>
</div>
