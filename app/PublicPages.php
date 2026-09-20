<?php
declare(strict_types=1);

function public_how_html(): string {
    ob_start(); ?>
    <section class="legal">
      <div class="eyebrow">Für Verkäuferinnen</div>
      <h1>So funktioniert die Plattform</h1>
      <p>Die Plattform ist kein klassischer Marktplatz. Der Betreiber veröffentlicht konkrete Ankaufangebote. Du entscheidest selbst, welches Angebot du annehmen möchtest. Andere Käufer gibt es nicht und es gibt keine öffentlichen Verkäuferinnenprofile.</p>

      <h2>1. Konto erstellen</h2>
      <p>Registriere dich mit deinen persönlichen Kontaktdaten. Die Plattform ist ausschließlich für volljährige Personen ab 18 Jahren bestimmt. Nach der Registrierung bestätigst du deine E-Mail-Adresse. Erst danach können Angebote verbindlich angenommen werden.</p>

      <h2>2. Angebote prüfen</h2>
      <p>Vor der Annahme siehst du die wesentlichen Bedingungen des Auftrags, insbesondere Kategorie, Vergütung, Dauer oder Erfüllungsumfang, Nachweise, Zusatzoptionen, Aufgaben, Versandbedingungen und mögliche Folgen nicht erfüllter Pflichten.</p>

      <h2>3. Auftrag annehmen</h2>
      <p>Nach der Annahme wird ein Auftrag mit eindeutiger Auftragsnummer angelegt. Die vereinbarte Vergütung erscheint zunächst als vorgemerkter Betrag im Wallet. Vorgemerkt bedeutet noch nicht auszahlbar.</p>

      <h2>4. Vorbereitung und Vorabkontrolle</h2>
      <p>Bei physischen Aufträgen wird zunächst der konkrete Artikel dokumentiert. Je nach Kategorie sind bestimmte Startnachweise erforderlich. Der Auftrag beginnt erst nach vollständiger Freigabe der Vorabkontrolle.</p>

      <h2>5. Durchführung</h2>
      <p>Während des Auftrags zeigt dir die Heute-Ansicht fällige Nachweise, Zeitfenster, spontane Anforderungen, Zusatzaufgaben, Revisionen und andere Fristen. Pflichtnachweise werden über die vorgesehenen Plattformfunktionen eingereicht.</p>

      <h2>6. Spontane Nachweise und Zusatzaufgaben</h2>
      <p>Der Betreiber kann im Rahmen der vereinbarten Auftragsbedingungen zusätzliche Nachweise oder Aufgaben anfordern. Jede Anforderung enthält eine konkrete Beschreibung und, sofern erforderlich, eine Frist.</p>

      <h2>7. Verstöße und Nachfristen</h2>
      <p>Nicht oder verspätet erfüllte Pflichten können zunächst als möglicher Verstoß erfasst werden. Eine automatische Erkennung ist noch keine endgültige Entscheidung. Der Betreiber prüft den Vorgang und bestätigt oder verwirft ihn. Bei bestätigten Verstößen können nach den Auftragsbedingungen zusätzliche Durchführungstage entstehen.</p>

      <h2>8. Beschädigungen</h2>
      <p>Wird ein festgelegter Artikel beschädigt oder unbrauchbar, kannst du dies im Auftrag melden. Bei einer anerkannten Beschädigung kann ein neuer Durchlauf mit neuem Artikel und neuer Vorabkontrolle beginnen. Frühere Durchläufe bleiben historisch dokumentiert.</p>

      <h2>9. Versand oder digitale Abgabe</h2>
      <p>Bei physischen Aufträgen folgt der vorgesehene Versandworkflow. Die konkrete Empfängeradresse wird erst angezeigt, wenn sie benötigt wird. Digitale Aufträge werden über die geschützten Abgabe- und Revisionsfunktionen abgewickelt.</p>

      <h2>10. Prüfung und Wallet</h2>
      <p>Nach vollständiger Erfüllung prüft der Betreiber den Auftrag. Der Auftrag kann vollständig akzeptiert, teilweise akzeptiert oder endgültig abgelehnt werden. Nur freigegebene Beträge werden im Wallet verfügbar.</p>

      <h2>11. Auszahlung</h2>
      <p>Verfügbares Guthaben kann über die aktuell aktivierten Auszahlungsmethoden beantragt werden. Mindestauszahlungsbetrag, mögliche Gebühren und Bearbeitungshinweise werden vor Antragstellung angezeigt.</p>

      <h2>12. Archiv</h2>
      <p>Vollständig ausgezahlte Aufträge werden automatisch archiviert. Archivierte Aufträge sind schreibgeschützt. Endgültig abgelehnte Aufträge werden ebenfalls archiviert und können nicht wieder geöffnet werden.</p>
    </section>
    <?php return ob_get_clean();
}

function public_faq_html(): string {
    ob_start(); ?>
    <section class="legal">
      <div class="eyebrow">Häufige Fragen</div><h1>FAQ</h1>

      <h2>Was ist diese Plattform?</h2>
      <p>Eine Ankaufsplattform. Der Betreiber veröffentlicht konkrete Angebote, die registrierte volljährige Verkäuferinnen annehmen können.</p>

      <h2>Muss ich selbst Anzeigen erstellen?</h2>
      <p>Nein. Du erstellst keine öffentlichen Verkaufsanzeigen und kein öffentliches Verkäuferinnenprofil.</p>

      <h2>Gibt es andere Käufer?</h2>
      <p>Nein. Der Betreiber ist der einzige Käufer und Auftraggeber innerhalb der Plattform.</p>

      <h2>Wer darf sich registrieren?</h2>
      <p>Ausschließlich volljährige Personen ab 18 Jahren.</p>

      <h2>Kann ich sofort Angebote annehmen?</h2>
      <p>Erst nachdem deine E-Mail-Adresse bestätigt wurde.</p>

      <h2>Darf ich Artikel anderer Personen verwenden?</h2>
      <p>Nein. Aufträge werden ausschließlich persönlich mit eigenen Artikeln beziehungsweise selbst erstellten Inhalten erfüllt.</p>

      <h2>Was passiert vor dem Start?</h2>
      <p>Bei physischen Aufträgen wird der konkrete Artikel dokumentiert. Die erforderliche Vorabkontrolle muss vollständig freigegeben sein, bevor der Auftrag beginnt.</p>

      <h2>Was passiert, wenn ich einen Nachweis verpasse?</h2>
      <p>Je nach Nachweis kann eine kurze Nachfrist bestehen. Danach kann ein möglicher Verstoß entstehen, der vom Betreiber geprüft wird.</p>

      <h2>Was bedeutet ein zusätzlicher Tag?</h2>
      <p>Bestätigte Verstöße oder manuell dokumentierte Änderungen können nach den Auftragsbedingungen zusätzliche Durchführungstage erzeugen. Im Auftrag wird angezeigt, aus welchem Grund ein Tag entstanden ist und ob er vergütet wird.</p>

      <h2>Was passiert bei einer Beschädigung?</h2>
      <p>Du meldest den Vorgang innerhalb des Auftrags. Der Betreiber prüft ihn. Bei Anerkennung kann ein neuer Durchlauf mit neuem Artikel beginnen.</p>

      <h2>Wann bekomme ich die Versandadresse?</h2>
      <p>Erst wenn sie zur Erfüllung des Versandworkflows tatsächlich benötigt wird.</p>

      <h2>Wann wird meine Vergütung verfügbar?</h2>
      <p>Erst nach vollständiger Abwicklung und der vorgesehenen Abschlussprüfung.</p>

      <h2>Welche Auszahlungsmethoden gibt es?</h2>
      <p>Die aktuell aktivierten Methoden werden im Wallet angezeigt. Möglich sind insbesondere Banküberweisung und PayPal.</p>

      <h2>Was sind digitale Aufträge?</h2>
      <p>Digitale Aufträge können Text-, Audio- oder Videobestandteile enthalten. Die konkrete technische und inhaltliche Anforderung wird im jeweiligen Angebot festgelegt.</p>

      <h2>Kann eine digitale Abgabe überarbeitet werden?</h2>
      <p>Ja. Der Betreiber kann strukturierte Revisionen mit einzelnen Änderungspunkten und eigener Frist anfordern.</p>

      <h2>Kann ich mein Konto selbst löschen?</h2>
      <p>Eine Kontolöschung erfolgt über den Betreiber. Dabei werden personenbezogene Profildaten anonymisiert beziehungsweise entfernt, soweit keine Aufbewahrungs- oder Nachweisgründe entgegenstehen.</p>
    </section>
    <?php return ob_get_clean();
}

function public_rules_html(): string {
    ob_start(); ?>
    <section class="legal">
      <div class="eyebrow">Verbindlicher Plattform-Rahmen</div><h1>Plattform- und Auftragsregeln</h1>
      <div class="panel"><strong>Hinweis:</strong> Diese Fassung bildet die technische und geschäftliche Plattformlogik ab. Sie ist vor einem öffentlichen Produktivbetrieb als Vertrags- und Rechtstext professionell juristisch zu prüfen.</div>

      <h2>1. Volljährigkeit</h2>
      <p>Die Nutzung als Verkäuferin ist ausschließlich Personen gestattet, die mindestens 18 Jahre alt sind. Falsche Altersangaben sind unzulässig.</p>

      <h2>2. Persönliche Auftragserfüllung</h2>
      <p>Jeder Auftrag muss von der registrierten Verkäuferin persönlich erfüllt werden. Fremdware, fremde Nachweisbilder, fremde Audio- oder Videodateien sowie von Dritten erstellte digitale Leistungen sind nicht zulässig.</p>

      <h2>3. Keine Minderjährigen oder nicht einwilligenden Dritten</h2>
      <p>Inhalte mit Minderjährigen sind ausgeschlossen. Ebenso ausgeschlossen sind heimliche Aufnahmen oder Inhalte mit nicht einwilligenden Personen.</p>

      <h2>4. Wahrheitsgemäße Angaben</h2>
      <p>Registrierungs-, Auftrags-, Versand- und Zahlungsangaben müssen wahrheitsgemäß sein. Eine Manipulation von Nachweisen, Dateiinformationen oder Plattformfunktionen ist untersagt.</p>

      <h2>5. Angebotsbedingungen</h2>
      <p>Für jeden Auftrag gelten die bei Annahme angezeigten Bedingungen der gespeicherten Angebotsversion. Spätere Änderungen eines öffentlichen Angebots verändern einen bereits angenommenen Auftrag nicht automatisch.</p>

      <h2>6. Annahme eines Auftrags</h2>
      <p>Mit der Annahme bestätigt die Verkäuferin, dass sie den Auftrag persönlich erfüllen kann und die angezeigten Bedingungen gelesen hat. Nach Annahme ist kein normaler einseitiger Selbst-Storno innerhalb der Plattform vorgesehen. Zwingende gesetzliche Rechte bleiben unberührt.</p>

      <h2>7. Festgelegter Artikel</h2>
      <p>Bei physischen Aufträgen wird der konkrete Artikel vor Beginn dokumentiert. Ein freiwilliger Wechsel nach Auftragsstart ist grundsätzlich nicht vorgesehen. Ein Wechsel kann im Rahmen eines anerkannten Beschädigungsvorgangs erfolgen.</p>

      <h2>8. Vorabkontrolle</h2>
      <p>Erforderliche Startnachweise dienen der Identifikation des konkreten Artikels, der Dokumentation des Ausgangszustands und der Prüfung auftragsspezifischer Anforderungen. Körperbezogene Fotos stellen keine medizinische Untersuchung oder Diagnose dar.</p>

      <h2>9. Nachweise</h2>
      <p>Pflichtnachweise müssen über die vorgesehenen Plattformfunktionen eingereicht werden. Bereits eingereichte Dateien werden nicht überschrieben. Ergänzungen oder Wiederholungen werden als neue Nachweise gespeichert.</p>

      <h2>10. Zeitfenster und Nachfristen</h2>
      <p>Es gelten die im konkreten Auftrag angezeigten Zeitfenster. Für bestimmte Nachweise kann eine Nachfrist vorgesehen sein. Verspätete Einreichungen werden als solche dokumentiert.</p>

      <h2>11. Spontane Anforderungen</h2>
      <p>Der Betreiber kann im Rahmen der angenommenen Bedingungen zusätzliche Nachweise anfordern. Anzahl, Inhalt und Frist werden im Auftrag angezeigt.</p>

      <h2>12. Zusatzaufgaben</h2>
      <p>Zusatzaufgaben können Text-, Zahlen-, Auswahl-, Bewertungs- oder Fotofelder enthalten. Sofern eine zusätzliche Vergütung vorgesehen ist, wird diese im Auftrag ausgewiesen.</p>

      <h2>13. Verstöße</h2>
      <p>Eine automatisch oder manuell festgestellte Pflichtverletzung wird zunächst als möglicher Verstoß behandelt. Der Betreiber prüft den Vorgang. Erst nach Bestätigung wird die vorgesehene Konsequenz verbindlich.</p>

      <h2>14. Zusätzliche Durchführungstage</h2>
      <p>Nach der vorgesehenen Plattformlogik kann ein bestätigter Verstoß einen zusätzlichen Durchführungstag erzeugen. Ob und in welchem Umfang unvergütete zusätzliche Leistungen wirksam vereinbart werden können, ist vor Produktivbetrieb juristisch zu prüfen.</p>

      <h2>15. Beschädigungen</h2>
      <p>Beschädigungen werden über den dafür vorgesehenen Vorgang gemeldet. Der Betreiber entscheidet nach Prüfung, ob ein Neustart mit neuem Artikel erforderlich ist. Frühere Durchläufe bleiben dokumentiert.</p>

      <h2>16. Versand</h2>
      <p>Physische Aufträge müssen entsprechend dem für den Auftrag vorgesehenen Versandworkflow verpackt und versendet werden. Die Empfängeradresse darf ausschließlich für die konkrete Vertragserfüllung verwendet werden.</p>

      <h2>17. Versandnachweis</h2>
      <p>Der Versand ist durch die im Auftrag vorgesehene Form, beispielsweise Trackingnummer oder Einlieferungsbeleg, nachzuweisen.</p>

      <h2>18. Digitale Aufträge</h2>
      <p>Digitale Leistungen müssen von der Verkäuferin selbst erstellt worden sein. Dateiformat, Länge, Auflösung, Umfang und weitere Anforderungen ergeben sich aus dem konkreten Auftrag.</p>

      <h2>19. Digitale Revisionen</h2>
      <p>Digitale Abgaben können in mehreren Versionen gespeichert werden. Der Betreiber kann Revisionen mit konkreten Änderungspunkten verlangen. Bereits gespeicherte Versionen bleiben Bestandteil der Auftragshistorie.</p>

      <h2>20. Nutzungsrechte</h2>
      <p>Mit dem Upload werden zunächst nur die für Speicherung, technische Verarbeitung und Prüfung erforderlichen Rechte eingeräumt. Weitergehende Nutzungsrechte richten sich nach der beim jeweiligen Auftrag akzeptierten Rechtevereinbarung. Die endgültige urheberrechtliche Vertragsfassung ist juristisch zu prüfen.</p>

      <h2>21. Wallet</h2>
      <p>Vorgemerkte Beträge sind noch nicht auszahlbar. Erst nach Freigabe werden Beträge als verfügbar geführt. Das Wallet ist kein Bankkonto.</p>

      <h2>22. Auszahlungen</h2>
      <p>Auszahlungsanträge können nur aus verfügbarem Guthaben gestellt werden. Aktive Methoden, Mindestauszahlungsbetrag und Gebühren werden vor Antragstellung angezeigt. Zahlungsdaten werden bei Antragstellung als unveränderlicher Snapshot gespeichert.</p>

      <h2>23. Archivierung</h2>
      <p>Vollständig ausgezahlte Aufträge werden automatisch archiviert. Archivierte Aufträge sind schreibgeschützt. Endgültig abgelehnte Aufträge werden ebenfalls archiviert und bleiben endgültig geschlossen.</p>

      <h2>24. Datenschutz und private Dateien</h2>
      <p>Private Nachweise werden nicht als frei erreichbare öffentliche Dateien bereitgestellt. Zugriffe erfolgen nur nach Authentifizierungs- und Berechtigungsprüfung.</p>

      <h2>25. Technische Störungen</h2>
      <p>Bei einem bestätigten Plattformausfall können betroffene Fristen angepasst werden. Ein allein durch den bestätigten technischen Ausfall verursachtes Versäumnis soll nicht als Verstoß gewertet werden.</p>

      <h2>26. Verbotene Inhalte und Handlungen</h2>
      <p>Unzulässig sind insbesondere rechtswidrige Inhalte, Inhalte mit Minderjährigen, nicht einvernehmliche Aufnahmen, gestohlene oder rechtswidrige Waren, Identitätstäuschung, Schadsoftware und die Umgehung technischer Schutzmechanismen.</p>

      <h2>27. Rangfolge</h2>
      <p>Zwingendes Recht geht vor. Danach gelten individuell vereinbarte Bedingungen, konkrete Angebotsbedingungen, besondere Kategorienregeln und anschließend die allgemeinen Plattformregeln.</p>
    </section>
    <?php return ob_get_clean();
}

function public_contact_html(): string {
    $mail=(string)setting_value('support_email',app_config('mail.from',''));
    ob_start(); ?>
    <section class="legal"><div class="eyebrow">Kontakt</div><h1>Kontakt</h1>
      <p>Für allgemeine Fragen zur Plattform erreichst du den Betreiber per E-Mail.</p>
      <?php if($mail):?><div class="panel"><strong>E-Mail:</strong> <?=e($mail)?></div><?php endif;?>
      <h2>Fragen zu bestehenden Aufträgen</h2><p>Nutze dafür vorrangig den jeweiligen Auftragschat. So bleibt die Kommunikation dem richtigen Auftrag zugeordnet.</p>
      <h2>Sensible Dateien</h2><p>Bitte sende keine Nachweisbilder oder sensiblen Auftragsdateien per normaler E-Mail. Nutze ausschließlich die geschützten Nachweis- und Uploadfunktionen der Plattform.</p>
    </section>
    <?php return ob_get_clean();
}

function public_privacy_html(): string {
    ob_start(); ?>
    <section class="legal"><div class="eyebrow">Datenschutz</div><h1>Datenschutzhinweise</h1>
      <div class="panel"><strong>Entwurfsstatus:</strong> Diese Datenschutzhinweise müssen vor Produktivbetrieb mit den tatsächlich eingesetzten Hosting-, Mail-, Protokollierungs- und sonstigen Diensten abgeglichen und juristisch geprüft werden.</div>
      <h2>Verarbeitete Daten</h2><p>Je nach Nutzung werden Registrierungs- und Kontaktdaten, Altersangaben, Vertrags- und Auftragsdaten, Nachweise, Kommunikationsdaten, Versandinformationen, Wallet- und Auszahlungsdaten sowie technische Sicherheitsdaten verarbeitet.</p>
      <h2>Zwecke</h2><p>Die Datenverarbeitung dient insbesondere der Kontoführung, Vertrags- und Auftragsabwicklung, Nachweisprüfung, Kommunikation, Versandabwicklung, Zahlungsabwicklung, Missbrauchsprävention und technischen Sicherheit.</p>
      <h2>Private Nachweise</h2><p>Nachweisdateien werden außerhalb frei erreichbarer öffentlicher Verzeichnisse gespeichert und nur über geschützte Plattformfunktionen ausgeliefert.</p>
      <h2>Speicherung und Löschung</h2><p>Personenbezogene Daten werden nur so lange gespeichert, wie dies für den jeweiligen Zweck, die Vertragsabwicklung, berechtigte Nachweisinteressen oder gesetzliche Aufbewahrungspflichten erforderlich ist. Bei einer Kontolöschung werden Profildaten anonymisiert oder entfernt, soweit keine zulässige weitere Speicherung erforderlich ist.</p>
      <h2>Betroffenenrechte</h2><p>Gesetzlich bestehende Rechte auf Auskunft, Berichtigung, Löschung, Einschränkung, Datenübertragbarkeit und Widerspruch bleiben unberührt.</p>
    </section>
    <?php return ob_get_clean();
}

function public_imprint_html(): string {
    $name=(string)setting_value('operator_name','');
    $street=(string)setting_value('operator_street','');
    $city=(string)setting_value('operator_city','');
    $email=(string)setting_value('operator_email',setting_value('support_email',app_config('mail.from','')));
    $phone=(string)setting_value('operator_phone','');
    ob_start(); ?>
    <section class="legal"><div class="eyebrow">Anbieterkennzeichnung</div><h1>Impressum</h1>
      <?php if($name && $street && $city):?>
        <div class="panel"><strong><?=e($name)?></strong><br><?=e($street)?><br><?=e($city)?><br><?php if($email):?>E-Mail: <?=e($email)?><br><?php endif;?><?php if($phone):?>Telefon: <?=e($phone)?><?php endif;?></div>
      <?php else:?><div class="panel"><strong>Produktivbetrieb noch nicht freigegeben.</strong><p>Die vollständigen Betreiberangaben müssen vor öffentlicher Inbetriebnahme in den Systemeinstellungen hinterlegt werden.</p></div><?php endif;?>
      <h2>Kontakt</h2><p><?php if($email):?>E-Mail: <?=e($email)?><?php else:?>Die Kontaktangaben werden vor Produktivbetrieb konfiguriert.<?php endif;?></p>
    </section>
    <?php return ob_get_clean();
}


function public_terms_html(): string {
    $name=(string)setting_value('operator_name','Betreiber');
    $email=(string)setting_value('operator_email',setting_value('support_email',app_config('mail.from','')));
    ob_start(); ?>
    <section class="legal">
      <div class="eyebrow">Vertragsrahmen</div><h1>Allgemeine Geschäftsbedingungen – Entwurf</h1>
      <div class="panel"><strong>Prüfhinweis:</strong> Dieser Text bildet die vereinbarte Plattformlogik ab und muss vor einem öffentlichen Produktivbetrieb juristisch geprüft und an die tatsächliche Betreiber- und Steuersituation angepasst werden.</div>

      <h2>1. Anbieter und Geltungsbereich</h2>
      <p>Diese Plattform wird von <?=e($name)?> betrieben. Sie dient ausschließlich der Abwicklung von Ankaufangeboten des Betreibers gegenüber registrierten volljährigen Verkäuferinnen. Es gibt innerhalb der Plattform keine fremden Käufer und keine öffentlichen Verkäuferinnenprofile.</p>

      <h2>2. Registrierung</h2>
      <p>Die Registrierung ist nur für volljährige Personen ab 18 Jahren vorgesehen. Registrierungs- und Kontaktdaten sind vollständig und wahrheitsgemäß anzugeben. Die E-Mail-Adresse muss bestätigt werden, bevor ein Ankaufangebot verbindlich angenommen werden kann.</p>

      <h2>3. Angebote</h2>
      <p>Der Betreiber legt Inhalt, Vergütung, Dauer oder sonstigen Erfüllungsumfang, Nachweise, Zusatzoptionen, Aufgaben, Versandbedingungen und weitere Anforderungen des jeweiligen Angebots fest. Maßgeblich für einen angenommenen Auftrag ist die bei Annahme gespeicherte Angebotsversion.</p>

      <h2>4. Vertragsschluss innerhalb der Plattform</h2>
      <p>Mit der ausdrücklichen Annahme eines Angebots und den dazugehörigen Pflichtbestätigungen wird der Auftrag angelegt. Die Plattform speichert Zeitpunkt, Angebotsversion, vereinbarte Vergütung und die wesentlichen Auftragsbedingungen als historische Auftragsbestätigung.</p>

      <h2>5. Persönliche Leistung</h2>
      <p>Aufträge sind persönlich zu erfüllen. Eigene physische Artikel und selbst erstellte digitale Inhalte dürfen nicht durch Fremdware oder fremde Leistungen ersetzt werden, sofern der konkrete Auftrag nicht ausdrücklich etwas anderes vorsieht.</p>

      <h2>6. Nachweise und Dokumentation</h2>
      <p>Erforderliche Nachweise sind über die vorgesehenen Plattformfunktionen einzureichen. Eingereichte Dateien bleiben Teil der Auftragshistorie. Technische Metadaten, Hashwerte, Dateieigenschaften und Qualitätsindikatoren können zur Dokumentation und Integritätsprüfung gespeichert werden.</p>

      <h2>7. Fristen, Nachfristen und Verstöße</h2>
      <p>Für Nachweise und Aufgaben gelten die im Auftrag angezeigten Fristen. Mögliche Pflichtverletzungen können zunächst automatisiert oder manuell erfasst und anschließend vom Betreiber geprüft werden. Erst eine bestätigte Entscheidung löst die im Auftrag vorgesehene Konsequenz aus.</p>

      <h2>8. Nachträgliche Änderungen</h2>
      <p>Nachträgliche Änderungen eines bereits angenommenen Auftrags werden separat protokolliert. Die ursprüngliche Auftragsbestätigung bleibt unverändert erhalten. Änderungen werden mit Altwert, Neuwert, Grund und Zeitpunkt dokumentiert und der Verkäuferin mitgeteilt.</p>

      <h2>9. Vergütung und Wallet</h2>
      <p>Die vereinbarte Vergütung wird zunächst als vorgemerkt geführt. Vorgemerkte Beträge sind noch nicht auszahlbar. Nach der Abschlussprüfung werden freigegebene Beträge verfügbar. Teilfreigaben, Boni, Versandzuschüsse oder andere ausdrücklich vereinbarte Vergütungsbestandteile werden im jeweiligen Auftrag dokumentiert.</p>

      <h2>10. Versand</h2>
      <p>Bei physischen Aufträgen gelten die beim Auftrag gespeicherten Versandbedingungen. Die konkrete Empfängeradresse wird erst in der dafür vorgesehenen Versandphase angezeigt. Je nach Angebot können Versandkosten von der Verkäuferin getragen, pauschal bezuschusst oder gegen Nachweis erstattet werden.</p>

      <h2>11. Digitale Leistungen</h2>
      <p>Digitale Abgaben können als Text, Audio, Video oder in einer vereinbarten Kombination erfolgen. Eingereichte Versionen bleiben historisch erhalten. Revisionen können mit einzelnen Änderungspunkten und eigenen Fristen dokumentiert werden.</p>

      <h2>12. Abschlussprüfung</h2>
      <p>Nach Erfüllung des Auftrags erfolgt die vorgesehene Prüfung. Der Auftrag kann vollständig akzeptiert, teilweise akzeptiert oder abgelehnt werden. Die jeweilige Entscheidung und der freigegebene Betrag werden im Auftrag dokumentiert.</p>

      <h2>13. Auszahlungen</h2>
      <p>Auszahlungen können nur aus verfügbarem Guthaben und über die jeweils aktivierten Auszahlungsmethoden beantragt werden. Zahlungsdaten und Gebühren werden vor Antragstellung angezeigt und bei Einreichung des Auszahlungsantrags als Snapshot gespeichert.</p>

      <h2>14. Kontolöschung und Aufbewahrung</h2>
      <p>Eine Kontolöschung wird administrativ durchgeführt. Personenbezogene Profildaten werden dabei soweit vorgesehen entfernt oder anonymisiert. Historische Auftrags-, Nachweis-, Zahlungs- und Dokumentationsdaten können erhalten bleiben, soweit hierfür ein zulässiger Aufbewahrungs- oder Nachweisgrund besteht.</p>

      <h2>15. Technische Verfügbarkeit</h2>
      <p>Bei bestätigten Plattformstörungen können betroffene Fristen angepasst werden. Ein ausschließlich durch einen dokumentierten technischen Plattformausfall verursachtes Versäumnis soll innerhalb der Plattform nicht als Pflichtverletzung behandelt werden.</p>

      <h2>16. Unzulässige Inhalte und Nutzung</h2>
      <p>Unzulässig sind insbesondere rechtswidrige Inhalte, Inhalte mit Minderjährigen, nicht einvernehmliche Aufnahmen, Identitätstäuschung, Manipulation von Nachweisen, Schadsoftware sowie Versuche, Sicherheits- oder Zugriffsschutzmechanismen zu umgehen.</p>

      <h2>17. Zwingende Rechte</h2>
      <p>Zwingende gesetzliche Rechte und Ansprüche bleiben von diesen Plattformregeln unberührt. Für rechtlich zwingende Fragen ist die endgültige Vertragsfassung vor Produktivbetrieb fachkundig zu prüfen.</p>

      <h2>18. Kontakt</h2>
      <p>Allgemeine Vertragsfragen können<?php if($email):?> per E-Mail an <?=e($email)?><?php endif;?> oder bei bestehenden Aufträgen über den zugehörigen Auftragschat gestellt werden.</p>
    </section>
    <?php return ob_get_clean();
}

function public_withdrawal_html(): string {
    ob_start(); ?>
    <section class="legal">
      <div class="eyebrow">Vertragliche und gesetzliche Rechte</div><h1>Widerruf, Rücktritt und Storno</h1>
      <div class="panel"><strong>Wichtiger Hinweis:</strong> Diese Plattform ist eine Ankaufsplattform, bei der der Betreiber Waren oder Leistungen von Verkäuferinnen ankauft. Ob im Einzelfall ein gesetzliches Widerrufsrecht oder andere Lösungsrechte bestehen, hängt von der rechtlichen Einordnung des konkreten Vertrags und der beteiligten Personen ab. Vor Produktivbetrieb ist hierzu eine juristische Prüfung erforderlich.</div>

      <h2>Kein pauschales Shop-Widerrufsversprechen</h2>
      <p>Die Plattform behandelt Aufträge nicht wie einen üblichen Online-Shop, in dem eine Verbraucherin Waren vom Betreiber kauft. Deshalb wird hier kein pauschales, möglicherweise unzutreffendes Standard-Widerrufsrecht zugesagt oder ausgeschlossen.</p>

      <h2>Storno innerhalb der Plattform</h2>
      <p>Nach verbindlicher Annahme ist kein gewöhnlicher einseitiger Selbst-Storno über einen Button vorgesehen. Tritt ein tatsächliches Problem auf, wird der Vorgang über die vorgesehenen Auftrags-, Beschädigungs-, Änderungs- oder Kommunikationsfunktionen dokumentiert.</p>

      <h2>Zwingende gesetzliche Rechte</h2>
      <p>Bestehende zwingende gesetzliche Rechte werden durch die technische Plattformlogik nicht ausgeschlossen. Soweit im Einzelfall ein gesetzlicher Widerruf, Rücktritt, eine Anfechtung oder ein sonstiges Lösungsrecht besteht, ist dieses unabhängig davon zu beurteilen, ob die Plattform einen eigenen Storno-Button anbietet.</p>

      <h2>Wie ein rechtlicher Einwand gemeldet werden kann</h2>
      <p>Bei einem bestehenden Auftrag kann die Verkäuferin den Auftragschat verwenden und den konkreten Sachverhalt mitteilen. Allgemeine rechtliche oder vertragliche Fragen können über die Kontaktadresse des Betreibers gestellt werden.</p>

      <h2>Produktivfreigabe</h2>
      <p>Vor öffentlicher Inbetriebnahme muss geprüft werden, welche konkrete Belehrung und welche gesetzlichen Informationspflichten für das tatsächliche Geschäftsmodell und die konkrete Betreiberrolle erforderlich sind.</p>
    </section>
    <?php return ob_get_clean();
}
