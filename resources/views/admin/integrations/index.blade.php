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
        <label>Webhook de retorno <small class="muted">opcional por enquanto</small>
            <input name="webhook_url" value="{{ old('webhook_url') }}" placeholder="https://.../webhook">
        </label>
        <label class="toggle-card">
            <input type="checkbox" name="active" value="1" @checked(old('active',true))>
            <span><b>Integração ativa</b><small>A chave pode ser usada pelo sistema externo.</small></span>
        </label>
        <div><button class="button" type="submit">Criar integração e gerar chave</button></div>
    </form>
</article>

<article class="panel table-panel mobile-card-panel">
    <div class="responsive-table desktop-admin-table">
        <table class="admin-table">
            <thead><tr><th>Integração</th><th>Status</th><th>Chave</th><th></th></tr></thead>
            <tbody>
            @forelse($integrations as $integration)
                <tr>
                    <td><b>{{ $integration->name }}</b>@if($integration->base_url)<div class="muted">{{ $integration->base_url }}</div>@endif</td>
                    <td><span class="pill {{ $integration->active?'ok':'off' }}">{{ $integration->active?'Ativa':'Inativa' }}</span></td>
                    <td><span class="muted">{{ $integration->api_token_hash ? 'Configurada' : 'Não gerada' }}</span></td>
                    <td class="right">
                        <form action="{{ route('admin.integrations.rotate-key',$integration) }}" method="post" style="display:inline" onsubmit="return confirm('Gerar uma nova chave? A chave atual deixará de funcionar imediatamente.');">
                            @csrf
                            <button class="secondary-button compact" type="submit">Gerar nova chave</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">Nenhuma integração cadastrada.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mobile-admin-list">
    @forelse($integrations as $integration)
        <article class="mobile-admin-card">
            <div class="mobile-admin-card-head">
                <div><h3>{{ $integration->name }}</h3><span class="mobile-admin-sub">Chave {{ $integration->api_token_hash ? 'configurada' : 'não gerada' }}</span></div>
                <span class="pill {{ $integration->active?'ok':'off' }}">{{ $integration->active?'Ativa':'Inativa' }}</span>
            </div>
            @if($integration->base_url)<p class="muted">{{ $integration->base_url }}</p>@endif
            <div class="mobile-admin-actions">
                <form action="{{ route('admin.integrations.rotate-key',$integration) }}" method="post" onsubmit="return confirm('Gerar uma nova chave? A chave atual deixará de funcionar imediatamente.');">
                    @csrf
                    <button class="secondary-button compact" type="submit">Gerar nova chave</button>
                </form>
            </div>
        </article>
    @empty
        <div class="mobile-admin-empty">Nenhuma integração cadastrada.</div>
    @endforelse
    </div>
</article>
@endsection
