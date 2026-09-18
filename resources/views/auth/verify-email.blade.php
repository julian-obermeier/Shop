@extends('layouts.app')
@section('title','E-Mail bestätigen')
@section('content')
<div class="page-head"><span class="eyebrow">Kontosicherheit</span><h1>E-Mail-Adresse bestätigen</h1><p>Für Auftragsannahmen und Auszahlungen muss zusätzlich zur Identitätsprüfung auch deine E-Mail-Adresse bestätigt sein.</p></div>
<div class="panel" style="max-width:720px">
@if(auth()->user()->hasVerifiedEmail())
<div class="flash success">Deine E-Mail-Adresse wurde bereits bestätigt.</div>
@else
<p>Wir haben einen Bestätigungslink an <strong>{{ auth()->user()->email }}</strong> gesendet. Der Link ist signiert und zeitlich begrenzt.</p>
<form method="post" action="{{ route('verification.send') }}">@csrf<button class="btn primary">Bestätigungs-E-Mail erneut senden</button></form>
@endif
</div>
@endsection
