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
    <details class="mobile-filters" @if(request('status') || request('priority') || request()->boolean('unassigned') || request()->boolean('overdue')) open @endif>
        <summary>Filtros</summary>
        <div class="mobile-filter-grid">
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
                    <th>Número</th>
                    <th>Título</th>
                    <th>Status</th>
                    <th>Prioridade</th>
                    <th>Departamento</th>
                    <th>Responsável</th>
                    <th>Prazo</th>
                </tr>
            </thead>
            <tbody>
            @forelse($tickets as $ticket)
                @php($ticketUrl = route('tickets.show',$ticket))
                <tr class="ticket-row">
                    <td><a class="row-link ticket-number" href="{{ $ticketUrl }}">#{{ $ticket->number }}</a></td>
                    <td><a class="row-link ticket-title-cell" href="{{ $ticketUrl }}"><strong>{{ $ticket->title }}</strong><small>{{ Str::limit($ticket->description,72) }}</small></a></td>
                    <td><a class="row-link" href="{{ $ticketUrl }}"><span class="status" style="--status:{{ $ticket->status?->color ?? '#6d28d9' }}">{{ $ticket->status?->name ?? 'Sem status' }}</span></a></td>
                    <td><a class="row-link" href="{{ $ticketUrl }}"><span class="priority-badge {{ $ticket->priority }}"><i></i>{{ ['low'=>'Baixa','normal'=>'Normal','high'=>'Alta','urgent'=>'Urgente'][$ticket->priority] ?? ucfirst($ticket->priority) }}</span></a></td>
                    <td><a class="row-link" href="{{ $ticketUrl }}">{{ $ticket->department?->name ?? 'Sem departamento' }}</a></td>
                    <td><a class="row-link" href="{{ $ticketUrl }}">{{ $ticket->assignee?->name ?? 'Não atribuído' }}</a></td>
                    <td><a class="row-link {{ $ticket->due_at && $ticket->due_at->isPast() ? 'overdue' : '' }}" href="{{ $ticketUrl }}">{{ $ticket->due_at?->format('d/m/Y H:i') ?? 'Sem prazo' }}</a></td>
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
            <a class="mobile-ticket-card" href="{{ $ticketUrl }}">
                <div class="mobile-ticket-card-top">
                    <strong class="ticket-number">#{{ $ticket->number }}</strong>
                    <span class="status" style="--status:{{ $ticket->status?->color ?? '#6d28d9' }}">{{ $ticket->status?->name ?? 'Sem status' }}</span>
                </div>
                <strong class="mobile-ticket-card-title">{{ $ticket->title }}</strong>
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
@endsection
