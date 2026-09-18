@extends('layouts.app')
@section('title','Auftrag #'.$order->order_number)
@section('content')
@php
$currentSeries=(int)$order->series_number;
$currentDays=$order->days->where('series_number',$currentSeries);
$acceptedDays=$currentDays->where('day_number','>',0)->where('counts_toward_series',true)->where('status','accepted')->count();
$requiredDays=(int)data_get($order->offer_snapshot,'duration_days',1);
$canComplete=$order->status==='active' && $acceptedDays >= $requiredDays;
$trackingMode=(string)data_get($order->offer_snapshot,'tracking_mode','optional');
@endphp

<div class="page-head split">
<div>
<span class="eyebrow">Auftrag #{{ $order->order_number }}</span>
<h1>{{ data_get($order->offer_snapshot,'title') }}</h1>
<span class="status {{ $order->status }}">{{ strtoupper(str_replace('_',' ',$order->status)) }}</span>
</div>
<div class="headline-amount"><span>Vereinbart bis</span><strong>{{ number_format($order->compensation_total,2,',','.') }} €</strong>@if($order->final_compensation!==null)<small>Final: {{ number_format($order->final_compensation,2,',','.') }} €</small>@endif</div>
</div>

@if($order->status==='requested')
<div class="panel start-panel">
<div><h3>Auftragsanfrage wartet auf Adminprüfung</h3><p>Gewünschter Aktivierungstag: <strong>{{ $order->proposed_start_date?->format('d.m.Y') }}</strong>. Erst die Bestätigung durch den Admin macht Auftrag und Termin verbindlich.</p></div>
<form method="post" action="{{ route('orders.withdraw',$order) }}">@csrf<button class="btn secondary">Anfrage zurückziehen</button></form>
</div>
@endif

@if($order->status==='request_rejected')
<div class="panel">
<h2>Auftragsanfrage abgelehnt</h2>
<p>Der Admin hat diese Anfrage abgelehnt. Die Anfrage zählt nicht gegen dein persönliches Auftragslimit und kann später vom Admin wieder geöffnet werden.</p>
@php($rejectEvent=$order->statusHistory->where('to_status','request_rejected')->sortByDesc('created_at')->first())
@if($rejectEvent?->reason)<div class="notice">{{ $rejectEvent->reason }}</div>@endif
</div>
@endif

@if($order->status==='awaiting_date_confirmation')
<div class="panel">
<h2>Neuer Starttermin vorgeschlagen</h2>
<p>Der Admin schlägt den <strong>{{ $order->proposed_start_date?->format('d.m.Y') }}</strong> als Aktivierungstag vor.</p>
<div style="display:flex;gap:10px;flex-wrap:wrap">
<form method="post" action="{{ route('orders.accept-date',$order) }}">@csrf<button class="btn primary">Termin verbindlich bestätigen</button></form>
<form method="post" action="{{ route('orders.withdraw',$order) }}">@csrf<button class="btn secondary">Anfrage zurückziehen</button></form>
</div>
</div>
@endif

@if(in_array($order->status,['precheck','precheck_resubmit']))
<div class="panel" style="margin-bottom:18px">
<h2>Vorprüfung erforderlich</h2>
@if($order->precheck?->admin_comment)<div class="notice">{{ $order->precheck->admin_comment }}</div>@endif
<form method="post" enctype="multipart/form-data" action="{{ route('orders.precheck',$order) }}" class="form-grid">@csrf
<input type="hidden" name="existing_photo" value="{{ $order->precheck?->photo_path }}">
<label>Artikelart<input name="item_type" value="{{ old('item_type',$order->precheck?->item_type) }}"></label>
<label>Größe / Variante<input name="item_size" value="{{ old('item_size',$order->precheck?->item_size) }}"></label>
<label class="full">Beschreibung<textarea name="item_description" rows="4" required>{{ old('item_description',$order->precheck?->item_description) }}</textarea></label>
<label class="full">Prüffoto<input type="file" name="photo" accept="image/jpeg,image/png,image/webp" @required(!$order->precheck?->photo_path)><small>Das eingereichte Originalbild bleibt dauerhaft zum Auftrag gespeichert.</small></label>
<div class="full"><button class="btn primary">Vorprüfung einreichen</button></div>
</form>
</div>
@endif

@if($order->status==='approved')
<div class="panel start-panel">
<div>
<h3>Bestätigter Aktivierungstag: {{ $order->confirmed_start_date?->format('d.m.Y') }}</h3>
<p>Am Aktivierungstag bestätigst du den Start und reichst anschließend das Startfoto mit einem 10-Minuten-Code ein. Tag 1 beginnt erst am folgenden Kalendertag.</p>
</div>
@if($order->confirmed_start_date?->isToday())
<form method="post" action="{{ route('orders.start',$order) }}">@csrf<button class="btn primary">Aktivierung bestätigen</button></form>
@else
<span class="status pending">NOCH NICHT FÄLLIG</span>
@endif
</div>
@endif

@if($order->status==='waiting_start')
@php
$startDay=$currentDays->firstWhere('day_number',0);
$startChallenge=$startDay ? $order->proofChallenges->first(fn($c)=>$c->order_day_id===$startDay->id && $c->window_key==='start' && !$c->used_at && $c->expires_at?->isFuture()) : null;
@endphp
<div class="panel" style="margin-bottom:18px">
<h2>Verpflichtendes Startfoto</h2>
<p>Der Code muss im Bild sichtbar sein – handschriftlich auf einem Zettel, auf einem zweiten Gerät oder per digitalem Overlay. Andere Personen dürfen nicht erkennbar sein.@if(data_get($order->current_requirements,'inspection_config.start_face_required',false)) <strong>Dein Gesicht muss auf diesem Startfoto sichtbar sein.</strong>@endif</p>
@if($startDay)
@if(!$startChallenge)
<form method="post" action="{{ route('proofs.challenge',$startDay) }}">@csrf<input type="hidden" name="window_key" value="start"><button class="btn secondary">10-Minuten-Code erzeugen</button></form>
@else
<div class="notice"><strong>Code: {{ $startChallenge->code }}</strong><br>Gültig bis {{ $startChallenge->expires_at->format('H:i:s') }} Uhr.</div>
<form method="post" enctype="multipart/form-data" action="{{ route('proofs.store',$startDay) }}" class="stack-form" data-proof-upload data-code="{{ $startChallenge->code }}">@csrf
<input type="hidden" name="challenge_id" value="{{ $startChallenge->id }}">
<input type="hidden" name="proof_code" value="{{ $startChallenge->code }}">
<input type="hidden" name="window_key" value="start">
<label>Live-Kamera<input type="file" name="proof" accept="image/*" capture="environment" required data-proof-file></label>
<label class="check"><input type="checkbox" data-overlay-code><span>Code automatisch sichtbar in das aufgenommene Bild einblenden</span></label>
<button class="btn primary">Startfoto einreichen</button>
</form>
@endif
@endif
</div>
@endif

@if($order->status==='active')
<div class="panel start-panel">
<div><h3>Aktuelle Trageserie {{ $currentSeries }}</h3><p><strong>{{ $acceptedDays }} von {{ $requiredDays }}</strong> erforderlichen gültigen Tagen sind vollständig akzeptiert. Eine zweite Unterbrechung innerhalb derselben Serie startet die Serie wieder bei Tag 1.</p></div>
@if($canComplete)<form method="post" action="{{ route('orders.complete',$order) }}">@csrf<button class="btn primary">Erfüllungsphase abschließen</button></form>@endif
</div>
@endif

@if(in_array($order->status,['waiting_shipping','shipping_overdue'],true) || ($order->status==='shipped' && $order->shipment?->review_status==='rejected' && $order->shipment?->resubmit_due_at?->isFuture()))
<div class="panel" style="margin-bottom:18px">
<h2>{{ $order->status==='shipped'?'Versandnachweise erneut aufnehmen':'Versand melden' }}</h2>
@if($order->shipping_due_at)
<div class="notice">Versandfrist: <strong>{{ $order->shipping_due_at->format('d.m.Y H:i') }} Uhr</strong>@if($order->shipping_due_at->isPast()) · <strong>überschritten</strong>@endif</div>
@endif
@if($order->shipment?->review_status==='rejected')
<div class="flash error">{{ $order->shipment->review_comment }}<br>Neue Nachweise bis {{ $order->shipment->resubmit_due_at?->format('d.m.Y H:i') }} Uhr.</div>
@endif
<form method="post" enctype="multipart/form-data" action="{{ route('orders.shipment',$order) }}" class="form-grid">@csrf
<label>Versanddienstleister<input name="carrier" value="{{ old('carrier',$order->shipment?->carrier) }}" placeholder="z. B. DHL" required></label>
@if($trackingMode!=='none')
<label>Trackingnummer<input name="tracking_number" value="{{ old('tracking_number',$order->shipment?->tracking_number) }}" @required($trackingMode==='required')><small>{{ $trackingMode==='required'?'Pflicht':'optional' }}</small></label>
@endif
<label class="full">Paketfoto über Live-Kamera<input type="file" name="package_photo" accept="image/*" capture="environment" required></label>
<label class="full">Versand-/Annahmebeleg über Live-Kamera<input type="file" name="receipt_photo" accept="image/*" capture="environment" required><small>Versanddatum und Versanddienstleister müssen eindeutig lesbar sein.</small></label>
<div class="notice full">Lege die Auftragsnummer <strong>#{{ $order->order_number }}</strong> in das Paket. Die Versandkosten trägst du selbst.</div>
<div class="full"><button class="btn primary">Versandnachweise einreichen</button></div>
</form>
</div>
@endif

@if($order->shipment)
<div class="panel" style="margin-bottom:18px">
<h2>Versand</h2>
<dl class="meta-list">
<div><dt>Dienstleister</dt><dd>{{ $order->shipment->carrier }}</dd></div>
<div><dt>Tracking</dt><dd>{{ $order->shipment->tracking_number ?: '–' }}</dd></div>
<div><dt>Versendet</dt><dd>{{ $order->shipment->shipped_at?->format('d.m.Y H:i') }}</dd></div>
<div><dt>Nachweisprüfung</dt><dd>{{ strtoupper($order->shipment->review_status) }}</dd></div>
</dl>
</div>
@endif

@if($order->status==='rejected' && $order->goodsInspection?->result==='rejected')
@php
$returnDecisionDeadline=$order->goodsInspection->reviewed_at?->copy()->timezone('Europe/Berlin')->startOfDay()->addDays(3)->endOfDay();
@endphp
<div class="panel" style="margin-bottom:18px">
<h2>Rücksendung abgelehnter Ware</h2>
@if(!$order->returnRequest)
@if($returnDecisionDeadline && now('Europe/Berlin')->lte($returnDecisionDeadline))
<p>Du kannst bis <strong>{{ $returnDecisionDeadline->format('d.m.Y H:i') }} Uhr</strong> eine Rücksendung auf eigene Kosten verlangen.</p>
<form method="post" enctype="multipart/form-data" action="{{ route('orders.return-request',$order) }}" class="stack-form">@csrf
<label>Variante<select name="method" data-return-method>
<option value="own_label">Eigenes gültiges Rücksendeetikett bereitstellen</option>
<option value="operator_quote">Betreiber teilt tatsächliche Versandkosten mit; separate Überweisung</option>
</select></label>
<label>Eigenes Rücksendeetikett<input type="file" name="return_label" accept="application/pdf,image/jpeg,image/png,image/webp"><small>Bei Wahl „eigenes Etikett“ erforderlich.</small></label>
<div class="notice">Nach rechtzeitiger Anforderung bleiben 24 Stunden, um das Etikett bereitzustellen bzw. die mitgeteilten Versandkosten separat zu begleichen. Das Wallet wird dafür nicht verwendet.</div>
<button class="btn primary">Rücksendung verbindlich anfordern</button>
</form>
@else
<div class="notice">Die Frist zur Anforderung einer Rücksendung ist abgelaufen. Die Ware verbleibt beim Betreiber.</div>
@endif
@else
<dl class="meta-list">
<div><dt>Status</dt><dd>{{ strtoupper(str_replace('_',' ',$order->returnRequest->status)) }}</dd></div>
<div><dt>Angefordert</dt><dd>{{ $order->returnRequest->requested_at?->format('d.m.Y H:i') }}</dd></div>
<div><dt>24-Stunden-Frist</dt><dd>{{ $order->returnRequest->fulfillment_due_at?->format('d.m.Y H:i') }}</dd></div>
<div><dt>Variante</dt><dd>{{ $order->returnRequest->method==='own_label'?'Eigenes Rücksendeetikett':'Separate Übernahme der tatsächlichen Versandkosten' }}</dd></div>
@if($order->returnRequest->requested_shipping_cost)<div><dt>Mitgeteilte Versandkosten</dt><dd>{{ number_format($order->returnRequest->requested_shipping_cost,2,',','.') }} €</dd></div>@endif
</dl>
@if($order->returnRequest->status==='awaiting_quote_payment' && $order->returnRequest->requested_shipping_cost)
<div class="notice">Bitte überweise die mitgeteilten Rücksendekosten separat. Der Admin bestätigt den Zahlungseingang im System. Eine Belastung des Wallets erfolgt nicht.</div>
@endif
@if($order->returnRequest->status==='expired')
<div class="flash error">Die 24-Stunden-Frist ist abgelaufen. Die Rücksendeoption ist endgültig verfallen.</div>
@endif
@if($order->returnRequest->status==='returned')
<div class="notice">Die Rücksendung wurde durch den Betreiber als ausgeführt markiert.</div>
@endif
@endif
</div>
@endif

<div class="order-layout">
<section>
<div class="section-head"><div><span class="eyebrow">Nachweise</span><h2>Serien & Kalendertage</h2></div></div>
<div class="days">
@forelse($order->days->sortByDesc('series_number')->sortBy('day_number') as $day)
@php
$isCurrent=(int)$day->series_number===$currentSeries;
$windows=$day->day_number===0
    ? [['key'=>'start','label'=>'Startfoto','start'=>'00:00','end'=>'23:59','required_images'=>1,'text_required'=>false,'face_required'=>data_get($order->current_requirements,'inspection_config.start_face_required',false)]]
    : data_get($order->current_requirements ?: $order->offer_snapshot,'proof_requirements',[]);
@endphp
<article class="day-card {{ !$day->counts_toward_series?'archived':'' }}" id="nachweis-tag-{{ $day->id }}">
<div class="day-top">
<div><span class="day-number">Serie {{ $day->series_number }} · {{ $day->day_number===0?'Start':('Tag '.$day->day_number) }}</span><strong>{{ $day->date->format('d.m.Y') }}</strong></div>
<span class="status {{ $day->status }}">{{ strtoupper($day->status) }}</span>
</div>
@if(!$day->counts_toward_series)<div class="notice">Archiviert – zählt nicht mehr zur aktuellen erfolgreichen Serie.@if($day->invalid_reason) {{ $day->invalid_reason }}@endif</div>@endif

@foreach(is_array($windows)?$windows:[] as $window)
@php
$key=(string)($window['key']??'default');
$required=(int)($window['required_images']??1);
$windowProofs=$day->proofs->where('window_key',$key);
$accepted=$windowProofs->where('review_status','accepted')->count();
$pending=$windowProofs->where('review_status','pending')->count();
$latestRejected=$windowProofs->where('review_status','rejected')->sortByDesc('id')->first();
$challenge=$order->proofChallenges->first(fn($c)=>$c->order_day_id===$day->id && $c->window_key===$key && !$c->used_at && $c->expires_at?->isFuture());
$canSubmit=$isCurrent && $day->counts_toward_series && in_array($order->status,['active','waiting_start'],true) && ($accepted+$pending)<$required;
@endphp
<div class="panel" style="margin:12px 0">
<strong>{{ $window['label']??$key }}</strong>
<small class="muted"> · {{ $window['start']??'00:00' }}–{{ $window['end']??'23:59' }} · akzeptiert {{ $accepted }}/{{ $required }}@if($window['face_required']??false) · Gesicht Pflicht@endif</small>
@foreach($windowProofs as $proof)
<div class="proof-list"><div><span>📎 Versuch {{ $proof->retry_number }} · Code {{ $proof->proof_code }}</span><span class="status {{ $proof->review_status }}">{{ strtoupper($proof->review_status) }}</span></div>@if($proof->review_comment)<small>{{ $proof->review_comment }}</small>@endif</div>
@endforeach

@if($canSubmit && $day->day_number>0)
@if(!$challenge)
<form method="post" action="{{ route('proofs.challenge',$day) }}" style="margin-top:10px">@csrf<input type="hidden" name="window_key" value="{{ $key }}"><button class="btn secondary">10-Minuten-Code erzeugen</button></form>
@else
<div class="notice"><strong>Code: {{ $challenge->code }}</strong> · gültig bis {{ $challenge->expires_at->format('H:i:s') }} Uhr</div>
<form method="post" enctype="multipart/form-data" action="{{ route('proofs.store',$day) }}" class="stack-form" data-proof-upload data-code="{{ $challenge->code }}">@csrf
<input type="hidden" name="challenge_id" value="{{ $challenge->id }}">
<input type="hidden" name="proof_code" value="{{ $challenge->code }}">
<input type="hidden" name="window_key" value="{{ $key }}">
@if($window['text_required']??false)<label>Pflichttext<textarea name="text_value" rows="3" required></textarea></label>@endif
<label>Live-Kamera<input type="file" name="proof" accept="image/*" capture="environment" required data-proof-file></label>
<label class="check"><input type="checkbox" data-overlay-code><span>Code automatisch sichtbar in das aufgenommene Bild einblenden</span></label>
<button class="btn secondary">Nachweis einreichen</button>
</form>
@endif
@endif
</div>
@endforeach
</article>
@empty<div class="empty">Noch keine Auftragstage vorhanden.</div>@endforelse
</div>
</section>

<aside>
<div class="panel">
<h3>Aktuelle Konditionen</h3>
<dl class="meta-list">
<div><dt>Aktivierung</dt><dd>{{ $order->confirmed_start_date?->format('d.m.Y') ?: 'noch offen' }}</dd></div>
<div><dt>Dauer</dt><dd>{{ data_get($order->offer_snapshot,'duration_days') }} gültige Tage</dd></div>
<div><dt>Serie</dt><dd>{{ $order->series_number }}</dd></div>
<div><dt>Unterbrechungen</dt><dd>{{ $order->series_interruptions }}/1 vor Neustart</dd></div>
<div><dt>Tracking</dt><dd>{{ ['required'=>'Pflicht','optional'=>'optional','none'=>'nicht vorgesehen'][$trackingMode] }}</dd></div>
</dl>

@if($order->fieldValues->count())
<h4>Deine Angaben</h4>
<dl class="meta-list">@foreach($order->fieldValues as $field)<div><dt>{{ $field->label }}</dt><dd>{{ $field->value==='1' && data_get($field->field_snapshot,'type')==='checkbox' ? 'Ja' : ($field->value ?: '–') }}</dd></div>@endforeach</dl>
@endif

@if($order->options->count())
<h4>Extras</h4><ul>@foreach($order->options as $option)<li>{{ $option->name }} · +{{ number_format($option->price_delta,2,',','.') }} €</li>@endforeach</ul>
@endif

@if(data_get($order->current_requirements,'admin_addition.text'))
<div class="notice"><strong>Nachträgliche verbindliche Anforderung</strong><br>{{ data_get($order->current_requirements,'admin_addition.text') }}<br><small>Wirksam ab {{ \Carbon\Carbon::parse(data_get($order->current_requirements,'admin_addition.effective_at'))->format('d.m.Y H:i') }}</small></div>
@endif

<a class="btn secondary wide" href="{{ route('messages.index') }}">Auftragsnachrichten</a>
</div>

@if(in_array($order->status,['precheck','precheck_resubmit','approved','waiting_start','active','paused'],true))
<div class="panel danger-zone">
<h3>Auftrag freiwillig abbrechen</h3>
<p>Ein freiwilliger Abbruch wird mit 0 € vergütet und in der Zuverlässigkeit berücksichtigt.</p>
<form method="post" action="{{ route('orders.abort',$order) }}" class="stack-form">@csrf
<label>Grund<textarea name="reason" rows="4" required></textarea></label>
<button class="btn secondary">Auftrag abbrechen</button>
</form>
</div>
@endif
</aside>
</div>
@endsection
