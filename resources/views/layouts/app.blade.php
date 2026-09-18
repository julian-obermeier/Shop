<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', 'Wear&Earn')</title>
<link rel="stylesheet" href="{{ asset('assets/css/app.css') }}">
<script defer src="{{ asset('assets/js/app.js') }}"></script>
</head>
<body>
@php($unread=auth()->user()->userNotifications()->whereNull('read_at')->count())
<div class="app-shell">
<aside class="sidebar">
    <a href="{{ route('dashboard') }}" class="brand"><span class="brand-mark">♥</span><span><strong>Wear&Earn</strong><small>Deine Sachen. Unser Interesse.</small></span></a>
    <nav class="side-nav">
        <a class="{{ request()->routeIs('dashboard')?'active':'' }}" href="{{ route('dashboard') }}">⌂ <span>Dashboard</span></a>
        <a class="{{ request()->routeIs('offers.*')?'active':'' }}" href="{{ route('offers.index') }}">▣ <span>Angebote</span></a>
        <a class="{{ request()->routeIs('orders.*')?'active':'' }}" href="{{ route('orders.index') }}">☷ <span>Meine Aufträge</span></a>
        <a class="{{ request()->routeIs('wallet.*')?'active':'' }}" href="{{ route('wallet.index') }}">◫ <span>Wallet</span></a>
        <a class="{{ request()->routeIs('messages.*')?'active':'' }}" href="{{ route('messages.index') }}">✉ <span>Nachrichten</span></a>
        <a class="{{ request()->routeIs('notifications.*')?'active':'' }}" href="{{ route('notifications.index') }}">◉ <span>Benachrichtigungen@if($unread) ({{ $unread }})@endif</span></a>
        <a class="{{ request()->routeIs('profile.*')?'active':'' }}" href="{{ route('profile.edit') }}">♙ <span>Profil</span></a>
        <a class="{{ request()->routeIs('verification.*')?'active':'' }}" href="{{ route('verification.index') }}">✓ <span>Verifizierung</span></a>
        <a class="{{ request()->routeIs('documents.*')?'active':'' }}" href="{{ route('documents.index') }}">▤ <span>Dokumente</span></a>

        @if(auth()->user()->isAdmin())
        <div class="nav-caption">Administration</div>
        <a class="{{ request()->routeIs('admin.dashboard')?'active':'' }}" href="{{ route('admin.dashboard') }}">⚙ <span>Admin-Dashboard</span></a>
        <a class="{{ request()->routeIs('admin.users.*')?'active':'' }}" href="{{ route('admin.users.index') }}">♙ <span>Anbieterinnen</span></a>
        <a class="{{ request()->routeIs('admin.verifications.*')?'active':'' }}" href="{{ route('admin.verifications.index') }}">✓ <span>Verifizierungen</span></a>
        <a class="{{ request()->routeIs('admin.prechecks.*')?'active':'' }}" href="{{ route('admin.prechecks.index') }}">⌕ <span>Vorprüfungen</span></a>
        <a class="{{ request()->routeIs('admin.payouts.*')?'active':'' }}" href="{{ route('admin.payouts.index') }}">€ <span>Auszahlungen</span></a>
        <a class="{{ request()->routeIs('admin.messages.*')?'active':'' }}" href="{{ route('admin.messages.index') }}">✉ <span>Nachrichten</span></a>
        <a class="{{ request()->routeIs('admin.documents.*')?'active':'' }}" href="{{ route('admin.documents.index') }}">▤ <span>Dokumente</span></a>
        <a class="{{ request()->routeIs('admin.audit.*')?'active':'' }}" href="{{ route('admin.audit.index') }}">☷ <span>Audit-Log</span></a>
        @endif
    </nav>
    <form action="{{ route('logout') }}" method="post" class="logout">@csrf<button>↪ Abmelden</button></form>
</aside>
<main class="main">
<header class="topbar">
    <form action="{{ route('offers.index') }}" class="top-search"><span>⌕</span><input name="q" value="{{ request('q') }}" placeholder="Angebote durchsuchen …"></form>
    <div class="top-user"><div class="avatar">{{ strtoupper(substr(auth()->user()->first_name,0,1)) }}{{ strtoupper(substr(auth()->user()->last_name,0,1)) }}</div><div><strong>Hallo, {{ auth()->user()->first_name }}</strong><small>{{ auth()->user()->role === 'provider' ? 'Anbieterin' : ucfirst(auth()->user()->role) }} · {{ auth()->user()->verified_at ? 'verifiziert' : 'nicht verifiziert' }}</small></div></div>
</header>
<div class="content">
@if(session('success'))<div class="flash success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="flash error"><strong>Bitte prüfen:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('content')
</div>
</main>
</div>
</body></html>
