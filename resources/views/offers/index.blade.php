@extends('layouts.app')
@section('title','Angebote')
@section('content')
<div class="page-head"><div><span class="eyebrow">Ankaufangebote</span><h1>Wähle den Auftrag, der zu dir passt.</h1><p>Vergütung und Anforderungen sind vor der Annahme vollständig transparent.</p></div></div>
<form class="filters" method="get"><input name="q" value="{{ request('q') }}" placeholder="Angebot suchen"><select name="category"><option value="">Alle Kategorien</option>@foreach($categories as $category)<option value="{{ $category->slug }}" @selected(request('category')===$category->slug)>{{ $category->name }}</option>@endforeach</select><button class="btn secondary">Filtern</button></form>
<div class="offer-grid">@forelse($offers as $offer)@include('components.offer-card',['offer'=>$offer])@empty<div class="empty">Keine passenden Angebote gefunden.</div>@endforelse</div><div class="pagination">{{ $offers->links() }}</div>
@endsection
