@extends('layouts.app')
@section('title',$user->first_name.' '.$user->last_name)
@section('content')
@php
$wallet=$user->walletAccount;
$available=$wallet?->balance('available') ?? 0;
$reserved=$wallet?->balance('payout_pending') ?? 0;
$openOrders=$user->orders->whereNotIn('status',['completed','cancelled','rejected','not_started']);
@endphp

<div class="page-head split">
<div>
<span class="eyebrow">Benutzerakte #{{ $user->id }}</span>
<h1>{{ $user->first_name }} {{ $user->last_name }}</h1>
<p>{{ $user->email }}</p>
</div>
<span class="status {{ $user->status==='active'?'accepted':'rejected' }}">{{ strtoupper($user->status) }}</span>
</div>

<div class="admin-order-grid">
<section>
<div class="panel">
<h2>Stammdaten</h2>
<form method="post" action="{{ route('admin.users.master-data',$user) }}" class="form-grid">@csrf @method('PUT')
<label>Vorname<input name="first_name" value="{{ $user->first_name }}" required></label>
<label>Nachname<input name="last_name" value="{{ $user->last_name }}" required></label>
<label>Geburtsdatum<input type="date" name="birth_date" value="{{ $user->birth_date?->format('Y-m-d') }}" required></label>
<label>Straße<input name="street" value="{{ $user->profile?->street }}"></label>
<label>PLZ<input name="postal_code" value="{{ $user->profile?->postal_code }}"></label>
<label>Ort<input name="city" value="{{ $user->profile?->city }}"></label>
<label>Ländercode<input name="country_code" maxlength="2" value="{{ $user->profile?->country_code ?: 'DE' }}" required></label>
<div class="full"><button class="btn secondary">Stammdaten speichern</button></div>
</form>
<dl class="meta-list">
<div><dt>Telefon</dt><dd>{{ $user->profile?->phone ?: '–' }}</dd></div>
<div><dt>E-Mail</dt><dd>{{ $user->hasVerifiedEmail()?'bestätigt':'offen' }}</dd></div>
@if($user->deactivated_at)<div><dt>Deaktiviert</dt><dd>{{ $user->deactivated_at->format('d.m.Y H:i') }} · {{ $user->deactivation_reason }}</dd></div>@endif
</dl>
</div>

<div class="panel">
<h2>Wallet</h2>
<div class="wallet-stats">
<div><span>Verfügbar</span><strong>{{ number_format($available,2,',','.') }} €</strong></div>
<div><span>Reserviert</span><strong>{{ number_format($reserved,2,',','.') }} €</strong></div>
</div>
<form method="post" action="{{ route('admin.users.wallet-override',$user) }}" class="stack-form">@csrf
<label>Verfügbaren Kontostand direkt setzen (€)<input type="number" name="available_balance" min="0" step="0.01" value="{{ number_format($available,2,'.','') }}" required></label>
<label>Begründung<textarea name="reason" rows="3" required></textarea></label>
<button class="btn secondary">Wallet-Kontostand überschreiben</button>
</form>
<p class="muted">Der Override ersetzt den aktuell verfügbaren Wallet-Kontostand direkt. Reservierte Auszahlungen bleiben separat bestehen. Alt-/Neuwert werden im Audit-Log gespeichert.</p>
</div>

<div class="panel">
<h2>Auszahlungsempfänger</h2>
<dl class="meta-list">
<div><dt>Bank</dt><dd>{{ $user->profile?->bank_account_holder ?: '–' }} · {{ $user->profile?->bank_iban ?: '–' }}</dd></div>
<div><dt>PayPal</dt><dd>{{ $user->profile?->paypal_name ?: '–' }} · {{ $user->profile?->paypal_email ?: '–' }}</dd></div>
<div><dt>Letzte Änderung</dt><dd>{{ $user->profile?->payout_details_changed_at?->format('d.m.Y H:i') ?: '–' }}</dd></div>
<div><dt>Abweichender Name freigegeben</dt><dd>{{ $user->profile?->payout_name_approved_at?->format('d.m.Y H:i') ?: 'Nein' }}</dd></div>
</dl>
<form method="post" action="{{ route('admin.users.payout-name-approve',$user) }}">@csrf<button class="btn secondary">Abweichenden Empfänger freigeben</button></form>
</div>

<div class="panel">
<h2>Aufträge</h2>
<div class="table-card"><table>
<thead><tr><th>Nr.</th><th>Status</th><th>Angebot</th><th>Vergütung</th></tr></thead>
<tbody>
@forelse($user->orders->take(20) as $order)
<tr>
<td><a href="{{ route('admin.orders.show',$order) }}">#{{ $order->order_number }}</a></td>
<td>{{ strtoupper(str_replace('_',' ',$order->status)) }}</td>
<td>{{ data_get($order->offer_snapshot,'title') }}</td>
<td>{{ number_format($order->final_compensation ?? $order->compensation_total,2,',','.') }} €</td>
</tr>
@empty<tr><td colspan="4" class="empty">Keine Aufträge.</td></tr>@endforelse
</tbody>
</table></div>
</div>

<div class="panel">
<h2>Zuverlässigkeitshistorie</h2>
<p>Aktueller Bewährungszyklus: <strong>{{ (int)($user->profile?->reliability_cycle_violations ?? 0) }}</strong> relevante Verstöße.</p>
<div class="timeline">
@forelse($user->reliabilityEvents->take(100) as $event)
<div>
<span>{{ $event->occurred_at?->format('d.m.Y H:i') }}</span>
<strong>{{ strtoupper(str_replace('_',' ',$event->event_kind)) }} · {{ $event->type }}</strong>
<small>{{ $event->description }}@if($event->order) · Auftrag #{{ $event->order->order_number }}@endif</small>
</div>
@empty<p class="muted">Noch keine Zuverlässigkeitsereignisse.</p>@endforelse
</div>
</div>
</section>

<aside>
<div class="panel">
<h2>Regelbasierte Einschränkung</h2>
<form method="post" action="{{ route('admin.users.restriction',$user) }}" class="stack-form">@csrf
<label>Typ<select name="type">
<option value="reliability">Zuverlässigkeit / Auftragslimit</option>
<option value="payouts">Auszahlungen sperren</option>
<option value="uploads">Uploads sperren</option>
</select></label>
<label>Grund<textarea name="reason" rows="4" required></textarea></label>
<label>Max. bestätigte/aktive Aufträge<input type="number" name="max_active_orders" min="0" max="5" placeholder="nur bei Zuverlässigkeit"></label>
<label>Bestimmte Angebote ausschließen
<select name="blocked_offer_ids[]" multiple size="6">
@foreach($offers as $offer)<option value="{{ $offer->id }}">{{ $offer->title }}</option>@endforeach
</select>
</label>
<button class="btn primary wide">Einschränkung aktivieren</button>
</form>
</div>

<div class="panel">
<h2>Aktive Einschränkungen</h2>
@forelse($user->restrictions->where('active',true) as $item)
<div class="notice">
<strong>{{ strtoupper($item->type) }}</strong>
<p>{{ $item->reason }}</p>
@if($item->type==='reliability')
<p>Bewährung: <strong>{{ (int)$item->successful_count }} von {{ (int)$item->required_successes }}</strong></p>
@if($item->max_active_orders!==null)<p>Auftragslimit: {{ $item->max_active_orders }}</p>@endif
@if(is_array($item->blocked_offer_ids) && count($item->blocked_offer_ids))<p>Ausgeschlossene Angebote: {{ implode(', ',$item->blocked_offer_ids) }}</p>@endif
@endif
<form method="post" action="{{ route('admin.users.restriction.remove',[$user,$item->id]) }}">@csrf
<button class="btn secondary" @disabled($item->type==='reliability' && (int)$item->successful_count < (int)$item->required_successes)>
{{ $item->type==='reliability'?'Nach 5/5 manuell aufheben':'Aufheben' }}
</button>
</form>
</div>
@empty<p class="muted">Keine aktiven Einschränkungen.</p>@endforelse
</div>

<div class="panel">
<h2>Einschränkungshistorie</h2>
@forelse($user->restrictions->sortByDesc('created_at') as $item)
<div style="padding:10px 0;border-bottom:1px solid var(--line)">
<strong>{{ strtoupper($item->type) }}</strong>
<p>{{ $item->reason }}</p>
<small class="muted">{{ $item->created_at->format('d.m.Y H:i') }} · {{ $item->active?'aktiv':'aufgehoben' }} · Bewährung {{ (int)$item->successful_count }}/{{ (int)$item->required_successes }}</small>
</div>
@empty<p class="muted">Keine Einschränkungen vorhanden.</p>@endforelse
</div>

@if($user->status==='active')
<div class="panel danger-zone">
<h2>Konto deaktivieren</h2>
<p>Login und neue Aufträge werden gesperrt. Für jeden offenen Auftrag muss ausdrücklich entschieden werden, was passiert.</p>
<form method="post" action="{{ route('admin.users.deactivate',$user) }}" class="stack-form">@csrf
<label>Grund der Kontodeaktivierung<textarea name="reason" rows="4" required></textarea></label>

@foreach($openOrders as $order)
@php($canContinue=in_array($order->status,['shipped','received','inspection','accepted'],true))
<fieldset style="border:1px solid var(--line);border-radius:12px;padding:12px">
<legend><strong>#{{ $order->order_number }} · {{ data_get($order->offer_snapshot,'title') }}</strong></legend>
<p class="muted">Aktueller Status: {{ strtoupper(str_replace('_',' ',$order->status)) }}</p>
<label>Entscheidung
<select name="orders[{{ $order->id }}][action]" required>
@if($canContinue)<option value="continue">Weiterlaufen lassen</option>@endif
<option value="pause">Pausieren</option>
<option value="cancel">Abbrechen</option>
</select>
</label>
<label>Auftragsspezifischer Grund optional<textarea name="orders[{{ $order->id }}][reason]" rows="2"></textarea></label>
<label>Vergütung bei Abbruch (€)<input type="number" name="orders[{{ $order->id }}][compensation_amount]" min="0" max="{{ $order->compensation_total }}" step="0.01" value="0"></label>
@if(!$canContinue)<small class="muted">Weiterlaufen ist nicht zulässig, weil in diesem Status noch eine Interaktion der Anbieterin erforderlich ist.</small>@endif
</fieldset>
@endforeach

<button class="btn primary wide">Konto mit diesen Entscheidungen deaktivieren</button>
</form>
</div>
@else
<div class="panel">
<h2>Konto reaktivieren</h2>
<p>Pausierte Aufträge bleiben zunächst pausiert und müssen anschließend einzeln fortgesetzt werden.</p>
<form method="post" action="{{ route('admin.users.reactivate',$user) }}">@csrf<button class="btn primary wide">Konto wieder aktivieren</button></form>
</div>
@endif
</aside>
</div>
@endsection
