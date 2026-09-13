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
                <span><b>Integração</b><small>O chamado também aparecerá no painel geral do sistema escolhido.</small></span>
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

            <label>Nome do solicitante
                <input type="text" name="requester_name" id="requesterName" value="{{ old('requester_name') }}" maxlength="160" placeholder="Ex.: Maria da Silva">
                <small class="muted">O nome será registrado no chamado, sem vincular o ticket a um usuário específico do sistema integrado.</small>
            </label>
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
.integration-create-card{border:1px solid #e7e2ec;border-radius:16px;padding:18px;display:grid;gap:14px;background:#fbfafc}.integration-mode-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.integration-mode-option{display:flex!important;align-items:flex-start;gap:10px;border:1px solid #e4deea;border-radius:12px;padding:13px;background:#fff;cursor:pointer}.integration-mode-option input{width:auto;margin-top:3px}.integration-mode-option span{display:grid;gap:3px}.integration-mode-option small,.muted{color:#716978;font-size:.82rem}.integration-fields{display:grid;gap:14px;border-top:1px solid #e8e2ed;padding-top:14px}@media(max-width:700px){.integration-mode-grid{grid-template-columns:1fr}.integration-create-card{padding:14px}}
</style>

<script>
(() => {
    const integrationFields = document.getElementById('integrationFields');
    const integrationSelect = document.getElementById('integrationSelect');
    const requesterName = document.getElementById('requesterName');
    const sourceMode = () => document.querySelector('input[name="source_mode"]:checked')?.value;

    function syncVisibility() {
        const integrated = sourceMode() === 'integration';
        integrationFields.hidden = !integrated;
        integrationSelect.required = integrated;
        requesterName.required = integrated;
        if (!integrated) requesterName.value = '';
    }

    document.querySelectorAll('input[name="source_mode"]').forEach(el => el.addEventListener('change', syncVisibility));
    syncVisibility();
})();
</script>
@endsection
