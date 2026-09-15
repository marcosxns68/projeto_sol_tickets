@extends('layouts.app')
@section('title','Configuração de e-mail — Sutoorii Tickets')
@section('content')
<div class="page-head">
    <div>
        <p class="eyebrow">ADMINISTRAÇÃO</p>
        <h1>Configuração de e-mail</h1>
        <p class="muted">Configure o servidor SMTP usado para confirmações de conta, recuperação de senha e demais e-mails do sistema.</p>
    </div>
</div>

@if($errors->any())
<div class="alert error-box"><strong>Não foi possível concluir a ação.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

<form action="{{ route('admin.settings.mail.update') }}" method="post" class="admin-editor">
    @csrf
    @method('PATCH')

    <article class="panel">
        <div class="section-title"><div><p class="eyebrow">SERVIDOR</p><h2>Conexão SMTP</h2><p class="muted">Use os dados fornecidos pelo provedor da conta de e-mail.</p></div></div>
        <div class="grid form-grid">
            <label>Servidor SMTP
                <input name="host" value="{{ old('host',$mailSettings['host']) }}" placeholder="mail.exemplo.com" required>
            </label>
            <label>Porta
                <input type="number" name="port" min="1" max="65535" value="{{ old('port',$mailSettings['port']) }}" required>
            </label>
            <label>Criptografia
                @php($encryption=old('encryption',$mailSettings['encryption']))
                <select name="encryption" required>
                    <option value="ssl" @selected($encryption==='ssl')>SSL</option>
                    <option value="tls" @selected($encryption==='tls')>TLS / STARTTLS</option>
                    <option value="none" @selected($encryption==='none')>Sem criptografia</option>
                </select>
            </label>
            <label>Usuário SMTP
                <input name="username" value="{{ old('username',$mailSettings['username']) }}" autocomplete="username" placeholder="usuario@exemplo.com">
            </label>
        </div>
    </article>

    <article class="panel">
        <div class="section-title">
            <div>
                <p class="eyebrow">CREDENCIAL</p>
                <h2>Senha SMTP</h2>
                <p class="muted">A senha é armazenada criptografada e nunca é exibida novamente.</p>
            </div>
        </div>
        @if($mailSettings['password_saved'])
            <div class="alert"><strong>Senha já configurada</strong><br><span class="muted">Deixe o campo abaixo vazio para manter a senha atual.</span></div>
        @endif
        <div class="grid form-grid">
            <label>Nova senha SMTP
                <input type="password" name="password" value="" autocomplete="new-password" placeholder="{{ $mailSettings['password_saved'] ? 'Deixe em branco para manter a atual' : 'Digite a senha da conta' }}">
            </label>
        </div>
    </article>

    <article class="panel">
        <div class="section-title"><div><p class="eyebrow">REMETENTE</p><h2>Identificação dos e-mails</h2></div></div>
        <div class="grid form-grid">
            <label>E-mail remetente
                <input type="email" name="from_address" value="{{ old('from_address',$mailSettings['from_address']) }}" required>
            </label>
            <label>Nome remetente
                <input name="from_name" value="{{ old('from_name',$mailSettings['from_name']) }}" required>
            </label>
        </div>
    </article>

    <div class="sticky-actions">
        <button class="button" type="submit">Salvar configuração</button>
    </div>
</form>

<article class="panel">
    <div class="section-title">
        <div>
            <p class="eyebrow">TESTE</p>
            <h2>Enviar e-mail de teste</h2>
            <p class="muted">Salve a configuração acima e envie uma mensagem de teste para confirmar a conexão com o servidor SMTP.</p>
        </div>
    </div>
    <form action="{{ route('admin.settings.mail.test') }}" method="post">
        @csrf
        <div class="grid form-grid">
            <label>E-mail de destino
                <input type="email" name="test_email" value="{{ old('test_email',auth()->user()->email) }}" required>
            </label>
        </div>
        <div class="sticky-actions">
            <button class="secondary-button" type="submit">Enviar e-mail de teste</button>
        </div>
    </form>
</article>
@endsection