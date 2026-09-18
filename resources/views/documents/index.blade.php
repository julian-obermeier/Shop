@extends('layouts.app')
@section('title','Dokumente')
@section('content')
<div class="page-head"><span class="eyebrow">Rechtliches</span><h1>Dokumente & Zustimmungen</h1><p>Hier siehst du die jeweils aktuelle veröffentlichte Version und deine dokumentierte Zustimmung.</p></div>
<div class="days">
@foreach($documents as $document)
@php($version=$document->versions->first())
@if($version)
<article class="panel"><div class="page-head split"><div><span class="eyebrow">{{ strtoupper($document->type) }}</span><h2>{{ $document->title }}</h2><p>Version {{ $version->version }} · veröffentlicht {{ $version->published_at?->format('d.m.Y') }}</p></div>
@if($document->requires_consent)
@if(in_array($version->id,$consented,true))<span class="status accepted">ZUGESTIMMT</span>
@else<form method="post" action="{{ route('documents.consent',$version) }}">@csrf<button class="btn primary">Zustimmen</button></form>@endif
@endif</div><div class="prose" style="white-space:pre-wrap">{{ $version->content }}</div></article>
@endif
@endforeach
</div>
@endsection
