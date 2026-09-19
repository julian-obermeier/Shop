@extends('layouts.app')
@section('title','Angebote verwalten')
@section('content')
<div class="page-head split"><div><span class="eyebrow">Administration</span><h1>Angebote</h1><p>Manuell aktivieren, duplizieren, bearbeiten oder vollständig löschen.</p></div><a class="btn primary" href="{{ route('admin.offers.create') }}">+ Neues Angebot</a></div>
<div class="table-card"><table><thead><tr><th>Titel</th><th>Kategorie</th><th>Vergütung</th><th>Kapazität</th><th>Typ</th><th>Status</th><th>Aktionen</th></tr></thead><tbody>
@foreach($offers as $offer)
<tr>
<td><strong>{{ $offer->title }}</strong></td>
<td>{{ $offer->category?->name }}</td>
<td>{{ number_format($offer->base_compensation,2,',','.') }} €</td>
<td>{{ $offer->capacity ? $offer->active_orders_count.'/'.$offer->capacity : $offer->active_orders_count.'/∞' }}</td>
<td>{{ $offer->is_sock_wearing?'Socken · Tragezeit':'Standard' }}</td>
<td><span class="status {{ $offer->active?'accepted':'pending' }}">{{ $offer->active?'AKTIV':'INAKTIV' }}</span></td>
<td style="display:flex;gap:8px;flex-wrap:wrap">
<a class="btn secondary" href="{{ route('admin.offers.edit',$offer) }}">Bearbeiten</a>
<form method="post" action="{{ route('admin.offers.duplicate',$offer) }}">@csrf<button class="btn secondary">Duplizieren</button></form>
<form method="post" action="{{ route('admin.offers.destroy',$offer) }}" onsubmit="return confirm('Angebot wirklich vollständig löschen? Bestehende Aufträge behalten ihren Snapshot.')">@csrf @method('DELETE')<label class="check"><input type="checkbox" name="confirm_delete" value="1" required><span>Löschen ausdrücklich bestätigen</span></label><button class="btn secondary">Löschen</button></form>
</td>
</tr>
@endforeach
</tbody></table></div>{{ $offers->links() }}
@endsection
