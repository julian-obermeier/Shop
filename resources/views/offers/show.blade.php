@extends('layouts.app')
@section('title',$offer->title)
@section('content')
<div class="offer-detail-grid" data-offer-configurator data-base="{{ $offer->base_compensation }}">
<section>
<a class="back" href="{{ route('offers.index') }}">← Zurück zu den Angeboten</a>
<div class="detail-visual"><span>{{ $offer->category?->icon ?: '✦' }}</span><small>{{ $offer->category?->name }}</small></div>
<span class="eyebrow">{{ $offer->category?->name }}</span>
<h1>{{ $offer->title }}</h1>
<p class="lead">{{ $offer->short_description }}</p>
<div class="prose">{!! nl2br(e($offer->description)) !!}</div>
<div class="rule-grid"><div><strong>{{ $offer->duration_days }}</strong><span>Tage Grunddauer</span></div><div><strong>{{ $offer->proofs_per_day }}</strong><span>Nachweise täglich</span></div><div><strong>{{ $offer->minimum_minutes_per_day }}</strong><span>Minuten/Tag mindestens</span></div><div><strong>{{ $offer->shipping_deadline_hours }}h</strong><span>Versandfrist</span></div></div>
@if($offer->rules)<div class="panel"><h3>Regeln & Bedingungen</h3><ul>@foreach($offer->rules as $rule)<li>✓ {{ $rule }}</li>@endforeach</ul></div>@endif
</section>

<aside class="config-card">
<span class="eyebrow">Deine Vergütung</span>
<div class="big-price"><span>Gesamt</span><strong data-total>{{ number_format($offer->base_compensation,2,',','.') }} €</strong></div>
<div class="summary-row"><span>Grundvergütung</span><strong>{{ number_format($offer->base_compensation,2,',','.') }} €</strong></div>
<form method="post" action="{{ route('offers.accept',$offer) }}">@csrf
@if($offer->options->count())
<h3>Zusatzoptionen</h3>
<div class="option-list">
@foreach($offer->options->where('active',true) as $option)
@php($rules=$option->rules?:[])
<label class="option">
<input type="checkbox" name="options[]" value="{{ $option->id }}" data-price="{{ $option->price_delta }}" data-requires='@json($rules["requires_ids"]??[])' data-excludes='@json($rules["excludes_ids"]??[])' @checked($option->required) @if($option->required) required @endif>
<span>
<strong>{{ $option->name }} @if($option->required)<small style="display:inline;color:var(--pink)">Pflicht</small>@endif</strong>
<small>{{ $option->description }}</small>
@if(($rules['min_duration_days']??0)>0)<small>Mindestens {{ $rules['min_duration_days'] }} Tage Gesamtlaufzeit</small>@endif
</span>
<b>+{{ number_format($option->price_delta,2,',','.') }} €</b>
</label>
@endforeach
</div>
@endif
<div class="notice">Mit der Annahme werden Preis, Regeln und gewählte Optionen als unveränderbare Auftragsversion gespeichert. Abhängigkeiten und Ausschlüsse werden serverseitig geprüft.</div>
<button class="btn primary wide" @disabled(!auth()->user()->verified_at || auth()->user()->hasRestriction('offers'))>Auftrag verbindlich annehmen</button>
@if(!auth()->user()->verified_at)<p class="muted">Vorher ist eine abgeschlossene Verifizierung erforderlich.</p>@endif
</form>
</aside>
</div>
@endsection
