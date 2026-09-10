@extends('layouts.app')
@section('title','#'.$ticket->number.' — '.$ticket->title)
@section('content')
@php
    $me = auth()->user();
    $canContent = $me->hasPermission('tickets.edit');
    $canPriority = $me->hasPermission('tickets.change_priority');
    $canStatus = $me->hasPermission('tickets.change_status');
    $canDue = $me->hasPermission('tickets.change_due_date');
    $canUpdate = $canContent || $canPriority || $canStatus || $canDue;
    $canComment = $me->hasPermission('tickets.comment');
    $canInternal = $me->hasPermission('tickets.internal_note');
    $eventLabels = [
        'created'=>'Ticket criado','assumed'=>'Ticket assumido','reassigned'=>'Responsável alterado','forwarded'=>'Ticket encaminhado',
        'participant.added'=>'Participante adicionado','participant.removed'=>'Participante removido','ticket.updated'=>'Ticket atualizado',
        'checklist.added'=>'Item de checklist adicionado','checklist.toggled'=>'Checklist atualizado','checklist.removed'=>'Item de checklist removido',
        'comment.public'=>'Comentário público adicionado','comment.internal'=>'Nota interna adicionada','completion.requested'=>'Conclusão solicitada',
        'resolved'=>'Ticket resolvido','closed'=>'Ticket fechado','cancelled'=>'Ticket cancelado','reopened'=>'Ticket reaberto'
    ];
@endphp

<div class="page-head ticket-head">
    <div><p class="eyebrow">TICKET #{{ $ticket->number }}</p><h1>{{ $ticket->title }}</h1><p class="muted head-sub">{{ $ticket->department?->name ?? 'Sem departamento' }} · {{ $ticket->assignee?->name ?? 'Sem responsável' }}</p></div>
    <span class="status large" style="--status:{{ $ticket->status?->color ?? '#6d28d9' }}">{{ $ticket->status?->name ?? 'Sem status' }}</span>
</div>

@if($errors->any())
<div class="alert error-box"><strong>Não foi possível concluir a ação.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

<div class="ticket-layout workspace">
<section>
    <article class="panel">
        <div class="section-title"><div><p class="eyebrow">DADOS</p><h2>Informações do ticket</h2></div></div>
        @if($canUpdate)
        <form action="{{ route('tickets.update',$ticket) }}" method="post" class="form compact-form">
            @csrf @method('PATCH')
            <label>Título<input name="title" value="{{ old('title',$ticket->title) }}" @readonly(!$canContent) required></label>
            <label>Descrição<textarea name="description" rows="5" @readonly(!$canContent) required>{{ old('description',$ticket->description) }}</textarea></label>
            <div class="grid form-grid">
                <label>Prioridade
                    <select name="priority" @disabled(!$canPriority)>
                        @foreach(['low'=>'Baixa','normal'=>'Normal','high'=>'Alta','urgent'=>'Urgente'] as $key=>$label)<option value="{{ $key }}" @selected($ticket->priority===$key)>{{ $label }}</option>@endforeach
                    </select>
                    @unless($canPriority)<input type="hidden" name="priority" value="{{ $ticket->priority }}">@endunless
                </label>
                <label>Status
                    <select name="status_id" @disabled(!$canStatus)>
                        @foreach($statuses as $status)<option value="{{ $status->id }}" @selected($ticket->status_id===$status->id)>{{ $status->name }}</option>@endforeach
                    </select>
                    @unless($canStatus)<input type="hidden" name="status_id" value="{{ $ticket->status_id }}">@endunless
                </label>
                <label>Prazo<input type="datetime-local" name="due_at" value="{{ old('due_at',$ticket->due_at?->format('Y-m-d\TH:i')) }}" @readonly(!$canDue)></label>
            </div>
            <div class="actions"><button class="button" type="submit">Salvar alterações</button></div>
        </form>
        @else
            <p class="description-text">{{ $ticket->description }}</p>
        @endif
    </article>

    @if($canComment || $canInternal)
    <article class="panel">
        <div class="section-title"><div><p class="eyebrow">COMUNICAÇÃO</p><h2>Adicionar atividade</h2></div></div>
        <form action="{{ route('tickets.comments.store',$ticket) }}" method="post" class="form">@csrf
            <label>Tipo<select name="visibility">
                @if($canComment)<option value="public">Comentário público</option>@endif
                @if($canInternal)<option value="internal">Nota interna</option>@endif
            </select></label>
            <label>Mensagem<textarea name="body" rows="4" placeholder="Escreva uma atualização..." required></textarea></label>
            <div class="actions"><button class="button" type="submit">Adicionar</button></div>
        </form>
    </article>
    @endif

    <article class="panel">
        <div class="section-title"><div><p class="eyebrow">ATIVIDADE</p><h2>Comentários e notas</h2></div></div>
        @forelse($ticket->comments->sortByDesc('created_at') as $comment)
            <div class="comment activity-row"><div class="avatar">{{ strtoupper(substr($comment->user?->name ?? $ticket->requester_name ?? '?',0,1)) }}</div><div class="activity-body"><div><b>{{ $comment->user?->name ?? $ticket->requester_name ?? 'Solicitante' }}</b><span class="activity-badge {{ $comment->visibility==='internal'?'internal':'' }}">{{ $comment->visibility==='public'?'Público':'Nota interna' }}</span><small>{{ $comment->created_at?->format('d/m/Y H:i') }}</small></div><p>{{ $comment->body }}</p></div></div>
        @empty<p class="muted">Nenhum comentário ou nota ainda.</p>@endforelse
    </article>

    <article class="panel">
        <div class="section-title"><div><p class="eyebrow">HISTÓRICO</p><h2>Linha do tempo do ticket</h2></div></div>
        <div class="timeline">
        @forelse($ticket->events->sortByDesc('created_at') as $event)
            <div class="timeline-item"><span class="timeline-dot"></span><div><b>{{ $eventLabels[$event->event] ?? $event->event }}</b><p class="muted">{{ $event->actor?->name ?? 'Sistema' }} · {{ $event->created_at?->format('d/m/Y H:i') }}</p>
                @if($event->event==='forwarded' && is_array($event->data))<small>{{ $event->data['old_department_name'] ?? 'Origem' }} → {{ $event->data['new_department_name'] ?? 'Destino' }}</small>@endif
                @if($event->event==='reassigned' && is_array($event->data))<small>{{ $event->data['old_assignee_name'] ?? 'Sem responsável' }} → {{ $event->data['new_assignee_name'] ?? 'Novo responsável' }}</small>@endif
            </div></div>
        @empty<p class="muted">O histórico começará a aparecer conforme o ticket for movimentado.</p>@endforelse
        </div>
    </article>
</section>

<aside class="sidebar-stack">
    <article class="panel details">
        <div class="section-title"><h2>Detalhes</h2></div>
        <dl><dt>Prioridade</dt><dd>{{ ['low'=>'Baixa','normal'=>'Normal','high'=>'Alta','urgent'=>'Urgente'][$ticket->priority] ?? ucfirst($ticket->priority) }}</dd><dt>Departamento</dt><dd>{{ $ticket->department?->name ?? 'Não definido' }}</dd><dt>Responsável</dt><dd>{{ $ticket->assignee?->name ?? 'Não atribuído' }}</dd><dt>Prazo</dt><dd>{{ $ticket->due_at?->format('d/m/Y H:i') ?? 'Sem prazo' }}</dd><dt>Origem</dt><dd>{{ $ticket->origin==='internal'?'Interno':'Integração' }}</dd></dl>

        @if(!$ticket->assignee && $ticket->department_id && $me->department_id===$ticket->department_id && $me->hasPermission('tickets.assume'))
        <form method="post" action="{{ route('tickets.assume',$ticket) }}">@csrf<button class="button full" type="submit">Assumir ticket</button></form>
        @endif

        @if($me->hasPermission('tickets.reassign'))
        <form method="post" action="{{ route('tickets.reassign',$ticket) }}" class="mini-form">@csrf @method('PATCH')<label>Alterar responsável<select name="user_id" required><option value="">Selecione...</option>@foreach($users as $user)<option value="{{ $user->id }}" @selected($ticket->assignee_id===$user->id)>{{ $user->name }}</option>@endforeach</select></label><button class="secondary-button full" type="submit">Atribuir</button></form>
        @endif

        @if($me->hasPermission('tickets.forward'))
        <form method="post" action="{{ route('tickets.forward',$ticket) }}" class="mini-form">@csrf<label>Encaminhar para<select name="department_id" required><option value="">Selecione...</option>@foreach($departments as $department)@if($department->id!==$ticket->department_id)<option value="{{ $department->id }}">{{ $department->name }}</option>@endif @endforeach</select></label><input type="text" name="reason" placeholder="Motivo (opcional)"><button class="secondary-button full" type="submit">Encaminhar</button></form>
        @endif
    </article>

    <article class="panel">
        <div class="section-title"><h2>Checklist</h2><span class="counter">{{ $ticket->checklist->where('completed',true)->count() }}/{{ $ticket->checklist->count() }}</span></div>
        @forelse($ticket->checklist as $item)
        <div class="check-row"><form method="post" action="{{ route('tickets.checklist.toggle',[$ticket,$item]) }}">@csrf @method('PATCH')<button class="check-button" type="submit" @disabled(!$me->hasPermission('tickets.manage_checklist'))>{{ $item->completed?'✓':'○' }}</button></form><div><span class="{{ $item->completed?'done':'' }}">{{ $item->text }}</span>@if($item->required)<small>Obrigatório</small>@endif</div>@if($me->hasPermission('tickets.manage_checklist'))<form method="post" action="{{ route('tickets.checklist.destroy',[$ticket,$item]) }}" class="push-right">@csrf @method('DELETE')<button class="icon-button" title="Remover">×</button></form>@endif</div>
        @empty<p class="muted">Sem itens.</p>@endforelse
        @if($me->hasPermission('tickets.manage_checklist'))<form method="post" action="{{ route('tickets.checklist.store',$ticket) }}" class="mini-form">@csrf<input name="text" placeholder="Novo item" required><label class="inline-check"><input type="checkbox" name="required" value="1"> Obrigatório</label><button class="secondary-button full">Adicionar item</button></form>@endif
    </article>

    <article class="panel">
        <div class="section-title"><h2>Participantes</h2></div>
        @forelse($ticket->participants as $participant)<div class="person-row"><div><b>{{ $participant->name }}</b><small>{{ $participant->pivot->type==='collaborator'?'Colaborador':'Seguidor' }}</small></div>@if($me->hasPermission('tickets.manage_participants'))<form method="post" action="{{ route('tickets.participants.destroy',[$ticket,$participant]) }}">@csrf @method('DELETE')<button class="icon-button">×</button></form>@endif</div>@empty<p class="muted">Nenhum participante.</p>@endforelse
        @if($me->hasPermission('tickets.manage_participants'))<form method="post" action="{{ route('tickets.participants.store',$ticket) }}" class="mini-form">@csrf<select name="user_id" required><option value="">Adicionar usuário...</option>@foreach($users as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach</select><select name="type"><option value="collaborator">Colaborador</option><option value="follower">Seguidor</option></select><button class="secondary-button full">Adicionar</button></form>@endif
    </article>

    <article class="panel danger-zone">
        <div class="section-title"><h2>Ações</h2></div>
        <div class="action-stack">
        @if($me->hasPermission('tickets.request_completion') && ($ticket->assignee_id===$me->id || $ticket->participants->contains(fn($p)=>$p->id===$me->id && $p->pivot->type==='collaborator')))<form method="post" action="{{ route('tickets.completion.request',$ticket) }}">@csrf<button class="secondary-button full">Solicitar conclusão</button></form>@endif
        @if($me->hasPermission('tickets.resolve') && $ticket->assignee_id===$me->id && !in_array($ticket->status?->system_key,['resolved','closed','cancelled']))<form method="post" action="{{ route('tickets.resolve',$ticket) }}">@csrf<button class="button full">Resolver ticket</button></form>@endif
        @if($me->hasPermission('tickets.close') && $ticket->status?->system_key==='resolved')<form method="post" action="{{ route('tickets.close',$ticket) }}">@csrf<button class="secondary-button full">Fechar ticket</button></form>@endif
        @if($me->hasPermission('tickets.cancel') && $ticket->status?->system_key!=='cancelled')<form method="post" action="{{ route('tickets.cancel',$ticket) }}">@csrf<button class="danger-button full">Cancelar ticket</button></form>@endif
        @if($me->hasPermission('tickets.reopen') && in_array($ticket->status?->system_key,['resolved','closed','cancelled']))<form method="post" action="{{ route('tickets.reopen',$ticket) }}">@csrf<button class="secondary-button full">Reabrir ticket</button></form>@endif
        </div>
    </article>
</aside>
</div>
@endsection
