@extends('layouts.app')
@section('title','Novo ticket')
@section('content')
<div class="page-head">
    <div><p class="eyebrow">NOVO</p><h1>Criar ticket</h1></div>
</div>

<form class="panel form" method="post" action="{{ route('tickets.store') }}" id="ticketCreateForm">
    @csrf

    <div class="integration-create-card">
        <strong>Onde este ticket deve aparecer?</strong>
        <div class="integration-mode-grid">
            <label class="integration-mode-option">
                <input type="radio" name="source_mode" value="internal" {{ old('source_mode','internal') === 'internal' ? 'checked' : '' }}>
                <span><b>Somente interno</b><small>Ticket usado apenas pela equipe no Sutoorii Tickets.</small></span>
            </label>
            <label class="integration-mode-option">
                <input type="radio" name="source_mode" value="integration" {{ old('source_mode') === 'integration' ? 'checked' : '' }}>
                <span><b>Integração</b><small>O chamado também aparecerá no sistema escolhido.</small></span>
            </label>
        </div>

        <div id="integrationFields" class="integration-fields" {{ old('source_mode') === 'integration' ? '' : 'hidden' }}>
            <label>Integração
                <select name="system_id" id="integrationSelect">
                    <option value="">Selecione</option>
                    @foreach($integrations as $integration)
                        <option value="{{ $integration->id }}" {{ (string) old('system_id') === (string) $integration->id ? 'selected' : '' }}>{{ $integration->name }}</option>
                    @endforeach
                </select>
            </label>

            <div class="integration-target-block">
                <span class="integration-target-label">Quem deve receber este ticket?</span>
                <div class="integration-mode-grid integration-target-grid">
                    <label class="integration-mode-option">
                        <input type="radio" name="integration_target" value="integration" {{ old('integration_target','integration') === 'integration' ? 'checked' : '' }}>
                        <span><b>Chamado geral</b><small>Fica visível aos gestores da integração.</small></span>
                    </label>
                    <label class="integration-mode-option">
                        <input type="radio" name="integration_target" value="external_user" {{ old('integration_target') === 'external_user' ? 'checked' : '' }}>
                        <span><b>Usuário específico</b><small>Vincula o ticket a um usuário ativo do sistema integrado.</small></span>
                    </label>
                </div>
            </div>

            <div id="generalRequesterFields" class="integration-subfields">
                <label>Nome do solicitante
                    <input type="text" name="requester_name" id="requesterName" value="{{ old('requester_name') }}" maxlength="160" placeholder="Opcional, ex.: Maria da Silva">
                    <small class="muted">Opcional. Registra um nome no chamado sem criar vínculo com um usuário específico.</small>
                </label>
            </div>

            <div id="externalUserFields" class="integration-subfields" hidden>
                <input type="hidden" name="external_requester_id" id="externalRequesterId" value="{{ old('external_requester_id') }}">
                <label>Buscar usuário da integração
                    <div class="external-user-search-row">
                        <input type="search" id="externalUserSearch" autocomplete="off" placeholder="Digite pelo menos 2 letras do nome ou e-mail">
                        <button type="button" class="button secondary" id="externalUserSearchButton">Buscar</button>
                    </div>
                </label>
                <div id="externalUserStatus" class="muted" aria-live="polite"></div>
                <div id="externalUserResults" class="external-user-results"></div>
                <div id="externalUserSelected" class="external-user-selected" hidden></div>
            </div>
        </div>
    </div>

    <label>Título<input name="title" value="{{ old('title') }}" maxlength="180" required placeholder="Resumo claro do que precisa ser feito"></label>
    <label>Descrição<textarea name="description" rows="7" required placeholder="Explique os detalhes, contexto e resultado esperado">{{ old('description') }}</textarea></label>
    <div class="grid">
        <label>Prioridade
            <select name="priority">
                <option value="low" {{ old('priority') === 'low' ? 'selected' : '' }}>Baixa</option>
                <option value="normal" {{ old('priority','normal') === 'normal' ? 'selected' : '' }}>Normal</option>
                <option value="high" {{ old('priority') === 'high' ? 'selected' : '' }}>Alta</option>
                <option value="urgent" {{ old('priority') === 'urgent' ? 'selected' : '' }}>Urgente</option>
            </select>
        </label>
        <label>Prazo<input type="datetime-local" name="due_at" value="{{ old('due_at') }}" required></label>
        <label>Departamento
            <select name="department_id">
                <option value="">Triagem / nenhum</option>
                @foreach($departments as $department)
                    <option value="{{ $department->id }}" {{ (string) old('department_id') === (string) $department->id ? 'selected' : '' }}>{{ $department->name }}</option>
                @endforeach
            </select>
            <small class="muted">Se a integração tiver departamento padrão, ele terá prioridade.</small>
        </label>
    </div>

    @if($errors->any())<div class="error">{{ $errors->first() }}</div>@endif
    <div class="actions"><a href="{{ route('dashboard') }}">Cancelar</a><button class="button">Criar ticket</button></div>
</form>

<style>
.integration-create-card{border:1px solid #e7e2ec;border-radius:16px;padding:18px;display:grid;gap:14px;background:#fbfafc}.integration-mode-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.integration-mode-option{display:flex!important;align-items:flex-start;gap:10px;border:1px solid #e4deea;border-radius:12px;padding:13px;background:#fff;cursor:pointer}.integration-mode-option input{width:auto;margin-top:3px}.integration-mode-option span{display:grid;gap:3px}.integration-mode-option small,.muted{color:#716978;font-size:.82rem}.integration-fields{display:grid;gap:14px;border-top:1px solid #e8e2ed;padding-top:14px}.integration-target-block{display:grid;gap:8px}.integration-target-label{font-weight:700}.integration-subfields{display:grid;gap:10px;padding:13px;border:1px solid #ebe6ef;border-radius:12px;background:#fff}.external-user-search-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;align-items:end}.external-user-results{display:grid;gap:7px}.external-user-result{width:100%;display:flex;justify-content:space-between;gap:12px;padding:10px 12px;border:1px solid #e3ddea;border-radius:10px;background:#fff;text-align:left;cursor:pointer}.external-user-result:hover{border-color:#8b5cf6;background:#faf8ff}.external-user-result strong,.external-user-result small{display:block}.external-user-result small{margin-top:2px;color:#716978}.external-user-selected{padding:10px 12px;border-radius:10px;background:#f1ecff;color:#50338a;font-weight:700}@media(max-width:700px){.integration-mode-grid{grid-template-columns:1fr}.integration-create-card{padding:14px}.external-user-search-row{grid-template-columns:1fr}.external-user-search-row .button{width:100%}}
</style>

<script>
(() => {
    const integrationFields = document.getElementById('integrationFields');
    const integrationSelect = document.getElementById('integrationSelect');
    const requesterName = document.getElementById('requesterName');
    const generalRequesterFields = document.getElementById('generalRequesterFields');
    const externalUserFields = document.getElementById('externalUserFields');
    const externalUserSearch = document.getElementById('externalUserSearch');
    const externalUserSearchButton = document.getElementById('externalUserSearchButton');
    const externalRequesterId = document.getElementById('externalRequesterId');
    const externalUserStatus = document.getElementById('externalUserStatus');
    const externalUserResults = document.getElementById('externalUserResults');
    const externalUserSelected = document.getElementById('externalUserSelected');
    const searchUrlTemplate = @json(url('/integracoes/__INTEGRATION__/usuarios'));

    const sourceMode = () => document.querySelector('input[name="source_mode"]:checked')?.value;
    const integrationTarget = () => document.querySelector('input[name="integration_target"]:checked')?.value || 'integration';

    function clearExternalSelection() {
        externalRequesterId.value = '';
        externalUserSelected.hidden = true;
        externalUserSelected.textContent = '';
        externalUserResults.replaceChildren();
        externalUserStatus.textContent = '';
    }

    function syncVisibility() {
        const integrated = sourceMode() === 'integration';
        const external = integrated && integrationTarget() === 'external_user';
        integrationFields.hidden = !integrated;
        integrationSelect.required = integrated;
        generalRequesterFields.hidden = !integrated || external;
        externalUserFields.hidden = !external;

        if (!integrated) {
            requesterName.value = '';
            clearExternalSelection();
        } else if (external) {
            requesterName.value = '';
        } else {
            clearExternalSelection();
        }
    }

    function selectExternalUser(user) {
        externalRequesterId.value = String(user.id || '');
        externalUserSelected.textContent = user.email ? `${user.name} · ${user.email}` : user.name;
        externalUserSelected.hidden = false;
        externalUserResults.replaceChildren();
        externalUserStatus.textContent = 'Usuário selecionado.';
    }

    async function searchUsers() {
        const integrationId = integrationSelect.value;
        const query = externalUserSearch.value.trim();
        if (!integrationId) {
            externalUserStatus.textContent = 'Selecione a integração primeiro.';
            return;
        }
        if (query.length < 2) {
            externalUserStatus.textContent = 'Digite pelo menos 2 caracteres.';
            return;
        }

        clearExternalSelection();
        externalUserStatus.textContent = 'Buscando usuários…';
        externalUserSearchButton.disabled = true;
        try {
            const url = searchUrlTemplate.replace('__INTEGRATION__', encodeURIComponent(integrationId)) + '?search=' + encodeURIComponent(query);
            const response = await fetch(url, {headers: {'Accept': 'application/json'}});
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(payload.message || 'Busca indisponível.');
            const users = Array.isArray(payload.data) ? payload.data : [];
            externalUserResults.replaceChildren();
            if (!users.length) {
                externalUserStatus.textContent = 'Nenhum usuário ativo encontrado.';
                return;
            }
            externalUserStatus.textContent = `${users.length} usuário(s) encontrado(s).`;
            users.forEach(user => {
                if (!user || !user.id || !user.name) return;
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'external-user-result';
                const copy = document.createElement('span');
                const name = document.createElement('strong');
                const email = document.createElement('small');
                name.textContent = String(user.name);
                email.textContent = user.email ? String(user.email) : 'Sem e-mail informado';
                copy.append(name, email);
                const choose = document.createElement('span');
                choose.textContent = 'Selecionar';
                button.append(copy, choose);
                button.addEventListener('click', () => selectExternalUser(user));
                externalUserResults.append(button);
            });
        } catch (error) {
            externalUserStatus.textContent = error instanceof Error ? error.message : 'Não foi possível buscar usuários agora.';
        } finally {
            externalUserSearchButton.disabled = false;
        }
    }

    document.querySelectorAll('input[name="source_mode"], input[name="integration_target"]').forEach(el => el.addEventListener('change', syncVisibility));
    integrationSelect.addEventListener('change', () => {
        clearExternalSelection();
        externalUserSearch.value = '';
    });
    externalUserSearchButton.addEventListener('click', searchUsers);
    externalUserSearch.addEventListener('keydown', event => {
        if (event.key === 'Enter') {
            event.preventDefault();
            searchUsers();
        }
    });

    if (externalRequesterId.value) {
        externalUserSelected.textContent = 'Usuário selecionado anteriormente: ' + externalRequesterId.value;
        externalUserSelected.hidden = false;
    }
    syncVisibility();
})();
</script>
@endsection