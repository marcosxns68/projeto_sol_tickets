@extends('layouts.app')
@section('title','WhatsApp — Sutoorii Tickets')
@section('content')
<div class="page-head"><div>
    <p class="eyebrow">ADMINISTRAÇÃO</p>
    <h1>WhatsApp</h1>
    <p class="muted">Conecte a conta da Sutoorii Tickets por QR Code. Esta página prepara a conexão; o envio automático de notificações pelo WhatsApp será configurado separadamente.</p>
</div></div>

@if($errors->any())
<div class="alert error-box"><strong>Não foi possível concluir a ação.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

<form class="admin-editor" method="post" action="{{ route('admin.settings.whatsapp.update') }}">
    @csrf @method('PATCH')
    <article class="panel">
        <div class="section-title"><div><p class="eyebrow">CONEXÃO</p><h2>Evolution API</h2><p class="muted">Informe o endereço público HTTPS da sua Evolution API e os dados de uma instância já criada.</p></div></div>
        <div class="grid form-grid">
            <label>Endereço da API
                <input type="url" name="base_url" value="{{ old('base_url', $settings['base_url']) }}" placeholder="https://evolution.seudominio.com.br" required>
            </label>
            <label>Nome da instância
                <input name="instance" value="{{ old('instance', $settings['instance']) }}" pattern="[a-zA-Z0-9_-]{1,80}" maxlength="80" placeholder="sutoorii-tickets" required>
            </label>
            <label>Chave de API
                <input type="password" name="api_key" autocomplete="new-password" value="" placeholder="{{ $settings['api_key_saved'] ? 'Deixe vazio para manter a chave salva' : 'Cole a chave da instância ou API' }}" @required(!$settings['api_key_saved'])>
                <small class="muted">{{ $settings['api_key_saved'] ? 'Chave armazenada com criptografia. Deixe vazio para mantê-la.' : 'A chave será armazenada com criptografia e não será exibida novamente.' }}</small>
            </label>
        </div>
        <div class="sticky-actions"><button class="button" type="submit">Salvar configuração</button></div>
    </article>
</form>

<article class="panel">
    <div class="section-title"><div><p class="eyebrow">PAREAMENTO</p><h2>Conectar WhatsApp</h2><p class="muted">Depois de salvar, abra o WhatsApp no celular e acesse Aparelhos conectados → Conectar um aparelho para ler o QR Code abaixo.</p></div></div>
    <div class="actions">
        <form action="{{ route('admin.settings.whatsapp.qrcode') }}" method="post">@csrf<button class="button" type="submit">Gerar / atualizar QR Code</button></form>
        <form action="{{ route('admin.settings.whatsapp.status') }}" method="post">@csrf<button class="secondary-button" type="submit">Consultar conexão</button></form>
    </div>
    @if($connectionState !== null)
        <p class="notice">Estado da conexão: <strong>{{ $connectionState === 'open' ? 'Conectado' : $connectionState }}</strong></p>
    @endif
    @if($qrCode)
        <div style="margin-top:16px;max-width:360px;text-align:center">
            <img src="{{ $qrCode }}" width="320" height="320" style="display:block;width:100%;height:auto;border-radius:12px;border:1px solid #eee;padding:12px;background:white" alt="QR Code para conectar WhatsApp">
            <p class="muted">Escaneie com o WhatsApp. Se expirar, gere outro QR Code.</p>
        </div>
    @endif
</article>
@endsection
