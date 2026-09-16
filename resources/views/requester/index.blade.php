@extends('layouts.app')
@section('title','Minhas solicitações — Sutoorii Tickets')
@section('content')
<style>
.requester-shell{width:min(920px,100%);margin:28px auto;display:grid;gap:18px}.requester-brand{display:flex;align-items:center;gap:12px}.requester-brand .brand-mark{display:grid;place-items:center;width:42px;height:42px;border-radius:13px;background:#2d1743;color:#fff;font-weight:800}.requester-head{display:flex;justify-content:space-between;gap:18px;align-items:flex-start}.requester-list{display:grid;gap:10px}.requester-ticket{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;padding:16px;border:1px solid #e8e3ec;border-radius:14px;background:#fff;text-decoration:none;color:inherit}.requester-ticket:hover{border-color:#8760b5;box-shadow:0 8px 28px rgba(50,31,69,.08)}.requester-ticket h2{margin:3px 0 5px;font-size:1rem}.requester-ticket small,.requester-ticket p{margin:0;color:#746c7b}.requester-status{align-self:start;padding:6px 9px;border-radius:999px;background:#eee8f8;color:#553774;font-size:.78rem;font-weight:700}.requester-empty{padding:28px;text-align:center;border:1px dashed #dcd4e4;border-radius:14px;color:#746c7b}@media(max-width:620px){.requester-shell{margin:14px auto}.requester-head{display:grid}.requester-ticket{grid-template-columns:1fr}.requester-status{justify-self:start}}
</style>
<div class="requester-shell">
    <div class="requester-brand"><span class="brand-mark">S</span><div><strong>Sutoorii Tickets</strong><small style="display:block;color:#746c7b">Acompanhamento de solicitações</small></div></div>
    <div class="requester-head"><div><p class="eyebrow">PORTAL DO SOLICITANTE</p><h1>Minhas solicitações</h1><p class="muted">Aqui aparecem todos os chamados vinculados ao seu e-mail, inclusive os já encerrados.</p></div></div>

    <div class="requester-list">
        @forelse($tickets as $ticket)
            <a class="requester-ticket" href="{{ \Illuminate\Support\Facades\URL::signedRoute('requester.show',['ticket'=>$ticket->id,'email'=>$email]) }}">
                <div><small>#{{ $ticket->number }}</small><h2>{{ $ticket->title }}</h2><p>{{ $ticket->department?->name ?? 'Sem departamento' }} · atualizado em {{ $ticket->updated_at?->format('d/m/Y H:i') }}</p></div>
                <span class="requester-status">{{ $ticket->status?->name ?? 'Sem status' }}</span>
            </a>
        @empty
            <div class="requester-empty">Nenhuma solicitação encontrada para este e-mail.</div>
        @endforelse
    </div>
</div>
@endsection
