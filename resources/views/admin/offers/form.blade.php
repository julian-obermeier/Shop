@extends('layouts.app')
@section('title',$offer->exists?'Angebot bearbeiten':'Neues Angebot')
@section('content')
@php
$scoreBandText=collect(data_get($inspectionConfig,'score_bands',[]))->map(fn($b)=>($b['min']??0).'-'.($b['max']??0).'='.($b['percentage']??0))->implode("\n");
@endphp
<div class="page-head"><div><span class="eyebrow">Angebotseditor</span><h1>{{ $offer->exists?'Angebot bearbeiten':'Neues Angebot erstellen' }}</h1><p>Alle fachlichen Regeln des Angebots werden hier zentral konfiguriert.</p></div></div>

<form method="post" enctype="multipart/form-data" action="{{ $offer->exists?route('admin.offers.update',$offer):route('admin.offers.store') }}" class="editor-grid" data-option-editor>
@csrf @if($offer->exists)@method('PUT')@endif

<section class="panel">
<h2>Grunddaten</h2>
<div class="form-grid">
<label>Titel<input name="title" value="{{ old('title',$offer->title) }}" required></label>
<label>Kategorie<select name="category_id" required>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(old('category_id',$offer->category_id)==$category->id)>{{ $category->name }}</option>@endforeach</select></label>
<label class="full">Kurzbeschreibung<input name="short_description" value="{{ old('short_description',$offer->short_description) }}"></label>
<label class="full">Beschreibung<textarea name="description" rows="6">{{ old('description',$offer->description) }}</textarea></label>
<label>Grundvergütung (€)<input type="number" step="0.01" min="0" name="base_compensation" value="{{ old('base_compensation',$offer->base_compensation??40) }}" required></label>
<label>Dauer (Kalendertage)<input type="number" min="1" max="365" name="duration_days" value="{{ old('duration_days',$offer->duration_days??5) }}" required></label>
<label>Mindestdauer/Tag (Min., optional)<input type="number" min="0" max="1440" name="minimum_minutes_per_day" value="{{ old('minimum_minutes_per_day',$offer->minimum_minutes_per_day??0) }}" required></label>
<label>Gleichzeitige Angebotsplätze<input type="number" min="1" name="capacity" value="{{ old('capacity',$offer->capacity) }}" placeholder="leer = unbegrenzt"></label>
<label>Tracking<select name="tracking_mode" required><option value="required" @selected(old('tracking_mode',$offer->tracking_mode)==='required')>Pflicht</option><option value="optional" @selected(old('tracking_mode',$offer->tracking_mode??'optional')==='optional')>Optional</option><option value="none" @selected(old('tracking_mode',$offer->tracking_mode)==='none')>Nicht vorgesehen</option></select></label>
<label class="full">Angebotsbild<input type="file" name="image" accept="image/jpeg,image/png,image/webp"></label>
@if($offer->image_path)<div class="full"><img src="{{ asset('storage/'.$offer->image_path) }}" alt="{{ $offer->title }}" style="max-width:320px;border-radius:16px"></div>@endif
<label class="check"><input type="checkbox" name="requires_precheck" value="1" @checked(old('requires_precheck',$offer->requires_precheck))><span>Vorherige Kontrolle/Vorprüfung erforderlich</span></label>
<label class="check"><input type="checkbox" name="is_sock_wearing" value="1" @checked(old('is_sock_wearing',$offer->is_sock_wearing))><span>Sockenauftrag mit Tragezeit</span></label>
<label class="check"><input type="checkbox" name="start_face_required" value="1" @checked(old('start_face_required',data_get($inspectionConfig,'start_face_required',false)))><span>Gesicht auf Startfoto erforderlich</span></label>
<label class="check"><input type="checkbox" name="active" value="1" @checked(old('active',$offer->active))><span>Angebot aktiv</span></label>
<label class="full">Regeln – eine pro Zeile<textarea name="rules_text" rows="6">{{ old('rules_text',is_array($offer->rules)?implode("\n",$offer->rules):'') }}</textarea></label>
</div>
<div class="notice">Versandfrist ist systemweit auf 24 Stunden festgelegt. Angebote werden ausschließlich manuell aktiviert oder deaktiviert.</div>
</section>

<section class="panel" data-proof-window-editor>
<div class="section-head"><div><span class="eyebrow">Nachweise</span><h2>Nachweisfenster</h2></div><button type="button" class="btn secondary" data-add-proof-window>+ Zeitfenster</button></div>
<p class="muted">Jedes Fenster besitzt einen eigenen 10-Minuten-Code und eigenen Prüfstatus. Wird ein Pflichtfenster verpasst, ist der gesamte Tag ungültig.</p>
<div data-proof-windows>
@foreach(old('proof_windows',$proofRows) as $i=>$window)
<div class="option-editor-row">
<label>Interner Schlüssel<input name="proof_windows[{{ $i }}][key]" value="{{ $window['key']??'' }}" placeholder="z. B. morgens"></label>
<label>Bezeichnung<input name="proof_windows[{{ $i }}][label]" value="{{ $window['label']??'' }}" required></label>
<label>Von<input type="time" name="proof_windows[{{ $i }}][start]" value="{{ $window['start']??'00:00' }}" required></label>
<label>Bis<input type="time" name="proof_windows[{{ $i }}][end]" value="{{ $window['end']??'23:59' }}" required></label>
<label>Bilder<input type="number" min="1" max="20" name="proof_windows[{{ $i }}][required_images]" value="{{ $window['required_images']??1 }}" required></label>
<label class="check"><input type="checkbox" name="proof_windows[{{ $i }}][text_required]" value="1" @checked($window['text_required']??false)><span>Text Pflicht</span></label>
<label class="check"><input type="checkbox" name="proof_windows[{{ $i }}][face_required]" value="1" @checked($window['face_required']??false)><span>Gesicht Pflicht</span></label>
<button type="button" class="icon-btn" data-remove-proof-window>×</button>
</div>
@endforeach
</div>
</section>

<section class="panel">
<h2>Warenprüfung & Vergütung</h2>
<p class="muted">Jede Kategorie erhält bestanden/nicht bestanden, 0–10 Punkte und optionalen Kommentar.</p>
<div class="form-grid">
@foreach(['appearance'=>'Aussehen','smell'=>'Geruch','taste'=>'Geschmack','proofs'=>'Nachweise','extras'=>'Extras'] as $key=>$label)
<label class="check"><input type="checkbox" name="inspection_ko_{{ $key }}" value="1" @checked(old('inspection_ko_'.$key,data_get($inspectionConfig,"categories.$key.ko",false)))><span>{{ $label }} als KO-Kriterium</span></label>
@endforeach
<label class="check full"><input type="checkbox" name="points_affect_compensation" value="1" @checked(old('points_affect_compensation',data_get($inspectionConfig,'points_affect_compensation',false)))><span>Gesamtpunkte beeinflussen Grundvergütung</span></label>
<label class="full">Punktebänder – je Zeile z. B. 45-50=100<textarea name="score_bands" rows="5">{{ old('score_bands',$scoreBandText) }}</textarea></label>
</div>
</section>

<section class="panel">
<div class="section-head"><div><span class="eyebrow">Extras</span><h2>Zusatzoptionen</h2></div><button type="button" class="btn secondary" data-add-option>+ Option</button></div>
<p class="muted">Extras werden bei der Endprüfung binär bewertet: erfüllt = 100 %, nicht erfüllt = 0 %.</p>
<div data-options>
@foreach(old('options',$optionRows) as $i=>$option)
<div class="option-editor-row" style="grid-template-columns:1.1fr .7fr .7fr .7fr .7fr 1.1fr 1.1fr auto">
<input type="hidden" name="options[{{ $i }}][id]" value="{{ $option['id']??'' }}">
<label>Name<input name="options[{{ $i }}][name]" value="{{ $option['name']??'' }}"></label>
<label>Aufpreis (€)<input type="number" step="0.01" name="options[{{ $i }}][price_delta]" value="{{ $option['price_delta']??0 }}"></label>
<label>Extra-Nachweise/Tag<input type="number" min="0" name="options[{{ $i }}][extra_proofs_per_day]" value="{{ $option['extra_proofs_per_day']??0 }}"></label>
<label>Extra-Tage<input type="number" min="0" name="options[{{ $i }}][extra_duration_days]" value="{{ $option['extra_duration_days']??0 }}"></label>
<label>Mindestlaufzeit<input type="number" min="0" name="options[{{ $i }}][min_duration_days]" value="{{ $option['min_duration_days']??0 }}"></label>
<label>Benötigt Optionen<input name="options[{{ $i }}][requires_names]" value="{{ $option['requires_names']??'' }}"></label>
<label>Schließt aus<input name="options[{{ $i }}][excludes_names]" value="{{ $option['excludes_names']??'' }}"></label>
<label class="check"><input type="checkbox" name="options[{{ $i }}][required]" value="1" @checked($option['required']??false)><span>Pflicht</span></label>
<label class="wide" style="grid-column:1/-2">Beschreibung<input name="options[{{ $i }}][description]" value="{{ $option['description']??'' }}"></label>
<button type="button" class="icon-btn" data-remove-option>×</button>
</div>
@endforeach
</div>
</section>

<section class="panel" data-field-editor>
<div class="section-head"><div><span class="eyebrow">Konfiguration</span><h2>Angebotsfelder</h2></div><button type="button" class="btn secondary" data-add-field>+ Feld</button></div>
<p class="muted">Damit können z. B. Sockenauswahl, Größe, Material oder andere auftragsspezifische Angaben erfasst werden – ohne separates Wareninventar.</p>
<div data-fields>
@foreach(old('fields',$fieldRows) as $i=>$field)
<div class="option-editor-row">
<input type="hidden" name="fields[{{ $i }}][id]" value="{{ $field['id']??'' }}">
<label>Bezeichnung<input name="fields[{{ $i }}][label]" value="{{ $field['label']??'' }}"></label>
<label>Typ<select name="fields[{{ $i }}][type]">@foreach(['text'=>'Text','textarea'=>'Textbereich','number'=>'Zahl','select'=>'Auswahl','radio'=>'Radio','checkbox'=>'Ja/Nein'] as $type=>$label)<option value="{{ $type }}" @selected(($field['type']??'text')===$type)>{{ $label }}</option>@endforeach</select></label>
<label class="wide">Hilfetext<input name="fields[{{ $i }}][help_text]" value="{{ $field['help_text']??'' }}"></label>
<label class="wide">Auswahlwerte – eine pro Zeile<textarea name="fields[{{ $i }}][options_text]" rows="3">{{ $field['options_text']??'' }}</textarea></label>
<label class="check"><input type="checkbox" name="fields[{{ $i }}][required]" value="1" @checked($field['required']??false)><span>Pflichtfeld</span></label>
<button type="button" class="icon-btn" data-remove-field>×</button>
</div>
@endforeach
</div>
</section>

<div class="editor-actions"><button class="btn primary">Angebot speichern</button></div>
</form>
@endsection
