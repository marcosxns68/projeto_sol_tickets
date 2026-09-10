@extends('layouts.app')

@section('title', $boxTitle.' — Sutoorii Tickets')

@section('content')
<div class="page-head">
    <div>
        <p class="eyebrow">{{ $boxKind === 'mine' ? 'MINHA CAIXA' : 'DEPARTAMENTO' }}</p>
        <h1>{{ $boxTitle }}</h1>
    </div>
    @if(auth()->user()->hasPermission('tickets.create'))
        <a class="button desktop" href="{{ route('tickets.create') }}">+ Novo ticket</a>
    @endif
</div>

<form class="box-search panel" method="get">
    <div class="box-search-grid">
        <label>Buscar
            <input name="q" value="{{ request('q') }}" placeholder="Número, título ou descrição">
        </label>
        <label>Status
            <select name="status">
                <option value="">Ativos</option>
                @foreach($statuses as $status)
                    <option value="{{ $status->system_key ?: $status->id }}" @selected((string)request('status') === (string)($status->system_key ?: $status->id))>{{ $status->name }}</option>
                @endforeach
            </select>
        </label>
        <label>Prioridade
            <select name="priority">
                <option value="">Todas</option>
                <option value="low" @selected(request('priority')==='low')>Baixa</option>
                <option value="normal" @selected(request('priority')==='normal')>Normal</option>
                <option value="high" @selected(request('priority')==='high')>Alta</option>
                <option value="urgent" @selected(request('priority')==='urgent')>Urgente</option>
            </select>
        </label>
    </div>
    @if($boxKind === 'mine')
        <div class="filters">
            <a class="filter-chip {{ !request('relation') ? 'active' : '' }}" href="{{ route('boxes.mine', array_filter(request()->except('relation'))) }}">Todos</a>
            <a class="filter-chip {{ request('relation')==='assignee' ? 'active' : '' }}" href="{{ route('boxes.mine', array_merge(request()->except('relation'), ['relation'=>'assignee'])) }}">Responsável</a>
            <a class="filter-chip {{ request('relation')==='collaborator' ? 'active' : '' }}" href="{{ route('boxes.mine', array_merge(request()->except('relation'), ['relation'=>'collaborator'])) }}">Colaborador</a>
            <a class="filter-chip {{ request('relation')==='follower' ? 'active' : '' }}" href="{{ route('boxes.mine', array_merge(request()->except('relation'), ['relation'=>'follower'])) }}">Seguidor</a>
        </div>
    @endif
    <div class="box-actions">
        <label class="inline-check"><input type="checkbox" name="unassigned" value="1" @checked(request()->boolean('unassigned'))> Sem responsável</label>
        <label class="inline-check"><input type="checkbox" name="overdue" value="1" @checked(request()->boolean('overdue'))> Atrasados</label>
        <button class="button" type="submit">Filtrar</button>
        <a class="subtle-link" href="{{ $boxKind === 'mine' ? route('boxes.mine') : route('boxes.department', $department) }}">Limpar</a>
    </div>
</form>

<section class="ticket-list box-list">
@forelse($tickets as $ticket)
    <a class="ticket" href="{{ route('tickets.show', $ticket) }}">
        <div class="ticket-top">
            <span class="number">#{{ $ticket->number }}</span>
            <span class="status" style="--status:{{ $ticket->status->color }}">{{ $ticket->status->name }}</span>
        </div>
        <h2>{{ $ticket->title }}</h2>
        <p>{{ Str::limit($ticket->description, 110) }}</p>
        <div class="meta">
            <span>{{ ['low'=>'Baixa','normal'=>'Normal','high'=>'Alta','urgent'=>'Urgente'][$ticket->priority] ?? ucfirst($ticket->priority) }}</span>
            <span>{{ $ticket->department?->name ?? 'Sem departamento' }}</span>
            <span>{{ $ticket->assignee?->name ?? 'Sem responsável' }}</span>
            <span>{{ $ticket->due_at?->format('d/m/Y H:i') ?? 'Sem prazo' }}</span>
        </div>
    </a>
@empty
    <div class="empty panel"><span>✓</span><h2>Nenhum ticket nesta caixa</h2><p>Ajuste os filtros ou aguarde novos tickets.</p></div>
@endforelse
</section>

{{ $tickets->links() }}
@endsection
