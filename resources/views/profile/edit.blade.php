@extends('layouts.app')
@section('title','Profil')
@section('content')
<div class="page-head"><span class="eyebrow">Konto</span><h1>Profil & Kontostatus</h1><p>Stammdaten, Verifizierungen, aktive Einschränkungen und Verwarnungen.</p></div>
<div class="wallet-layout">
<section class="panel"><h2>Stammdaten</h2>
<form method="post" action="{{ route('profile.update') }}" class="form-grid">@csrf @method('PUT')
<label>Vorname<input name="first_name" value="{{ old('first_name',$user->first_name) }}" required></label>
<label>Nachname<input name="last_name" value="{{ old('last_name',$user->last_name) }}" required></label>
<label>Telefon<input name="phone" value="{{ old('phone',$user->profile?->phone) }}"></label>
<label>Straße<input name="street" value="{{ old('street',$user->profile?->street) }}"></label>
<label>PLZ<input name="postal_code" value="{{ old('postal_code',$user->profile?->postal_code) }}"></label>
<label>Ort<input name="city" value="{{ old('city',$user->profile?->city) }}"></label>
<label>Ländercode<input name="country_code" value="{{ old('country_code',$user->profile?->country_code ?: 'DE') }}" maxlength="2" required></label>
<div class="full"><button class="btn primary">Profil speichern</button></div>
</form></section>

<aside>
<div class="panel"><h2>Kontostatus</h2>
<dl class="meta-list">
<div><dt>Status</dt><dd>{{ strtoupper($user->status) }}</dd></div>
<div><dt>Identitätsprüfung</dt><dd>{{ $user->verified_at?'abgeschlossen':'offen' }}</dd></div>
<div><dt>E-Mail</dt><dd>{{ $user->hasVerifiedEmail()?'bestätigt':'nicht bestätigt' }}</dd></div>
</dl>
@if(!$user->hasVerifiedEmail())<a class="btn secondary wide" href="{{ route('verification.notice') }}">E-Mail jetzt bestätigen</a>@endif
@if(!$user->verified_at)<a class="btn secondary wide" style="margin-top:8px" href="{{ route('verification.index') }}">Identität verifizieren</a>@endif
</div>

<div class="panel"><h2>Aktive Einschränkungen</h2>
@forelse($user->restrictions->where('active',true) as $item)
<div class="notice"><strong>{{ strtoupper($item->type) }}</strong><br>{{ $item->reason }}@if($item->ends_at)<br><small>bis {{ $item->ends_at->format('d.m.Y H:i') }}</small>@endif</div>
@empty<p class="muted">Keine aktiven Einschränkungen.</p>@endforelse
</div>

<div class="panel"><h2>Verwarnungen</h2>
@forelse($user->warnings->sortByDesc('created_at') as $warning)
<div style="padding:10px 0;border-bottom:1px solid var(--line)"><strong>{{ $warning->title }}</strong><p>{{ $warning->reason }}</p><small class="muted">{{ $warning->created_at->format('d.m.Y H:i') }}</small></div>
@empty<p class="muted">Keine Verwarnungen.</p>@endforelse
</div>
</aside>
</div>
@endsection
