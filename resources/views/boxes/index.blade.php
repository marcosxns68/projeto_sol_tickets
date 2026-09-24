@extends('layouts.app')

@section('title', $boxTitle.' — Sutoorii Tickets')

@section('content')
@php
    $boxBaseRoute = $boxKind === 'mine'
        ? route('boxes.mine')
        : ($boxKind === 'all' ? route('boxes.all') : route('boxes.department', $department));
    $boxEyebrow = $boxKind === 'mine' ? 'MINHA CAIXA' : ($boxKind === 'all' ? 'TODOS OS TICKETS' : 'DEPARTAMENTO');
@endphp
<div class="page-head helpdesk-head">
    <div>
        <p class="eyebrow">{{ $boxEyebrow }}</p>
        <h1>{{ $boxTitle }}</h1>
        <p class="muted head-sub">Acompanhe e organize os tickets que precisam da sua atenção.</p>
    </div>
    @if(auth()->user()->hasPermission('tickets.create'))
        <a class="button" href="{{ route('tickets.create') }}">+ Novo ticket</a>
    @endif
</div>

@if($boxKind === 'department' && !empty($departmentSeenAt))
    <div class="department-inbox-notice">
        <span><strong>Você acompanha esta caixa.</strong> Tickets criados ou atualizados desde sua última confirmação aparecem destacados.</span>
        <div class="department-inbox-actions">
            <a href="{{ route('boxes.department', ['department' => $department, 'novos' => 1]) }}" class="secondary-button compact">Ver somente novidades</a>
            <form method="post" action="{{ route('departments.mark-seen', $department) }}">@csrf<button type="submit" class="secondary-button compact">Marcar novidades como vistas</button></form>
        </div>
    </div>
@endif

<div class="box-tabs" role="navigation" aria-label="Filtros rápidos">
    @if($boxKind === 'mine')
        <a class="box-tab {{ !request('relation') && !request('status') ? 'active' : '' }}" href="{{ route('boxes.mine') }}">Abertos</a>
        <a class="box-tab {{ request('relation')==='assignee' ? 'active' : '' }}" href="{{ route('boxes.mine', ['relation'=>'assignee']) }}">Responsável</a>
        <a class="box-tab {{ request('relation')==='collaborator' ? 'active' : '' }}" href="{{ route('boxes.mine', ['relation'=>'collaborator']) }}">Colaborador</a>
        <a class="box-tab {{ request('relation')==='follower' ? 'active' : '' }}" href="{{ route('boxes.mine', ['relation'=>'follower']) }}">Seguidor</a>
    @else
        <a class="box-tab {{ !request('status') ? 'active' : '' }}" href="{{ $boxBaseRoute }}">Abertos</a>
    @endif
    @foreach($statuses->whereIn('system_key',['resolved','closed','cancelled']) as $status)
        @php
            $statusRoute = $boxKind === 'mine'
                ? route('boxes.mine', ['status' => $status->system_key])
                : ($boxKind === 'all'
                    ? route('boxes.all', ['status' => $status->system_key])
                    : route('boxes.department', [$department, 'status' => $status->system_key]));
        @endphp
        <a class="box-tab {{ request('status')===$status->system_key ? 'active' : '' }}" href="{{ $statusRoute }}">{{ $status->name }}</a>
    @endforeach
</div>

<form class="helpdesk-toolbar desktop-filters" method="get">
    <div class="toolbar-search">
        <span>⌕</span>
        <input name="q" value="{{ request('q') }}" placeholder="Buscar nesta caixa...">
    </div>
    @if($boxKind === 'mine' && $departments->isNotEmpty())
    <label class="toolbar-field">Filtrar departamento
        <select name="department">
            <option value="">Todos</option>
            @foreach($departments as $filterDepartment)
                <option value="{{ $filterDepartment->id }}" @selected((string)request('department') === (string)$filterDepartment->id)>{{ $filterDepartment->name }} ({{ $filterDepartment->open_tickets_count }})</option>
            @endforeach
        </select>
    </label>
    @endif
    <label class="toolbar-field">Status
        <select name="status">
            <option value="">Ativos</option>
            @foreach($statuses as $status)
                <option value="{{ $status->system_key ?: $status->id }}" @selected((string)request('status') === (string)($status->system_key ?: $status->id))>{{ $status->name }}</option>
            @endforeach
        </select>
    </label>
    <label class="toolbar-field">Prioridade
        <select name="priority">
            <option value="">Todas</option>
            <option value="low" @selected(request('priority')==='low')>Baixa</option>
            <option value="normal" @selected(request('priority')==='normal')>Normal</option>
            <option value="high" @selected(request('priority')==='high')>Alta</option>
            <option value="urgent" @selected(request('priority')==='urgent')>Urgente</option>
        </select>
    </label>
    <label class="toolbar-field">Etiqueta
        <select name="label">
            <option value="">Todas</option>
            @foreach($labels as $label)
                <option value="{{ $label->id }}" @selected((string)request('label') === (string)$label->id)>{{ $label->name }}</option>
            @endforeach
        </select>
    </label>
    <label class="toolbar-field toolbar-sort">Ordenar por
        <select name="sort">
            <option value="due_soon" @selected(!request('sort') || request('sort')==='due_soon')>Prazo: mais próximo</option>
            <option value="oldest" @selected(request('sort')==='oldest')>Idade: mais antigos</option>
            <option value="newest" @selected(request('sort')==='newest')>Idade: mais recentes</option>
            <option value="due_late" @selected(request('sort')==='due_late')>Prazo: mais distante</option>
        </select>
    </label>
    @if($boxKind === 'mine' && request('relation'))<input type="hidden" name="relation" value="{{ request('relation') }}">@endif
    <label class="toolbar-check"><input type="checkbox" name="unassigned" value="1" @checked(request()->boolean('unassigned'))> Não atribuídos</label>
    <label class="toolbar-check"><input type="checkbox" name="overdue" value="1" @checked(request()->boolean('overdue'))> Atrasados</label>
    <button class="button compact" type="submit">Filtrar</button>
    <a class="subtle-link" href="{{ $boxBaseRoute }}">Limpar</a>
</form>

<form class="mobile-filter-form" method="get">
    @if($boxKind === 'mine' && request('relation'))<input type="hidden" name="relation" value="{{ request('relation') }}">@endif
    <div class="mobile-search-row">
        <input name="q" value="{{ request('q') }}" placeholder="Buscar nesta caixa..." aria-label="Buscar nesta caixa">
        <button class="button compact" type="submit">Buscar</button>
    </div>
    <details class="mobile-filters" @if(request('department') || request('status') || request('priority') || request('label') || request('sort') || request()->boolean('unassigned') || request()->boolean('overdue')) open @endif>
        <summary>Filtros</summary>
        <div class="mobile-filter-grid">
            @if($boxKind === 'mine' && $departments->isNotEmpty())
            <label class="toolbar-field">Filtrar departamento
                <select name="department">
                    <option value="">Todos</option>
                    @foreach($departments as $filterDepartment)
                        <option value="{{ $filterDepartment->id }}" @selected((string)request('department') === (string)$filterDepartment->id)>{{ $filterDepartment->name }} ({{ $filterDepartment->open_tickets_count }})</option>
                    @endforeach
                </select>
            </label>
            @endif
            <label class="toolbar-field">Status
                <select name="status">
                    <option value="">Ativos</option>
                    @foreach($statuses as $status)
                        <option value="{{ $status->system_key ?: $status->id }}" @selected((string)request('status') === (string)($status->system_key ?: $status->id))>{{ $status->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="toolbar-field">Prioridade
                <select name="priority">
                    <option value="">Todas</option>
                    <option value="low" @selected(request('priority')==='low')>Baixa</option>
                    <option value="normal" @selected(request('priority')==='normal')>Normal</option>
                    <option value="high" @selected(request('priority')==='high')>Alta</option>
                    <option value="urgent" @selected(request('priority')==='urgent')>Urgente</option>
                </select>
            </label>
            <label class="toolbar-field">Etiqueta
                <select name="label">
                    <option value="">Todas</option>
                    @foreach($labels as $label)
                        <option value="{{ $label->id }}" @selected((string)request('label') === (string)$label->id)>{{ $label->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="toolbar-field mobile-sort-field">Ordenar por
                <select name="sort">
                    <option value="due_soon" @selected(!request('sort') || request('sort')==='due_soon')>Prazo: mais próximo</option>
                    <option value="oldest" @selected(request('sort')==='oldest')>Idade: mais antigos</option>
                    <option value="newest" @selected(request('sort')==='newest')>Idade: mais recentes</option>
                    <option value="due_late" @selected(request('sort')==='due_late')>Prazo: mais distante</option>
                </select>
            </label>
            <div class="mobile-filter-checks">
                <label class="toolbar-check"><input type="checkbox" name="unassigned" value="1" @checked(request()->boolean('unassigned'))> Não atribuídos</label>
                <label class="toolbar-check"><input type="checkbox" name="overdue" value="1" @checked(request()->boolean('overdue'))> Atrasados</label>
            </div>
            <div class="mobile-filter-actions">
                <a class="subtle-link" href="{{ $boxBaseRoute }}">Limpar</a>
                <button class="button compact" type="submit">Aplicar filtros</button>
            </div>
        </div>
    </details>
</form>

<div class="ticket-table-panel">
    <div class="table-summary"><strong>{{ $tickets->total() }}</strong> {{ $tickets->total() === 1 ? 'ticket' : 'tickets' }}</div>

    <div class="responsive-table desktop-table-wrap">
        <table class="tickets-table">
            <thead>
                <tr>
                    <th class="ticket-col-center">Número</th>
                    <th>Título</th>
                    <th class="ticket-col-center">Status</th>
                    <th class="ticket-col-center">Prioridade</th>
                    <th class="ticket-col-center">Departamento</th>
                    <th class="ticket-col-center">Responsável</th>
                    <th class="ticket-col-center">Prazo</th>
                </tr>
            </thead>
            <tbody>
            @forelse($tickets as $ticket)
                @php($ticketUrl = route('tickets.show',$ticket))
                @php($isNewForDepartment = $boxKind === 'department' && !empty($departmentSeenAt) && ($ticket->created_at?->greaterThan(\Illuminate\Support\Carbon::parse($departmentSeenAt)) || $ticket->updated_at?->greaterThan(\Illuminate\Support\Carbon::parse($departmentSeenAt))))
                <tr class="ticket-row {{ $isNewForDepartment ? 'department-ticket-new' : '' }}">
                    <td class="ticket-col-center"><a class="row-link ticket-number" href="{{ $ticketUrl }}">#{{ $ticket->number }}</a></td>
                    <td><a class="row-link ticket-title-cell" href="{{ $ticketUrl }}"><strong>{{ $ticket->title }} @if($isNewForDepartment)<span class="department-new-ticket-badge">Novo na caixa</span>@endif</strong><small>{{ Str::limit($ticket->description,72) }}</small>@if($ticket->labels->isNotEmpty())<span class="ticket-labels">@foreach($ticket->labels as $label)<span class="label-chip" style="--label-color:{{ $label->color }}">{{ $label->name }}</span>@endforeach</span>@endif</a></td>
                    <td class="ticket-col-center"><a class="row-link" href="{{ $ticketUrl }}"><span class="status" style="--status:{{ $ticket->status?->color ?? '#6d28d9' }}">{{ $ticket->status?->name ?? 'Sem status' }}</span></a></td>
                    <td class="ticket-col-center"><a class="row-link" href="{{ $ticketUrl }}"><span class="priority-badge {{ $ticket->priority }}"><i></i>{{ ['low'=>'Baixa','normal'=>'Normal','high'=>'Alta','urgent'=>'Urgente'][$ticket->priority] ?? ucfirst($ticket->priority) }}</span></a></td>
                    <td class="ticket-col-center"><a class="row-link" href="{{ $ticketUrl }}">{{ $ticket->department?->name ?? 'Sem departamento' }}</a></td>
                    <td class="ticket-col-center"><a class="row-link" href="{{ $ticketUrl }}">{{ $ticket->assignee?->name ?? 'Não atribuído' }}</a></td>
                    <td class="ticket-col-center"><a class="row-link ticket-deadline {{ $ticket->due_at && $ticket->due_at->isPast() ? 'overdue' : '' }}" href="{{ $ticketUrl }}">@if($ticket->due_at)<span class="ticket-deadline-date">{{ $ticket->due_at->format('d/m/Y') }}</span><span class="ticket-deadline-time">{{ $ticket->due_at->format('H:i') }}</span>@else<span>Sem prazo</span>@endif</a></td>
                </tr>
            @empty
                <tr><td colspan="7"><div class="table-empty"><span>✓</span><strong>Nenhum ticket nesta caixa</strong><small>Ajuste os filtros ou aguarde novos tickets.</small></div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mobile-ticket-list">
        @forelse($tickets as $ticket)
            @php($ticketUrl = route('tickets.show',$ticket))
            @php($isNewForDepartment = $boxKind === 'department' && !empty($departmentSeenAt) && ($ticket->created_at?->greaterThan(\Illuminate\Support\Carbon::parse($departmentSeenAt)) || $ticket->updated_at?->greaterThan(\Illuminate\Support\Carbon::parse($departmentSeenAt))))
            <a class="mobile-ticket-card {{ $isNewForDepartment ? 'department-ticket-new' : '' }}" href="{{ $ticketUrl }}">
                <div class="mobile-ticket-card-top">
                    <strong class="ticket-number">#{{ $ticket->number }}</strong>
                    <span class="status" style="--status:{{ $ticket->status?->color ?? '#6d28d9' }}">{{ $ticket->status?->name ?? 'Sem status' }}</span>
                </div>
                <strong class="mobile-ticket-card-title">{{ $ticket->title }} @if($isNewForDepartment)<span class="department-new-ticket-badge">Novo na caixa</span>@endif</strong>
                @if($ticket->labels->isNotEmpty())<div class="ticket-labels">@foreach($ticket->labels as $label)<span class="label-chip" style="--label-color:{{ $label->color }}">{{ $label->name }}</span>@endforeach</div>@endif
                <div class="mobile-ticket-card-meta">
                    <span class="priority-badge {{ $ticket->priority }}"><i></i>{{ ['low'=>'Baixa','normal'=>'Normal','high'=>'Alta','urgent'=>'Urgente'][$ticket->priority] ?? ucfirst($ticket->priority) }}</span>
                    @if($ticket->due_at)<span class="{{ $ticket->due_at->isPast() ? 'overdue' : 'muted' }}">Prazo {{ $ticket->due_at->format('d/m H:i') }}</span>@endif
                </div>
                <div class="mobile-ticket-card-bottom">
                    <span>{{ $ticket->department?->name ?? 'Sem departamento' }}</span>
                    <span>{{ $ticket->assignee?->name ?? 'Não atribuído' }}</span>
                </div>
            </a>
        @empty
            <div class="mobile-empty"><strong>Nenhum ticket nesta caixa</strong><br><small>Ajuste os filtros ou aguarde novos tickets.</small></div>
        @endforelse
    </div>

    @if($tickets->hasPages())<div class="pagination">{{ $tickets->links() }}</div>@endif
</div>
<style>
.department-inbox-notice{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;background:#f6f1ff;border:1px solid #d8c9fa;border-radius:12px;padding:14px 18px;margin-bottom:12px;color:#452c6c;font-size:.88rem}
.department-inbox-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.department-inbox-actions form{margin:0}
.department-ticket-new{background:#faf6ff!important;box-shadow:inset 3px 0 #7c3aed}
.department-new-ticket-badge{display:inline-block;padding:3px 7px;margin-left:5px;border-radius:999px;background:#6d28d9;color:#fff;font-size:.65rem;font-weight:750;white-space:nowrap;vertical-align:middle}
</style>
@endsection
