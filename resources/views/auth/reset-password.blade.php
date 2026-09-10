@extends('layouts.app')
@section('title','Criar nova senha — Sutoorii Tickets')
@section('content')
<section class="auth-card"><p class="eyebrow">NOVA SENHA</p><h1>Redefinir senha</h1><p>Crie uma senha com pelo menos 10 caracteres, letras maiúsculas, minúsculas e números.</p><form method="post" action="{{ route('password.update') }}">@csrf<input type="hidden" name="token" value="{{ $token }}"><label>E-mail<input name="email" type="email" value="{{ old('email', $email) }}" required autocomplete="email"></label><label>Nova senha<input name="password" type="password" required autocomplete="new-password"></label><label>Confirmar nova senha<input name="password_confirmation" type="password" required autocomplete="new-password"></label>@if($errors->any())<div class="error">{{ $errors->first() }}</div>@endif<button class="button full">Salvar nova senha</button></form></section>
@endsection
