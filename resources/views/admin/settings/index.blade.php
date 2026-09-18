@extends('layouts.app')
@section('title','Systemeinstellungen')
@section('content')
<div class="page-head"><span class="eyebrow">Administration</span><h1>Systemeinstellungen</h1><p>Zentrale Plattformparameter und regelbasierte Zuverlässigkeitslogik verwalten.</p></div>

<div class="panel" style="max-width:980px">
<form method="post" action="{{ route('admin.settings.update') }}" class="form-grid">@csrf @method('PUT')
<label>Plattformname<input name="site_name" value="{{ $settings['site_name'] }}" required></label>
<label>Support-E-Mail<input type="email" name="support_email" value="{{ $settings['support_email'] }}"></label>

<label class="check full"><input type="checkbox" name="proof_reminders_enabled" value="1" @checked($settings['proof_reminders_enabled'])><span>Mehrstufige Nachweiserinnerungen aktivieren</span></label>
<label class="check full"><input type="checkbox" name="email_notifications_enabled" value="1" @checked($settings['email_notifications_enabled'])><span>Benachrichtigungen zusätzlich per E-Mail senden</span></label>
<label class="check full"><input type="checkbox" name="push_notifications_enabled" value="1" @checked($settings['push_notifications_enabled'])><span>Push-Benachrichtigungen verwenden, wenn vom Gerät aktiviert und technisch verfügbar</span></label>

<div class="full">
<hr>
<h2>Zuverlässigkeitsregeln</h2>
<p class="notice">Es gibt keinen Score. Regeln reagieren auf die Anzahl relevanter Verstöße im aktuellen Bewährungszyklus. Ein neuer relevanter Verstoß setzt eine laufende 5er-Bewährung auf 0. Einschränkungen werden nach 5/5 niemals automatisch aufgehoben.</p>
</div>

<label class="full">Regeln als JSON
<textarea name="reliability_rules_json" rows="18" spellcheck="false">{{ json_encode($settings['reliability_rules'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</textarea>
<small>Felder: key, violations, max_active_orders (0–5 oder null), blocked_offer_ids (Array), reason. Die höchste erreichte Schwelle gilt.</small>
</label>

<div class="full"><button class="btn primary">Einstellungen speichern</button></div>
</form>
</div>
@endsection
