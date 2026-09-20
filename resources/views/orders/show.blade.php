@extends('layouts.app')
@section('title','Auftrag #'.$order->order_number)
@section('content')
@php
$currentSeries=(int)$order->series_number;
$currentDays=$order->days->where('series_number',$currentSeries)->sortBy('day_number');
$acceptedRegular=$currentDays->where('source_type','regular')->where('day_number','>',0)->where('status','accepted')->count();
$requiredDays=(int)data_get($order->offer_snapshot,'duration_days',1);
$canComplete=$order->status==='active' && $order->executionProofsAccepted();
$isDigital=data_get($order->offer_snapshot,'category_kind')==='digital';
@endphp

<div class="page-head split">
<div>
<span class="eyebrow">Auftrag #{{ $order->order_number }}</span>
<h1>{{ data_get($order->offer_snapshot,'title') }}</h1>
<span class="status {{ $order->status }}">{{ strtoupper(str_replace('_',' ',$order->status)) }}</span>
</div>
<div class="headline-amount"><span>Auftragswert</span><strong>{{ number_format($order->compensation_total,2,',','.') }} €</strong>@if($order->final_compensation!==null)<small>Final: {{ number_format($order->final_compensation,2,',','.') }} €</small>@endif</div>
</div>

<div class="phase-bar">
@foreach(['preparation'=>'Vorbereitung','execution'=>'Durchführung','shipping'=>'Versand','review'=>'Prüfung','payout'=>'Auszahlung','archive'=>'Archiv'] as $key=>$label)
<span class="{{ $order->phase===$key?'active':'' }}">{{ $label }}</span>
@endforeach
</div>

@if(in_array($order->status,['precheck','precheck_resubmit'],true))
<section class="panel" style="margin-bottom:18px">
<span class="eyebrow">Vorbereitung · Durchlauf {{ $currentSeries }}</span>
<h2>Vorabkontrolle</h2>
<p>Lege den konkreten Artikel fest und nimm alle geforderten Perspektiven direkt mit der Live-Kamera auf. Bereits akzeptierte Perspektiven müssen nicht erneut erstellt werden.</p>
@if($order->precheck?->admin_comment)<div class="notice">{{ $order->precheck->admin_comment }}</div>@endif

<form method="post" action="{{ route('orders.precheck.details',$order) }}" class="form-grid">@csrf
<label>Artikelart<input name="item_type" value="{{ old('item_type',$order->precheck?->item_type) }}"></label>
<label>Größe / Variante <span class="muted">(freiwillig)</span><input name="item_size" value="{{ old('item_size',$order->precheck?->item_size) }}"></label>
<label class="full">Kurze Artikelbeschreibung<textarea name="item_description" rows="3" required>{{ old('item_description',$order->precheck?->item_description) }}</textarea></label>
<div class="full"><button class="btn secondary">Artikeldaten speichern</button></div>
</form>

<div class="precheck-grid">
@foreach($precheckSlots as $slot)
@php($ev=$precheckEvidence->get($slot['key']))
<article class="precheck-slot">
<div class="day-top"><strong>{{ $slot['label'] }}</strong><span class="status {{ $ev?->status ?? 'open' }}">{{ strtoupper($ev?->status ?? 'OFFEN') }}</span></div>
@if($ev?->admin_comment)<div class="notice">{{ $ev->admin_comment }}</div>@endif
@if(!$ev || $ev->status!=='accepted')
<form method="post" enctype="multipart/form-data" action="{{ route('orders.precheck.evidence',$order) }}" class="stack-form">@csrf
<input type="hidden" name="slot_key" value="{{ $slot['key'] }}">
<label>Live-Kamera<input type="file" name="photo" accept="image/jpeg" required data-live-camera data-camera-context="precheck:{{ $order->id }}:{{ $currentSeries }}:{{ $slot['key'] }}" hidden></label>
<button class="btn primary">Aufnahme speichern</button>
</form>
@else
<p class="positive">Dieser Nachweis wurde freigegeben.</p>
@endif
</article>
@endforeach
</div>

@php($hasAll=$precheckEvidence->count()>=count($precheckSlots))
@if($hasAll)
<form method="post" action="{{ route('orders.precheck.submit',$order) }}" style="margin-top:16px">@csrf<button class="btn primary">Vorabkontrolle vollständig einreichen</button></form>
@endif
<div class="notice">Der Auftrag startet automatisch und unmittelbar, sobald der Admin alle erforderlichen Vorabnachweise akzeptiert hat.</div>
</section>
@endif

@if($order->status==='active' && !$isDigital)
<section class="panel start-panel">
<div><span class="eyebrow">Durchführung · Durchlauf {{ $currentSeries }}</span><h2>{{ $acceptedRegular }} von {{ $requiredDays }} regulären Tagen abgeschlossen</h2><p>Zusätzliche Tage werden am Auftragsende fortlaufend angehängt und mit ihrer Ursache gekennzeichnet.</p></div>
@if($canComplete)<form method="post" action="{{ route('orders.complete',$order) }}">@csrf<button class="btn primary">Durchführung abschließen</button></form>@endif
</section>

<div class="days">
@foreach($currentDays as $day)
<article class="day-card">
<div class="day-top">
<div><span class="day-number">{{ $day->day_number===0?'Starttag':('Tag '.$day->day_number) }} @if($day->source_type!=='regular' && $day->day_number>0) · {{ strtoupper($day->source_type) }} @endif</span><strong>{{ $day->date->format('d.m.Y') }}</strong></div>
<span class="status {{ $day->status }}">{{ strtoupper(str_replace('_',' ',$day->status)) }}</span>
</div>
@if($day->day_number===0)
<div class="notice">Dieser Kalendertag dokumentiert nur den Start. Tag 1 beginnt am folgenden Kalendertag.</div>
@else
@php($windows=is_array($day->plan) ? $day->plan : [])
@foreach($windows as $window)
@php
$key=(string)($window['key']??'default');
$required=(int)($window['required_images']??0);
$proofs=$day->proofs->where('window_key',$key);
$accepted=$proofs->where('review_status','accepted')->count();
$pending=$proofs->where('review_status','pending')->count();
$challenge=$order->proofChallenges->first(fn($c)=>$c->order_day_id===$day->id && $c->window_key===$key && !$c->used_at && !$c->expired_at && $c->expires_at?->isFuture());
@endphp
<div class="proof-window">
<div><strong>{{ $window['label']??$key }}</strong><small>{{ $window['start']??'00:00' }}–{{ $window['end']??'23:59' }} Uhr · benötigt {{ $required }} · akzeptiert {{ $accepted }} · offen/in Prüfung {{ max(0,$required-$accepted) }}</small></div>
@if($day->date->isToday() && ($accepted+$pending)<$required)
@if(!$challenge)
<form method="post" action="{{ route('proofs.challenge',$day) }}">@csrf<input type="hidden" name="window_key" value="{{ $key }}"><button class="btn secondary">10-Minuten-Code erzeugen</button></form>
@else
<div class="notice"><strong>Code {{ $challenge->code }}</strong> · gültig bis {{ $challenge->expires_at->format('H:i:s') }} Uhr</div>
<form method="post" enctype="multipart/form-data" action="{{ route('proofs.store',$day) }}" class="stack-form" data-proof-upload data-code="{{ $challenge->code }}">@csrf
<input type="hidden" name="challenge_id" value="{{ $challenge->id }}"><input type="hidden" name="proof_code" value="{{ $challenge->code }}"><input type="hidden" name="window_key" value="{{ $key }}">
<label>Live-Kamera<input type="file" name="proof" accept="image/jpeg" required data-proof-file data-live-camera data-camera-context="proof:{{ $day->id }}:{{ $challenge->id }}:{{ $key }}" hidden></label>
<button class="btn primary">Nachweis einreichen</button>
</form>
@endif
@endif
</div>
@endforeach
@endif
</article>
@endforeach
</div>
@endif

@if($isDigital)
<section class="panel">
<span class="eyebrow">Digitale Ausführung</span>
<h2>Digitale Leistung</h2>
<p>Dieser Auftrag wird digital erfüllt. Versionen, technische Anforderungen und Revisionen werden im digitalen Abgabebereich des Auftrags geführt.</p>
@if($digitalComponent)<div class="notice">Status: <strong>{{ strtoupper($digitalComponent->status) }}</strong></div>@endif
</section>
@endif

@if($extensionDays->count())
<section class="panel" style="margin-top:18px"><h2>Zusätzliche Tage</h2>
<div class="timeline">@foreach($extensionDays as $extra)<div><span>{{ $extra->date ? \Carbon\Carbon::parse($extra->date)->format('d.m.Y') : 'noch offen' }}</span><strong>{{ strtoupper($extra->source_type) }} · {{ $extra->paid ? number_format((float)$extra->amount,2,',','.').' €' : 'unbezahlt' }}</strong>@if($extra->reason)<small>{{ $extra->reason }}</small>@endif</div>@endforeach</div>
</section>
@endif

@if($violations->count())
<section class="panel" style="margin-top:18px"><h2>Verstöße</h2>
<div class="timeline">@foreach($violations as $v)<div><span>{{ strtoupper($v->status) }}</span><strong>{{ $v->type }}</strong><small>{{ $v->reason }}</small></div>@endforeach</div>
</section>
@endif

@if(in_array($order->status,['waiting_shipping','shipping_overdue'],true))
<section class="panel" style="margin-top:18px"><h2>Versand</h2>
@if($order->shipping_due_at)<div class="notice">Versandfrist: {{ $order->shipping_due_at->format('d.m.Y H:i') }} Uhr</div>@endif
<form method="post" enctype="multipart/form-data" action="{{ route('orders.shipment',$order) }}" class="form-grid">@csrf
<label>Versanddienstleister<input name="carrier" required></label>
<label>Trackingnummer<input name="tracking_number"></label>
<label class="full">Foto des fertig verpackten Pakets<input type="file" name="package_photo" accept="image/*" required></label>
<label class="full">Einlieferungs-/Annahmebeleg über Live-Kamera<input type="file" name="receipt_photo" accept="image/jpeg" required data-live-camera data-camera-context="shipment:{{ $order->id }}:receipt" hidden></label>
<div class="full"><button class="btn primary">Versand nachweisen</button></div>
</form>
</section>
@endif

@if($order->shipment)
<section class="panel" style="margin-top:18px"><h2>Versandstatus</h2><dl class="meta-list"><div><dt>Status</dt><dd>{{ strtoupper($order->shipment->status) }}</dd></div><div><dt>Dienstleister</dt><dd>{{ $order->shipment->carrier ?: '–' }}</dd></div><div><dt>Tracking</dt><dd>{{ $order->shipment->tracking_number ?: '–' }}</dd></div></dl></section>
@endif

@if($order->status==='rejected')
<section class="panel" style="margin-top:18px"><h2>Auftrag endgültig abgelehnt</h2>@php($event=$order->statusHistory->where('to_status','rejected')->sortByDesc('created_at')->first())<div class="notice">{{ $event?->reason ?: 'Der Auftrag wurde endgültig abgelehnt.' }}</div><p>Die zugehörigen Nachweisdateien sind nach endgültiger Ablehnung nur noch administrativ sichtbar.</p></section>
@endif

@if($order->conversation)
<div style="margin-top:18px"><a class="btn secondary" href="{{ route('messages.show',$order->conversation) }}">Auftragschat öffnen</a></div>
@endif

<section class="panel" style="margin-top:18px"><h2>Auftragshistorie</h2><div class="timeline">@foreach($order->statusHistory->sortByDesc('created_at') as $event)<div><span>{{ $event->created_at->format('d.m.Y H:i') }}</span><strong>{{ strtoupper(str_replace('_',' ',$event->to_status)) }}</strong><small>{{ $event->reason }}</small></div>@endforeach</div></section>
@endsection
