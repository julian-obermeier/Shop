@extends('layouts.app')
@section('title','Angebote verwalten')
@section('content')
<div class="page-head split"><div><span class="eyebrow">Administration</span><h1>Angebote</h1></div><a class="btn primary" href="{{ route('admin.offers.create') }}">+ Neues Angebot</a></div><div class="table-card"><table><thead><tr><th>Titel</th><th>Kategorie</th><th>Grundvergütung</th><th>Dauer</th><th>Status</th><th></th></tr></thead><tbody>@foreach($offers as $offer)<tr><td><strong>{{ $offer->title }}</strong></td><td>{{ $offer->category?->name }}</td><td>{{ number_format($offer->base_compensation,2,',','.') }} €</td><td>{{ $offer->duration_days }} Tage</td><td><span class="status {{ $offer->active?'accepted':'pending' }}">{{ $offer->active?'AKTIV':'ENTWURF' }}</span></td><td><a href="{{ route('admin.offers.edit',$offer) }}">Bearbeiten →</a></td></tr>@endforeach</tbody></table></div>{{ $offers->links() }}
@endsection
