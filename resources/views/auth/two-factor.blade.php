@extends('layouts.guest')
@section('title','Sicherheitscode')
@section('content')
<h1>Zwei-Faktor-Anmeldung</h1>
<p class="muted">Für Administrationskonten ist ein zusätzlicher Sicherheitscode erforderlich. Der Code wurde an die hinterlegte E-Mail-Adresse gesendet und ist 10 Minuten gültig.</p>
@if(session('success'))<div class="flash success">{{ session('success') }}</div>@endif
<form method="post" action="{{ route('two-factor.verify') }}" class="stack-form">@csrf
<label>Sicherheitscode<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required autofocus></label>
<button class="btn primary wide">Anmeldung abschließen</button>
</form>
<form method="post" action="{{ route('two-factor.resend') }}" style="margin-top:12px">@csrf<button class="btn secondary wide">Neuen Code senden</button></form>
@endsection
