@extends('layouts.app')
@section('title','Recuperar senha — Sutoorii Tickets')
@section('content')
<section class="auth-card">
    <p class="eyebrow">RECUPERAÇÃO DE ACESSO</p>
    <h1>Esqueceu a senha?</h1>
    <p>Informe o e-mail cadastrado na sua conta. Enviaremos um link temporário para criar uma nova senha.</p>

    <form method="post" action="{{ route('password.email') }}">
        @csrf
        <label>
            E-mail
            <input name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email" placeholder="seu@email.com">
        </label>
        @error('email')
            <div class="error">{{ $message }}</div>
        @enderror
        <button class="button full">Enviar link de recuperação</button>
    </form>

    <p class="muted"><a href="{{ route('login') }}">Voltar para o login</a></p>
</section>
@endsection
