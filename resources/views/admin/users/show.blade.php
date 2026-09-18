@extends('layouts.app')
@section('title',$user->first_name.' '.$user->last_name)
@section('content')
<div class="page-head split"><div><span class="eyebrow">Benutzerakte #{{ $user->id }}</span><h1>{{ $user->first_name }} {{ $user->last_name }}</h1><p>{{ $user->email }}</p></div><span class="status {{ $user->status==='active'?'accepted':'rejected' }}">{{ strtoupper($user->status) }}</span></div>
<div class="admin-order-grid"><section>
<div class="panel"><h2>Stammdaten</h2>
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
<dl class="meta-list"><div><dt>Telefon</dt><dd>{{ $user->profile?->phone ?: '–' }}</dd></div><div><dt>E-Mail</dt><dd>{{ $user->hasVerifiedEmail()?'bestätigt':'offen' }}</dd></div></dl>
</div>

<div class="panel"><h2>Auszahlungsempfänger</h2>
<dl class="meta-list">
<div><dt>Bank</dt><dd>{{ $user->profile?->bank_account_holder ?: '–' }} · {{ $user->profile?->bank_iban ?: '–' }}</dd></div>
<div><dt>PayPal</dt><dd>{{ $user->profile?->paypal_name ?: '–' }} · {{ $user->profile?->paypal_email ?: '–' }}</dd></div>
<div><dt>Letzte Änderung</dt><dd>{{ $user->profile?->payout_details_changed_at?->format('d.m.Y H:i') ?: '–' }}</dd></div>
<div><dt>Abweichender Name freigegeben</dt><dd>{{ $user->profile?->payout_name_approved_at?->format('d.m.Y H:i') ?: 'Nein' }}</dd></div>
</dl>
<form method="post" action="{{ route('admin.users.payout-name-approve',$user) }}">@csrf<button class="btn secondary">Abweichenden Empfänger freigeben</button></form>
</div>

<div class="panel"><h2>Aufträge</h2><div class="table-card"><table><thead><tr><th>Nr.</th><th>Status</th><th>Vergütung</th></tr></thead><tbody>@foreach($user->orders->sortByDesc('created_at')->take(10) as $order)<tr><td><a href="{{ route('admin.orders.show',$order) }}">#{{ $order->order_number }}</a></td><td>{{ strtoupper($order->status) }}</td><td>{{ number_format($order->final_compensation ?? $order->compensation_total,2,',','.') }} €</td></tr>@endforeach</tbody></table></div></div>
</section>
<aside>
<div class="panel"><h2>Einschränkung</h2><form method="post" action="{{ route('admin.users.restriction',$user) }}" class="stack-form">@csrf<label>Bereich<select name="type"><option value="offers">Neue Angebote / Auftragsstart</option><option value="payouts">Auszahlungen</option><option value="uploads">Uploads</option><option value="account">Gesamtes Konto</option></select></label><label>Grund<textarea name="reason" rows="4" required></textarea></label><button class="btn primary wide">Einschränkung aktivieren</button></form></div>
<div class="panel"><h2>Aktive Einschränkungen</h2>@forelse($user->restrictions->where('active',true) as $item)<div class="notice"><strong>{{ strtoupper($item->type) }}</strong><p>{{ $item->reason }}</p><p>Bewährung: {{ (int)$item->successful_count }}/{{ (int)$item->required_successes }}</p><form method="post" action="{{ route('admin.users.restriction.remove',[$user,$item->id]) }}">@csrf<button class="btn secondary" @disabled((int)$item->successful_count < (int)$item->required_successes)>Nach Bewährung aufheben</button></form></div>@empty<p class="muted">Keine.</p>@endforelse</div>
</aside></div>
@endsection
