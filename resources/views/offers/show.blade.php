@extends('layouts.app')
@section('title',$offer->title)
@section('content')
<div class="offer-detail-grid" data-offer-configurator data-base="{{ $offer->base_compensation }}">
<section>
<a class="back" href="{{ route('offers.index') }}">← Zurück zu den Angeboten</a>

@if($offer->image_path)
<img src="{{ asset('storage/'.$offer->image_path) }}" alt="{{ $offer->title }}" style="width:100%;max-height:430px;object-fit:cover;border-radius:24px;margin-bottom:20px">
@else
<div class="detail-visual"><span>{{ $offer->category?->icon ?: '✦' }}</span><small>{{ $offer->category?->name }}</small></div>
@endif

<span class="eyebrow">{{ $offer->category?->name }}</span>
<h1>{{ $offer->title }}</h1>
<p class="lead">{{ $offer->short_description }}</p>
<div class="prose">{!! nl2br(e($offer->description)) !!}</div>

<div class="rule-grid">
<div><strong>{{ $offer->duration_days }}</strong><span>Tage Grunddauer</span></div>
<div><strong>{{ $offer->proofs_per_day }}</strong><span>Nachweise täglich</span></div>
<div><strong>{{ $offer->minimum_minutes_per_day }}</strong><span>Minuten/Tag mindestens</span></div>
<div><strong>{{ $offer->shipping_deadline_hours }}h</strong><span>Versandfrist</span></div>
</div>

@if($offer->capacity)
<div class="notice">Kapazität: maximal {{ $offer->capacity }} Aufträge.</div>
@endif

@if($offer->rules)
<div class="panel"><h3>Regeln & Bedingungen</h3><ul>@foreach($offer->rules as $rule)<li>✓ {{ $rule }}</li>@endforeach</ul></div>
@endif
</section>

<aside class="config-card">
<span class="eyebrow">Deine Vergütung</span>
<div class="big-price"><span>Gesamt</span><strong data-total>{{ number_format($offer->base_compensation,2,',','.') }} €</strong></div>
<div class="summary-row"><span>Grundvergütung</span><strong>{{ number_format($offer->base_compensation,2,',','.') }} €</strong></div>

<form method="post" action="{{ route('offers.accept',$offer) }}" class="stack-form">@csrf

@if($offer->fields->where('active',true)->count())
<h3>Angaben zum Auftrag</h3>
@foreach($offer->fields->where('active',true) as $field)
<div>
@if($field->type==='textarea')
<label>{{ $field->label }}@if($field->required) * @endif<textarea name="fields[{{ $field->key }}]" rows="4" @required($field->required)>{{ old('fields.'.$field->key) }}</textarea></label>
@elseif($field->type==='number')
<label>{{ $field->label }}@if($field->required) * @endif<input type="number" step="any" name="fields[{{ $field->key }}]" value="{{ old('fields.'.$field->key) }}" @required($field->required)></label>
@elseif($field->type==='select')
<label>{{ $field->label }}@if($field->required) * @endif
<select name="fields[{{ $field->key }}]" @required($field->required)>
<option value="">Bitte auswählen</option>
@foreach($field->options??[] as $value)<option value="{{ $value }}" @selected(old('fields.'.$field->key)===$value)>{{ $value }}</option>@endforeach
</select></label>
@elseif($field->type==='radio')
<fieldset style="border:0;padding:0;margin:0"><legend><strong>{{ $field->label }}@if($field->required) * @endif</strong></legend>
@foreach($field->options??[] as $value)<label class="check"><input type="radio" name="fields[{{ $field->key }}]" value="{{ $value }}" @checked(old('fields.'.$field->key)===$value) @required($field->required)><span>{{ $value }}</span></label>@endforeach
</fieldset>
@elseif($field->type==='checkbox')
<label class="check"><input type="checkbox" name="fields[{{ $field->key }}]" value="1" @checked(old('fields.'.$field->key)) @required($field->required)><span>{{ $field->label }}@if($field->required) * @endif</span></label>
@else
<label>{{ $field->label }}@if($field->required) * @endif<input name="fields[{{ $field->key }}]" value="{{ old('fields.'.$field->key) }}" @required($field->required)></label>
@endif
@if($field->help_text)<small class="muted">{{ $field->help_text }}</small>@endif
</div>
@endforeach
@endif

@if($offer->options->where('active',true)->count())
<h3>Zusatzoptionen</h3>
<div class="option-list">
@foreach($offer->options->where('active',true) as $option)
@php($rules=$option->rules?:[])
<label class="option">
<input type="checkbox" name="options[]" value="{{ $option->id }}" data-price="{{ $option->price_delta }}" data-requires='@json($rules["requires_ids"]??[])' data-excludes='@json($rules["excludes_ids"]??[])' @checked($option->required || in_array($option->id,old('options',[]))) @if($option->required) required @endif>
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

<div class="notice">Mit der Annahme werden Preis, Regeln, deine Angaben und gewählte Optionen als unveränderbare Auftragsversion gespeichert.</div>
<button class="btn primary wide" @disabled(!auth()->user()->verified_at || auth()->user()->hasRestriction('offers'))>Auftrag verbindlich annehmen</button>
@if(!auth()->user()->verified_at)<p class="muted">Vorher ist eine abgeschlossene Verifizierung erforderlich.</p>@endif
</form>
</aside>
</div>
@endsection
