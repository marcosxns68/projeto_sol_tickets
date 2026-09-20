@extends('layouts.app')
@section('title','Configurações de notificações — Sutoorii Tickets')
@section('content')
<div class="page-head">
    <div>
        <p class="eyebrow">ADMINISTRAÇÃO</p>
        <h1>Configurações de notificações</h1>
        <p class="muted">Personalize cada mensagem automática do WhatsApp. As notificações de e-mail e do painel continuam funcionando independentemente destas opções.</p>
    </div>
</div>

@if($errors->any())
    <div class="alert error-box"><strong>Confira a mensagem antes de salvar.</strong>
        <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif

@foreach($automations as $event => $configuration)
    <form class="admin-editor" method="post" action="{{ route('admin.settings.notifications.update', ['event' => $event]) }}">
        @csrf
        @method('PATCH')
        <input type="hidden" name="automation_event" value="{{ $event }}">
        <article class="panel">
            <div class="section-title">
                <div>
                    <p class="eyebrow">WHATSAPP · SOLICITANTE</p>
                    <h2>{{ $configuration['label'] }}</h2>
                    @if($event === 'opened')
                        <p class="muted">Enviada quando um novo ticket é aberto.</p>
                    @elseif($event === 'closed')
                        <p class="muted">Enviada quando um ticket passa para Fechado. Apenas marcar como Resolvido não dispara este aviso.</p>
                    @endif
                </div>
            </div>
            <label style="display:flex;align-items:center;gap:10px;margin:12px 0 20px">
                <input type="hidden" name="enabled" value="0">
                <input type="checkbox" name="enabled" value="1" style="width:auto" @checked(old('automation_event') === $event ? old('enabled', $configuration['enabled']) : $configuration['enabled'])>
                <strong>Habilitar notificação</strong>
            </label>
            <label for="message-{{ $event }}">Mensagem automática</label>
            <textarea id="message-{{ $event }}" name="message" rows="6" maxlength="2000" required style="width:100%;margin-top:8px;white-space:pre-wrap">{{ old('automation_event') === $event ? old('message', $configuration['message']) : $configuration['message'] }}</textarea>
            <p class="muted" style="margin:10px 0 0">Use <code>{numero}</code> para inserir automaticamente o número do ticket. As quebras de linha e o formato do texto serão mantidos.</p>
            <div class="sticky-actions"><button class="button" type="submit">Salvar {{ strtolower($configuration['label']) }}</button></div>
        </article>
    </form>
@endforeach

<article class="panel">
    <p class="muted">As mensagens são enviadas somente ao WhatsApp cadastrado para o solicitante. Sem número válido ou sem conexão ativa na Evolution API, o ticket continua funcionando, mas o aviso não é entregue. Cada confirmação é enviada uma vez por ticket.</p>
    <a href="{{ route('admin.settings.whatsapp.edit') }}">Configurar conexão do WhatsApp</a>
</article>
@endsection
