@extends('layouts.app')
@section('title','#'.$ticket->number.' — '.$ticket->title)
@section('content')
<link rel="stylesheet" href="{{ asset('css/user-picker.css') }}?v={{ filemtime(public_path('css/user-picker.css')) }}">
<link rel="stylesheet" href="{{ asset('css/ticket-workspace.css') }}?v={{ filemtime(public_path('css/ticket-workspace.css')) }}">
@php
    $me = auth()->user();
    $departmentAccess = app(\App\Services\DepartmentAccess::class);
    $canDepartmentEdit = !$ticket->department_id || $departmentAccess->canEdit($me, $ticket->department_id);
    $canDepartmentView = !$ticket->department_id || $departmentAccess->canView($me, $ticket->department_id);
    $isRequester = (int) $ticket->requester_user_id === (int) $me->id;
    $canContent = $me->hasPermission('tickets.edit') && ($canDepartmentEdit || $ticket->assignee_id === $me->id);
    $canPriority = $me->hasPermission('tickets.change_priority') && ($canDepartmentEdit || $ticket->assignee_id === $me->id);
    $canStatus = $me->hasPermission('tickets.change_status') && ($canDepartmentEdit || $ticket->assignee_id === $me->id);
    $canDue = $me->hasPermission('tickets.change_due_date') && ($canDepartmentEdit || $ticket->assignee_id === $me->id);
    $canUpdate = $canContent || $canPriority || $canStatus || $canDue;
    $canComment = $me->hasPermission('tickets.comment') || $isRequester;
    $canInternal = $me->hasPermission('tickets.internal_note');
    $canLabels = $me->hasPermission('tickets.manage_labels');
    $canManagePeople = $me->hasPermission('tickets.manage_participants') && $canDepartmentEdit;
    $canAttachments = $me->hasPermission('tickets.manage_attachments');
    $canRecurrence = $me->hasPermission('tickets.recurrence');
    $canReassign = $me->hasPermission('tickets.reassign') && $canDepartmentEdit;
    $canForward = $me->hasPermission('tickets.forward') && $canDepartmentEdit;
    $canAssume = !$ticket->assignee && $ticket->department_id && $canDepartmentView && $me->hasPermission('tickets.assume');
    $hasRequesterEmail = filled($ticket->requester_email);
    $activeAttachments = $ticket->attachments->whereNull('deleted_at');
    $recurrence = $ticket->recurrence;
    $priorityLabel = ['low'=>'Baixa','normal'=>'Normal','high'=>'Alta','urgent'=>'Urgente'][$ticket->priority] ?? ucfirst($ticket->priority);
    $eventLabels = [
        'created'=>'Ticket criado','assumed'=>'Ticket assumido','reassigned'=>'Responsável alterado','forwarded'=>'Ticket encaminhado',
        'participant_added'=>'Participante adicionado','participant_changed'=>'Participante atualizado','participant_removed'=>'Participante removido','ticket.updated'=>'Ticket atualizado',
        'checklist.added'=>'Item de checklist adicionado','checklist.toggled'=>'Checklist atualizado','checklist.removed'=>'Item de checklist removido',
        'comment.public'=>'Comentário público adicionado','comment.internal'=>'Nota interna adicionada','completion.requested'=>'Conclusão solicitada',
        'label.added'=>'Etiqueta adicionada','label.removed'=>'Etiqueta removida','attachment.added'=>'Anexo adicionado','attachment.removed'=>'Anexo removido',
        'recurrence.configured'=>'Recorrência configurada','recurrence.removed'=>'Recorrência removida','recurrence_created'=>'Ticket criado por recorrência',
        'deadline.reminder'=>'Lembrete de prazo enviado','resolved'=>'Ticket resolvido','closed'=>'Ticket fechado','cancelled'=>'Ticket cancelado','reopened'=>'Ticket reaberto',
        'status.changed'=>'Status atualizado'
    ];
    $attachedLabelIds = $ticket->labels->pluck('id')->all();
    $availableLabels = $labels->whereNotIn('id', $attachedLabelIds);
    $completedChecklist = $ticket->checklist->where('completed',true)->count();
    $recurrenceSummary = $recurrence
        ? (($recurrence->active ? 'Ativa' : 'Pausada').' · '.match($recurrence->frequency){'daily'=>'Diária','weekly'=>'Semanal','monthly'=>'Mensal',default=>ucfirst($recurrence->frequency)})
        : 'Sem recorrência';
@endphp

<div class="page-head ticket-head ticket-head-v2">
    <div class="ticket-head-copy">
        <p class="eyebrow">TICKET #{{ $ticket->number }}</p>
        <h1>{{ $ticket->title }}</h1>
        <p class="muted head-sub">{{ $ticket->department?->name ?? 'Sem departamento' }} · {{ $ticket->assignee?->name ?? 'Sem responsável' }}</p>
        @if($ticket->labels->isNotEmpty())
            <div class="ticket-labels">@foreach($ticket->labels->sortBy('name') as $label)<span class="label-chip" style="--label-color:{{ $label->color }}">{{ $label->name }}</span>@endforeach</div>
        @endif
    </div>
    <span class="status large" style="--status:{{ $ticket->status?->color ?? '#6d28d9' }}">{{ $ticket->status?->name ?? 'Sem status' }}</span>
</div>

<div class="ticket-summary-strip" aria-label="Resumo do ticket">
    <div class="ticket-summary-item"><small>Prioridade</small><strong>{{ $priorityLabel }}</strong></div>
    <div class="ticket-summary-item"><small>Responsável</small><strong>{{ $ticket->assignee?->name ?? 'Não atribuído' }}</strong></div>
    <div class="ticket-summary-item"><small>Prazo</small><strong>{{ $ticket->due_at?->format('d/m/Y') ?? 'Sem prazo' }}</strong></div>
    <div class="ticket-summary-item"><small>Solicitante</small><strong>{{ $ticket->requester_name ?? 'Não informado' }}</strong></div>
</div>

@if($errors->any())
<div class="alert error-box"><strong>Não foi possível concluir a ação.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

<div class="ticket-layout workspace ticket-layout-v2">
<section class="ticket-primary-column">
    @if($canUpdate)
    <form action="{{ route('tickets.update',$ticket) }}" method="post" id="ticketUnifiedForm" class="ticket-unified-form">
        @csrf @method('PATCH')
    @endif
    <article class="panel ticket-conversation-panel" data-ticket-conversation>
        <div class="ticket-conversation-head">
            <div>
                <p class="eyebrow">CONVERSA</p>
                <h2>Comentários e notas</h2>
            </div>
            <span class="counter">{{ $ticket->comments->count() }}</span>
        </div>

        <div class="ticket-opening-message">
            <div class="ticket-opening-meta">
                <strong>Solicitação inicial</strong>
                @if($ticket->created_at)<small>{{ $ticket->created_at->format('d/m/Y H:i') }}</small>@endif
            </div>
            <p>{{ $ticket->description }}</p>
        </div>

        <div class="ticket-comment-list" data-ticket-comment-list>
            @forelse($ticket->comments->sortBy('created_at') as $comment)
                <div class="comment activity-row">
                    <div class="avatar">{{ strtoupper(substr($comment->user?->name ?? $ticket->requester_name ?? '?',0,1)) }}</div>
                    <div class="activity-body">
                        <div>
                            <b>{{ $comment->user?->name ?? $ticket->requester_name ?? 'Solicitante' }}</b>
                            <span class="activity-badge {{ $comment->visibility==='internal'?'internal':'' }}">{{ $comment->visibility==='public'?'Público':'Nota interna' }}</span>
                            <small>{{ $comment->created_at?->format('d/m/Y H:i') }}</small>
                        </div>
                        <p>{{ $comment->body }}</p>
                    </div>
                </div>
            @empty
                <p class="ticket-empty-conversation muted">Nenhum comentário ou nota ainda.</p>
            @endforelse
        </div>

        @if($canComment || $canInternal)
        <div class="ticket-composer" data-ticket-composer>
            @unless($canUpdate)<form action="{{ route('tickets.comments.store',$ticket) }}" method="post" class="form ticket-composer-form" id="ticketActivityForm">@csrf@endunless
                <div class="ticket-composer-main">
                    <label class="ticket-composer-type">Tipo
                        <select name="{{ $canUpdate ? 'comment_visibility' : 'visibility' }}" id="ticketActivityVisibility">
                            @if($canComment)<option value="public">Comentário público</option>@endif
                            @if($canInternal)<option value="internal">Nota interna</option>@endif
                        </select>
                    </label>
                    <label class="ticket-composer-message">Mensagem
                        <textarea name="{{ $canUpdate ? 'comment_body' : 'body' }}" rows="4" placeholder="Escreva uma resposta..." @required(!$canUpdate)>{{ old('comment_body') }}</textarea>
                    </label>
                </div>

                @if($hasRequesterEmail || $ticket->assignee || $ticket->participants->isNotEmpty())
                <details class="composer-options" id="commentNotifyOptions">
                    <summary>Notificações desta resposta</summary>
                    <div class="notify-options composer-notify-grid">
                        <span>Enviar e-mail para</span>
                        @if($hasRequesterEmail)<label><input type="checkbox" name="{{ $canUpdate ? 'comment_notify_requester' : 'notify_requester' }}" value="1" checked> Solicitante</label>@endif
                        @if($ticket->assignee)<label><input type="checkbox" name="notify_responsible" value="1"> Responsável</label>@endif
                        @if($ticket->participants->where('pivot.type','collaborator')->isNotEmpty())<label><input type="checkbox" name="notify_collaborators" value="1"> Colaboradores</label>@endif
                        @if($ticket->participants->where('pivot.type','follower')->isNotEmpty())<label><input type="checkbox" name="notify_followers" value="1"> Seguidores</label>@endif
                    </div>
                </details>
                @endif

                @unless($canUpdate)
                <div class="ticket-composer-actions"><button class="button" type="submit">Salvar</button></div>
                </form>
                @endunless
        </div>
        @endif
    </article>

    <details class="ticket-disclosure" data-ticket-tool="details">
        <summary class="ticket-disclosure-summary">
            <span class="ticket-disclosure-copy"><strong>Informações do ticket</strong><small>Descrição, prioridade, status e prazo</small></span>
            <span class="ticket-disclosure-chevron" aria-hidden="true">›</span>
        </summary>
        <div class="ticket-disclosure-content">
            @if($canUpdate)
            <div class="form compact-form">
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
                @if($hasRequesterEmail)
                <div class="notify-options"><span>Notificação</span><label><input type="hidden" name="notify_requester" value="0"><input type="checkbox" name="notify_requester" value="1" checked> Notificar solicitante sobre esta alteração</label></div>
                @endif
            </div>
            @else
                <p class="description-text">{{ $ticket->description }}</p>
            @endif
        </div>
    </details>
    @if($canUpdate)
        <div class="ticket-unified-actions"><button class="button" type="submit">Salvar alterações</button></div>
    </form>
    @endif

    <details class="ticket-disclosure" data-ticket-tool="history">
        <summary class="ticket-disclosure-summary">
            <span class="ticket-disclosure-copy"><strong>Histórico</strong><small>{{ $ticket->events->where('event','!=','created')->count() + 1 }} movimentações registradas</small></span>
            <span class="ticket-disclosure-chevron" aria-hidden="true">›</span>
        </summary>
        <div class="ticket-disclosure-content">
            <div class="timeline">
            @foreach($ticket->events->where('event','!=','created')->sortByDesc('created_at') as $event)
                <div class="timeline-item"><span class="timeline-dot"></span><div><b>{{ $eventLabels[$event->event] ?? $event->event }}</b><p class="muted">{{ $event->actor?->name ?? 'Sistema' }} · {{ $event->created_at?->format('d/m/Y H:i') }}</p>
                    @if($event->event==='forwarded' && is_array($event->data))<small>{{ $event->data['from_department_name'] ?? 'Origem' }} → {{ $event->data['to_department_name'] ?? 'Destino' }}</small>@endif
                    @if($event->event==='reassigned' && is_array($event->data))<small>{{ $event->data['old_assignee_name'] ?? 'Sem responsável' }} → {{ $event->data['new_assignee_name'] ?? 'Novo responsável' }}</small>@endif
                    @if(in_array($event->event,['label.added','label.removed'],true) && is_array($event->data))<small>{{ $event->data['label_name'] ?? 'Etiqueta' }}</small>@endif
                    @if(in_array($event->event,['attachment.added','attachment.removed'],true) && is_array($event->data))<small>{{ $event->data['name'] ?? 'Arquivo' }}</small>@endif
                </div></div>
            @endforeach
            <div class="timeline-item" data-ticket-creation>
                <span class="timeline-dot"></span>
                <div>
                    <b>Ticket criado em {{ $ticket->created_at?->format('d/m/Y') }} às {{ $ticket->created_at?->format('H:i') }}</b>
                    <p class="muted">Origem: {{ $ticket->origin === 'integration' ? 'integração '.($ticket->system?->name ?? 'sistema externo') : 'painel Sutoorii Tickets'.($ticket->system ? ' para '.$ticket->system->name : '') }}</p>
                    <small>{{ $ticket->creator?->name ?? $ticket->requester_name ?? 'Solicitante externo' }}</small>
                </div>
            </div>
            </div>
        </div>
    </details>
</section>

<aside class="sidebar-stack ticket-tools">
    <details class="ticket-disclosure" data-ticket-tool="service">
        <summary class="ticket-disclosure-summary">
            <span class="ticket-disclosure-copy"><strong>Atendimento</strong><small>{{ $ticket->department?->name ?? 'Sem departamento' }} · {{ $ticket->assignee?->name ?? 'Não atribuído' }}</small></span>
            <span class="ticket-disclosure-chevron" aria-hidden="true">›</span>
        </summary>
        <div class="ticket-disclosure-content details">
            <dl>
                <dt>Prioridade</dt><dd>{{ $priorityLabel }}</dd>
                <dt>Departamento</dt><dd>{{ $ticket->department?->name ?? 'Não definido' }}</dd>
                <dt>Responsável</dt><dd>{{ $ticket->assignee?->name ?? 'Não atribuído' }}</dd>
                <dt>Prazo</dt><dd>{{ $ticket->due_at?->format('d/m/Y') ?? 'Sem prazo' }}</dd>
                <dt>Origem</dt><dd>{{ $ticket->origin==='internal'?'Interno':'Integração' }}</dd>
                @if($ticket->system)<dt>Integração</dt><dd>{{ $ticket->system->name }}</dd>@endif
                @if($ticket->requester_name)<dt>Solicitante</dt><dd>{{ $ticket->requester_name }}@if($ticket->requester_email)<small style="display:block">{{ $ticket->requester_email }}</small>@endif</dd>@endif
            </dl>

            @if($canAssume)
            <form method="post" action="{{ route('tickets.assume',$ticket) }}">@csrf<button class="button full" type="submit">Assumir ticket</button></form>
            @endif

            @if($canReassign)
            <form method="post" action="{{ route('tickets.reassign',$ticket) }}" class="mini-form">@csrf @method('PATCH')
                <label>Alterar responsável</label>
                <div class="user-picker mini-user-picker" data-user-picker data-field="user_id" data-multiple="false">
                    @if($ticket->assignee)<span data-preselected-user data-id="{{ $ticket->assignee->id }}" data-name="{{ $ticket->assignee->name }}" data-email="{{ $ticket->assignee->email }}"></span>@endif
                    <input type="search" data-user-search autocomplete="off" placeholder="Buscar por nome ou e-mail">
                    <div class="user-picker-results" data-user-results></div><div class="user-picker-selected" data-user-selected></div>
                </div>
                <button class="secondary-button full" type="submit">Atribuir</button>
            </form>
            @endif

            @if($canForward)
            <form method="post" action="{{ route('tickets.forward',$ticket) }}" class="mini-form">@csrf
                <label>Encaminhar para<select name="department_id" required><option value="">Selecione...</option>@foreach($departments as $department)@if($department->id!==$ticket->department_id)<option value="{{ $department->id }}">{{ $department->name }}</option>@endif @endforeach</select></label>
                <input type="text" name="reason" placeholder="Motivo (opcional)">
                @if($hasRequesterEmail)<label class="inline-check"><input type="hidden" name="notify_requester" value="0"><input type="checkbox" name="notify_requester" value="1" checked> Notificar solicitante</label>@endif
                <button class="secondary-button full" type="submit">Encaminhar</button>
            </form>
            @endif
        </div>
    </details>

    <details class="ticket-disclosure" data-ticket-tool="labels">
        <summary class="ticket-disclosure-summary">
            <span class="ticket-disclosure-copy"><strong>Etiquetas</strong><small>{{ $ticket->labels->count() ? $ticket->labels->pluck('name')->join(', ') : 'Nenhuma etiqueta' }}</small></span>
            <span class="ticket-disclosure-chevron" aria-hidden="true">›</span>
        </summary>
        <div class="ticket-disclosure-content ticket-label-panel">
            <div class="ticket-labels ticket-labels-large">
                @forelse($ticket->labels->sortBy('name') as $label)
                    <span class="ticket-label-token" style="--label-color:{{ $label->color }}"><span>{{ $label->name }}</span>@if($canLabels)<form method="post" action="{{ route('tickets.labels.destroy',[$ticket,$label]) }}">@csrf @method('DELETE')<button class="label-remove-button" type="submit" title="Remover {{ $label->name }}" aria-label="Remover etiqueta {{ $label->name }}">×</button></form>@endif</span>
                @empty<span class="muted">Nenhuma etiqueta.</span>@endforelse
            </div>
            @if($canLabels)
                <div class="label-manager"><h3>Gerenciar etiquetas</h3>
                    @if($availableLabels->isNotEmpty())<form method="post" action="{{ route('tickets.labels.store',$ticket) }}" class="mini-form">@csrf<select name="label_id" required><option value="">Adicionar etiqueta...</option>@foreach($availableLabels as $label)<option value="{{ $label->id }}">{{ $label->name }}</option>@endforeach</select><button class="secondary-button full" type="submit">Adicionar etiqueta</button></form>
                    @elseif($labels->isEmpty())<p class="muted">Nenhuma etiqueta foi cadastrada pela administração.</p>@else<p class="muted">Todas as etiquetas disponíveis já estão neste ticket.</p>@endif
                </div>
            @endif
        </div>
    </details>

    <details class="ticket-disclosure" data-ticket-tool="checklist">
        <summary class="ticket-disclosure-summary">
            <span class="ticket-disclosure-copy"><strong>Checklist</strong><small>{{ $completedChecklist }}/{{ $ticket->checklist->count() }} concluídos</small></span>
            <span class="ticket-disclosure-chevron" aria-hidden="true">›</span>
        </summary>
        <div class="ticket-disclosure-content">
            @forelse($ticket->checklist as $item)
            <div class="check-row"><form method="post" action="{{ route('tickets.checklist.toggle',[$ticket,$item]) }}">@csrf @method('PATCH')<button class="check-button" type="submit" @disabled(!$me->hasPermission('tickets.manage_checklist'))>{{ $item->completed?'✓':'○' }}</button></form><div><span class="{{ $item->completed?'done':'' }}">{{ $item->text }}</span>@if($item->required)<small>Obrigatório</small>@endif</div>@if($me->hasPermission('tickets.manage_checklist'))<form method="post" action="{{ route('tickets.checklist.destroy',[$ticket,$item]) }}" class="push-right">@csrf @method('DELETE')<button class="icon-button" title="Remover">×</button></form>@endif</div>
            @empty<p class="muted">Sem itens.</p>@endforelse
            @if($me->hasPermission('tickets.manage_checklist'))<form method="post" action="{{ route('tickets.checklist.store',$ticket) }}" class="mini-form">@csrf<input name="text" placeholder="Novo item" required><label class="inline-check"><input type="checkbox" name="required" value="1"> Obrigatório</label><button class="secondary-button full">Adicionar item</button></form>@endif
        </div>
    </details>

    <details class="ticket-disclosure" data-ticket-tool="attachments">
        <summary class="ticket-disclosure-summary">
            <span class="ticket-disclosure-copy"><strong>Anexos</strong><small>{{ $activeAttachments->count() }} arquivo(s)</small></span>
            <span class="ticket-disclosure-chevron" aria-hidden="true">›</span>
        </summary>
        <div class="ticket-disclosure-content">
            @forelse($activeAttachments as $attachment)
                <div class="person-row"><div><b>{{ $attachment->original_name }}</b><small>{{ number_format($attachment->size/1024,1,',','.') }} KB · expira {{ $attachment->expires_at?->format('d/m/Y') }}</small></div><div class="attachment-actions"><a class="secondary-button" href="{{ route('tickets.attachments.download',[$ticket,$attachment]) }}">Baixar</a>@if($canAttachments)<form method="post" action="{{ route('tickets.attachments.destroy',[$ticket,$attachment]) }}">@csrf @method('DELETE')<button class="icon-button" type="submit" title="Remover">×</button></form>@endif</div></div>
            @empty<p class="muted">Nenhum anexo.</p>@endforelse
            @if($canAttachments)
            <form method="post" action="{{ route('tickets.attachments.store',$ticket) }}" enctype="multipart/form-data" class="mini-form">@csrf
                <input type="file" name="file" required>
                <small class="muted">Vídeos não são permitidos.</small>
                <button class="secondary-button full" type="submit">Enviar anexo</button>
            </form>
            @endif
        </div>
    </details>

    @if($canRecurrence || $recurrence)
    <details class="ticket-disclosure" data-ticket-tool="recurrence">
        <summary class="ticket-disclosure-summary">
            <span class="ticket-disclosure-copy"><strong>Recorrência</strong><small>{{ $recurrenceSummary }}</small></span>
            <span class="ticket-disclosure-chevron" aria-hidden="true">›</span>
        </summary>
        <div class="ticket-disclosure-content">
            @if($canRecurrence)
            <form method="post" action="{{ route('tickets.recurrence.store',$ticket) }}" class="mini-form">@csrf
                <label>Frequência<select name="frequency" required><option value="daily" @selected(($recurrence?->frequency ?? '')==='daily')>Diária</option><option value="weekly" @selected(($recurrence?->frequency ?? '')==='weekly')>Semanal</option><option value="monthly" @selected(($recurrence?->frequency ?? '')==='monthly')>Mensal</option></select></label>
                <label>Intervalo<input type="number" min="1" max="365" name="interval" value="{{ $recurrence?->interval ?? 1 }}" required></label>
                <div><small>Dias da semana (para recorrência semanal)</small><div class="notify-options recurrence-weekdays">@foreach([0=>'Dom',1=>'Seg',2=>'Ter',3=>'Qua',4=>'Qui',5=>'Sex',6=>'Sáb'] as $day=>$dayName)<label><input type="checkbox" name="weekdays[]" value="{{ $day }}" @checked(in_array($day,$recurrence?->weekdays ?? [],true))> {{ $dayName }}</label>@endforeach</div></div>
                <label>Próxima criação<input type="datetime-local" name="next_run_at" value="{{ $recurrence?->next_run_at?->format('Y-m-d\TH:i') ?? now()->addDay()->format('Y-m-d\TH:i') }}" required></label>
                <label>Encerrar em (opcional)<input type="datetime-local" name="ends_at" value="{{ $recurrence?->ends_at?->format('Y-m-d\TH:i') }}"></label>
                <label class="inline-check"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" @checked(!$recurrence || $recurrence->active)> Ativa</label>
                <button class="secondary-button full" type="submit">Salvar recorrência</button>
            </form>
            @if($recurrence)<form method="post" action="{{ route('tickets.recurrence.destroy',$ticket) }}" class="recurrence-remove">@csrf @method('DELETE')<button class="danger-button full" type="submit">Remover recorrência</button></form>@endif
            @else
                <p class="muted">{{ ucfirst($recurrence->frequency) }} · a cada {{ $recurrence->interval }} · próxima em {{ $recurrence->next_run_at?->format('d/m/Y H:i') }}</p>
            @endif
        </div>
    </details>
    @endif

    <details class="ticket-disclosure" data-ticket-tool="participants">
        <summary class="ticket-disclosure-summary">
            <span class="ticket-disclosure-copy"><strong>Participantes</strong><small>{{ $ticket->participants->count() }} pessoa(s)</small></span>
            <span class="ticket-disclosure-chevron" aria-hidden="true">›</span>
        </summary>
        <div class="ticket-disclosure-content">
            @forelse($ticket->participants as $participant)<div class="person-row"><div><b>{{ $participant->name }}</b><small>{{ $participant->pivot->type==='collaborator'?'Colaborador':'Seguidor' }}</small></div>@if($canManagePeople)<form method="post" action="{{ route('tickets.participants.destroy',[$ticket,$participant]) }}">@csrf @method('DELETE')<button class="icon-button">×</button></form>@endif</div>@empty<p class="muted">Nenhum participante.</p>@endforelse
            @if($canManagePeople)
            <form method="post" action="{{ route('tickets.participants.store',$ticket) }}" class="mini-form">@csrf
                <div class="user-picker mini-user-picker" data-user-picker data-field="user_id" data-multiple="false"><input type="search" data-user-search autocomplete="off" placeholder="Buscar usuário"><div class="user-picker-results" data-user-results></div><div class="user-picker-selected" data-user-selected></div></div>
                <select name="type"><option value="collaborator">Colaborador</option><option value="follower">Seguidor</option></select>
                <button class="secondary-button full">Adicionar</button>
            </form>
            @endif
        </div>
    </details>

    <details class="ticket-disclosure ticket-danger-disclosure" data-ticket-tool="actions">
        <summary class="ticket-disclosure-summary">
            <span class="ticket-disclosure-copy"><strong>Ações do ticket</strong><small>Concluir, fechar, cancelar ou reabrir</small></span>
            <span class="ticket-disclosure-chevron" aria-hidden="true">›</span>
        </summary>
        <div class="ticket-disclosure-content action-stack">
        @if($me->hasPermission('tickets.request_completion') && ($ticket->assignee_id===$me->id || $ticket->participants->contains(fn($p)=>$p->id===$me->id && $p->pivot->type==='collaborator')))
            <form method="post" action="{{ route('tickets.completion.request',$ticket) }}">@csrf @if($hasRequesterEmail)<label class="inline-check"><input type="hidden" name="notify_requester" value="0"><input type="checkbox" name="notify_requester" value="1" checked> Notificar solicitante</label>@endif<button class="secondary-button full">Solicitar conclusão</button></form>
        @endif
        @if($me->hasPermission('tickets.resolve') && $ticket->assignee_id===$me->id && !in_array($ticket->status?->system_key,['resolved','closed','cancelled']))
            <form method="post" action="{{ route('tickets.resolve',$ticket) }}">@csrf @if($hasRequesterEmail)<label class="inline-check"><input type="hidden" name="notify_requester" value="0"><input type="checkbox" name="notify_requester" value="1" checked> Notificar solicitante</label>@endif<button class="button full">Resolver ticket</button></form>
        @endif
        @if($me->hasPermission('tickets.close') && $ticket->status?->system_key==='resolved')
            <form method="post" action="{{ route('tickets.close',$ticket) }}">@csrf @if($hasRequesterEmail)<label class="inline-check"><input type="hidden" name="notify_requester" value="0"><input type="checkbox" name="notify_requester" value="1" checked> Notificar solicitante</label>@endif<button class="secondary-button full">Fechar ticket</button></form>
        @endif
        @if($me->hasPermission('tickets.cancel') && $ticket->status?->system_key!=='cancelled')
            <form method="post" action="{{ route('tickets.cancel',$ticket) }}">@csrf @if($hasRequesterEmail)<label class="inline-check"><input type="hidden" name="notify_requester" value="0"><input type="checkbox" name="notify_requester" value="1" checked> Notificar solicitante</label>@endif<button class="danger-button full">Cancelar ticket</button></form>
        @endif
        @if($me->hasPermission('tickets.reopen') && in_array($ticket->status?->system_key,['resolved','closed','cancelled']))
            <form method="post" action="{{ route('tickets.reopen',$ticket) }}">@csrf @if($hasRequesterEmail)<label class="inline-check"><input type="hidden" name="notify_requester" value="0"><input type="checkbox" name="notify_requester" value="1" checked> Notificar solicitante</label>@endif<button class="secondary-button full">Reabrir ticket</button></form>
        @endif
        </div>
    </details>
</aside>
</div>

<script src="{{ asset('js/user-autocomplete.js') }}?v={{ filemtime(public_path('js/user-autocomplete.js')) }}" defer></script>
<script>
(() => {
    const visibility = document.getElementById('ticketActivityVisibility');
    const options = document.getElementById('commentNotifyOptions');
    if (!visibility || !options) return;
    const sync = () => { options.hidden = visibility.value !== 'public'; };
    visibility.addEventListener('change', sync);
    sync();
})();
</script>
@endsection