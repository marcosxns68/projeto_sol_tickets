@extends('layouts.app')
@section('title','Entrar — Sutoorii Tickets')
@section('content')
<section class="auth-card">
    <h1>Bem-vindo</h1>
    <p>Acesse a central da Sutoorii.</p>

    <form method="post" action="{{ route('login.perform') }}">
        @csrf

        <label>
            E-mail
            <input name="email" type="email" value="{{ old('email') }}" required autocomplete="email">
        </label>

        <label>
            Senha
            <input name="password" type="password" required autocomplete="current-password">
        </label>

        <label class="toggle-card">
            <input name="remember" type="checkbox" value="1" @checked(old('remember'))>
            <span>
                <b>Permanecer conectado neste dispositivo</b>
                <small>Evita precisar entrar novamente neste celular ou computador.</small>
            </span>
        </label>

        @error('email')<small class="error">{{ $message }}</small>@enderror

        <p class="muted"><a href="{{ route('password.request') }}">Esqueci minha senha</a></p>
        <button class="button full">Entrar</button>
    </form>

    <p class="muted">Primeiro acesso? <a href="{{ route('register') }}">Criar conta</a></p>
</section>
@endsection
