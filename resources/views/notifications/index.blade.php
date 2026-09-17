@extends('layouts.app')
@section('title','Notificações — Sutoorii Tickets')
@section('content')
<div class="page-head">
    <div><p class="eyebrow">ACOMPANHAMENTO</p><h1>Notificações</h1><p class="muted">Atualizações dos tickets em que você participa ou acompanha.</p></div>
    @if(auth()->user()->unreadNotifications()->exists())
    <form method="post" action="{{ route('notifications.read-all') }}">@csrf<button class="secondary-button" type="submit">Marcar todas como lidas</button></form>
    @endif
</div>

<div class="panel">
    @forelse($notifications as $notification)
        @php($data = $notification->data)
        <form method="post" action="{{ route('notifications.read',$notification->id) }}" class="notification-row {{ $notification->read_at ? '' : 'unread' }}">
            @csrf
            <button type="submit" class="notification-button">
                <span class="notification-copy">
                    <strong>{{ $data['title'] ?? 'Atualização de ticket' }}</strong>
                    <small>#{{ $data['ticket_number'] ?? '—' }} · {{ $data['message'] ?? '' }}</small>
                    @if(!empty($data['actor_name']))<small>Por {{ $data['actor_name'] }}</small>@endif
                </span>
                <time>{{ $notification->created_at?->format('d/m/Y H:i') }}</time>
            </button>
        </form>
    @empty
        <p class="muted">Nenhuma notificação até agora.</p>
    @endforelse
</div>

@if($notifications->hasPages())<div class="pagination-wrap">{{ $notifications->links() }}</div>@endif

<style>
.notification-row{border-bottom:1px solid rgba(148,163,184,.16)}.notification-row:last-child{border-bottom:0}.notification-row.unread{background:rgba(124,58,237,.08)}.notification-button{width:100%;border:0;background:transparent;color:inherit;text-align:left;display:flex;justify-content:space-between;gap:18px;padding:16px;cursor:pointer}.notification-copy{display:flex;flex-direction:column;gap:5px}.notification-copy small,time{color:var(--muted,#94a3b8)}
</style>
@endsection
