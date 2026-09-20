@extends(auth()->check() ? 'layouts.app' : 'layouts.public')
@section('title',$offer->title)
@section('content')
@php
$windows=is_array($offer->proof_requirements)?$offer->proof_requirements:[];
$dailyProofs=collect($windows)->sum(fn($w)=>(int)($w['required_images']??0));
if($dailyProofs===0){ $dailyProofs=(int)$offer->proofs_per_day; }
$regularProofs=$dailyProofs*max(1,(int)$offer->duration_days);
$isDigital=$offer->category?->kind==='digital';
@endphp
<div class="{{ auth()->check() ? '' : 'public-page' }}"><div class="{{ auth()->check() ? '' : 'public-wrap' }}">
<div class="offer-detail-grid" data-offer-configurator data-base="{{ $offer->base_compensation }}">
<section>
<a class="back" href="{{ route('offers.index') }}">← Zurück zu den Angeboten</a>
<div class="detail-visual"><span>{{ $offer->category?->icon ?: '✦' }}</span><small>{{ $offer->category?->name }}</small></div>
<span class="eyebrow">{{ $offer->category?->name }}</span>
<h1>{{ $offer->title }}</h1>
<p class="lead">{{ $offer->short_description }}</p>
<div class="prose">{!! nl2br(e($offer->description)) !!}</div>

<div class="rule-grid">
<div><strong>{{ number_format($offer->base_compensation,2,',','.') }} €</strong><span>Grundvergütung</span></div>
<div><strong>{{ $isDigital ? 'Digital' : $offer->duration_days.' Tag(e)' }}</strong><span>Erfüllung</span></div>
<div><strong>{{ $regularProofs }}</strong><span>regulär geplante Nachweise</span></div>
<div><strong>18+</strong><span>nur volljährige Verkäuferinnen</span></div>
</div>

<div class="panel"><h3>So läuft dieser Auftrag</h3>
@if($isDigital)
<p>Die digitale Leistung wird innerhalb der Plattform eingereicht. Zulässige Formate, technische Anforderungen und mögliche Revisionen ergeben sich aus diesem Angebot.</p>
@else
<p>Nach der Annahme legst du den konkreten Artikel fest und reichst die erforderliche Vorabkontrolle ein. Sobald der Admin alle erforderlichen Startnachweise freigibt, beginnt der Auftrag unmittelbar.</p>
@endif
@if(count($windows))
<h4>Reguläre Nachweisfenster</h4>
@foreach($windows as $window)
<div class="notice"><strong>{{ $window['label']??$window['key']??'Nachweis' }}</strong> · {{ $window['start']??'00:00' }}–{{ $window['end']??'23:59' }} Uhr · {{ (int)($window['required_images']??0) }} Bild(er)</div>
@endforeach
@endif
<p class="muted">Spontane Nachweise oder Zusatzaufgaben können je nach Auftragsbedingungen hinzukommen. Bestätigte Verstöße können zusätzliche, grundsätzlich unbezahlte Durchführungstage auslösen.</p>
</div>

<div class="panel"><h3>Versand & Abschluss</h3>
@if($isDigital)
<p>Nach der finalen digitalen Abgabe erfolgt die Prüfung. Falls erforderlich, kann eine strukturierte Revision angefordert werden.</p>
@else
<p>Nach Ende der Durchführung führt dich der Auftrag sequenziell durch die vorgesehenen Abschluss- und Versandschritte. Die konkrete Empfängeradresse wird erst in der Versandphase angezeigt.</p>
@endif
<p>Eine Auszahlung wird erst nach vollständigem Abschluss und finaler Freigabe des gesamten Auftrags möglich.</p>
</div>

@if($offer->rules)
<div class="panel"><h3>Regeln & Bedingungen</h3><ul>@foreach($offer->rules as $rule)<li>✓ {{ is_string($rule)?$rule:json_encode($rule,JSON_UNESCAPED_UNICODE) }}</li>@endforeach</ul></div>
@endif
</section>

<aside class="config-card">
<span class="eyebrow">Auftrag</span>
<div class="big-price"><span>Vorgemerkt ab</span><strong data-total>{{ number_format($offer->base_compensation,2,',','.') }} €</strong></div>
<div class="summary-row"><span>Reguläre Nachweise</span><strong>{{ $regularProofs }}</strong></div>
<div class="summary-row"><span>Dauer</span><strong>{{ $isDigital?'nach Vorgabe':$offer->duration_days.' Tag(e)' }}</strong></div>

@guest
<div class="notice">Zum Annehmen benötigst du ein volljähriges Verkäuferinnenkonto mit bestätigter E-Mail-Adresse.</div>
<a class="btn primary wide" href="{{ route('register') }}">Als Verkäuferin registrieren</a>
<a class="btn secondary wide" style="margin-top:8px" href="{{ route('login') }}">Anmelden</a>
@else
@if(auth()->user()->isAdmin())
<div class="notice">Du bist als Admin angemeldet. Angebote werden von Verkäuferinnenkonten angenommen.</div>
@elseif(!auth()->user()->hasVerifiedEmail())
<div class="notice"><strong>E-Mail noch nicht bestätigt.</strong><br>Bestätige deine E-Mail-Adresse, bevor du einen Auftrag annehmen kannst.</div>
<a class="btn secondary wide" href="{{ route('verification.notice') }}">E-Mail bestätigen</a>
@elseif($categoryConflict)
<div class="notice"><strong>Kategorie bereits belegt.</strong><br>Du hast in dieser Kategorie bereits einen offenen oder aktiven Auftrag.</div>
@else
<form method="post" action="{{ route('offers.accept',$offer) }}" class="stack-form">@csrf

@if($offer->fields->where('active',true)->count())
<h3>Angaben zum Auftrag</h3>
@foreach($offer->fields->where('active',true) as $field)
@if($field->type==='textarea')
<label>{{ $field->label }}@if($field->required) * @endif<textarea name="fields[{{ $field->key }}]" rows="4" @required($field->required)>{{ old('fields.'.$field->key) }}</textarea></label>
@elseif($field->type==='number')
<label>{{ $field->label }}@if($field->required) * @endif<input type="number" step="any" name="fields[{{ $field->key }}]" value="{{ old('fields.'.$field->key) }}" @required($field->required)></label>
@elseif(in_array($field->type,['select','radio'],true))
<label>{{ $field->label }}@if($field->required) * @endif<select name="fields[{{ $field->key }}]" @required($field->required)><option value="">Bitte auswählen</option>@foreach($field->options??[] as $value)<option value="{{ $value }}">{{ $value }}</option>@endforeach</select></label>
@elseif($field->type==='checkbox')
<label class="check"><input type="checkbox" name="fields[{{ $field->key }}]" value="1" @required($field->required)><span>{{ $field->label }}</span></label>
@else
<label>{{ $field->label }}@if($field->required) * @endif<input name="fields[{{ $field->key }}]" value="{{ old('fields.'.$field->key) }}" @required($field->required)></label>
@endif
@endforeach
@endif

@if($offer->options->where('active',true)->count())
<h3>Zusatzoptionen</h3>
<div class="option-list">@foreach($offer->options->where('active',true) as $option)
<label class="option"><input type="checkbox" name="options[]" value="{{ $option->id }}" data-price="{{ $option->price_delta }}" @checked($option->required) @required($option->required)><span><strong>{{ $option->name }}</strong><small>{{ $option->description }}</small></span><b>{{ (float)$option->price_delta>=0?'+':'' }}{{ number_format($option->price_delta,2,',','.') }} €</b></label>
@endforeach</div>
@endif

@if($isDigital)
<label class="check"><input type="checkbox" name="confirm_rights" value="1" required><span>Ich habe die für diesen digitalen Auftrag angezeigte Rechtevereinbarung gelesen und akzeptiere sie.</span></label>
@endif
<div class="notice">Mit der Annahme wird der Auftrag verbindlich. Der vollständige Auftragswert wird zunächst im Wallet vorgemerkt. Ein normaler Selbst-Storno nach Annahme ist nicht vorgesehen.</div>
<label class="check"><input type="checkbox" name="confirm_summary" value="1" required><span>Ich habe Vergütung, Umfang, Nachweise, Optionen, Versand-/Abgaberegeln und mögliche Verlängerungen geprüft.</span></label>
<button class="btn primary wide">Auftrag verbindlich annehmen</button>
</form>
@endif
@endguest
</aside>
</div>
</div></div>
@endsection
