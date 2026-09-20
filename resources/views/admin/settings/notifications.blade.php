@extends('layouts.app')
@section('title','Configurações de notificações — Sutoorii Tickets')
@section('content')
<link rel="stylesheet" href="{{ asset('css/notification-settings.css') }}?v={{ filemtime(public_path('css/notification-settings.css')) }}">
<div class="notification-settings-page">
<div class="page-head">
    <div>
        <p class="eyebrow">ADMINISTRAÇÃO</p>
        <h1>Configurações de notificações</h1>
        <p class="muted">Personalize as mensagens automáticas de WhatsApp. As notificações por e-mail e do painel são configuradas separadamente.</p>
    </div>
</div>

@if($errors->any())
    <div class="alert error-box"><strong>Confira as mensagens antes de salvar.</strong>
        <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif

<form class="admin-editor" method="post" action="{{ route('admin.settings.notifications.update-all') }}">
    @csrf
    @method('PATCH')
    @foreach($automations as $event => $configuration)
    <details class="notification-automation" data-notification-automation="{{ $event }}" @if($event === 'opened' || $errors->has('automations.'.$event.'.message') || $errors->has('automations.'.$event.'.enabled')) open @endif>
        <summary>
            <span class="notification-summary-icon" aria-hidden="true">{{ ['opened'=>'↗', 'closed'=>'✓', 'comment'=>'☏', 'status'=>'↻'][$event] }}</span>
            <span class="notification-summary-copy"><strong>{{ $configuration['label'] }}</strong><small>WhatsApp · solicitante</small></span>
            <span class="notification-summary-state {{ $configuration['enabled'] ? 'is-enabled' : '' }}">{{ $configuration['enabled'] ? 'Ativada' : 'Desativada' }}</span>
            <span class="notification-summary-chevron" aria-hidden="true">›</span>
        </summary>
        <div class="notification-automation-content">
            @if($event === 'opened')
                <p class="muted">Enviada quando um novo ticket é aberto.</p>
            @elseif($event === 'closed')
                <p class="muted">Enviada quando um ticket é fechado. Marcar como resolvido não dispara este aviso.</p>
            @elseif($event === 'comment')
                <p class="muted">Enviada quando a equipe publica um comentário. Notas internas e respostas do próprio solicitante não disparam este aviso.</p>
            @elseif($event === 'status')
                <p class="muted">Enviada quando a equipe altera o status. O fechamento possui aviso próprio.</p>
            @endif
            <label class="notification-enable">
                <input type="hidden" name="automations[{{ $event }}][enabled]" value="0">
                <input type="checkbox" name="automations[{{ $event }}][enabled]" value="1" @checked(old('automations.'.$event.'.enabled', $configuration['enabled']))>
                <strong>Habilitar notificação</strong>
            </label>
            <label for="notification-message-{{ $event }}" class="notification-message-field">Mensagem automática
                <textarea id="notification-message-{{ $event }}" name="automations[{{ $event }}][message]" rows="6" maxlength="2000" required>{{ old('automations.'.$event.'.message', $configuration['message']) }}</textarea>
            </label>
        </div>
    </details>
    @endforeach

    <article class="panel notification-settings-help">
        <p class="muted">Variáveis: <code>{numero}</code> (número do ticket), <code>{assunto}</code> (assunto informado pelo cliente) e <code>{status}</code> (status atual). As quebras de linha serão mantidas.</p>
        <p class="muted">Os avisos são enviados somente ao WhatsApp do solicitante quando houver número válido e conexão ativa na Evolution API.</p>
        <a href="{{ route('admin.settings.whatsapp.edit') }}">Configurar conexão do WhatsApp</a>
    </article>
    <div class="sticky-actions notification-settings-actions"><button class="button" type="submit">Salvar configurações</button></div>
</form>
</div>
@endsection
