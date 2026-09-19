@extends('layouts.app')
@section('title','#'.$ticket->number.' — Sutoorii Tickets')
@section('content')
<style>
.requester-shell{width:min(920px,100%);margin:28px auto;display:grid;gap:18px}.requester-brand{display:flex;align-items:center;gap:12px}.requester-brand .brand-mark{display:grid;place-items:center;width:42px;height:42px;border-radius:13px;background:#2d1743;color:#fff;font-weight:800}.requester-back{display:inline-flex;align-items:center;gap:7px;color:#654681;text-decoration:none;font-weight:700;font-size:.9rem}.requester-head{display:flex;justify-content:space-between;align-items:flex-start;gap:18px}.requester-head h1{margin:.2rem 0 .45rem}.requester-status{padding:7px 11px;border-radius:999px;background:#eee8f8;color:#553774;font-size:.8rem;font-weight:800;white-space:nowrap}.requester-card{padding:20px;border:1px solid #e8e3ec;border-radius:16px;background:#fff;box-shadow:0 10px 32px rgba(50,31,69,.05)}.requester-meta{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:18px 0 0}.requester-meta div{padding:12px;border-radius:12px;background:#faf8fc}.requester-meta small{display:block;color:#7a7180;margin-bottom:4px}.requester-description{white-space:pre-wrap;line-height:1.6;color:#3d3542}.requester-comments{display:grid;gap:12px}.requester-comment{padding:14px 16px;border:1px solid #eee9f1;border-radius:14px;background:#fff}.requester-comment.requester-own{background:#f8f3fc;border-color:#e5d8ef}.requester-comment-head{display:flex;justify-content:space-between;gap:12px;margin-bottom:7px}.requester-comment-head small{color:#817888}.requester-comment p{margin:0;white-space:pre-wrap;line-height:1.55}.requester-form textarea{width:100%;min-height:120px;resize:vertical}.requester-form .button{margin-top:10px}.requester-empty{padding:20px;border:1px dashed #dcd4e4;border-radius:14px;color:#746c7b;text-align:center}@media(max-width:620px){.requester-shell{margin:14px auto}.requester-head{display:grid}.requester-status{justify-self:start}.requester-meta{grid-template-columns:1fr}.requester-card{padding:16px}}
</style>
<div class="requester-shell">
    <div class="requester-brand"><span class="brand-mark">S</span><div><strong>Sutoorii Tickets</strong><small style="display:block;color:#746c7b">Acompanhamento de solicitações</small></div></div>

    <a class="requester-back" href="{{ \Illuminate\Support\Facades\URL::signedRoute('requester.index',['email'=>$email]) }}">← Minhas solicitações</a>

    <section class="requester-card">
        <div class="requester-head">
            <div>
                <p class="eyebrow">TICKET #{{ $ticket->number }}</p>
                <h1>{{ $ticket->title }}</h1>
                <p class="muted">Aberto em {{ $ticket->created_at?->format('d/m/Y H:i') }}</p>
            </div>
            <span class="requester-status">{{ $ticket->status?->name ?? 'Sem status' }}</span>
        </div>

        <div class="requester-meta">
            <div><small>Departamento</small><strong>{{ $ticket->department?->name ?? 'Não definido' }}</strong></div>
            <div><small>Prioridade</small><strong>{{ ['low'=>'Baixa','normal'=>'Normal','high'=>'Alta','urgent'=>'Urgente'][$ticket->priority] ?? ucfirst($ticket->priority) }}</strong></div>
            <div><small>Prazo</small><strong>{{ $ticket->due_at?->format('d/m/Y') ?? 'Sem prazo' }}</strong></div>
        </div>
    </section>

    <section class="requester-card">
        <p class="eyebrow">SOLICITAÇÃO</p>
        <h2>Descrição</h2>
        <div class="requester-description">{{ $ticket->description }}</div>
    </section>

    <section class="requester-card">
        <p class="eyebrow">CONVERSA</p>
        <h2>Comentários públicos</h2>
        <div class="requester-comments">
            @forelse($ticket->comments as $comment)
                <div class="requester-comment {{ $comment->source === 'requester' ? 'requester-own' : '' }}">
                    <div class="requester-comment-head">
                        <strong>{{ $comment->source === 'requester' ? ($ticket->requester_name ?: 'Solicitante') : ($comment->user?->name ?: 'Equipe Sutoorii') }}</strong>
                        <small>{{ $comment->created_at?->format('d/m/Y H:i') }}</small>
                    </div>
                    <p>{{ $comment->body }}</p>
                </div>
            @empty
                <div class="requester-empty">Ainda não há comentários públicos neste ticket.</div>
            @endforelse
        </div>
    </section>

    <section class="requester-card">
        <p class="eyebrow">RESPONDER</p>
        <h2>Adicionar comentário</h2>
        @if($errors->any())
            <div class="alert error-box"><strong>Não foi possível enviar a resposta.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        <form class="form requester-form" method="post" action="{{ \Illuminate\Support\Facades\URL::signedRoute('requester.comments.store',['ticket'=>$ticket->id,'email'=>$email]) }}">
            @csrf
            <label>Mensagem<textarea name="body" maxlength="10000" required placeholder="Escreva sua resposta...">{{ old('body') }}</textarea></label>
            <button class="button" type="submit">Enviar resposta</button>
        </form>
    </section>
</div>
@endsection
