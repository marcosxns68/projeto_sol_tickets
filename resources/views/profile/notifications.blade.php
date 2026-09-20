@extends('layouts.app')
@section('title', 'Meu WhatsApp — Sutoorii Tickets')
@section('content')
<div class="page-head"><div><p class="eyebrow">MEU PERFIL</p><h1>Notificações por WhatsApp</h1><p class="muted">Cadastre seu número para receber avisos quando um cliente responder a um ticket sob sua responsabilidade.</p></div></div>
@if($errors->any())
    <div class="alert error-box"><strong>Não foi possível salvar.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
<form method="post" action="{{ route('profile.notifications.update') }}" class="admin-editor">
    @csrf @method('PATCH')
    <article class="panel">
        <div class="form-grid">
            <label>Meu WhatsApp (DDD + número)
                <input type="tel" inputmode="tel" autocomplete="tel" name="whatsapp" maxlength="30"
                       placeholder="(15) 99999-8888" value="{{ old('whatsapp', $managedUser->whatsapp) }}">
            </label>
            <label class="toggle-card">
                <input type="hidden" name="whatsapp_reply_enabled" value="0">
                <input type="checkbox" name="whatsapp_reply_enabled" value="1"
                       @checked(old('whatsapp_reply_enabled', $managedUser->whatsapp_reply_enabled))>
                <span><b>Receber avisos de respostas dos clientes</b>
                    <small>Quando você for o responsável pelo ticket, o sistema tentará avisar pelo WhatsApp, além do e-mail e do sininho. Você pode desabilitar aqui.</small>
                </span>
            </label>
        </div>
        <p class="muted">O número fica protegido no banco. Você só receberá mensagens se houver número válido, conexão ativa e a automação habilitada nas Configurações de notificações.</p>
    </article>
    <div class="sticky-actions"><button class="button" type="submit">Salvar minhas preferências</button></div>
</form>
@endsection
