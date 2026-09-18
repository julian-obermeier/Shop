@extends('layouts.app')
@section('title','Auszahlungen')
@section('content')
<div class="page-head"><span class="eyebrow">Finanzen</span><h1>Auszahlungen</h1><p>Prüfung, Freigabe, Zahlung und Rückabwicklung von Auszahlungsanträgen.</p></div>
<div class="table-card"><table><thead><tr><th>Nr.</th><th>Anbieterin</th><th>Betrag</th><th>Status</th><th>Ziel</th><th>Bearbeitung</th></tr></thead><tbody>
@foreach($payouts as $payout)
<tr><td><strong>{{ $payout->payout_number }}</strong><br><small>{{ $payout->created_at->format('d.m.Y H:i') }}</small></td><td><a href="{{ route('admin.users.show',$payout->user) }}">{{ $payout->user->first_name }} {{ $payout->user->last_name }}</a></td><td>{{ number_format($payout->amount,2,',','.') }} €</td><td><span class="status {{ $payout->status }}">{{ strtoupper($payout->status) }}</span></td><td>{{ data_get($payout->destination,'iban') }}</td><td>
@if(!in_array($payout->status,['paid','rejected','cancelled'],true))
<form method="post" action="{{ route('admin.payouts.update',$payout) }}" class="stack-form">@csrf<select name="status"><option value="review">In Prüfung</option><option value="approved">Freigegeben</option><option value="paid">Ausgezahlt</option><option value="rejected">Abgelehnt</option><option value="failed">Fehlgeschlagen</option><option value="cancelled">Storniert</option></select><textarea name="admin_note" rows="2" placeholder="Notiz"></textarea><button class="btn primary">Status speichern</button></form>
@else<span class="muted">{{ $payout->admin_note }}</span>@endif
</td></tr>
@endforeach
</tbody></table></div>{{ $payouts->links() }}
@endsection
