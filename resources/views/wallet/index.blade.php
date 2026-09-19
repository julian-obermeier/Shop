@extends('layouts.app')
@section('title','Wallet')
@section('content')
<div class="page-head"><div><span class="eyebrow">Finanzen</span><h1>Wallet & Auszahlungen</h1><p>Freigegebene Vergütungen, reservierte Auszahlungen und Auszahlungshistorie.</p></div></div>
<div class="wallet-stats">
<div><span>Vorgemerkt</span><strong>{{ number_format($wallet->balance('pending'),2,',','.') }} €</strong></div>
<div><span>Verfügbar</span><strong>{{ number_format($wallet->balance('available'),2,',','.') }} €</strong></div>
<div><span>Für Auszahlung reserviert</span><strong>{{ number_format($wallet->balance('payout_pending'),2,',','.') }} €</strong></div>
<div><span>Ausgezahlt</span><strong>{{ number_format($wallet->balance('paid'),2,',','.') }} €</strong></div>
</div>

<div class="wallet-layout">
<section>
<div class="table-card"><div class="card-head"><h2>Buchungen</h2></div>
<table><thead><tr><th>Datum</th><th>Beschreibung</th><th>Bereich</th><th>Betrag</th></tr></thead><tbody>
@forelse($wallet->entries->sortByDesc('created_at') as $entry)
<tr><td>{{ $entry->created_at->format('d.m.Y H:i') }}</td><td>{{ $entry->description }}</td><td>{{ ucfirst($entry->bucket) }}</td><td class="{{ $entry->amount>=0?'positive':'negative' }}">{{ $entry->amount>=0?'+':'' }}{{ number_format($entry->amount,2,',','.') }} €</td></tr>
@empty<tr><td colspan="4" class="empty">Noch keine Buchungen vorhanden.</td></tr>@endforelse
</tbody></table></div>

<div class="panel" style="margin-top:18px"><h2>Auszahlungshistorie</h2>
@forelse($payouts as $payout)
<div class="payout-row">
<span><strong>{{ $payout->payout_number }}</strong><small>{{ strtoupper(str_replace('_',' ',$payout->status)) }} · {{ $payout->method==='paypal'?'PayPal':'Bank' }} · Bearbeitung {{ $payout->processing_date?->format('d.m.Y') }}</small>
@if($payout->rejection_reason)<small>{{ $payout->rejection_reason }}</small>@endif</span>
<strong>{{ number_format($payout->amount,2,',','.') }} €</strong>
@if(in_array($payout->status,['requested','review'],true))
<form method="post" action="{{ route('wallet.payout.cancel',$payout) }}">@csrf<button class="btn secondary">Stornieren</button></form>
@endif
</div>
@empty<p class="muted">Noch keine Auszahlung beantragt.</p>@endforelse
</div>
</section>

<aside>
<div class="panel"><h2>Auszahlungsdaten</h2>
<form method="post" action="{{ route('wallet.payout-details') }}" class="stack-form">@csrf @method('PUT')
<label>IBAN<input name="bank_iban" maxlength="34" value="{{ old('bank_iban',$user->profile?->bank_iban) }}"></label>
<label>Kontoinhaber<input name="bank_account_holder" value="{{ old('bank_account_holder',$user->profile?->bank_account_holder) }}"></label>
<label>PayPal-E-Mail<input type="email" name="paypal_email" value="{{ old('paypal_email',$user->profile?->paypal_email) }}"></label>
<label>PayPal-Name<input name="paypal_name" value="{{ old('paypal_name',$user->profile?->paypal_name) }}"></label>
<button class="btn secondary wide">Auszahlungsdaten speichern</button>
</form>
@if($user->profile?->payout_details_changed_at)
@php
$payoutDetailsUsableAt=$user->profile->payout_details_changed_at->copy()->addHours(24);
$payoutDetailsLocked=now('Europe/Berlin')->lt($payoutDetailsUsableAt);
@endphp
<p class="muted">
Letzte tatsächliche Änderung: {{ $user->profile->payout_details_changed_at->timezone('Europe/Berlin')->format('d.m.Y H:i') }} Uhr.
@if($payoutDetailsLocked)
<strong>Noch gesperrt bis {{ $payoutDetailsUsableAt->timezone('Europe/Berlin')->format('d.m.Y H:i') }} Uhr.</strong>
@else
<strong>Die gespeicherten Daten sind für neue Auszahlungen nutzbar.</strong>
@endif
<br>Unverändertes erneutes Speichern startet die 24-Stunden-Sicherheitsfrist nicht neu.
</p>
@endif
@if($user->profile && !$user->profile->payout_name_approved_at && (($user->profile->bank_account_holder && mb_strtolower(trim($user->profile->bank_account_holder))!==mb_strtolower(trim($user->first_name.' '.$user->last_name))) || ($user->profile->paypal_name && mb_strtolower(trim($user->profile->paypal_name))!==mb_strtolower(trim($user->first_name.' '.$user->last_name)))))
<div class="notice">Ein abweichender Empfängername wartet auf Adminfreigabe.</div>
@endif
</div>

<div class="panel"><h2>Auszahlung beantragen</h2>
<p>Kein Mindestbetrag. Es kann ein frei wählbarer Teilbetrag des verfügbaren Guthabens beantragt werden. Pro Anbieterin ist nur ein offener Antrag gleichzeitig zulässig.</p>
<form method="post" action="{{ route('wallet.payout') }}" class="stack-form">@csrf
<label>Betrag<input type="number" name="amount" min="0.01" step="0.01" max="{{ max(0,$wallet->balance('available')) }}" required></label>
<label>Methode<select name="method" required><option value="bank_transfer">Banküberweisung</option><option value="paypal">PayPal</option></select></label>
<button class="btn primary wide" @disabled(auth()->user()->hasRestriction('payouts'))>Auszahlung beantragen</button>
</form>
<p class="muted">Anträge bis Donnerstag 23:59 Uhr werden für den folgenden Freitag eingeplant. Freitags eingehende Anträge werden erst am nächsten Freitag bearbeitet.</p>
</div>
</aside>
</div>
@endsection
