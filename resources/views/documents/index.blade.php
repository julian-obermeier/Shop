@extends('layouts.app')
@section('title','Dokumente')
@section('content')
<div class="page-head">
<span class="eyebrow">Rechtliches</span>
<h1>Dokumente</h1>
<p>Aktuelle veröffentlichte Dokumente sowie die bei deiner Registrierung protokollierten Zustimmungen.</p>
</div>

<div class="days">
@foreach($documents as $document)
@php($version=$document->versions->first())
@if($version)
<article class="panel">
<div class="page-head split">
<div>
<span class="eyebrow">{{ strtoupper($document->type) }}</span>
<h2>{{ $document->title }}</h2>
<p>Aktuelle Version {{ $version->version }} · veröffentlicht {{ $version->published_at?->format('d.m.Y') }}</p>
</div>
@php($consent=$consents->get($version->id))
@if($consent)
<span class="status accepted">ZUGESTIMMT {{ $consent->consented_at?->format('d.m.Y') }}</span>
@elseif($document->requires_consent)
<span class="status pending">KEINE NACHTRÄGLICHE ZUSTIMMUNG ERFORDERLICH</span>
@endif
</div>
<div class="prose" style="white-space:pre-wrap">{{ $version->content }}</div>
</article>
@endif
@endforeach
</div>

<div class="notice">Zustimmungspflichtige Dokumentversionen werden einmalig bei der Registrierung dokumentiert. Später veröffentlichte Versionen erzeugen keinen erneuten Zustimmungszwang.</div>
@endsection
