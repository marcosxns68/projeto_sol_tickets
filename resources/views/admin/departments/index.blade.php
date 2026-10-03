@extends('layouts.app')
@section('title','Departamentos — Sutoorii Tickets')
@section('content')
@php
    $priorityStyles = [
        'low' => ['label' => 'Baixa', 'color' => '#2F855A'],
        'normal' => ['label' => 'Normal', 'color' => '#6D28D9'],
        'high' => ['label' => 'Alta', 'color' => '#D97706'],
        'urgent' => ['label' => 'Urgente', 'color' => '#DC2626'],
    ];
@endphp
<div class="page-head department-tree-head">
    <div>
        <p class="eyebrow">EQUIPE</p>
        <h1>Departamentos</h1>
        <p class="muted">Organize tickets em departamentos, pastas e subpastas sem alterar as permissões da equipe.</p>
    </div>
    @if($canManage)<button class="button" type="button" data-tree-panel-target="department-create">+ Novo departamento</button>@endif
</div>

@if($errors->any())<div class="alert error-box"><strong>Não foi possível salvar.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

@if($canManage)
<div class="tree-inline-panel department-create-inline" id="department-create" @if(!$errors->any()) hidden @endif>
    <form action="{{ route('admin.departments.store') }}" method="post" class="tree-department-form">@csrf
        <label>Nome<input name="name" value="{{ old('name') }}" placeholder="Ex.: Desenvolvimento" required maxlength="120"></label>
        <label>Descrição<textarea name="description" rows="2" maxlength="2000" placeholder="Descrição opcional do departamento">{{ old('description') }}</textarea></label>
        <label class="tree-check"><input type="checkbox" name="active" value="1" @checked(old('active',true))> Departamento ativo</label>
        <div class="tree-inline-actions"><button class="secondary-button compact" type="button" data-tree-panel-close>Cancelar</button><button class="button compact" type="submit">Criar departamento</button></div>
    </form>
</div>
@endif

<div class="department-access-legend">
    <strong>Hierarquia de acesso</strong>
    <span>Nível 1 — Enviar</span><span>→</span><span>Nível 2 — Visualizar</span><span>→</span><span>Nível 3 — Editar</span>
    <small>Hierarquia: Enviar → Visualizar → Editar. Cada nível inclui o anterior.</small>
</div>

<div class="department-tree" data-department-tree>
@forelse($departments as $department)
    @php
        $accessState = $departmentAccessStates[$department->id] ?? ['level'=>null,'can_view'=>false,'can_edit'=>false];
        $canViewTickets = (bool) $accessState['can_view'];
        $canEditTree = (bool) $accessState['can_edit'] && !$department->isTriage();
        $subscriptionState = $subscriptionStates[$department->id] ?? ['following'=>false,'notify_email'=>false,'notify_whatsapp'=>false,'notify_push'=>false];
        $tree = $departmentTrees[$department->id] ?? ['root_tickets'=>collect(),'folders'=>[],'ticket_count'=>0];
    @endphp
    <section class="department-tree-node" data-tree-node>
        <div class="department-tree-row">
            <button type="button" class="tree-main-toggle department-main-toggle" data-tree-toggle aria-expanded="false">
                <span class="tree-chevron" aria-hidden="true">›</span>
                <span class="department-folder-icon" aria-hidden="true">▰</span>
                <span class="tree-copy department-tree-copy">
                    <span class="department-title-line"><strong>{{ $department->name }}</strong>@if($department->isTriage())<span class="department-system-badge">Padrão</span>@endif @if(($unseenCounts[$department->id] ?? 0) > 0)<span class="department-new-count">{{ $unseenCounts[$department->id] }} {{ ($unseenCounts[$department->id] ?? 0) === 1 ? 'novidade' : 'novidades' }}</span>@endif</span>
                    <small class="department-description">{{ $department->description ?: ($department->isTriage() ? 'Caixa padrão do sistema' : ($department->active ? 'Ativo' : 'Inativo')) }}</small>
                </span>
                <span class="department-compact-stats"><span><b>Abertos</b> {{ $department->open_tickets_count }}</span><span><b>Concluídos</b> {{ $department->completed_tickets_count }}</span></span>
            </button>

            @if($canEditTree)
            <details class="tree-action-menu">
                <summary aria-label="Adicionar em {{ $department->name }}">+</summary>
                <div class="tree-popover">
                    <a href="{{ route('tickets.create', ['department' => $department->id]) }}">Nova tarefa</a>
                    <button type="button" data-tree-panel-target="department-folder-create-{{ $department->id }}">Nova pasta</button>
                </div>
            </details>
            @endif

            <details class="tree-action-menu tree-more-menu">
                <summary aria-label="Opções de {{ $department->name }}">⋯</summary>
                <div class="tree-popover tree-more-popover">
                    @if($canViewTickets)
                        <button type="button" data-tree-panel-target="department-follow-{{ $department->id }}">Acompanhar e notificações</button>
                        <a href="{{ route('boxes.department', $department) }}">Ver tickets do departamento</a>
                    @endif
                    @if($canManage)
                        <button type="button" data-tree-panel-target="department-people-{{ $department->id }}">Pessoas e acessos</button>
                        @unless($department->isTriage())<a href="{{ route('admin.departments.edit', $department) }}">Editar departamento</a>@endunless
                    @endif
                    @if(!$canViewTickets && !$canManage)<span class="tree-menu-empty">Sem outras ações disponíveis</span>@endif
                </div>
            </details>
        </div>

        @if($canEditTree)
        <div class="tree-inline-panel" id="department-folder-create-{{ $department->id }}" hidden>
            <form method="post" action="{{ route('departments.folders.store', $department) }}" class="tree-compact-form">@csrf
                <label>Nome da pasta<input name="name" required maxlength="160" placeholder="Ex.: Afialo, Bugs, Financeiro"></label>
                <div class="tree-inline-actions"><button class="secondary-button compact" type="button" data-tree-panel-close>Cancelar</button><button class="button compact" type="submit">Criar pasta</button></div>
            </form>
        </div>
        @endif

        @if($canViewTickets)
        <div class="tree-inline-panel" id="department-follow-{{ $department->id }}" hidden>
            <form method="post" action="{{ route('departments.follow',$department) }}" class="follow-department-form">@csrf @method('PATCH')
                <div class="follow-department-heading"><strong>Acompanhar {{ $department->name }}</strong><small class="muted">Escolha como receber avisos quando chegar um ticket, ele for encaminhado para cá ou o cliente responder.</small></div>
                <div class="department-channel-options">
                    <label><input type="hidden" name="notify_email" value="0"><input type="checkbox" name="notify_email" value="1" @checked($subscriptionState['notify_email'])> E-mail</label>
                    <label><input type="hidden" name="notify_whatsapp" value="0"><input type="checkbox" name="notify_whatsapp" value="1" @checked($subscriptionState['notify_whatsapp'])> WhatsApp</label>
                    <label><input type="hidden" name="notify_push" value="0"><input type="checkbox" name="notify_push" value="1" @checked($subscriptionState['notify_push'])> Push do aplicativo</label>
                </div>
                <small class="muted">O WhatsApp utiliza o número em <a href="{{ route('profile.notifications.edit') }}">Meu perfil</a>. O Push pode funcionar com o PWA fechado após conectar este dispositivo.</small>
                <div class="department-follow-actions">
                    <button class="secondary-button compact" type="submit">Salvar preferências</button>
                    <button class="secondary-button compact" type="button" data-enable-browser-alerts>Ativar push neste celular</button>
                    <small class="muted" data-browser-alert-status role="status"></small>
                    <button class="secondary-button compact" type="button" data-disable-pwa-push>Desativar push neste dispositivo</button>
                    @if($subscriptionState['following'])<a class="subtle-link" href="{{ route('boxes.department', ['department'=>$department,'novos'=>1]) }}">Ver novidades ({{ $unseenCounts[$department->id] ?? 0 }})</a>@endif
                </div>
            </form>
            @if($subscriptionState['following'])<form action="{{ route('departments.mark-seen',$department) }}" method="post" class="department-seen-form">@csrf<button class="secondary-button compact" type="submit">Marcar novidades como vistas</button></form>@endif
        </div>
        @endif

        @if($canManage)
        <div class="tree-inline-panel" id="department-people-{{ $department->id }}" hidden>
            <div class="department-people-head"><div><strong>Pessoas e acessos</strong><small>O nível escolhido vale para todas as pastas e subpastas deste departamento.</small></div><small class="access-hierarchy-copy">Hierarquia: Enviar → Visualizar → Editar. Cada nível inclui o anterior.</small></div>
            <div class="department-members">
                <div class="member-row member-head"><span>Pessoa</span><span>Acesso</span><span>Acompanhamento</span><span></span></div>
                @forelse($department->users as $member)
                    <div class="member-row">
                        <div><strong>{{ $member->name }}</strong><small>{{ $member->email }}</small></div>
                        <form method="post" action="{{ route('admin.departments.users.update',[$department,$member]) }}" class="member-access-form">@csrf @method('PATCH')
                            <select name="access_level" onchange="this.form.submit()">
                                <option value="send" @selected($member->pivot->access_level==='send')>Nível 1 — Enviar</option>
                                <option value="view" @selected($member->pivot->access_level==='view')>Nível 2 — Visualizar</option>
                                <option value="edit" @selected($member->pivot->access_level==='edit')>Nível 3 — Editar</option>
                            </select>
                        </form>
                        <span>{{ in_array($member->pivot->access_level,['view','edit'],true) ? ($member->pivot->follow_department ? collect(['E-mail'=>$member->pivot->notify_email,'WhatsApp'=>$member->pivot->notify_whatsapp,'Push'=>$member->pivot->notify_push])->filter()->keys()->join(', ') : 'Não acompanha') : 'Indisponível' }}</span>
                        <form method="post" action="{{ route('admin.departments.users.destroy',[$department,$member]) }}" onsubmit="return confirm('Remover esta pessoa do departamento?')">@csrf @method('DELETE')<button class="text-danger" type="submit">Remover</button></form>
                    </div>
                @empty
                    <div class="tree-empty-row">Nenhuma pessoa associada.</div>
                @endforelse
            </div>
            <form method="post" action="{{ route('admin.departments.users.store',$department) }}" class="department-add-user" data-user-picker>@csrf
                <div class="user-picker-wrap"><label>Adicionar pessoa<input type="search" data-user-search autocomplete="off" placeholder="Digite nome ou e-mail"></label><input type="hidden" name="user_id" data-user-id><div class="user-picker-results" data-user-results></div></div>
                <label>Nível de acesso<select name="access_level" required><option value="send">Nível 1 — Enviar</option><option value="view">Nível 2 — Visualizar</option><option value="edit">Nível 3 — Editar</option></select></label>
                <button class="button" type="submit">Adicionar</button>
            </form>
        </div>
        @endif

        <div class="department-tree-children" data-tree-children hidden>
            @if($canViewTickets)
                @foreach($tree['root_tickets'] as $ticket)
                    @php($priority = $priorityStyles[$ticket->priority] ?? $priorityStyles['normal'])
                    <a class="tree-ticket-row root-ticket-row" href="{{ route('tickets.show',$ticket) }}">
                        <span class="ticket-priority-dot" data-priority="{{ $ticket->priority }}" style="--priority-color:{{ $priority['color'] }}" aria-label="Prioridade: {{ $priority['label'] }}" title="Prioridade: {{ $priority['label'] }}"></span>
                        <span class="tree-ticket-copy"><strong>#{{ $ticket->number }} · {{ $ticket->title }}</strong><small>{{ $ticket->assignee?->name ?? 'Não atribuído' }}@if($ticket->due_at) · {{ $ticket->due_at->format('d/m/Y') }}@endif</small></span>
                    </a>
                @endforeach
                @foreach($tree['folders'] as $node)
                    @include('admin.departments._tree-node', ['node'=>$node,'department'=>$department,'depth'=>1,'canEdit'=>$canEditTree])
                @endforeach
                @if($tree['root_tickets']->isEmpty() && empty($tree['folders']))<div class="tree-empty-row">Nenhum ticket ou pasta neste departamento.</div>@endif
            @else
                <div class="tree-no-access"><strong>Sem acesso aos tickets</strong><small>Seu nível permite enviar tickets, mas não visualizar a caixa deste departamento.</small></div>
            @endif
        </div>
    </section>
@empty
    <div class="tree-empty-row">Nenhum departamento disponível.</div>
@endforelse
</div>

<style>
.department-tree-head{align-items:flex-start}.department-access-legend{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 12px;padding:10px 12px;border:1px solid #ece6f0;border-radius:10px;background:#fff;font-size:.78rem;color:#655b6b}.department-access-legend strong{color:#332c38}.department-access-legend small{width:100%;color:#84798a}.department-tree{--tree-indent:22px;border:1px solid #e8e2ec;border-radius:12px;background:#fff;overflow:visible}.department-tree-node+.department-tree-node{border-top:1px solid #eee9f1}.department-tree-row,.tree-folder-row{min-height:44px;display:flex;align-items:center;position:relative;padding:4px 8px;gap:4px}.tree-folder-row{padding-left:calc(8px + (var(--tree-depth) * var(--tree-indent)));border-top:1px solid #f1edf3}.tree-main-toggle{min-width:0;flex:1;display:flex;align-items:center;gap:8px;border:0;background:transparent;text-align:left;padding:5px 4px;cursor:pointer;border-radius:7px}.tree-main-toggle:hover{background:#faf7fc}.tree-chevron{width:16px;display:inline-grid;place-items:center;color:#7a6a83;font-size:20px;line-height:1;transition:transform .16s}.tree-main-toggle[aria-expanded=true] .tree-chevron{transform:rotate(90deg)}.department-folder-icon,.tree-folder-icon{font-size:18px;color:#7a39bf;width:20px;text-align:center}.tree-copy{min-width:0;display:grid;gap:1px;flex:1}.tree-copy strong{font-size:.88rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.tree-copy small{font-size:.72rem;color:#84798a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.department-title-line{display:flex;align-items:center;min-width:0;gap:5px}.department-title-line>strong{min-width:0;overflow:hidden;text-overflow:ellipsis}.department-compact-stats{display:flex;align-items:center;gap:14px;color:#756b7b;font-size:.7rem;white-space:nowrap;margin-left:auto}.department-compact-stats b{color:#4c4252}.department-system-badge,.department-new-count{display:inline-flex;align-items:center;border-radius:999px;padding:2px 6px;font-size:.62rem;font-weight:750;white-space:nowrap}.department-system-badge{background:#ede9fe;color:#5b21b6}.department-new-count{background:#6d28d9;color:#fff}.tree-action-menu{position:relative;flex:0 0 auto}.tree-action-menu>summary{list-style:none;width:30px;height:30px;display:grid;place-items:center;border-radius:7px;cursor:pointer;color:#695a72;font-weight:800;font-size:1rem}.tree-more-menu>summary{font-size:1.25rem}.tree-action-menu>summary::-webkit-details-marker{display:none}.tree-action-menu[open]>summary,.tree-action-menu>summary:hover{background:#f2edf5}.tree-popover{position:absolute;z-index:45;right:0;top:34px;min-width:190px;padding:5px;border:1px solid #e3dbe7;border-radius:10px;background:#fff;box-shadow:0 12px 30px #27143122;display:grid}.tree-popover a,.tree-popover button,.tree-menu-empty{display:block;width:100%;border:0;background:transparent;text-align:left;padding:9px 10px;border-radius:7px;color:#45394c;text-decoration:none;font-size:.8rem;cursor:pointer}.tree-popover a:hover,.tree-popover button:hover{background:#faf6fc}.tree-menu-empty{color:#8d8392;cursor:default}.tree-inline-panel{margin:0 10px 8px 42px;padding:12px;border:1px solid #ebe4ef;border-radius:10px;background:#fcfafd}.department-create-inline{margin:0 0 12px}.tree-department-form{display:grid;grid-template-columns:minmax(180px,1fr) minmax(220px,2fr) auto;gap:10px;align-items:end}.tree-department-form label,.tree-compact-form label,.department-add-user label{display:grid;gap:5px;font-size:.78rem;font-weight:650}.tree-department-form input,.tree-department-form textarea,.tree-compact-form input,.department-add-user input,.department-add-user select,.member-access-form select{width:100%;border:1px solid #ded6e4;border-radius:8px;padding:8px 9px;background:#fff}.tree-check{display:flex!important;align-items:center;gap:6px}.tree-check input{width:auto}.tree-compact-form{display:grid;grid-template-columns:minmax(180px,1fr) auto;gap:10px;align-items:end}.tree-inline-actions{display:flex;gap:6px;justify-content:flex-end}.department-tree-children,.tree-children{border-top:1px solid #f0ebf2}.tree-ticket-row{min-height:39px;display:flex;align-items:center;gap:8px;padding:5px 10px 5px calc(38px + (var(--tree-depth, 0) * var(--tree-indent)));text-decoration:none;color:#342c39;border-top:1px dashed #f0ebf2}.root-ticket-row{padding-left:40px}.tree-ticket-row:hover{background:#fcfafd}.ticket-priority-dot{width:9px;height:9px;border-radius:50%;background:var(--priority-color);box-shadow:0 0 0 2px color-mix(in srgb,var(--priority-color) 18%,transparent);flex:0 0 auto}.tree-ticket-copy{min-width:0;display:grid;gap:1px}.tree-ticket-copy strong{font-size:.8rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.tree-ticket-copy small{font-size:.68rem;color:#877d8c}.tree-empty-row,.tree-no-access{padding:10px 14px 10px 42px;color:#867a8b;font-size:.76rem}.tree-no-access{display:grid;gap:2px}.tree-no-access strong{color:#5e5364}.follow-department-form{display:grid;gap:10px}.follow-department-heading{display:grid;gap:2px}.department-channel-options,.department-follow-actions{display:flex;align-items:center;gap:10px 18px;flex-wrap:wrap}.department-channel-options label{display:flex;align-items:center;gap:6px;font-size:.78rem;font-weight:650}.department-channel-options input{width:16px;height:16px;accent-color:#6d28d9}.department-seen-form{margin-top:8px}.department-people-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.department-people-head>div{display:grid;gap:2px}.department-people-head small{color:#817686;font-size:.7rem}.access-hierarchy-copy{font-weight:650}.department-members{margin-top:10px}.member-row{display:grid;grid-template-columns:minmax(180px,2fr) minmax(170px,1fr) minmax(120px,1fr) auto;gap:10px;align-items:center;padding:8px 0;border-bottom:1px solid #eee9f1;font-size:.76rem}.member-row>div:first-child{display:grid}.member-row small{color:#85798a}.member-head{font-size:.68rem;text-transform:uppercase;font-weight:750;color:#766d7c}.department-add-user{display:grid;grid-template-columns:minmax(220px,2fr) minmax(180px,1fr) auto;gap:8px;align-items:end;margin-top:12px}.user-picker-wrap{position:relative}.user-picker-results{position:absolute;z-index:50;left:0;right:0;top:100%;background:#fff;border:1px solid #ded6e5;border-radius:10px;box-shadow:0 10px 24px #25133422;overflow:hidden}.user-picker-results:empty{display:none}.user-picker-result{display:block;width:100%;border:0;border-bottom:1px solid #eee8f2;background:#fff;padding:9px 11px;text-align:left;cursor:pointer}.user-picker-result:hover{background:#faf7ff}.user-picker-result small{display:block;color:#756d7b}.text-danger{border:0;background:none;color:#a93434;cursor:pointer}.department-description{max-width:540px}
@media(max-width:800px){.department-tree{--tree-indent:12px}.department-access-legend span:nth-of-type(even){display:none}.department-compact-stats{gap:7px;font-size:.62rem}.department-compact-stats span{display:grid}.department-description{display:none}.department-tree-row,.tree-folder-row{min-height:42px;padding-right:4px}.tree-folder-row{padding-left:calc(4px + (var(--tree-depth) * var(--tree-indent)))}.tree-action-menu>summary{width:28px;height:28px}.tree-popover{position:fixed;left:12px;right:12px;top:auto;bottom:16px;min-width:0;z-index:80;box-shadow:0 14px 45px #160d1f44}.tree-inline-panel{margin:0 6px 7px 24px}.tree-department-form,.tree-compact-form,.department-add-user,.member-row{grid-template-columns:1fr}.member-head{display:none}.department-people-head{display:grid}.tree-ticket-row{padding-left:calc(28px + (var(--tree-depth,0) * var(--tree-indent)))}.root-ticket-row{padding-left:29px}.tree-ticket-copy small{display:none}.department-tree-head .button{padding:8px 9px;font-size:.76rem}.department-title-line{gap:3px}}
</style>

<script>
(() => {
    document.querySelectorAll('[data-tree-toggle]').forEach(button => button.addEventListener('click', () => {
        const node = button.closest('[data-tree-node]');
        const children = node?.querySelector(':scope > [data-tree-children]');
        if (!children) return;
        const open = children.hidden;
        children.hidden = !open;
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
    }));

    document.querySelectorAll('[data-tree-panel-target]').forEach(button => button.addEventListener('click', () => {
        const panel = document.getElementById(button.dataset.treePanelTarget);
        if (!panel) return;
        panel.hidden = !panel.hidden;
        button.closest('details')?.removeAttribute('open');
        if (!panel.hidden) panel.querySelector('input,textarea,select')?.focus();
    }));
    document.querySelectorAll('[data-tree-panel-close]').forEach(button => button.addEventListener('click', () => {
        const panel = button.closest('.tree-inline-panel');
        if (panel) panel.hidden = true;
    }));

    const searchUrl = @json(route('users.search'));
    document.querySelectorAll('[data-user-picker]').forEach(picker => {
        const search = picker.querySelector('[data-user-search]'), id = picker.querySelector('[data-user-id]'), results = picker.querySelector('[data-user-results]'); let timer;
        search.addEventListener('input', () => { clearTimeout(timer); id.value=''; results.replaceChildren(); const q=search.value.trim(); if(q.length<2)return; timer=setTimeout(async()=>{ try { const r=await fetch(searchUrl+'?q='+encodeURIComponent(q),{headers:{Accept:'application/json'}}); const p=await r.json(); results.replaceChildren(); (p.data||[]).forEach(user=>{ const b=document.createElement('button'); b.type='button'; b.className='user-picker-result'; b.innerHTML='<strong></strong><small></small>'; b.querySelector('strong').textContent=user.name; b.querySelector('small').textContent=user.email; b.addEventListener('click',()=>{ id.value=user.id; search.value=user.name+' · '+user.email; results.replaceChildren(); }); results.append(b); }); } catch(e){ results.replaceChildren(); } },250); });
        picker.addEventListener('submit', e => { if(!id.value){ e.preventDefault(); search.focus(); } });
    });
})();
</script>
@endsection
