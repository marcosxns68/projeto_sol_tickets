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
                <span><b>Integração</b><small>O chamado também aparecerá no painel do sistema escolhido.</small></span>
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

            <div class="integration-target-grid">
                <label class="integration-mode-option">
                    <input type="radio" name="integration_target" value="integration" {{ old('integration_target','integration') === 'integration' ? 'checked' : '' }}>
                    <span><b>Chamado geral da integração</b><small>Visível para os gestores do sistema integrado.</small></span>
                </label>
                <label class="integration-mode-option">
                    <input type="radio" name="integration_target" value="external_user" {{ old('integration_target') === 'external_user' ? 'checked' : '' }}>
                    <span><b>Usuário específico</b><small>Também aparecerá em “Meus chamados” da pessoa escolhida.</small></span>
                </label>
            </div>

            <div id="externalUserFields" {{ old('integration_target') === 'external_user' ? '' : 'hidden' }}>
                <label>Buscar usuário
                    <input type="search" id="externalUserSearch" autocomplete="off" placeholder="Digite pelo menos 2 letras do nome ou e-mail">
                </label>
                <input type="hidden" name="external_requester_id" id="externalRequesterId" value="{{ old('external_requester_id') }}">
                <div id="externalUserSelected" class="external-user-selected" hidden></div>
                <div id="externalUserResults" class="external-user-results" aria-live="polite"></div>
                <small class="muted">A busca consulta diretamente o sistema integrado e traz no máximo 20 resultados.</small>
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
.integration-create-card{border:1px solid #e7e2ec;border-radius:16px;padding:18px;display:grid;gap:14px;background:#fbfafc}.integration-mode-grid,.integration-target-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.integration-mode-option{display:flex!important;align-items:flex-start;gap:10px;border:1px solid #e4deea;border-radius:12px;padding:13px;background:#fff;cursor:pointer}.integration-mode-option input{width:auto;margin-top:3px}.integration-mode-option span{display:grid;gap:3px}.integration-mode-option small,.muted{color:#716978;font-size:.82rem}.integration-fields{display:grid;gap:14px;border-top:1px solid #e8e2ed;padding-top:14px}.external-user-results{display:grid;gap:6px;margin-top:8px}.external-user-result{width:100%;border:1px solid #e2dce8;background:#fff;border-radius:10px;padding:10px 12px;text-align:left;cursor:pointer}.external-user-result:hover{border-color:#7c3aed}.external-user-result strong,.external-user-result small{display:block}.external-user-selected{border:1px solid #c9b7e8;background:#f5f0ff;border-radius:10px;padding:10px 12px;margin-top:8px}@media(max-width:700px){.integration-mode-grid,.integration-target-grid{grid-template-columns:1fr}.integration-create-card{padding:14px}}
</style>

<script>
(() => {
    const integrationFields = document.getElementById('integrationFields');
    const externalFields = document.getElementById('externalUserFields');
    const integrationSelect = document.getElementById('integrationSelect');
    const search = document.getElementById('externalUserSearch');
    const results = document.getElementById('externalUserResults');
    const selectedId = document.getElementById('externalRequesterId');
    const selectedBox = document.getElementById('externalUserSelected');
    let timer = null;
    let controller = null;

    const sourceMode = () => document.querySelector('input[name="source_mode"]:checked')?.value;
    const targetMode = () => document.querySelector('input[name="integration_target"]:checked')?.value;

    function clearUser() {
        selectedId.value = '';
        selectedBox.hidden = true;
        selectedBox.textContent = '';
        results.innerHTML = '';
    }

    function syncVisibility() {
        integrationFields.hidden = sourceMode() !== 'integration';
        externalFields.hidden = targetMode() !== 'external_user' || integrationFields.hidden;
        if (externalFields.hidden) clearUser();
    }

    document.querySelectorAll('input[name="source_mode"],input[name="integration_target"]').forEach(el => el.addEventListener('change', syncVisibility));
    integrationSelect.addEventListener('change', clearUser);

    search.addEventListener('input', () => {
        clearTimeout(timer);
        results.innerHTML = '';
        const query = search.value.trim();
        const integrationId = integrationSelect.value;
        if (query.length < 2 || !integrationId) return;

        timer = setTimeout(async () => {
            if (controller) controller.abort();
            controller = new AbortController();
            results.textContent = 'Buscando…';
            try {
                const response = await fetch(`/integracoes/${encodeURIComponent(integrationId)}/usuarios?search=${encodeURIComponent(query)}`, {
                    headers: {'Accept':'application/json'}, signal: controller.signal
                });
                const payload = await response.json();
                results.innerHTML = '';
                if (!response.ok) {
                    results.textContent = payload.message || 'Não foi possível consultar os usuários.';
                    return;
                }
                if (!payload.data?.length) {
                    results.textContent = 'Nenhum usuário encontrado.';
                    return;
                }
                payload.data.forEach(user => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'external-user-result';
                    const name = document.createElement('strong');
                    name.textContent = user.name;
                    const email = document.createElement('small');
                    email.textContent = user.email || 'Sem e-mail cadastrado';
                    button.append(name, email);
                    button.addEventListener('click', () => {
                        selectedId.value = user.id;
                        selectedBox.textContent = `${user.name}${user.email ? ' — '+user.email : ''}`;
                        selectedBox.hidden = false;
                        results.innerHTML = '';
                        search.value = user.name;
                    });
                    results.appendChild(button);
                });
            } catch (error) {
                if (error.name !== 'AbortError') results.textContent = 'Não foi possível consultar os usuários.';
            }
        }, 300);
    });

    syncVisibility();
})();
</script>
@endsection
