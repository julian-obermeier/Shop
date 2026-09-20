@extends('layouts.public')
@section('title','Ankaufsangebote für Verkäuferinnen')
@section('content')
<section class="public-hero">
<div class="public-wrap public-hero-grid">
<div><span class="eyebrow">Ankaufsplattform · nur 18+</span><h1>Du entscheidest, welcher Auftrag zu dir passt.</h1><p>Keine eigenen Anzeigen, keine fremden Käufer: Der Betreiber veröffentlicht konkrete Ankaufangebote. Du siehst Vergütung, Aufwand und Nachweise vor der Annahme.</p><div class="public-cta"><a class="btn primary" href="{{ route('offers.index') }}">Angebote entdecken</a><a class="btn secondary" href="{{ route('register') }}">Als Verkäuferin registrieren</a></div></div>
<div class="public-feature-card"><span>Dein Ablauf</span><strong>Auswählen → dokumentieren → erfüllen → versenden/abgeben → prüfen → auszahlen</strong><p>Dein Dashboard zeigt dir jederzeit, was jetzt, als Nächstes und später zu erledigen ist.</p></div>
</div>
</section>
<section class="public-section"><div class="public-wrap"><span class="eyebrow">So funktioniert es</span><h2>Klare Schritte statt kompliziertem Marktplatz.</h2><div class="public-steps">
<div><b>1</b><h3>Registrieren</h3><p>Konto erstellen, Volljährigkeit bestätigen und E-Mail verifizieren.</p></div>
<div><b>2</b><h3>Angebot wählen</h3><p>Vergütung, Dauer, Nachweise, Optionen und Versandbedingungen vorab prüfen.</p></div>
<div><b>3</b><h3>Auftrag durchführen</h3><p>Bei physischen Artikeln zuerst Vorabkontrolle; danach führt dich der Tagesplan durch den Auftrag.</p></div>
<div><b>4</b><h3>Vergütung erhalten</h3><p>Nach vollständigem Abschluss und Prüfung wird der freigegebene Betrag im Wallet verfügbar.</p></div>
</div></div></section>
<section class="public-section soft"><div class="public-wrap"><div class="section-head"><div><span class="eyebrow">Aktuell</span><h2>Verfügbare Angebote</h2></div><a href="{{ route('offers.index') }}">Alle Angebote →</a></div><div class="offer-grid">@forelse($offers as $offer)@include('components.offer-card',['offer'=>$offer])@empty<div class="empty">Aktuell sind keine öffentlichen Angebote aktiv.</div>@endforelse</div></div></section>
<section class="public-section"><div class="public-wrap public-columns"><div><span class="eyebrow">Diskret</span><h2>Deine Aufträge sind nicht öffentlich.</h2><p>Es gibt keine öffentlichen Verkäuferinnenprofile. Personenbezogene Daten, Nachweise und Auftragsverläufe bleiben im geschützten Konto- und Administrationsbereich.</p></div><div><span class="eyebrow">Persönlich</span><h2>Nur deine eigenen Artikel und Inhalte.</h2><p>Jeder Auftrag wird von dir selbst erfüllt. Fremdware oder Inhalte anderer Personen sind nicht Bestandteil der Plattform.</p></div></div></section>
@endsection
