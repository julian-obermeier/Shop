@extends('layouts.app')
@section('title','Wallet')
@section('content')
<div class="page-head"><div><span class="eyebrow">Finanzen</span><h1>Wallet & Auszahlungen</h1><p>Vormerkungen und freigegebene Vergütungen werden getrennt und als Buchungsjournal geführt.</p></div></div>
<div class="wallet-stats"><div><span>Vorgemerkt</span><strong>{{ number_format($wallet->balance('pending'),2,',','.') }} €</strong></div><div><span>Verfügbar</span><strong>{{ number_format($wallet->balance('available'),2,',','.') }} €</strong></div><div><span>Ausgezahlt</span><strong>{{ number_format(abs($wallet->balance('paid')),2,',','.') }} €</strong></div></div>
<div class="wallet-layout">
<section class="table-card"><div class="card-head"><h2>Buchungen</h2></div><table><thead><tr><th>Datum</th><th>Beschreibung</th><th>Bereich</th><th>Betrag</th></tr></thead><tbody>@forelse($wallet->entries->sortByDesc('created_at') as $entry)<tr><td>{{ $entry->created_at->format('d.m.Y H:i') }}</td><td>{{ $entry->description }}</td><td>{{ ucfirst($entry->bucket) }}</td><td class="{{ $entry->amount>=0?'positive':'negative' }}">{{ $entry->amount>=0?'+':'' }}{{ number_format($entry->amount,2,',','.') }} €</td></tr>@empty<tr><td colspan="4" class="empty">Noch keine Buchungen vorhanden.</td></tr>@endforelse</tbody></table></section>
<aside class="panel"><h2>Auszahlung beantragen</h2><p>Es kann ausschließlich bereits freigegebenes Guthaben beantragt werden. Mindestauszahlung: {{ number_format($minimum,2,',','.') }} €.</p>
<form method="post" action="{{ route('wallet.payout') }}" class="stack-form">@csrf
<label>Betrag<input type="number" name="amount" min="{{ $minimum }}" step="0.01" max="{{ max(0,$wallet->balance('available')) }}" required></label>
<label>IBAN<input name="iban" maxlength="34" required></label>
<button class="btn primary wide" @disabled(auth()->user()->hasRestriction('payouts'))>Auszahlung beantragen</button>
</form>
<hr><h3>Auszahlungen</h3>
@forelse($payouts as $payout)<div class="payout-row"><span>{{ $payout->payout_number }}<small>{{ strtoupper($payout->status) }}</small></span><strong>{{ number_format($payout->amount,2,',','.') }} €</strong></div>@empty<p class="muted">Noch keine Auszahlung beantragt.</p>@endforelse
</aside></div>
@endsection
