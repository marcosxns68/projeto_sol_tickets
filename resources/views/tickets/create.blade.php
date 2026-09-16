@extends('layouts.app')
@section('title','Novo ticket')
@section('content')
@php
    $canCreateIntegrationTicket = auth()->user()->hasPermission('tickets.create_integration');
    $sourceMode = $canCreateIntegrationTicket ? old('source_mode', 'internal') : 'internal';
    $integrationTarget = old('integration_target', 'integration');
    $assignmentOpen = $errors->has('requester_user_id')
        || $errors->has('assignee_id')
        || $errors->has('collaborator_ids')
        || $errors->has('follower_ids')
        || old('requester_user_id')
        || old('assignee_id')
        || count((array) old('collaborator_ids', [])) > 0
        || count((array) old('follower_ids', [])) > 0;
@endphp

<div class="page-head">
    <div>
        <p class="eyebrow">NOVO</p>
        <h1>Criar ticket</h1>
        <p class="muted">Informe o essencial. Os campos adicionais aparecem somente quando forem necessários.</p>
    </div>
</div>

@if($errors->any())
<div class="alert error-box"><strong>Não foi possível criar o ticket.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

<form class="ticket-create-v2" method="post" action="{{ route('tickets.store') }}" id="ticketCreateForm" data-create-progressive>
    @csrf

    @if($canCreateIntegrationTicket)
    <article class="panel create-section destination-panel">
        <div class="section-title compact-title">
            <div><p class="eyebrow">DESTINO</p><h2>Tipo de chamado</h2></div>
        </div>
        <div class="create-choice-grid">
            <label class="create-choice">
                <input type="radio" name="source_mode" value="internal" @checked($sourceMode === 'internal')>
                <span><b>Minha equipe</b><small>Chamado interno da Sutoorii.</small></span>
            </label>
            <label class="create-choice">
                <input type="radio" name="source_mode" value="integration" @checked($sourceMode === 'integration')>
                <span><b>Empresa / cliente</b><small>Chamado vinculado a um sistema integrado.</small></span>
            </label>
        </div>
    </article>
    @else
        <input type="hidden" name="source_mode" value="internal">
    @endif

    @if($canCreateIntegrationTicket)
    <article class="panel create-section integration-panel" data-integration-section @if($sourceMode !== 'integration') hidden @endif>
        <div class="section-title compact-title">
            <div><p class="eyebrow">EMPRESA / CLIENTE</p><h2>Para quem é este chamado?</h2></div>
        </div>

        <label>Empresa / sistema integrado
            <select name="system_id" id="integrationSelect" @disabled($sourceMode !== 'integration')>
                <option value="">Selecione...</option>
                @foreach($integrations as $integration)
                    <option value="{{ $integration->id }}" @selected((string) old('system_id') === (string) $integration->id)>{{ $integration->name }}</option>
                @endforeach
            </select>
        </label>

        <div class="requester-type-block">
            <b>Solicitante da empresa</b>
            <div class="create-choice-grid compact-choices">
                <label class="create-choice compact-choice">
                    <input type="radio" name="integration_target" value="integration" @checked($integrationTarget === 'integration') @disabled($sourceMode !== 'integration')>
                    <span><b>Chamado geral</b><small>Informe nome e e-mail, se desejar.</small></span>
                </label>
                <label class="create-choice compact-choice">
                    <input type="radio" name="integration_target" value="external_user" @checked($integrationTarget === 'external_user') @disabled($sourceMode !== 'integration')>
                    <span><b>Usuário específico</b><small>Pesquise uma pessoa do sistema integrado.</small></span>
                </label>
            </div>
        </div>

        <div class="requester-fields" data-general-requester @if($sourceMode !== 'integration' || $integrationTarget === 'external_user') hidden @endif>
            <label>Nome do solicitante
                <input type="text" name="requester_name" id="requesterName" value="{{ old('requester_name') }}" maxlength="160" placeholder="Ex.: Maria da Silva" @disabled($sourceMode !== 'integration' || $integrationTarget === 'external_user')>
            </label>
            <label>E-mail do solicitante
                <input type="email" name="requester_email" id="requesterEmail" value="{{ old('requester_email') }}" maxlength="190" placeholder="maria@empresa.com" @disabled($sourceMode !== 'integration' || $integrationTarget === 'external_user')>
                <small class="muted">Se o e-mail corresponder a um usuário ativo da integração, o vínculo será feito automaticamente.</small>
            </label>
        </div>

        <div class="external-requester" data-external-requester @if($sourceMode !== 'integration' || $integrationTarget !== 'external_user') hidden @endif>
            <input type="hidden" name="external_requester_id" id="externalRequesterId" value="{{ old('external_requester_id') }}" @disabled($sourceMode !== 'integration' || $integrationTarget !== 'external_user')>
            <label>Buscar usuário da integração
                <div class="external-user-search-row">
                    <input type="search" id="externalUserSearch" autocomplete="off" placeholder="Digite nome ou e-mail" @disabled($sourceMode !== 'integration' || $integrationTarget !== 'external_user')>
                    <button type="button" class="secondary-button" id="externalUserSearchButton" @disabled($sourceMode !== 'integration' || $integrationTarget !== 'external_user')>Buscar</button>
                </div>
            </label>
            <div id="externalUserStatus" class="muted" aria-live="polite"></div>
            <div id="externalUserResults" class="user-picker-results"></div>
            <div id="externalUserSelected" class="user-picker-selected" hidden></div>
        </div>
    </article>
    @endif

    <article class="panel create-section request-panel">
        <div class="section-title compact-title">
            <div><p class="eyebrow">SOLICITAÇÃO</p><h2>O que precisa ser feito?</h2></div>
        </div>

        <label>Departamento de destino
            <select name="department_id">
                <option value="">Triagem / nenhum</option>
                @foreach($departments as $department)
                    <option value="{{ $department->id }}" @selected((string) old('department_id') === (string) $department->id)>{{ $department->name }}</option>
                @endforeach
            </select>
            <small class="muted">Aparecem somente departamentos para os quais você pode enviar tickets.</small>
        </label>

        <label>Título
            <input name="title" value="{{ old('title') }}" maxlength="180" required placeholder="Resumo claro da solicitação">
        </label>

        <label>Descrição
            <textarea name="description" rows="6" required placeholder="Explique o contexto, os detalhes e o resultado esperado">{{ old('description') }}</textarea>
        </label>

        <div class="grid form-grid request-meta-grid">
            <label>Prioridade
                <select name="priority">
                    @foreach(['low'=>'Baixa','normal'=>'Normal','high'=>'Alta','urgent'=>'Urgente'] as $value=>$label)
                        <option value="{{ $value }}" @selected(old('priority','normal') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label>Prazo
                <input type="datetime-local" name="due_at" value="{{ old('due_at') }}" required>
            </label>
        </div>
    </article>

    <details class="panel assignment-details" @if($assignmentOpen) open @endif>
        <summary>
            <span><b>Atribuição inicial (opcional)</b><small>Solicitante interno, responsável, colaboradores e seguidores.</small></span>
            <span class="details-chevron" aria-hidden="true">⌄</span>
        </summary>

        <div class="assignment-content">
            <div class="picker-card internal-requester-card" data-internal-requester @if($sourceMode === 'integration') hidden @endif>
                <b>Solicitante interno</b>
                <small>Opcional. Quem cria o ticket não vira solicitante automaticamente.</small>
                <div class="user-picker" data-user-picker data-field="requester_user_id" data-multiple="false">
                    <input type="search" data-user-search autocomplete="off" placeholder="Buscar solicitante por nome ou e-mail" @disabled($sourceMode === 'integration')>
                    <div class="user-picker-results" data-user-results></div>
                    <div class="user-picker-selected" data-user-selected></div>
                </div>
            </div>

            <div class="people-picker-grid">
                <div class="picker-card">
                    <b>Responsável</b><small>Uma pessoa principal para conduzir o ticket.</small>
                    <div class="user-picker" data-user-picker data-field="assignee_id" data-multiple="false">
                        <input type="search" data-user-search autocomplete="off" placeholder="Buscar responsável">
                        <div class="user-picker-results" data-user-results></div>
                        <div class="user-picker-selected" data-user-selected></div>
                    </div>
                </div>
                <div class="picker-card">
                    <b>Colaboradores</b><small>Podem participar da execução e conclusão.</small>
                    <div class="user-picker" data-user-picker data-field="collaborator_ids" data-multiple="true">
                        <input type="search" data-user-search autocomplete="off" placeholder="Buscar colaboradores">
                        <div class="user-picker-results" data-user-results></div>
                        <div class="user-picker-selected" data-user-selected></div>
                    </div>
                </div>
                <div class="picker-card">
                    <b>Seguidores</b><small>Acompanham o ticket sem virar responsáveis. Você pode pesquisar seu próprio nome.</small>
                    <div class="user-picker" data-user-picker data-field="follower_ids" data-multiple="true">
                        <input type="search" data-user-search autocomplete="off" placeholder="Buscar seguidores">
                        <div class="user-picker-results" data-user-results></div>
                        <div class="user-picker-selected" data-user-selected></div>
                    </div>
                </div>
            </div>
        </div>
    </details>

    <div class="create-actions">
        <a class="secondary-button" href="{{ route('boxes.mine') }}">Cancelar</a>
        <button class="button" type="submit">Abrir ticket</button>
    </div>
</form>

<style>
.ticket-create-v2{display:grid;gap:14px;max-width:1180px}.create-section{display:grid;gap:16px}.compact-title{margin-bottom:0}.create-choice-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.create-choice{display:flex!important;align-items:flex-start;gap:10px;padding:14px;border:1px solid #e2dce8;border-radius:13px;background:#fff;cursor:pointer}.create-choice:has(input:checked){border-color:#7650aa;box-shadow:0 0 0 2px rgba(118,80,170,.1)}.create-choice input{width:auto;margin-top:3px}.create-choice span{display:grid;gap:2px}.create-choice small,.picker-card>small{color:#716978}.compact-choice{padding:12px}.integration-panel{background:#fcfbfd}.requester-type-block{display:grid;gap:8px}.requester-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.external-requester{display:grid;gap:9px}.request-meta-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.assignment-details{padding:0;overflow:visible}.assignment-details>summary{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:17px 19px;cursor:pointer;list-style:none}.assignment-details>summary::-webkit-details-marker{display:none}.assignment-details>summary span:first-child{display:grid;gap:3px}.assignment-details>summary small{color:#716978;font-weight:400}.details-chevron{font-size:1.25rem;color:#7650aa;transition:transform .16s ease}.assignment-details[open] .details-chevron{transform:rotate(180deg)}.assignment-content{display:grid;gap:12px;padding:0 19px 19px;border-top:1px solid #eee9f2}.internal-requester-card{margin-top:14px}.people-picker-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.picker-card{display:grid;align-content:start;gap:8px;padding:14px;border:1px solid #e8e3ec;border-radius:13px;background:#faf9fb}.user-picker{position:relative;display:grid;gap:8px}.user-picker-results{display:grid;gap:5px}.user-picker-result,.external-user-result{display:grid;width:100%;gap:2px;text-align:left;padding:10px 12px;border:1px solid #dfd8e6;border-radius:10px;background:#fff;cursor:pointer}.user-picker-result:hover,.external-user-result:hover{border-color:#7650aa;background:#fbf9ff}.user-picker-result small,.external-user-result small{color:#716978}.user-picker-selected{display:flex;flex-wrap:wrap;gap:6px}.user-picker-token{display:inline-flex;align-items:center;gap:6px;max-width:100%;padding:7px 9px;border-radius:9px;background:#eee8f8;color:#4f3374;font-size:.86rem}.user-picker-remove{border:0;background:transparent;color:inherit;font-size:1rem;cursor:pointer}.external-user-search-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px}.create-actions{display:flex;justify-content:flex-end;gap:10px;padding-bottom:8px}
@media(max-width:850px){.people-picker-grid,.requester-fields{grid-template-columns:1fr}.ticket-create-v2{max-width:none}}
@media(max-width:620px){.page-head .muted{max-width:32ch}.create-section{gap:14px}.create-choice-grid,.request-meta-grid,.external-user-search-row{grid-template-columns:1fr}.create-choice{padding:12px}.assignment-details>summary{padding:15px}.assignment-content{padding:0 15px 15px}.create-actions{position:sticky;bottom:0;padding:10px;background:rgba(255,255,255,.96);z-index:3}.create-actions>*{flex:1;text-align:center}}
</style>

<script src="{{ asset('js/user-autocomplete.js') }}?v={{ filemtime(public_path('js/user-autocomplete.js')) }}" defer></script>
<script>
(() => {
    const root = document.querySelector('[data-create-progressive]');
    if (!root) return;

    const integrationSection = root.querySelector('[data-integration-section]');
    const internalRequesterSection = root.querySelector('[data-internal-requester]');
    const generalRequesterSection = root.querySelector('[data-general-requester]');
    const externalRequesterSection = root.querySelector('[data-external-requester]');
    const integrationSelect = document.getElementById('integrationSelect');
    const externalUserSearch = document.getElementById('externalUserSearch');
    const externalUserSearchButton = document.getElementById('externalUserSearchButton');
    const externalRequesterId = document.getElementById('externalRequesterId');
    const externalUserStatus = document.getElementById('externalUserStatus');
    const externalUserResults = document.getElementById('externalUserResults');
    const externalUserSelected = document.getElementById('externalUserSelected');
    const searchUrlTemplate = @json(url('/integracoes/__INTEGRATION__/usuarios'));

    const sourceMode = () => root.querySelector('input[name="source_mode"]:checked')?.value
        || root.querySelector('input[name="source_mode"]')?.value
        || 'internal';
    const integrationTarget = () => root.querySelector('input[name="integration_target"]:checked')?.value || 'integration';
    const isExternalSpecific = () => sourceMode() === 'integration' && integrationTarget() === 'external_user';

    function setSectionState(section, visible) {
        if (!section) return;
        section.hidden = !visible;
        section.querySelectorAll('input, select, textarea, button').forEach(control => {
            control.disabled = !visible;
        });
    }

    function clearExternalSelection() {
        if (!externalRequesterId) return;
        externalRequesterId.value = '';
        externalUserResults?.replaceChildren();
        externalUserSelected?.replaceChildren();
        if (externalUserSelected) externalUserSelected.hidden = true;
        if (externalUserStatus) externalUserStatus.textContent = '';
    }

    function syncVisibility() {
        const integrated = sourceMode() === 'integration';
        const specific = integrated && integrationTarget() === 'external_user';

        setSectionState(integrationSection, integrated);
        setSectionState(internalRequesterSection, !integrated);
        setSectionState(generalRequesterSection, integrated && !specific);
        setSectionState(externalRequesterSection, specific);

        if (integrationSelect) integrationSelect.required = integrated;
    }

    function selectExternalUser(user) {
        if (!externalRequesterId || !externalUserResults || !externalUserSelected || !externalUserStatus) return;
        externalRequesterId.value = String(user.id);
        externalUserResults.replaceChildren();
        externalUserSelected.textContent = user.email ? `${user.name} · ${user.email}` : user.name;
        externalUserSelected.hidden = false;
        externalUserStatus.textContent = 'Usuário selecionado.';
    }

    async function searchExternalUsers() {
        if (!isExternalSpecific() || !integrationSelect || !externalUserSearch || !externalUserSearchButton || !externalUserStatus || !externalUserResults) return;

        const integrationId = integrationSelect.value;
        const term = externalUserSearch.value.trim();
        if (!integrationId) { externalUserStatus.textContent = 'Selecione a empresa/sistema primeiro.'; return; }
        if (term.length < 2) { externalUserStatus.textContent = 'Digite pelo menos 2 caracteres.'; return; }

        clearExternalSelection();
        externalUserStatus.textContent = 'Buscando...';
        externalUserSearchButton.disabled = true;
        try {
            const url = searchUrlTemplate.replace('__INTEGRATION__', encodeURIComponent(integrationId)) + '?search=' + encodeURIComponent(term);
            const response = await fetch(url, {headers:{'Accept':'application/json'}});
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error('Não foi possível consultar os usuários agora.');
            const users = Array.isArray(payload.data) ? payload.data : [];
            externalUserStatus.textContent = users.length ? `${users.length} resultado(s).` : 'Nenhum usuário encontrado.';
            users.forEach(user => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'external-user-result';
                const name = document.createElement('strong');
                name.textContent = user.name;
                const email = document.createElement('small');
                email.textContent = user.email || 'Sem e-mail';
                button.append(name, email);
                button.addEventListener('click', () => selectExternalUser(user));
                externalUserResults.append(button);
            });
        } catch (error) {
            externalUserStatus.textContent = error instanceof Error ? error.message : 'Busca indisponível.';
        } finally {
            externalUserSearchButton.disabled = !isExternalSpecific();
        }
    }

    root.querySelectorAll('input[name="source_mode"]').forEach(input => {
        input.addEventListener('change', () => {
            if (sourceMode() !== 'integration') clearExternalSelection();
            syncVisibility();
        });
    });

    root.querySelectorAll('input[name="integration_target"]').forEach(input => {
        input.addEventListener('change', () => {
            if (integrationTarget() !== 'external_user') clearExternalSelection();
            syncVisibility();
        });
    });

    integrationSelect?.addEventListener('change', clearExternalSelection);
    externalUserSearchButton?.addEventListener('click', searchExternalUsers);
    externalUserSearch?.addEventListener('keydown', event => {
        if (event.key === 'Enter') {
            event.preventDefault();
            searchExternalUsers();
        }
    });

    if (externalRequesterId?.value && externalUserSelected) {
        externalUserSelected.textContent = 'Usuário selecionado anteriormente: ' + externalRequesterId.value;
        externalUserSelected.hidden = false;
    }

    syncVisibility();
})();
</script>
@endsection
