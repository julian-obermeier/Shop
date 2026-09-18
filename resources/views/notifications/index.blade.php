@extends('layouts.app')
@section('title','Benachrichtigungen')
@section('content')
<div class="page-head split">
<div><span class="eyebrow">Aktuelles</span><h1>Benachrichtigungen</h1><p>Webapp, E-Mail und – wenn aktiviert – Browser-Push.</p></div>
<form method="post" action="{{ route('notifications.read-all') }}">@csrf<button class="btn secondary">Alle als gelesen markieren</button></form>
</div>

<div class="panel" style="margin-bottom:18px">
<h2>Push auf diesem Gerät</h2>
@if($pushConfigured)
<p>Du kannst Push-Benachrichtigungen für wichtige Fristen, Terminänderungen und Statusmeldungen auf diesem Gerät aktivieren.</p>
<div data-push-manager
     data-public-key="{{ $vapidPublicKey }}"
     data-store-url="{{ route('notifications.push.store') }}"
     data-destroy-url="{{ route('notifications.push.destroy') }}">
<button type="button" class="btn secondary" data-push-toggle>Push-Status prüfen …</button>
<small class="muted" data-push-status></small>
</div>
@else
<div class="notice">Browser-Push ist auf dem Server noch nicht mit VAPID-Schlüsseln konfiguriert. Webapp- und E-Mail-Benachrichtigungen funktionieren weiterhin.</div>
@endif
</div>

<div class="days">
@forelse($notifications as $notification)
<a class="panel" href="{{ route('notifications.read',$notification) }}" style="{{ $notification->read_at?'opacity:.7':'' }}">
<div class="page-head split" style="margin:0">
<div><span class="eyebrow">{{ strtoupper(str_replace('_',' ',$notification->type)) }}</span><h3>{{ $notification->title }}</h3><p>{{ $notification->body }}</p></div>
<small class="muted">{{ $notification->created_at->format('d.m.Y H:i') }}</small>
</div>
</a>
@empty<div class="empty">Keine Benachrichtigungen vorhanden.</div>@endforelse
</div>
{{ $notifications->links() }}
@endsection
