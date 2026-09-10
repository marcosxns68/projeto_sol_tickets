<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#241438">
<title>@yield('title','Sutoorii Tickets')</title>
<link rel="manifest" href="/manifest.webmanifest">
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body>
@auth
@php($me = auth()->user())
<div class="app-shell">
    <aside class="app-sidebar">
        <div class="sidebar-brand-row">
            <a class="sidebar-brand" href="{{ route('dashboard') }}">
                <span class="brand-mark">S</span>
                <span><strong>Sutoorii</strong><small>Tickets</small></span>
            </a>
        </div>

        @if($me->hasPermission('tickets.create'))
            <a href="{{ route('tickets.create') }}" class="sidebar-new">+ Novo ticket</a>
        @endif

        <nav class="sidebar-nav" aria-label="Navegação principal">
            <p class="sidebar-label">CAIXAS</p>
            <a href="{{ route('boxes.mine') }}" class="sidebar-link {{ request()->routeIs('boxes.mine') ? 'active' : '' }}">Minha Caixa</a>
            @if($me->department_id && $me->hasPermission('tickets.view_department'))
                <a href="{{ route('boxes.department',$me->department_id) }}" class="sidebar-link {{ request()->routeIs('boxes.department') ? 'active' : '' }}">Meu Departamento</a>
            @endif

            @if($me->hasPermission('users.manage') || $me->hasPermission('departments.manage') || $me->hasPermission('roles.manage'))
                <p class="sidebar-label admin-label">ADMINISTRAÇÃO</p>
            @endif
            @if($me->hasPermission('users.manage'))
                <a href="{{ route('admin.users.index') }}" class="sidebar-link {{ request()->routeIs('admin.users.*') ? 'active' : '' }}">Usuários</a>
            @endif
            @if($me->hasPermission('departments.manage'))
                <a href="{{ route('admin.departments.index') }}" class="sidebar-link {{ request()->routeIs('admin.departments.*') ? 'active' : '' }}">Departamentos</a>
            @endif
            @if($me->hasPermission('roles.manage'))
                <a href="{{ route('admin.roles.index') }}" class="sidebar-link {{ request()->routeIs('admin.roles.*') ? 'active' : '' }}">Cargos</a>
            @endif
        </nav>

        <div class="sidebar-user">
            <span class="sidebar-avatar">{{ strtoupper(substr($me->name,0,1)) }}</span>
            <div class="sidebar-user-copy"><strong>{{ $me->name }}</strong><small>{{ $me->role?->name ?? 'Usuário' }}</small></div>
            <form action="{{ route('logout') }}" method="post">@csrf<button class="sidebar-logout" title="Sair">Sair</button></form>
        </div>
    </aside>

    <section class="workspace-main">
        <header class="workspace-topbar">
            <details class="mobile-menu">
                <summary aria-label="Abrir menu">Menu</summary>
                <div class="mobile-menu-panel">
                    <a href="{{ route('boxes.mine') }}">Minha Caixa</a>
                    @if($me->department_id && $me->hasPermission('tickets.view_department'))<a href="{{ route('boxes.department',$me->department_id) }}">Meu Departamento</a>@endif
                    @if($me->hasPermission('users.manage'))<a href="{{ route('admin.users.index') }}">Usuários</a>@endif
                    @if($me->hasPermission('departments.manage'))<a href="{{ route('admin.departments.index') }}">Departamentos</a>@endif
                    @if($me->hasPermission('roles.manage'))<a href="{{ route('admin.roles.index') }}">Cargos</a>@endif
                </div>
            </details>
            <div class="mobile-brand"><span class="brand-mark small">S</span><b>Sutoorii Tickets</b></div>
            <form class="global-search" method="get" action="{{ route('boxes.mine') }}">
                <input name="q" value="{{ request()->routeIs('boxes.mine') ? request('q') : '' }}" placeholder="Buscar ticket por número, título ou descrição" aria-label="Buscar tickets">
            </form>
            <div class="topbar-user"><span>{{ $me->name }}</span></div>
        </header>

        <main class="workspace-content">
            @if(session('success'))<div class="notice">{{ session('success') }}</div>@endif
            @yield('content')
        </main>
        <footer class="site-footer">Desenvolvido por Sutoorii Labs</footer>
    </section>
</div>
@else
<div class="guest-shell">
    <main class="guest-container">
        @if(session('success'))<div class="notice">{{ session('success') }}</div>@endif
        @yield('content')
    </main>
    <footer class="site-footer guest-footer">Desenvolvido por Sutoorii Labs</footer>
</div>
@endauth
<script>if('serviceWorker' in navigator){navigator.serviceWorker.register('/service-worker.js',{updateViaCache:'none'}).then(function(reg){reg.update();});}</script>
</body>
</html>
