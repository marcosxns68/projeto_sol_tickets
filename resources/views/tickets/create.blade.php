@extends('layouts.app')
@section('title','Novo ticket')
@section('content')
<div class="page-head">
    <div><p class="eyebrow">NOVO</p><h1>Criar ticket</h1><p class="muted">Defina para quem é a solicitação e quem deve acompanhar o trabalho.</p></div>
</div>

@if($errors->any())
<div class="alert error-box"><strong>Não foi possível criar o ticket.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

<form class="ticket-create-v2" method="post" action="{{ route('tickets.store') }}" id="ticketCreateForm">
    @csrf

    <article class="panel create-section">
        <div class="section-title"><div><p class="eyebrow">DESTINO</p><h2>Este ticket é para:</h2></div></div>
        <div class="create-choice-grid">
            <label class="create-choice">
                <input type="radio" name="source_mode" value="internal" {{ old('source_mode','internal') === 'internal' ? 'checked' : '' }}>
                <span><b>Minha equipe</b><small>Solicitação interna da Sutoorii.</small></span>
            </label>
            <label class="create-choice">
                <input type="radio" name="source_mode" value="integration" {{ old('source_mode') === 'integration' ? 'checked' : '' }}>
                <span><b>Uma empresa/cliente</b><small>Vincula o ticket a um sistema integrado.</small></span>
            </label>
        </div>

        <div id="internalRequesterFields" class="create-subsection">
            <div><b>Solicitante interno</b><p class="muted">Opcional. Quem abriu o ticket não vira solicitante automaticamente.</p></div>
            <div class="user-picker" data-user-picker data-field="requester_user_id" data-multiple="false">
                <input type="search" data-user-search autocomplete="off" placeholder="Buscar solicitante por nome ou e-mail">
                <div class="user-picker-results" data-user-results></div>
                <div class="user-picker-selected" data-user-selected></div>
            </div>
        </div>

        <div id="integrationFields" class="create-subsection" {{ old('source_mode') === 'integration' ? '' : 'hidden' }}>
            <label>Empresa / sistema integrado
                <select name="system_id" id="integrationSelect">
                    <option value="">Selecione...</option>
                    @foreach($integrations as $integration)
                        <option value="{{ $integration->id }}" @selected((string) old('system_id') === (string) $integration->id)>{{ $integration->name }}</option>
                    @endforeach
                </select>
            </label>

            <div>
                <b>Solicitante da empresa</b>
                <div class="create-choice-grid compact-choices">
                    <label class="create-choice">
                        <input type="radio" name="integration_target" value="integration" {{ old('integration_target','integration') === 'integration' ? 'checked' : '' }}>
                        <span><b>Chamado geral</b><small>Nome e e-mail podem ser informados manualmente.</small></span>
                    </label>
                    <label class="create-choice">
                        <input type="radio" name="integration_target" value="external_user" {{ old('integration_target') === 'external_user' ? 'checked' : '' }}>
                        <span><b>Usuário específico</b><small>Pesquisa um usuário ativo do sistema integrado.</small></span>
                    </label>
                </div>
            </div>

            <div id="generalRequesterFields" class="requester-fields">
                <label>Nome do solicitante
                    <input type="text" name="requester_name" id="requesterName" value="{{ old('requester_name') }}" maxlength="160" placeholder="Ex.: Maria da Silva">
                </label>
                <label>E-mail do solicitante
                    <input type="email" name="requester_email" id="requesterEmail" value="{{ old('requester_email') }}" maxlength="190" placeholder="maria@empresa.com">
                    <small class="muted">Se este e-mail pertencer a um usuário ativo da integração, o vínculo é feito automaticamente.</small>
                </label>
            </div>

            <div id="externalUserFields" class="requester-fields" hidden>
                <input type="hidden" name="external_requester_id" id="externalRequesterId" value="{{ old('external_requester_id') }}">
                <label>Buscar usuário da integração
                    <div class="external-user-search-row">
                        <input type="search" id="externalUserSearch" autocomplete="off" placeholder="Digite nome ou e-mail">
                        <button type="button" class="secondary-button" id="externalUserSearchButton">Buscar</button>
                    </div>
                </label>
                <div id="externalUserStatus" class="muted" aria-live="polite"></div>
                <div id="externalUserResults" class="user-picker-results"></div>
                <div id="externalUserSelected" class="user-picker-selected" hidden></div>
            </div>
        </div>
    </article>

    <article class="panel create-section">
        <div class="section-title"><div><p class="eyebrow">SOLICITAÇÃO</p><h2>O que precisa ser feito?</h2></div></div>
        <label>Título<input name="title" value="{{ old('title') }}" maxlength="180" required placeholder="Resumo claro da solicitação"></label>
        <label>Descrição<textarea name="description" rows="7" required placeholder="Explique o contexto, os detalhes e o resultado esperado">{{ old('description') }}</textarea></label>
        <div class="grid form-grid">
            <label>Prioridade
                <select name="priority">
                    @foreach(['low'=>'Baixa','normal'=>'Normal','high'=>'Alta','urgent'=>'Urgente'] as $value=>$label)
                        <option value="{{ $value }}" @selected(old('priority','normal') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label>Prazo<input type="datetime-local" name="due_at" value="{{ old('due_at') }}" required></label>
            <label>Departamento de destino
                <select name="department_id">
                    <option value="">Triagem / nenhum</option>
                    @foreach($departments as $department)
                        <option value="{{ $department->id }}" @selected((string) old('department_id') === (string) $department->id)>{{ $department->name }}</option>
                    @endforeach
                </select>
                <small class="muted">São exibidos apenas departamentos para os quais você pode enviar tickets.</small>
            </label>
        </div>
    </article>

    <article class="panel create-section">
        <div class="section-title"><div><p class="eyebrow">PESSOAS</p><h2>Quem participa deste ticket?</h2></div></div>
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
    </article>

    <div class="create-actions"><a class="secondary-button" href="{{ route('boxes.mine') }}">Cancelar</a><button class="button" type="submit">Criar ticket</button></div>
</form>

<style>
.ticket-create-v2{display:grid;gap:16px}.create-section{display:grid;gap:18px}.create-choice-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.create-choice{display:flex!important;align-items:flex-start;gap:10px;padding:15px;border:1px solid #e2dce8;border-radius:14px;background:#fff;cursor:pointer}.create-choice:has(input:checked){border-color:#7650aa;box-shadow:0 0 0 2px rgba(118,80,170,.1)}.create-choice input{width:auto;margin-top:3px}.create-choice span{display:grid;gap:3px}.create-choice small,.picker-card>small{color:#716978}.create-subsection{display:grid;gap:14px;padding:16px;border:1px solid #e8e3ec;border-radius:14px;background:#faf9fb}.requester-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.compact-choices{margin-top:8px}.people-picker-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.picker-card{display:grid;align-content:start;gap:8px;padding:14px;border:1px solid #e8e3ec;border-radius:14px;background:#faf9fb}.user-picker{position:relative;display:grid;gap:8px}.user-picker-results{display:grid;gap:5px}.user-picker-result,.external-user-result{display:grid;width:100%;gap:2px;text-align:left;padding:10px 12px;border:1px solid #dfd8e6;border-radius:10px;background:#fff;cursor:pointer}.user-picker-result:hover,.external-user-result:hover{border-color:#7650aa;background:#fbf9ff}.user-picker-result small,.external-user-result small{color:#716978}.user-picker-selected{display:flex;flex-wrap:wrap;gap:6px}.user-picker-token{display:inline-flex;align-items:center;gap:6px;max-width:100%;padding:7px 9px;border-radius:9px;background:#eee8f8;color:#4f3374;font-size:.86rem}.user-picker-remove{border:0;background:transparent;color:inherit;font-size:1rem;cursor:pointer}.external-user-search-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px}.create-actions{display:flex;justify-content:flex-end;gap:10px;padding-bottom:8px}@media(max-width:850px){.people-picker-grid{grid-template-columns:1fr}.requester-fields{grid-template-columns:1fr}}@media(max-width:620px){.create-choice-grid{grid-template-columns:1fr}.external-user-search-row{grid-template-columns:1fr}.create-actions{position:sticky;bottom:0;padding:10px;background:rgba(255,255,255,.95);z-index:3}.create-actions>*{flex:1;text-align:center}}
</style>

<script src="{{ asset('js/user-autocomplete.js') }}?v={{ filemtime(public_path('js/user-autocomplete.js')) }}" defer></script>
<script>
(() => {
    const integrationFields = document.getElementById('integrationFields');
    const internalRequesterFields = document.getElementById('internalRequesterFields');
    const integrationSelect = document.getElementById('integrationSelect');
    const generalRequesterFields = document.getElementById('generalRequesterFields');
    const externalUserFields = document.getElementById('externalUserFields');
    const externalUserSearch = document.getElementById('externalUserSearch');
    const externalUserSearchButton = document.getElementById('externalUserSearchButton');
    const externalRequesterId = document.getElementById('externalRequesterId');
    const externalUserStatus = document.getElementById('externalUserStatus');
    const externalUserResults = document.getElementById('externalUserResults');
    const externalUserSelected = document.getElementById('externalUserSelected');
    const searchUrlTemplate = @json(url('/integracoes/__INTEGRATION__/usuarios'));

    const sourceMode = () => document.querySelector('input[name="source_mode"]:checked')?.value || 'internal';
    const integrationTarget = () => document.querySelector('input[name="integration_target"]:checked')?.value || 'integration';

    function clearExternalSelection() {
        externalRequesterId.value = '';
        externalUserResults.replaceChildren();
        externalUserSelected.replaceChildren();
        externalUserSelected.hidden = true;
        externalUserStatus.textContent = '';
    }

    function syncVisibility() {
        const integrated = sourceMode() === 'integration';
        const specific = integrated && integrationTarget() === 'external_user';
        integrationFields.hidden = !integrated;
        internalRequesterFields.hidden = integrated;
        integrationSelect.required = integrated;
        generalRequesterFields.hidden = !integrated || specific;
        externalUserFields.hidden = !specific;
    }

    function selectExternalUser(user) {
        externalRequesterId.value = String(user.id);
        externalUserResults.replaceChildren();
        externalUserSelected.textContent = user.email ? `${user.name} · ${user.email}` : user.name;
        externalUserSelected.hidden = false;
        externalUserStatus.textContent = 'Usuário selecionado.';
    }

    async function searchExternalUsers() {
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
                const name = document.createElement('strong'); name.textContent = user.name;
                const email = document.createElement('small'); email.textContent = user.email || 'Sem e-mail';
                button.append(name,email);
                button.addEventListener('click', () => selectExternalUser(user));
                externalUserResults.append(button);
            });
        } catch (error) {
            externalUserStatus.textContent = error instanceof Error ? error.message : 'Busca indisponível.';
        } finally {
            externalUserSearchButton.disabled = false;
        }
    }

    document.querySelectorAll('input[name="source_mode"],input[name="integration_target"]').forEach(input => input.addEventListener('change', syncVisibility));
    integrationSelect.addEventListener('change', clearExternalSelection);
    externalUserSearchButton.addEventListener('click', searchExternalUsers);
    externalUserSearch.addEventListener('keydown', event => { if(event.key==='Enter'){event.preventDefault();searchExternalUsers();} });
    if (externalRequesterId.value) {
        externalUserSelected.textContent = 'Usuário selecionado anteriormente: ' + externalRequesterId.value;
        externalUserSelected.hidden = false;
    }
    syncVisibility();
})();
</script>
@endsection
