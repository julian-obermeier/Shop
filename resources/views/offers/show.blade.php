@extends('layouts.app')
@section('title',$offer->title)
@section('content')
@php
$canRequest=!$offerBlocked && ($availableSlots>0 || $waitlistEntry?->status==='reserved');
$proofWindows=$offer->proof_requirements ?: [];
$inspection=$offer->inspection_config ?: [];
$inspectionCategories=collect(data_get($inspection,'categories',[]));
$scoreBands=collect(data_get($inspection,'score_bands',[]));
$activeOptions=$offer->options->where('active',true)->keyBy('id');
@endphp
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
<div><strong>{{ $offer->duration_days }}</strong><span>gültige Kalendertage</span></div>
@if((int)$offer->minimum_minutes_per_day>0)
<div><strong>{{ $offer->minimum_minutes_per_day }} min</strong><span>Mindestnutzung/Tag</span></div>
@endif
<div><strong>{{ $offer->proofs_per_day }}</strong><span>Pflichtbilder/Tag</span></div>
<div><strong>24h</strong><span>Versandfrist</span></div>
<div><strong>{{ $offer->is_sock_wearing?'1':'5' }}</strong><span>{{ $offer->is_sock_wearing?'aktiver Sockenauftrag':'allg. Auftragslimit' }}</span></div>
</div>

@if($offer->capacity)
<div class="notice">
@if($availableSlots>0)
Aktuell {{ $availableSlots }} freie(r) Angebotsplatz/-plätze.
@elseif($waitlistEntry?->status==='reserved')
Dein Platz ist bis {{ $waitlistEntry->reservation_expires_at?->format('d.m.Y H:i') }} Uhr exklusiv reserviert.
@else
Dieses Angebot ist derzeit voll.
@endif
</div>
@endif

<div class="panel">
<h3>Nachweise</h3>
<p>Der bestätigte Starttag ist der Aktivierungstag. Das Startfoto benötigt einen 10-Minuten-Code; Tag 1 beginnt am folgenden Kalendertag.</p>
@foreach($proofWindows as $window)
<div class="notice">
<strong>{{ $window['label'] ?? $window['key'] }}</strong>
· {{ $window['start'] ?? '00:00' }}–{{ $window['end'] ?? '23:59' }} Uhr
· {{ $window['required_images'] ?? 1 }} Bild(er)
@if(!empty($window['text_required']))
· Text Pflicht
@endif
@if(!empty($window['face_required']))
· Gesicht sichtbar
@endif
@if(!empty($window['image_requirements']))
<br><small><strong>Bildanforderung:</strong> {{ $window['image_requirements'] }}</small>
@endif
@if(!empty($window['required_fields']))
<br><small><strong>Zusätzliche Pflichtangaben:</strong> {{ collect($window['required_fields'])->pluck('label')->filter()->implode(', ') }}</small>
@endif
</div>
@endforeach
@if(data_get($inspection,'start_face_required',false))
<p><strong>Startfoto:</strong> Gesicht muss sichtbar sein.</p>
@endif
<p class="muted">Nachweisbilder werden dauerhaft im Original einschließlich vorhandener Metadaten gespeichert und können nach Einreichung nicht gelöscht werden. Andere Personen dürfen nicht erkennbar sein.</p>
</div>

<div class="panel">
<h3>Versand & Prüfung</h3>
@if($offer->requires_precheck)
<div class="notice"><strong>Vorprüfung erforderlich.</strong><br>Vor der endgültigen Auftragsfreigabe musst du die im Auftrag abgefragten Artikelangaben und ein Prüffoto einreichen; der Admin prüft diese Stufe vor dem verbindlichen Start.</div>
@endif
<p>Versand innerhalb von 24 Stunden nach Ende der Erfüllungsphase auf eigene Kosten. Paketfoto und Versand-/Annahmebeleg sind Pflicht.</p>
<p><strong>Versandbeleg:</strong> muss direkt über die Live-Kamera der Webanwendung aufgenommen werden; Galerie-/Dateiauswahl ist dafür nicht zulässig. Versanddatum und Versanddienstleister müssen eindeutig lesbar sein.</p>
<p><strong>Tracking:</strong> {{ ['required'=>'verpflichtend','optional'=>'optional','none'=>'nicht vorgesehen'][$offer->tracking_mode??'optional'] }}</p>
<ul>
<li>sichere Verpackung</li>
<li>Schutz vor Feuchtigkeit und Transportschäden</li>
<li>Ware innerhalb des Pakets getrennt bzw. geeignet verpacken</li>
<li>Auftragsnummer im Paket beilegen; außen ist keine Kennzeichnung erforderlich</li>
</ul>
<p class="muted">Eigentum an der eingesandten Ware geht mit dem Versand auf den Betreiber über. Das Versandrisiko bleibt bis zum bestätigten vollständigen Wareneingang bei dir.</p>
<p>Die finale Warenprüfung bewertet Aussehen, Geruch, Geschmack, Nachweise und Extras jeweils mit bestanden/nicht bestanden und 0–10 Punkten. Extras werden separat als erfüllt oder nicht erfüllt vergütet.</p>

@if($inspectionCategories->isNotEmpty())
<h4>Prüfkategorien & KO-Regeln</h4>
<ul>
@foreach($inspectionCategories as $key=>$config)
<li>
<strong>{{ $config['label']??ucfirst((string)$key) }}</strong>
· 0–10 Punkte
@if(!empty($config['ko']))
· <strong>KO-Kriterium</strong>
@else
· kein KO-Kriterium
@endif
</li>
@endforeach
</ul>
@endif

@if(data_get($inspection,'points_affect_compensation',false))
<p><strong>Die Gesamtpunktzahl beeinflusst die Grundvergütung.</strong></p>
@if($scoreBands->isNotEmpty())
<div class="table-card">
<table>
<thead><tr><th>Punktzahl</th><th>Anteil Grundvergütung</th></tr></thead>
<tbody>
@foreach($scoreBands->sortByDesc('min') as $band)
<tr>
<td>{{ (int)($band['min']??0) }}–{{ (int)($band['max']??0) }} Punkte</td>
<td>{{ number_format((float)($band['percentage']??0),2,',','.') }} %</td>
</tr>
@endforeach
</tbody>
</table>
</div>
@endif
@else
<p><strong>Die Punkte verändern die Grundvergütung bei diesem Angebot nicht.</strong></p>
@endif

<p class="muted">Scheitert ein als KO markiertes Kriterium, kann die Vergütung unabhängig von der Gesamtpunktzahl vollständig entfallen. Erfüllte Extras werden mit 100 % ihrer vereinbarten Extra-Vergütung berücksichtigt; nicht erfüllte Extras mit 0 %.</p>
</div>

@if($offer->rules)
<div class="panel"><h3>Regeln & Bedingungen</h3><ul>@foreach($offer->rules as $rule)<li>✓ {{ $rule }}</li>@endforeach</ul></div>
@endif
</section>

<aside class="config-card">
<span class="eyebrow">Auftragsanfrage</span>
<div class="big-price"><span>Max. vereinbart</span><strong data-total>{{ number_format($offer->base_compensation,2,',','.') }} €</strong></div>
<div class="summary-row"><span>Grundvergütung</span><strong>{{ number_format($offer->base_compensation,2,',','.') }} €</strong></div>

@if(!auth()->user()->hasVerifiedEmail())
<div class="notice">
<strong>E-Mail-Adresse noch nicht bestätigt.</strong><br>
Du kannst die Angebotsdetails bereits lesen. Warteliste, Auftragskonfiguration und Auftragsanfrage werden erst nach erfolgreicher E-Mail-Verifikation freigeschaltet.
</div>
@elseif($offerBlocked)
<div class="notice"><strong>Für dein Konto derzeit nicht verfügbar.</strong><br>Eine aktive Zuverlässigkeitseinschränkung verhindert neue Anfragen oder schließt dieses konkrete Angebot aus.</div>
@elseif(!$canRequest)
@if($waitlistEntry?->status==='waiting')
<div class="notice"><strong>Warteliste · Position {{ $waitlistPosition }}</strong><br>Du wirst nach FIFO berücksichtigt, sobald ein Platz frei wird.</div>
<form method="post" action="{{ route('offers.waitlist.leave',$offer) }}">@csrf @method('DELETE')<button class="btn secondary wide">Von Warteliste austragen</button></form>
@else
<div class="notice">Alle Plätze sind belegt. Du kannst dich auf die Warteliste setzen.</div>
<form method="post" action="{{ route('offers.waitlist.join',$offer) }}" class="stack-form">@csrf
@if($offer->is_sock_wearing)
<label>Geplanter Aktivierungstag<input type="date" name="planned_start_date" min="{{ now('Europe/Berlin')->format('Y-m-d') }}" required></label>
<small class="muted">Der Zeitraum muss mit deinen anderen Sockenaufträgen und Socken-Wartelisten vereinbar sein.</small>
@endif
<button class="btn primary wide">Auf Warteliste setzen</button>
</form>
@endif
@else
@if($waitlistEntry?->status==='reserved')
<div class="notice"><strong>Exklusives 24-Stunden-Vorrecht</strong><br>Reserviert bis {{ $waitlistEntry->reservation_expires_at?->format('d.m.Y H:i') }} Uhr. Danach wirst du automatisch von der Warteliste entfernt.</div>
@endif

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
<label>{{ $field->label }}@if($field->required) * @endif<select name="fields[{{ $field->key }}]" @required($field->required)><option value="">Bitte auswählen</option>@foreach($field->options??[] as $value)<option value="{{ $value }}" @selected(old('fields.'.$field->key)===$value)>{{ $value }}</option>@endforeach</select></label>
@elseif($field->type==='radio')
<fieldset style="border:0;padding:0;margin:0"><legend><strong>{{ $field->label }}@if($field->required) * @endif</strong></legend>@foreach($field->options??[] as $value)<label class="check"><input type="radio" name="fields[{{ $field->key }}]" value="{{ $value }}" @checked(old('fields.'.$field->key)===$value) @required($field->required)><span>{{ $value }}</span></label>@endforeach</fieldset>
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
@php
$rules=$option->rules ?: [];
$requires=collect($rules['requires_ids'] ?? [])->map(fn($id)=>$activeOptions->get((int)$id)?->name)->filter();
$excludes=collect($rules['excludes_ids'] ?? [])->map(fn($id)=>$activeOptions->get((int)$id)?->name)->filter();
$minDuration=(int)($rules['min_duration_days'] ?? 0);
@endphp
<label class="option">
<input type="checkbox" name="options[]" value="{{ $option->id }}" data-price="{{ $option->price_delta }}" data-requires='@json($rules["requires_ids"]??[])' data-excludes='@json($rules["excludes_ids"]??[])' @checked($option->required || in_array($option->id,old('options',[]))) @if($option->required) required @endif>
<span>
<strong>{{ $option->name }} @if($option->required)<small style="display:inline;color:var(--pink)">Pflicht</small>@endif</strong>
<small>{{ $option->description }}</small>
@if($requires->isNotEmpty())<small><strong>Benötigt:</strong> {{ $requires->implode(', ') }}</small>@endif
@if($excludes->isNotEmpty())<small><strong>Nicht kombinierbar mit:</strong> {{ $excludes->implode(', ') }}</small>@endif
@if($minDuration>0)<small><strong>Mindestdauer:</strong> {{ $minDuration }} Tage</small>@endif
@if((int)$option->extra_duration_days>0)<small><strong>Zusatzdauer:</strong> +{{ (int)$option->extra_duration_days }} Tag(e)</small>@endif
@if((int)$option->extra_proofs_per_day>0)<small><strong>Zusatznachweise:</strong> +{{ (int)$option->extra_proofs_per_day }} pro Tag</small>@endif
</span>
<b>+{{ number_format($option->price_delta,2,',','.') }} €</b>
</label>
@endforeach
</div>
@endif

<label>Gewünschter Aktivierungstag
<input type="date" name="proposed_start_date" min="{{ now('Europe/Berlin')->format('Y-m-d') }}" value="{{ old('proposed_start_date',$waitlistEntry?->planned_start_date?->format('Y-m-d')) }}" @readonly($waitlistEntry?->planned_start_date) required>
</label>
@if($waitlistEntry?->planned_start_date)<small class="muted">Der konfliktfreie Termin wurde durch die Wartelistenplanung festgelegt.</small>@endif

<div class="notice">Vor dem Absenden: Grundvergütung, Extras, Startdatum, Nachweisfenster, Versandregeln und die oben aufgeführten Bedingungen bilden die Auftragsanfrage. Der Admin muss Auftrag und Startdatum anschließend bestätigen.</div>
<label class="check"><input type="checkbox" name="confirm_summary" value="1" required><span>Ich habe die vollständige Auftragszusammenfassung geprüft und bestätige sie.</span></label>
<button class="btn primary wide" @disabled(!auth()->user()->hasVerifiedEmail() || $offerBlocked)>Auftrag anfragen</button>
@if(!auth()->user()->hasVerifiedEmail())<p class="muted">Vor einer Auftragsanfrage muss deine E-Mail-Adresse bestätigt sein.</p>@endif
</form>
@endif
</aside>
</div>
@endsection
