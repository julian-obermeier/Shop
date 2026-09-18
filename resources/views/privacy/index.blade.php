@extends('layouts.app')
@section('title','Datenschutz')
@section('content')
<div class="page-head">
<span class="eyebrow">Deine Daten</span>
<h1>Datenschutzcenter</h1>
<p>Hier kannst du einen strukturierten Auszug deiner im System gespeicherten Daten exportieren.</p>
</div>

<div class="wallet-layout">
<section>
<div class="panel">
<h2>Datenauszug</h2>
<p>Der Export enthält Kontodaten, Profilangaben, Aufträge, Wallet-Buchungen, Auszahlungen, Zustimmungen, Nachrichten und weitere strukturierte Informationen. Private Bild- und Dokumentdateien selbst werden nicht in die JSON-Datei eingebettet.</p>
<a class="btn primary" href="{{ route('privacy.export') }}">Datenauszug herunterladen</a>
</div>
</section>

<aside class="panel">
<h2>Kontoänderungen</h2>
<p>Eine eigene Löschung oder Deaktivierung des Kontos ist über die Anbieterinnenoberfläche nicht möglich.</p>
<div class="notice">Kontodeaktivierung, Reaktivierung oder eine administrative Löschung/Anonymisierung erfolgen ausschließlich durch den Admin. Auftrags-, Finanz-, Audit- und sonstige aufbewahrungspflichtige Daten werden dabei nicht automatisch entfernt.</div>
</aside>
</div>
@endsection
