@extends('layouts.app')
@section('title','Benachrichtigungen')
@section('content')
<div class="page-head split"><div><span class="eyebrow">Aktuelles</span><h1>Benachrichtigungen</h1><p>Erinnerungen, Auszahlungsstatus und Kontohinweise.</p></div><form method="post" action="{{ route('notifications.read-all') }}">@csrf<button class="btn secondary">Alle als gelesen markieren</button></form></div>
<div class="days">
@forelse($notifications as $notification)
<a class="panel" href="{{ route('notifications.read',$notification) }}" style="{{ $notification->read_at?'opacity:.7':'' }}">
<div class="page-head split" style="margin:0"><div><span class="eyebrow">{{ strtoupper(str_replace('_',' ',$notification->type)) }}</span><h3>{{ $notification->title }}</h3><p>{{ $notification->body }}</p></div><small class="muted">{{ $notification->created_at->format('d.m.Y H:i') }}</small></div>
</a>
@empty<div class="empty">Keine Benachrichtigungen vorhanden.</div>@endforelse
</div>{{ $notifications->links() }}
@endsection
