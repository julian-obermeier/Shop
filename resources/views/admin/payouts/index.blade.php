@extends('layouts.app')
@section('title','Auszahlungen')
@section('content')
<div class="page-head"><span class="eyebrow">Finanzen</span><h1>Auszahlungen</h1><p>Prüfung, externe Zahlung, 24-Stunden-Abschluss und vollständige Wiedereröffnung.</p></div>
<div class="table-card"><table><thead><tr><th>Nr.</th><th>Anbieterin</th><th>Betrag</th><th>Status</th><th>Ziel</th><th>Bearbeitung</th></tr></thead><tbody>
@foreach($payouts as $payout)
<tr>
<td><strong>{{ $payout->payout_number }}</strong><br><small>{{ $payout->created_at->format('d.m.Y H:i') }} · Freitag {{ $payout->processing_date?->format('d.m.Y') }}</small></td>
<td><a href="{{ route('admin.users.show',$payout->user) }}">{{ $payout->user->first_name }} {{ $payout->user->last_name }}</a></td>
<td>{{ number_format($payout->amount,2,',','.') }} €</td>
<td><span class="status {{ $payout->status }}">{{ strtoupper(str_replace('_',' ',$payout->status)) }}</span>
@if($payout->payment_executed_at)<br><small>Zahlung ausgeführt: {{ $payout->payment_executed_at->format('d.m.Y H:i') }}</small>@endif
</td>
<td>
@if($payout->method==='paypal')
PayPal<br><small>{{ data_get($payout->destination,'paypal_email') }} · {{ data_get($payout->destination,'paypal_name') }}</small>
@else
Bank<br><small>{{ data_get($payout->destination,'iban') }} · {{ data_get($payout->destination,'account_holder') }}</small>
@endif
</td>
<td>
<form method="post" action="{{ route('admin.payouts.update',$payout) }}" class="stack-form">@csrf
<select name="status">
@foreach(['requested'=>'Beantragt','review'=>'In Prüfung','approved'=>'Freigegeben','failed'=>'Zahlung fehlgeschlagen','payment_executed'=>'Zahlung ausgeführt','completed'=>'Abgeschlossen','rejected'=>'Abgelehnt','cancelled'=>'Beendet/Storniert'] as $value=>$label)
<option value="{{ $value }}" @selected($payout->status===$value)>{{ $label }}</option>
@endforeach
</select>
<textarea name="admin_note" rows="2" placeholder="Interner/verfahrensbezogener Hinweis">{{ $payout->admin_note }}</textarea>
<textarea name="rejection_reason" rows="2" placeholder="Ablehnungsgrund – bei Ablehnung Pflicht">{{ $payout->rejection_reason }}</textarea>
<button class="btn primary">Status speichern</button>
</form>
</td>
</tr>
@endforeach
</tbody></table></div>{{ $payouts->links() }}
@endsection
