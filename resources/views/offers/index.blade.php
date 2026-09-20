@extends(auth()->check() ? 'layouts.app' : 'layouts.public')
@section('title','Angebote')
@section('content')
<div class="{{ auth()->check() ? '' : 'public-page' }}"><div class="{{ auth()->check() ? '' : 'public-wrap' }}">
<div class="page-head"><div><span class="eyebrow">Ankaufangebote</span><h1>Wähle den Auftrag, der zu dir passt.</h1><p>Vergütung, Aufwand und Anforderungen sind vor der Annahme transparent.</p></div></div>
<form class="filters" method="get">
<input name="q" value="{{ request('q') }}" placeholder="Angebote durchsuchen">
<select name="category"><option value="">Alle Kategorien</option>@foreach($categories as $category)<option value="{{ $category->slug }}" @selected(request('category')===$category->slug)>{{ $category->name }}</option>@endforeach</select>
<input type="number" step="0.01" min="0" name="min_compensation" value="{{ request('min_compensation') }}" placeholder="Vergütung ab €">
<input type="number" min="1" name="duration" value="{{ request('duration') }}" placeholder="Dauer in Tagen">
<button class="btn secondary">Filtern</button>
</form>
<div class="offer-grid">@forelse($offers as $offer)@include('components.offer-card',['offer'=>$offer])@empty<div class="empty">Keine passenden Angebote gefunden.</div>@endforelse</div>
<div class="pagination">{{ $offers->links() }}</div>
</div></div>
@endsection
