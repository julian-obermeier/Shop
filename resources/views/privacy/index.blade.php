@extends(auth()->check() ? 'layouts.app' : 'layouts.public')
@section('title','Datenschutz')
@section('content')
<div class="{{ auth()->check() ? '' : 'public-page' }}"><div class="{{ auth()->check() ? '' : 'public-wrap narrow' }}">
<div class="page-head"><span class="eyebrow">Datenschutz</span><h1>Deine Daten werden nicht öffentlich präsentiert.</h1><p>Die Plattform verarbeitet Konto-, Auftrags-, Nachweis-, Kommunikations- und Zahlungsdaten ausschließlich für die vorgesehenen Plattform- und Vertragsprozesse.</p></div>
<div class="panel public-prose">
<h2>Grundsätze</h2>
<p>Personenbezogene Daten sollen zweckgebunden, auf das notwendige Maß beschränkt und angemessen geschützt verarbeitet werden. Nachweis- und Mediendateien werden außerhalb des öffentlichen Webverzeichnisses gespeichert und nur nach Berechtigungsprüfung ausgeliefert.</p>
<h2>Kontodaten</h2>
<p>Bei der Registrierung werden insbesondere Name, Geburtsdatum, Anschrift, Telefonnummer und E-Mail-Adresse verarbeitet. Die Plattform ist nur für volljährige Verkäuferinnen bestimmt.</p>
<h2>Auftrags- und Nachweisdaten</h2>
<p>Für angenommene Aufträge werden die vereinbarten Bedingungen, Statusänderungen, eingereichten Nachweise und die zur Auftragsabwicklung erforderlichen technischen Metadaten gespeichert.</p>
<h2>Wallet und Auszahlung</h2>
<p>Auszahlungsdaten werden für die Abwicklung von Auszahlungsanträgen verarbeitet. Bereits eingereichte Auszahlungsanträge speichern einen Snapshot der zum Zeitpunkt des Antrags verwendeten Zahlungsdaten.</p>
<h2>Kontolöschung</h2>
<p>Eine Kontolöschung erfolgt administrativ. Nicht mehr benötigte personenbezogene Profildaten werden gelöscht oder anonymisiert, soweit keine rechtliche Grundlage oder erforderliche Aufbewahrung entgegensteht. Historische Auftrags-, Zahlungs- und erforderliche Nachweisdaten können im zulässigen Umfang erhalten bleiben.</p>
<div class="notice"><strong>Hinweis:</strong> Diese v1-Seite beschreibt die technische Grundlogik. Vor Produktivbetrieb muss die endgültige Datenschutzerklärung mit den tatsächlichen Betreiber-, Hosting-, E-Mail-, Log- und Speicherinformationen vervollständigt und rechtlich geprüft werden.</div>
</div></div></div>
@endsection
