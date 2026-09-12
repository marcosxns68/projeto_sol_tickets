@extends('layouts.app')
@section('title','Integrações — Sutoorii Tickets')
@section('content')
<div class="page-head">
    <div>
        <p class="eyebrow">ADMINISTRAÇÃO</p>
        <h1>Integrações</h1>
        <p class="muted">Cadastre cada sistema externo. A chave identifica e isola a origem dos tickets.</p>
    </div>
</div>

@if($errors->any())
<div class="alert error-box"><strong>Não foi possível salvar.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

@if($generatedToken)
<article class="panel" style="border:1px solid #8b5cf6;">
    <div class="section-title"><div><p class="eyebrow">CHAVE GERADA</p><h2>Copie esta chave agora</h2></div></div>
    <p class="muted">Por segurança, ela será exibida somente desta vez. Se perder a chave, gere uma nova.</p>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:14px;">
        <input id="generatedIntegrationKey" value="{{ $generatedToken }}" readonly style="flex:1;min-width:260px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">
        <button type="button" class="secondary-button" onclick="navigator.clipboard.writeText(document.getElementById('generatedIntegrationKey').value);this.textContent='Copiado';">Copiar chave</button>
    </div>
</article>
@endif

@if($generatedWebhookSecret)
<article class="panel" style="border:1px solid #8b5cf6;">
    <div class="section-title"><div><p class="eyebrow">SEGREDO DO WEBHOOK</p><h2>Copie o segredo do webhook agora</h2></div></div>
    <p class="muted">Ele será exibido somente desta vez e deve ficar apenas no servidor do sistema integrado.</p>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:14px;">
        <input id="generatedWebhookSecret" value="{{ $generatedWebhookSecret }}" readonly style="flex:1;min-width:260px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">
        <button type="button" class="secondary-button" onclick="navigator.clipboard.writeText(document.getElementById('generatedWebhookSecret').value);this.textContent='Copiado';">Copiar segredo</button>
    </div>
</article>
@endif

<article class="panel">
    <div class="section-title"><div><p class="eyebrow">NOVO</p><h2>Nova integração</h2></div></div>
    <form action="{{ route('admin.integrations.store') }}" method="post" class="grid form-grid">
        @csrf
        <label>Nome da integração
            <input name="name" value="{{ old('name') }}" placeholder="Ex.: Estúdio França" required>
        </label>
        <label>Endereço do sistema <small class="muted">opcional</small>
            <input name="base_url" value="{{ old('base_url') }}" placeholder="https://...">
        </label>
        <label>Webhook de retorno <small class="muted">opcional</small>
            <input name="webhook_url" value="{{ old('webhook_url') }}" placeholder="https://.../webhook">
        </label>
        <label>Departamento padrão
            <select name="department_id" required>
                <option value="">Selecione o departamento</option>
                @foreach($departments as $department)
                    <option value="{{ $department->id }}" @selected((string) old('department_id') === (string) $department->id)>{{ $department->name }}</option>
                @endforeach
            </select>
            <small class="muted">Todo novo ticket recebido por esta integração entra neste departamento.</small>
        </label>
        <label class="toggle-card">
            <input type="checkbox" name="active" value="1" @checked(old('active',true))>
            <span><b>Integração ativa</b><small>A chave pode ser usada pelo sistema externo.</small></span>
        </label>
        <div><button class="button" type="submit">Criar integração e gerar chave</button></div>
    </form>
</article>

<article class="panel">
    <div class="section-title"><div><p class="eyebrow">RETORNO AUTOMÁTICO</p><h2>Configurar webhook</h2></div></div>
    <p class="muted">Em cada integração, informe a URL HTTPS que receberá respostas e mudanças do ticket. Depois use <b>Gerar segredo webhook</b> e guarde o segredo no servidor do sistema externo.</p>
</article>

<article class="panel table-panel mobile-card-panel">
    <div class="responsive-table desktop-admin-table">
        <table class="admin-table">
            <thead><tr><th>Integração</th><th>Departamento padrão</th><th>Status</th><th>Chave</th><th>Webhook</th><th></th></tr></thead>
            <tbody>
            @forelse($integrations as $integration)
                @php($defaultDepartment = $departments->firstWhere('id', $integrationDepartmentIds[$integration->id] ?? null))
                <tr>
                    <td><b>{{ $integration->name }}</b>@if($integration->base_url)<div class="muted">{{ $integration->base_url }}</div>@endif</td>
                    <td><span class="muted">{{ $defaultDepartment?->name ?? 'Não definido' }}</span></td>
                    <td><span class="pill {{ $integration->active?'ok':'off' }}">{{ $integration->active?'Ativa':'Inativa' }}</span></td>
                    <td><span class="muted">{{ $integration->api_token_hash ? 'Configurada' : 'Não gerada' }}</span></td>
                    <td><span class="muted">{{ $integration->webhook_url ? 'Configurado' : 'Não configurado' }}</span></td>
                    <td class="right">
                        <details style="text-align:left;min-width:260px;">
                            <summary class="secondary-button compact" style="display:inline-block;cursor:pointer;">Configurar</summary>
                            <form action="{{ route('admin.integrations.update',$integration) }}" method="post" class="grid" style="gap:8px;margin-top:12px;">
                                @csrf @method('PATCH')
                                <label>Nome<input name="name" value="{{ $integration->name }}" required></label>
                                <label>Endereço do sistema<input name="base_url" value="{{ $integration->base_url }}" placeholder="https://..."></label>
                                <label>Webhook de retorno<input name="webhook_url" value="{{ $integration->webhook_url }}" placeholder="https://.../webhook"></label>
                                <label>Departamento padrão
                                    <select name="department_id" required>
                                        <option value="">Selecione o departamento</option>
                                        @foreach($departments as $department)
                                            <option value="{{ $department->id }}" @selected((string) ($integrationDepartmentIds[$integration->id] ?? '') === (string) $department->id)>{{ $department->name }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="toggle-card"><input type="checkbox" name="active" value="1" @checked($integration->active)><span><b>Integração ativa</b></span></label>
                                <button class="button compact" type="submit">Salvar configuração</button>
                            </form>
                            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
                                <form action="{{ route('admin.integrations.rotate-key',$integration) }}" method="post" onsubmit="return confirm('Gerar uma nova chave? A chave atual deixará de funcionar imediatamente.');">
                                    @csrf
                                    <button class="secondary-button compact" type="submit">Gerar nova chave</button>
                                </form>
                                <form action="{{ route('admin.integrations.rotate-webhook-secret',$integration) }}" method="post" onsubmit="return confirm('Gerar um novo segredo de webhook? O segredo anterior deixará de validar novos envios.');">
                                    @csrf
                                    <button class="secondary-button compact" type="submit">Gerar segredo webhook</button>
                                </form>
                            </div>
                        </details>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">Nenhuma integração cadastrada.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mobile-admin-list">
    @forelse($integrations as $integration)
        @php($defaultDepartment = $departments->firstWhere('id', $integrationDepartmentIds[$integration->id] ?? null))
        <article class="mobile-admin-card">
            <div class="mobile-admin-card-head">
                <div><h3>{{ $integration->name }}</h3><span class="mobile-admin-sub">Departamento {{ $defaultDepartment?->name ?? 'não definido' }} · Chave {{ $integration->api_token_hash ? 'configurada' : 'não gerada' }} · Webhook {{ $integration->webhook_url ? 'configurado' : 'não configurado' }}</span></div>
                <span class="pill {{ $integration->active?'ok':'off' }}">{{ $integration->active?'Ativa':'Inativa' }}</span>
            </div>
            <details style="margin-top:10px;">
                <summary class="secondary-button compact" style="display:inline-block;cursor:pointer;">Configurar</summary>
                <form action="{{ route('admin.integrations.update',$integration) }}" method="post" class="grid" style="gap:8px;margin-top:12px;">
                    @csrf @method('PATCH')
                    <label>Nome<input name="name" value="{{ $integration->name }}" required></label>
                    <label>Endereço do sistema<input name="base_url" value="{{ $integration->base_url }}" placeholder="https://..."></label>
                    <label>Webhook de retorno<input name="webhook_url" value="{{ $integration->webhook_url }}" placeholder="https://.../webhook"></label>
                    <label>Departamento padrão
                        <select name="department_id" required>
                            <option value="">Selecione o departamento</option>
                            @foreach($departments as $department)
                                <option value="{{ $department->id }}" @selected((string) ($integrationDepartmentIds[$integration->id] ?? '') === (string) $department->id)>{{ $department->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="toggle-card"><input type="checkbox" name="active" value="1" @checked($integration->active)><span><b>Integração ativa</b></span></label>
                    <button class="button compact" type="submit">Salvar configuração</button>
                </form>
                <div class="mobile-admin-actions" style="margin-top:10px;">
                    <form action="{{ route('admin.integrations.rotate-key',$integration) }}" method="post" onsubmit="return confirm('Gerar uma nova chave? A chave atual deixará de funcionar imediatamente.');">
                        @csrf
                        <button class="secondary-button compact" type="submit">Gerar nova chave</button>
                    </form>
                    <form action="{{ route('admin.integrations.rotate-webhook-secret',$integration) }}" method="post" onsubmit="return confirm('Gerar um novo segredo de webhook? O segredo anterior deixará de validar novos envios.');">
                        @csrf
                        <button class="secondary-button compact" type="submit">Gerar segredo webhook</button>
                    </form>
                </div>
            </details>
        </article>
    @empty
        <div class="mobile-admin-empty">Nenhuma integração cadastrada.</div>
    @endforelse
    </div>
</article>
@endsection
