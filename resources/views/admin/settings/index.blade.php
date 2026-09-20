@extends('layouts.app')

@section('title', 'Configurações — Sutoorii Tickets')

@section('content')
<div class="page-head">
    <div>
        <p class="eyebrow">ADMINISTRAÇÃO</p>
        <h1>Configurações</h1>
        <p class="muted head-sub">Gerencie os prazos, os canais de atendimento e as integrações do Sutoorii Tickets.</p>
    </div>
</div>

<div class="settings-hub-grid">
    @foreach($sections as $section)
        <a class="settings-hub-card" href="{{ route($section['route']) }}">
            <span class="settings-hub-copy">
                <strong>{{ $section['title'] }}</strong>
                <small>{{ $section['description'] }}</small>
            </span>
            <span class="settings-hub-arrow" aria-hidden="true">›</span>
        </a>
    @endforeach
</div>
@endsection
