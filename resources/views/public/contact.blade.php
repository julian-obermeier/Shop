@extends('layouts.public')
@section('title','Kontakt')
@section('content')
<section class="public-page"><div class="public-wrap narrow"><span class="eyebrow">Kontakt</span><h1>Fragen zur Plattform?</h1><p class="lead">Bei allgemeinen Fragen erreichst du den Betreiber über die hinterlegte Kontaktadresse.</p>
<div class="panel"><h2>Kontakt</h2>@if($supportEmail)<p><a class="btn primary" href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a></p>@else<p>Die Kontaktadresse wird vor dem Produktivstart in den Systemeinstellungen hinterlegt.</p>@endif<p class="muted">Bitte sende keine sensiblen Nachweisbilder oder Auftragsdateien per normaler E-Mail. Nutze dafür ausschließlich die geschützten Funktionen innerhalb des jeweiligen Auftrags.</p></div></div></section>
@endsection
