@extends('layouts.app')
@section('title','Profil')
@section('content')
<div class="page-head"><span class="eyebrow">Konto</span><h1>Profil & Kontosicherheit</h1><p>Kontakt- und Zugangsdaten sowie deine aktuellen Zuverlässigkeitseinschränkungen.</p></div>

<div class="wallet-layout">
<section>
<div class="panel"><h2>Stammdaten</h2>
<dl class="meta-list">
<div><dt>Vorname</dt><dd>{{ $user->first_name }}</dd></div>
<div><dt>Nachname</dt><dd>{{ $user->last_name }}</dd></div>
<div><dt>Geburtsdatum</dt><dd>{{ $user->birth_date?->format('d.m.Y') }}</dd></div>
<div><dt>Anschrift</dt><dd>{{ collect([$user->profile?->street,trim(($user->profile?->postal_code ?? '').' '.($user->profile?->city ?? '')),$user->profile?->country_code])->filter()->implode(', ') ?: '–' }}</dd></div>
</dl>
<p class="muted">Name, Geburtsdatum und Anschrift können nur durch den Admin geändert werden.</p>
<form method="post" action="{{ route('profile.update') }}" class="stack-form">@csrf @method('PUT')
<label>Telefon<input name="phone" value="{{ old('phone',$user->profile?->phone) }}"></label>
<button class="btn primary">Telefonnummer speichern</button>
</form>
</div>

<div class="panel" style="margin-top:18px"><h2>E-Mail-Adresse ändern</h2>
<form method="post" action="{{ route('profile.email') }}" class="stack-form">@csrf @method('PUT')
<label>Neue E-Mail-Adresse<input type="email" name="email" value="{{ $user->email }}" required autocomplete="email"></label>
<label>Aktuelles Passwort<input type="password" name="current_password" required autocomplete="current-password"></label>
<button class="btn secondary">E-Mail-Adresse ändern</button>
</form>
<p class="muted">Nach einer Änderung muss die neue Adresse erneut bestätigt werden.</p>
</div>

<div class="panel" style="margin-top:18px"><h2>Passwort ändern</h2>
<form method="post" action="{{ route('profile.password') }}" class="stack-form">@csrf @method('PUT')
<label>Aktuelles Passwort<input type="password" name="current_password" required autocomplete="current-password"></label>
<label>Neues Passwort<input type="password" name="password" minlength="12" required autocomplete="new-password"></label>
<label>Neues Passwort wiederholen<input type="password" name="password_confirmation" minlength="12" required autocomplete="new-password"></label>
<button class="btn primary">Passwort ändern</button>
</form>
</div>
</section>

<aside>
<div class="panel"><h2>Kontostatus</h2>
<dl class="meta-list">
<div><dt>Status</dt><dd>{{ strtoupper($user->status) }}</dd></div>
<div><dt>E-Mail</dt><dd>{{ $user->hasVerifiedEmail()?'bestätigt':'nicht bestätigt' }}</dd></div>
</dl>
@if(!$user->hasVerifiedEmail())<a class="btn secondary wide" href="{{ route('verification.notice') }}">E-Mail jetzt bestätigen</a>@endif
</div>

<div class="panel"><h2>Aktive Einschränkungen</h2>
@forelse($user->restrictions->where('active',true) as $item)
<div class="notice"><strong>{{ strtoupper($item->type) }}</strong><br>{{ $item->reason }}<br><small>Bewährung: {{ (int)($item->successful_count ?? 0) }}/{{ (int)($item->required_successes ?? 5) }} · Aufhebung anschließend durch Admin</small></div>
@empty<p class="muted">Keine aktiven Einschränkungen.</p>@endforelse
</div>

<div class="panel"><h2>Verlauf</h2>
@forelse($user->restrictions->sortByDesc('created_at') as $item)
<div style="padding:10px 0;border-bottom:1px solid var(--line)"><strong>{{ strtoupper($item->type) }}</strong><p>{{ $item->reason }}</p><small class="muted">{{ $item->created_at->format('d.m.Y H:i') }} · {{ $item->active?'aktiv':'aufgehoben' }}</small></div>
@empty<p class="muted">Keine Einschränkungen vorhanden.</p>@endforelse
</div>
</aside>
</div>
@endsection
