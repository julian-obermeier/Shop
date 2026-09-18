@extends('layouts.app')
@section('title','Datenschutz')
@section('content')
<div class="page-head"><span class="eyebrow">Deine Daten</span><h1>Datenschutzcenter</h1><p>Exportiere deine gespeicherten Daten oder stelle einen Antrag auf Löschung bzw. Anonymisierung.</p></div>

<div class="wallet-layout">
<section>
<div class="panel">
<h2>Datenauszug</h2>
<p>Der Export enthält deine Kontodaten, Profilangaben, Aufträge, Wallet-Buchungen, Auszahlungen, Zustimmungen und Nachrichten in einer strukturierten JSON-Datei. Private Bild-/Dokumentdateien selbst werden nicht in den Export eingebettet.</p>
<a class="btn primary" href="{{ route('privacy.export') }}">Datenauszug herunterladen</a>
</div>

<div class="panel" style="margin-top:18px">
<h2>Löschung / Anonymisierung beantragen</h2>
<p>Der Antrag wird administrativ geprüft. Eine technische Anonymisierung ist erst möglich, wenn laufende Aufträge, offene Auszahlungen und Wallet-Beträge vollständig abgeschlossen sind.</p>
@if($blockers)
<div class="notice"><strong>Aktuell bestehende Sperrgründe:</strong><ul>@foreach($blockers as $blocker)<li>{{ $blocker }}</li>@endforeach</ul></div>
@endif
<form method="post" action="{{ route('privacy.delete-request') }}" class="stack-form">@csrf
<label>Optionale Begründung<textarea name="reason" rows="5" maxlength="2000"></textarea></label>
<button class="btn secondary">Antrag einreichen</button>
</form>
</div>
</section>

<aside class="panel">
<h2>Deine Datenschutzanträge</h2>
@forelse($requests as $item)
<div style="padding:12px 0;border-bottom:1px solid var(--line)">
<strong>{{ strtoupper($item->type) }}</strong>
<span class="status {{ $item->status }}">{{ strtoupper($item->status) }}</span>
<p>{{ $item->reason ?: 'Keine Begründung angegeben.' }}</p>
@if($item->admin_note)<div class="notice">{{ $item->admin_note }}</div>@endif
<small class="muted">{{ $item->created_at->format('d.m.Y H:i') }}</small>
@if($item->status==='requested')
<form method="post" action="{{ route('privacy.cancel',$item) }}" style="margin-top:8px">@csrf<button class="btn secondary">Antrag stornieren</button></form>
@endif
</div>
@empty<p class="muted">Noch keine Anträge vorhanden.</p>@endforelse
</aside>
</div>
@endsection
