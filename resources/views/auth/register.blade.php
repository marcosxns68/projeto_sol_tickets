@extends('layouts.app')
@section('title','Criar conta — Sutoorii Tickets')
@section('content')
<section class="auth-card">
    <h1>Criar conta</h1>
    <p>Disponível apenas para e-mails @sutoorii.com.</p>
    <form method="post" action="{{ route('register.store') }}">
        @csrf
        <label>Nome
            <input name="name" value="{{ old('name') }}" autocomplete="name" required>
        </label>
        <label>E-mail
            <input name="email" type="email" value="{{ old('email') }}" autocomplete="email" required>
        </label>
        <label>Telefone / WhatsApp
            <input name="whatsapp" type="tel" inputmode="tel" autocomplete="tel" maxlength="30"
                   value="{{ old('whatsapp') }}" placeholder="(15) 99999-8888" required>
            <small>Esse número será salvo automaticamente no seu perfil para os avisos do sistema.</small>
        </label>
        <label>Senha
            <input name="password" type="password" autocomplete="new-password" required>
        </label>
        <label>Confirmar senha
            <input name="password_confirmation" type="password" autocomplete="new-password" required>
        </label>
        @if($errors->any())<div class="error">{{ $errors->first() }}</div>@endif
        <button class="button full">Criar e validar e-mail</button>
    </form>
</section>
@endsection
