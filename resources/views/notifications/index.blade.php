@extends('layouts.app')
@section('title','Notificações — Sutoorii Tickets')
@section('content')
<div class="page-head notifications-page-head">
    <div>
        <p class="eyebrow">ACOMPANHAMENTO</p>
        <h1>Notificações</h1>
        <p class="muted">Atualizações dos tickets em que você participa ou acompanha.</p>
    </div>
    <div class="notification-head-actions">
        <form method="get" action="{{ route('notifications.index') }}" class="notification-page-size-form">
            <label for="notificationPageSize">Mostrar</label>
            <select id="notificationPageSize" name="per_page" onchange="this.form.submit()">
                @foreach($pageSizes as $size)
                    <option value="{{ $size }}" @selected($perPage === $size)>{{ $size }}</option>
                @endforeach
            </select>
            <span>por página</span>
        </form>
        @if(auth()->user()->unreadNotifications()->exists())
            <form method="post" action="{{ route('notifications.read-all') }}">
                @csrf
                <button class="secondary-button" type="submit">Marcar todas como lidas</button>
            </form>
        @endif
    </div>
</div>

<div class="panel notification-panel">
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
                <time>
                    <span>{{ $notification->created_at?->format('d/m/Y') }}</span>
                    <span>{{ $notification->created_at?->format('H:i') }}</span>
                </time>
            </button>
        </form>
    @empty
        <div class="notification-empty">
            <strong>Nenhuma notificação por aqui.</strong>
            <span class="muted">Quando houver novidades nos tickets que você acompanha, elas aparecerão nesta página.</span>
        </div>
    @endforelse
</div>

@if($notifications->total() > 0)
    <div class="notification-pagination" aria-label="Paginação das notificações">
        <div class="notification-pagination-summary">
            Mostrando {{ $notifications->firstItem() }} a {{ $notifications->lastItem() }} de {{ $notifications->total() }} notificações
        </div>

        @if($notifications->hasPages())
            <nav class="notification-pagination-controls" aria-label="Navegação entre páginas">
                @if($notifications->onFirstPage())
                    <span class="pagination-button disabled" aria-disabled="true">Anterior</span>
                @else
                    <a class="pagination-button" href="{{ $notifications->previousPageUrl() }}" rel="prev">Anterior</a>
                @endif

                <div class="pagination-pages">
                    @if(count($paginationPages) > 0 && $paginationPages[0] > 1)
                        <a class="pagination-page" href="{{ $notifications->url(1) }}">1</a>
                        @if($paginationPages[0] > 2)
                            <span class="pagination-gap">…</span>
                        @endif
                    @endif

                    @foreach($paginationPages as $pageNumber)
                        @if($pageNumber === $notifications->currentPage())
                            <span class="pagination-page active" aria-current="page">{{ $pageNumber }}</span>
                        @else
                            <a class="pagination-page" href="{{ $notifications->url($pageNumber) }}">{{ $pageNumber }}</a>
                        @endif
                    @endforeach

                    @if(count($paginationPages) > 0 && $paginationPages[count($paginationPages) - 1] < $notifications->lastPage())
                        @if($paginationPages[count($paginationPages) - 1] < $notifications->lastPage() - 1)
                            <span class="pagination-gap">…</span>
                        @endif
                        <a class="pagination-page" href="{{ $notifications->url($notifications->lastPage()) }}">{{ $notifications->lastPage() }}</a>
                    @endif
                </div>

                @if($notifications->hasMorePages())
                    <a class="pagination-button" href="{{ $notifications->nextPageUrl() }}" rel="next">Próxima</a>
                @else
                    <span class="pagination-button disabled" aria-disabled="true">Próxima</span>
                @endif
            </nav>
        @endif
    </div>
@endif

<style>
.notifications-page-head{gap:16px}.notification-head-actions{display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-wrap:wrap}.notification-page-size-form{display:flex;align-items:center;gap:7px;color:var(--muted);font-size:.84rem}.notification-page-size-form select{width:auto;min-width:68px;padding:8px 28px 8px 10px}.notification-panel{padding:0;overflow:hidden}.notification-row{border-bottom:1px solid rgba(148,163,184,.16)}.notification-row:last-child{border-bottom:0}.notification-row.unread{background:rgba(124,58,237,.08)}.notification-button{width:100%;border:0;background:transparent;color:inherit;text-align:left;display:flex;justify-content:space-between;align-items:flex-start;gap:18px;padding:16px 18px;cursor:pointer}.notification-button:hover{background:rgba(124,58,237,.04)}.notification-copy{display:flex;flex-direction:column;gap:5px;min-width:0}.notification-copy strong{line-height:1.35}.notification-copy small,time{color:var(--muted,#64748b)}.notification-copy small{line-height:1.45}.notification-button time{display:grid;gap:2px;justify-items:end;white-space:nowrap;font-size:.82rem}.notification-empty{display:grid;gap:5px;padding:32px 20px;text-align:center}.notification-pagination{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin-top:14px}.notification-pagination-summary{font-size:.84rem;color:var(--muted)}.notification-pagination-controls{display:flex;align-items:center;gap:8px}.pagination-pages{display:flex;align-items:center;gap:4px}.pagination-button,.pagination-page{display:inline-flex;align-items:center;justify-content:center;min-height:36px;border:1px solid #ded6e5;border-radius:9px;background:#fff;color:#4a355f;text-decoration:none;font-size:.84rem;font-weight:650;padding:0 11px}.pagination-page{min-width:36px;padding:0 8px}.pagination-page.active{background:#6d28d9;color:#fff;border-color:#6d28d9}.pagination-button.disabled{opacity:.45;cursor:default}.pagination-gap{padding:0 2px;color:var(--muted)}
@media(max-width:700px){.notifications-page-head{display:grid}.notification-head-actions{justify-content:flex-start}.notification-page-size-form{width:100%;justify-content:flex-start}.notification-button{padding:14px 13px;gap:10px}.notification-button time{font-size:.75rem}.notification-pagination{align-items:flex-start}.notification-pagination-summary{width:100%}.notification-pagination-controls{width:100%;justify-content:space-between}.pagination-pages{gap:2px}.pagination-page{min-width:32px;min-height:34px}.pagination-button{min-height:34px;padding:0 9px}}
</style>
@endsection
