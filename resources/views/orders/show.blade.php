@extends('layouts.app')
@section('title','Auftrag #'.$order->order_number)
@section('content')
@php($allComplete=$order->days->isNotEmpty() && $order->days->every(fn($day)=>$day->proofs->count() >= $day->required_proofs))
<div class="page-head split"><div><span class="eyebrow">Auftrag #{{ $order->order_number }}</span><h1>{{ data_get($order->offer_snapshot,'title') }}</h1><span class="status {{ $order->status }}">{{ strtoupper(str_replace('_',' ',$order->status)) }}</span></div><div class="headline-amount"><span>Vergütung</span><strong>{{ number_format($order->compensation_total,2,',','.') }} €</strong></div></div>

@if(in_array($order->status,['precheck','precheck_resubmit']))
<div class="panel" style="margin-bottom:18px">
<h2>Vorprüfung erforderlich</h2>
@if($order->precheck?->admin_comment)<div class="notice">{{ $order->precheck->admin_comment }}</div>@endif
<form method="post" enctype="multipart/form-data" action="{{ route('orders.precheck',$order) }}" class="form-grid">@csrf
<input type="hidden" name="existing_photo" value="{{ $order->precheck?->photo_path }}">
<label>Artikelart<input name="item_type" value="{{ old('item_type',$order->precheck?->item_type) }}"></label>
<label>Größe / Variante<input name="item_size" value="{{ old('item_size',$order->precheck?->item_size) }}"></label>
<label class="full">Beschreibung<textarea name="item_description" rows="4" required>{{ old('item_description',$order->precheck?->item_description) }}</textarea></label>
<label class="full">Prüffoto<input type="file" name="photo" accept="image/jpeg,image/png,image/webp" @required(!$order->precheck?->photo_path)></label>
<div class="full"><button class="btn primary">Vorprüfung einreichen</button></div>
</form>
</div>
@endif

@if(in_array($order->status,['approved','waiting_start']))<div class="panel start-panel"><div><h3>Bereit zum Start?</h3><p>Beim Start werden {{ data_get($order->offer_snapshot,'duration_days') }} Tage mit den jeweiligen Nachweispflichten erzeugt.</p></div><form method="post" action="{{ route('orders.start',$order) }}">@csrf<button class="btn primary">Erfüllungsphase starten</button></form></div>@endif

@if($order->status==='active' && $allComplete)
<div class="panel start-panel"><div><h3>Alle Pflichtnachweise vorhanden</h3><p>Du kannst die Erfüllungsphase jetzt abschließen und anschließend den Versand melden.</p></div><form method="post" action="{{ route('orders.complete',$order) }}">@csrf<button class="btn primary">Erfüllungsphase abschließen</button></form></div>
@endif

@if($order->status==='waiting_shipping')
<div class="panel" style="margin-bottom:18px"><h2>Versand melden</h2><form method="post" action="{{ route('orders.shipment',$order) }}" class="form-grid">@csrf<label>Versanddienstleister<input name="carrier" placeholder="z. B. DHL" required></label><label>Trackingnummer<input name="tracking_number" required></label><div class="full"><button class="btn primary">Versand speichern</button></div></form></div>
@endif

@if($order->shipment)
<div class="panel" style="margin-bottom:18px"><h2>Versand</h2><dl class="meta-list"><div><dt>Dienstleister</dt><dd>{{ $order->shipment->carrier }}</dd></div><div><dt>Tracking</dt><dd>{{ $order->shipment->tracking_number }}</dd></div><div><dt>Status</dt><dd>{{ strtoupper($order->shipment->status) }}</dd></div></dl></div>
@endif

<div class="order-layout"><section><div class="section-head"><div><span class="eyebrow">Fortschritt</span><h2>Tag für Tag</h2></div></div><div class="days">@forelse($order->days as $day)<article class="day-card" id="nachweise"><div class="day-top"><div><span class="day-number">Tag {{ $day->day_number }}</span><strong>{{ $day->date->format('d.m.Y') }}</strong></div><span>{{ $day->proofs->count() }}/{{ $day->required_proofs }} Nachweise</span></div><div class="proof-list">@foreach($day->proofs as $proof)<div><span>📎 {{ $proof->original_name }}</span><span class="status {{ $proof->review_status }}">{{ strtoupper($proof->review_status) }}</span></div>@endforeach</div>@if($day->proofs->count() < $day->required_proofs && $order->user_id===auth()->id())<form method="post" enctype="multipart/form-data" action="{{ route('proofs.store',$day) }}" class="upload-form">@csrf<input type="file" name="proof" accept="image/jpeg,image/png,image/webp" required><button class="btn secondary">Nachweis hochladen</button></form>@endif</article>@empty<div class="empty">Die Tagesübersicht wird beim Start des Auftrags erzeugt.</div>@endforelse</div></section><aside><div class="panel"><h3>Vereinbarte Konditionen</h3><dl class="meta-list"><div><dt>Dauer</dt><dd>{{ data_get($order->offer_snapshot,'duration_days') }} Tage</dd></div><div><dt>Nachweise/Tag</dt><dd>{{ data_get($order->offer_snapshot,'proofs_per_day') }}</dd></div><div><dt>Mindestdauer</dt><dd>{{ data_get($order->offer_snapshot,'minimum_minutes_per_day') }} Min.</dd></div><div><dt>Versandfrist</dt><dd>{{ data_get($order->offer_snapshot,'shipping_deadline_hours') }} Std.</dd></div></dl>@if($order->options->count())<h4>Extras</h4><ul>@foreach($order->options as $option)<li>{{ $option->name }} · +{{ number_format($option->price_delta,2,',','.') }} €</li>@endforeach</ul>@endif
<a class="btn secondary wide" href="{{ route('messages.index') }}">Nachricht zum Auftrag senden</a></div></aside></div>
@endsection
