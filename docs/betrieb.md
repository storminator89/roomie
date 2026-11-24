# Roomie betreiben

[Zur Übersicht](../readme.md)

## Voraussetzungen

- Webserver mit HTTPS und **PHP 8.6.x** im Web-Handler (z. B. PHP-FPM) und
  in der CLI. Nutze eine verfügbare Sicherheitsversion aus der 8.6-Reihe.
  Die CLI-Version umzustellen ändert den Web-Handler nicht.
- PHP-Erweiterungen: `pdo_sqlite`, `session`, `filter`; unter Linux zusätzlich
  `posix`. Für Bild-Uploads: `gd` mit PNG und JPEG, für Avatare je nach Format
  auch GIF. WebP bei Raumfotos hängt vom GD-Build ab.
- Anwendungsverzeichnis und DocumentRoot dürfen den privaten Datenbankpfad
  nicht enthalten. Das Linux-App-Konto muss Eigentümer des Verzeichnisses und
  der Datenbank sein, ohne Rechte für Gruppe oder andere (z. B. `0700` und
  `0600`). Kein Web-Alias, öffentliches Backup oder öffentliches
  Sitzungsverzeichnis für diese Daten.

Die ausgelieferte [`.htaccess`](../.htaccess) ist die Referenz für Apache.
Auf anderen Webservern müssen gleichwertige Sperren und Sicherheitsheader
gesetzt sein: kein Verzeichnislisting, kein direkter Zugriff auf interne
PHP-Helfer, Initialisierung/Schemaupdate, Punktdateien, Build-Verzeichnis,
alte Uploads oder SQLite-Dateien einschließlich Begleitdateien. Nur die
Anwendung bereitstellen, nicht `.git`, Build-Tools oder private Daten.
Insbesondere die eingeschränkten Header von MCP-Admin und QR-Druck und die
Weitergabe von `Authorization` an `mcp.php` beibehalten. Am HTTPS-Proxy den
echten Host und die TLS-Information vertrauenswürdig an PHP weitergeben,
damit der Sitzungscookie als Secure gesetzt wird und die Check-in-
Herkunftsprüfung funktioniert. Roomie vertraut dafür nicht einfach
beliebigen Forwarded-Headern.

## Neue Installation

Lege das private Datenbankverzeichnis an. Konfiguriere die Werte für
Web-Handler und CLI wie folgt:

| Variable | Zweck |
| --- | --- |
| `ROOMIE_DB_PATH` | Absoluter Dateipfad außerhalb aller Webroots, z. B. `/var/lib/roomie/roomie.sqlite`. Der Ordner muss bereits existieren. |
| `ROOMIE_DOCUMENT_ROOT` | Tatsächlicher absoluter DocumentRoot für die CLI-Sicherheitsprüfung. |
| `ROOMIE_ADMIN_NAME` | Anzeigename des ersten Admin-Kontos, nur für die Initialisierung. |
| `ROOMIE_ADMIN_EMAIL` | E-Mail-Adresse des ersten Admin-Kontos, nur für die Initialisierung. |
| `ROOMIE_ADMIN_PASSWORD_HASH` | PHP-`password_hash` eines starken, einzigartigen Passworts, nur für die Initialisierung. Weder Passwort noch Hash ins Repository oder Web-Verzeichnis schreiben. |

Führe als App-Konto **nur für eine neue, noch nicht vorhandene Datei** aus:

```sh
php init_db.php
```

Die CLI legt Schema und genau ein Admin-Konto an. Sie überschreibt keine
bestehende Datei und installiert keine Beispiele. Entferne die temporären
Admin-Variablen nach der Einrichtung. Es gibt keinen Setup-Aufruf im Browser.

## Bestehende Installation

1. App offline nehmen, eine konsistente private Datensicherung erstellen und
   verifizieren. Einen bisherigen Datenbestand nur aus vertrauenswürdigem
   Bestand übernehmen. Keine SQLite-Dateien aus Repo, Git-Historie oder
   Demo-Verteilern übernehmen. Bei alten Installationen Demo-Zugangsdaten
   prüfen, betroffene Zugangsdaten ersetzen und Sitzungen widerrufen; ein
   Dateiumzug allein ändert keine Zugänge.
2. `ROOMIE_DB_PATH` und `ROOMIE_DOCUMENT_ROOT` auf private Datei und
   tatsächlichen DocumentRoot konfigurieren. Dann als App-Konto
   `php schema_hardening.php` ausführen, bevor die neue App startet.
   Die CLI weist inkonsistente Referenzen zurück und löscht keine vorhandenen
   fachlichen Datensätze. Alte Sitzungen müssen sich neu anmelden. Das Update
   enthält u. a. die Tabellen für Grundrisse, Sperrzeiten und Freigaben.
3. Web-Handler und CLI auf PHP 8.6 prüfen. Ein reiner PHP-Wechsel verändert
   das SQLite-Schema nicht. In Staging Anmeldung, Übersicht, Grundriss,
   Buchungen, ICS und optionale Uploads prüfen und die Webserver-Fehlerlogs
   kontrollieren.
4. Nicht auf App-Code ohne Freigabe- oder Sperrzeit-Prüfung zurückrollen,
   solange diese Regeln aktiv sind. Für einen reinen PHP-Wechsel lässt sich
   bei Bedarf der alte PHP-Handler zurückschalten, ohne SQLite zu ändern.

## Konten, Rechte und E-Mail

- Die Administration legt Konten an und korrigiert E-Mail-Adressen.
  Änderungen an Passwort oder Rolle widerrufen alte Sitzungen. Mindestens
  ein Admin-Konto behalten. Die Anwendung schützt Konten und Räume mit
  Buchungshistorie vor versehentlicher Löschung.
- Mitglieder sehen bei fremden Buchungen die Verfügbarkeit, nicht die
  Identität der buchenden Person. Für beschränkte Räume brauchen Mitglieder
  eine explizite Freigabe; Admins können solche Räume verwalten. Verwaltung
  ersetzt nicht die für eine eigene Buchung erforderliche Raumfreigabe.
- `ROOMIE_MAIL_FROM` kann für Transaktionsmails auf eine autorisierte
  Absenderadresse gesetzt werden. Dann nur geprüfte Empfängeradressen
  verwenden. Ohne Mail-Konfiguration funktionieren Buchung und ICS weiterhin.
  Für Freigabeanfragen und deren Bearbeitung werden keine E-Mails gesendet.
- Avatare werden neben der Datenbank privat gespeichert; sie sind nicht im
  SQLite-Backup enthalten. Alte öffentliche Uploads werden nicht
  wiederverwendet. Backup und Restore separat testen.

## Grundrisse und Raumfotos

Öffne **Raumplan → Räume & Grundrisse verwalten** (auch über **Räume → Räume
verwalten**). Dort kannst du Räume anlegen, bearbeiten und über **+ Grundriss**
ein PNG/JPEG hochladen: maximal 5 MiB, 2400 Pixel je Kante und 3 Megapixel.
Markiere die Raumzone mit der Maus oder tippe auf zwei Ecken am Touchscreen.
**Position & Größe** erlaubt präzise Eingabe per Tastatur. Zonen extra
speichern. Ein separater Umbenennen-Dialog ersetzt weder Bild noch Zonen.
Bis zu 12 Grundrisse und 200 markierte Räume pro Plan werden unterstützt.

Roomie schreibt das hochgeladene Bild als neues PNG in privates SQLite.
**Jede angemeldete Person kann das Grundrissbild sehen.** Rechte filtern
anklickbare Raumzonen, nicht bereits im Bild sichtbare Beschriftungen.
Deshalb nur freigegebene Pläne hochladen. Raumliste und altes Raster bleiben
als alternative Ansichten erhalten.

Im Raumeditor kannst du unter **Raumfoto** ein eigenes Bild setzen,
ersetzen oder löschen. JPEG und PNG werden unterstützt, WebP je nach GD;
HEIC/HEIF wird nicht unterstützt. Für automatische JPEG-Ausrichtung kann PHP
die Erweiterung `exif` nutzen.
Roomie normalisiert das Bild und entfernt Metadaten; Fotozugriff folgt den
Raumrechten. Ohne Foto erscheint ein passendes Standardbild. Die Bilder
für Grundrisse und Räume sind in der SQLite-Sicherung enthalten. Für Uploads
`upload_max_filesize` auf mindestens 5 MiB und `post_max_size` etwas
darüber einstellen; Paketgrenzen des Webservers müssen ebenfalls passen.

## Buchungsregeln

- Individuelle Termine gelten für einen einzelnen Tag zwischen 09:00 und 17:00
  Uhr in 15-Minuten-Schritten, Zeitzone Europe/Berlin. Der Beginn muss in der
  Zukunft liegen. Ein regulärer Standardzeitblock kann bis zu seinem Ende
  gebucht werden; bei Mehrtagesbuchungen führt schon ein abgelaufener erster
  Zeitblock zur Ablehnung der gesamten Buchung. Der Buchungshorizont beträgt
  90 Tage.
- Sämtliche Buchungspfade prüfen Rechte, Sperrzeiten und Kapazität beim
  Speichern. Alte Sitzplatzbuchungen zählen gegen die Raumkapazität. Eine
  Suche oder Vorschau ist noch keine Reservierung.
- Eine eigene Buchung kann über **Optionen → Verschieben** innerhalb
  desselben Raums verschoben werden, solange sie noch nicht begonnen hat und
  der Raum keine Freigabe verlangt. Bei einer Buchung über mehrere Tage gibt
  es je Tag einen separaten Eintrag. ICS ist ein Download, kein bidirektionaler
  Kalenderabgleich. Eine früher importierte Datei aktualisiert sich nicht.
- Wöchentliche Serien haben 2 bis 12 Termine. Sie behalten ihre Berliner
  Uhrzeit über Sommerzeitwechsel hinweg. Bei neu angelegten Serien lassen
  sich über **Serie bearbeiten** alle künftigen Termine in einer Vorschau
  abgleichen. Beim gemeinsamen Ändern bleibt das tatsächliche Datum jedes
  Termins erhalten, auch nach Einzelverschiebungen. Es wird entweder alles
  zulässige bestätigt oder nichts geändert. Abgelaufene Vorschauen oder
  zwischenzeitlich geänderte Termine müssen erneut geprüft werden. Alte
  Wochenbuchungen ohne Serienkennung bleiben einzeln verwaltbar.

### Sperrzeiten und Freigaben

**Sperrzeiten** im Raum öffnen und einen künftigen Zeitraum wählen. Für den
ganzen üblichen Buchungstag 09:00–17:00 Uhr eintragen. Eine Sperre verdrängt
keine vorhandene Buchung.

**Freigabe erforderlich** lässt sich pro Raum aktivieren. Eine offene Anfrage
belegt keinen Platz und erzeugt keine ICS-Datei. Erst eine Freigabe nach
erneuter Prüfung von Zugriff, Zeit, Sperre und Kapazität erzeugt die Buchung.
Anfragen können vorher zurückgezogen oder abgelehnt werden. Bei
freigabepflichtigen Räumen sind nur einzelne Anfragen möglich, keine
Serienbuchungen, Mehrtagesbuchungen oder Verschiebungen. Vorhandene
Buchungen bleiben bestehen.

### Optionaler Check-in

In den Raumdetails pro Raum aktivierbar; standardmäßig aus. Die buchende
Person mit weiterhin bestehendem Raumzugriff kann in **Buchungen** ab
10 Minuten vor Beginn bestätigen. Das Fenster endet 10 Minuten nach
Beginn oder am früheren Ende des Termins. Kein Check-in storniert oder gibt
einen Raum automatisch frei. Der optionale Tageswert im Raumdetail erfasst
nur bereits begonnene, berechtigte Buchungen seit Aktivierung und deren
Selbstbestätigung. Es ist keine Anwesenheitsmessung.

Die Datenbank speichert nur den Buchungs- und Raumbezug der Bestätigung,
keine zusätzliche Identität, Zeitmarke, IP-Adresse, Geräte- oder Standortdaten.
Verschieben, Stornieren oder Deaktivieren löscht die Bestätigung entsprechend.
Private Backup-Aufbewahrung bleibt davon unberührt. Das optionale Schema
entsteht bei der ersten ausdrücklichen Aktivierung.

### Raumauswertung

**Konto → Raumauswertung** zeigt die letzten sieben Tage oder einen Zeitraum
von bis zu 31 Kalendertagen einschließlich. Überlappende bestätigte
Reservierungen desselben Raums zählen zeitlich nur einmal. Ungültige
historische Zeitwerte, Sperrzeiten und offene Freigabeanfragen sind keine
gebuchten Minuten. Spitzenstunden beziehen sich auf die Berliner Ortszeit;
Gleichstände bleiben sichtbar. Die Summe über mehrere Räume kann sich
zeitlich überschneiden.

Der UTF-8-CSV-Export enthält Raumname, Zeitraum, gebuchte Minuten und
Spitzenstunden, keine personenbezogenen Buchungsdetails. Bestätigte
Check-ins bleiben getrennt. Weder Bericht noch Check-in messen Anwesenheit.

## Raum-QR

Setze `ROOMIE_PUBLIC_BASE_URL` auf die stabile HTTPS-Basisadresse, z. B.
`https://rooms.example.org/roomie`. Verwende einen ASCII-Hostnamen und
einen gewöhnlichen Pfad ohne Zugangsdaten, Query oder Fragment. Ohne
gültige Konfiguration erscheint kein QR-Code. Die App leitet diese
Basisadresse bewusst nicht aus Request-Headern ab.

Als Admin **Raumdetails → Raum-QR drucken** öffnen und den Ausdruck am Handy
testen. Der QR-Code enthält nur die kanonische Raum-URL mit numerischer
Raum-ID, keinen Schlüssel und keine Buchungsdaten. Nutzung erfordert
Anmeldung und normale Raumrechte. Der Code wird lokal mit
[Project Nayukis QR Code Generator v1.8.0](https://github.com/nayuki/QR-Code-generator/tree/v1.8.0)
erzeugt; keine externe QR-Dienst erhält die Raum-URL.

Auf der mobilen Seite erscheinen die Verfügbarkeit und, wenn möglich, der
nächste freie 30-Minuten-Termin für heute. Der Start liegt auf der nächsten
passenden 15-Minuten-Grenze. Erst eine ausdrückliche Bestätigung bucht oder
stellt eine Freigabeanfrage. Bei Wechsel von URL oder Raum müssen alte
Schilder ersetzt oder der bisherige Link erhalten werden. Ein altes Schild
nie für einen anderen Raum wiederverwenden.

## MCP-Zugang

Roomie bietet `mcp.php` über **Streamable HTTP** mit JSON an, nicht über
stdio, Browser-Sitzung oder OAuth. Der Zugang ist ohne Einrichtung aus.
Er kann keine Buchung anlegen, verändern oder stornieren.

1. Unter **Konto → Benutzerverwaltung** ein eigenes Nicht-Admin-Konto
   anlegen. Unter **Berechtigungen** nur ausdrücklich vorgesehene
   beschränkte Räume freigeben. Das Konto kann auch allgemeine Räume lesen
   und soll nicht für die tägliche Nutzung verwendet werden.
2. Unter **Konto → MCP-Zugang** das Konto auswählen und **API-Key erstellen**
   nutzen. Der Schlüssel erscheint einmalig; privat gespeichert wird sein
   Hash. **API-Key erneuern** und **Zugang sperren** machen den alten
   Schlüssel für neue Anfragen ungültig.
3. Im Client **Streamable HTTP**, `https://<roomie-host>/mcp.php` und
   `Authorization: Bearer <API-Key>` konfigurieren; Installationsunterpfad
   gegebenenfalls ergänzen. Client muss eigene Header unterstützen.
4. Nur über HTTPS freigeben, den Header bis PHP durchreichen und ein Rate
   Limit pro Client am Webserver konfigurieren (z. B. 30 pro Minute mit
   kleinem Burst). Keine Schlüssel loggen und keine CORS-Freigaben ergänzen.
   `ROOMIE_MCP_ORIGIN` für normale Server-Clients leer lassen: Requests mit
   Origin werden standardmäßig abgelehnt. Wenn gebraucht, genau die
   öffentliche Roomie-Origin setzen (Schema, Host, optional Port; kein Slash).

`list_room_bookings` benötigt `from` und `to` als YYYY-MM-DD (einschließlich,
maximal 31 Tage). Optional: `room_id` als Ganzzahl und `limit` zwischen
1 und 200 (Standard 200). Ergebnis sind bestätigte Termine mit Raum-ID,
Raumname, Datum und Zeit, ohne Identität der buchenden Person. Bei
`truncated: true` einen kürzeren Zeitraum oder Raumfilter wählen. Fehlende
Raumrechte und unbekannte Raum-IDs liefern dasselbe leere Ergebnis.

Alte Umgebungsvariablen `ROOMIE_MCP_API_KEY` und `ROOMIE_MCP_USER_ID` gelten
nur, bis ein Admin den Schlüssel im Web anlegt oder sperrt. Danach hat die
SQLite-Konfiguration Vorrang; ein Sperren aktiviert keinen alten
Umgebungsschlüssel. Für die Verwaltung per Web ist kein manueller
Schemaschritt erforderlich.

## Exchange-Pilot

Es gibt einen [offline getesteten Parser](exchange-pilot.md) für
synthetische Kalenderantworten. Keine aktive Exchange-Anbindung, kein
Tenant-Zugriff, keine Microsoft-Zugangsdaten und kein Freischaltschalter.
Die [Voraussetzungen und Prüfungen](exchange-pilot-test-plan.md) für einen
späteren Pilotbetrieb sind separat dokumentiert.

## Lokal prüfen

```sh
PHP_BIN=/pfad/zu/php8.6 python3 build-files/php-smoke.py --expect-minor 8.6
PHP_BIN=/pfad/zu/php8.6 python3 build-files/mcp-http-smoke.py
PHP_BIN=/pfad/zu/php8.6 python3 build-files/room-photo-smoke.py
node --test build-files/*.test.mjs
php build-files/room-search.php
php build-files/room-visual-test.php
php build-files/room-qr-availability-test.php
php build-files/room-checkin-test.php
php build-files/series-manage-test.php
php build-files/room-insights-test.php
php build-files/exchange-availability.php
```

Die HTTP-Tests nutzen Python 3.9+, temporäre Daten und localhost; sie
versenden keine E-Mail und ersetzen keine Prüfung des tatsächlichen
Apache-/PHP-FPM-Setups. Browser-Prüfung mit Beispieldaten: Anmeldung,
Raumliste und Grundriss, Buchen, Bild-Uploads bei aktivem GD und die
Admin-Oberflächen auf Desktop und Smartphone-Breite ausprobieren. Beim
QR-Druck zusätzlich einen echten Ausdruck mit einem Handy scannen.

## Optional: Entra-Abgleich

Gruppenbasierter Benutzerabgleich mit Vorschau und Zeitplan: [Einrichtung und Grenzen](entra.md).
