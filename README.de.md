<!-- The German README, the twin of README.md: the same headings, snippets and diagrams, in
German, which scripts/check-project.sh compares with those of README.md. The badges read
appinfo/info.xml on main, as in README.md. An anchor such as <a id="requirements"></a>
above a heading lets an English page of the German website link to it. -->
<div align="center">

<img src="img/app.svg" alt="" width="72" height="72">

# Paperless Sync

**Dein Paperless-ngx-Archiv als stets aktueller Ordnerbaum in Nextcloud Dateien – dazu ein Eingang von Nextcloud zu Paperless.**

[![Die Version von Paperless Sync im Nextcloud App Store](https://img.shields.io/badge/dynamic/xml?url=https%3A%2F%2Fraw.githubusercontent.com%2FDennis-Otto%2Fpaperless-sync%2Fmain%2Fappinfo%2Finfo.xml&query=%2Finfo%2Fversion&prefix=v&label=App%20Store&logo=nextcloud&logoColor=white&color=0082c9)](https://apps.nextcloud.com/apps/paperless_sync)
[![Die Versionen von Nextcloud, die Paperless Sync unterstützt](https://img.shields.io/badge/dynamic/xml?url=https%3A%2F%2Fraw.githubusercontent.com%2FDennis-Otto%2Fpaperless-sync%2Fmain%2Fappinfo%2Finfo.xml&query=concat%28%2F%2Fdependencies%2Fnextcloud%2F%40min-version%2C%27%E2%80%93%27%2C%2F%2Fdependencies%2Fnextcloud%2F%40max-version%29&label=Nextcloud&logo=nextcloud&logoColor=white&color=0082c9)](#voraussetzungen)
[![Lizenz: AGPL-3.0-or-later](https://img.shields.io/badge/license-AGPL--3.0--or--later-blue)](LICENSE)
[![Dokumentation](https://img.shields.io/badge/docs-website-526cfe?logo=materialformkdocs&logoColor=white)](https://dennis-otto.github.io/paperless-sync/)

[**Im App Store holen**](https://apps.nextcloud.com/apps/paperless_sync) · [Voraussetzungen](#voraussetzungen) · [Schnellstart](#schnellstart) · [Dokumentation](https://dennis-otto.github.io/paperless-sync/) · [FAQ](docs/faq.de.md) · [Fehlerbehebung](docs/troubleshooting.de.md) · [Hilfe](#hilfe-und-feedback) · [Änderungen](CHANGELOG.md) · [English](README.md)

![Das Archiv in Nextcloud Dateien: von den Ordnern der Korrespondenten über einen Korrespondenten, einen Dokumenttyp und ein Jahr zu einem Dokument von Paperless, das sich im Viewer von Nextcloud öffnet](docs/images/archive.gif)

</div>

Paperless Sync ist eine native Nextcloud-App. Sie schreibt die fertigen Dokumente von Paperless-ngx in einen Ordnerbaum in den Dateien eines Nextcloud-Benutzers, nach Korrespondent, Dokumenttyp und Jahr, und hält den Baum aktuell: Ein neuer Titel benennt eine Datei um, ein neuer Korrespondent verschiebt sie, und der Papierkorb von Paperless hat einen eigenen Ordner. Dateien, die du in einen Eingangsordner in Nextcloud legst, gehen den umgekehrten Weg, zu Paperless. Paperless bleibt die maßgebliche Quelle, und Nextcloud gibt dir die Dokumente in Dateien, in seinen Desktop- und Mobil-Clients, in seinem Viewer und in Freigaben.

Anders als ein Mount des Medienordners von Paperless oder ein Skript, das über WebDAV kopiert, läuft Paperless Sync in Nextcloud als einer seiner Hintergrundjobs: Es braucht keinen zusätzlichen Container und kein gespeichertes Nextcloud-Passwort, die Kopien sind echte Dateien von Nextcloud mit Versionen, Freigaben und Suche, eine Bearbeitung in Nextcloud erreicht nie die Originale in Paperless, und eine Kopie, deren Dokument Paperless verlässt, wird nur gelöscht, wenn du das endgültige Löschen einschaltest, und erst, nachdem vollständige Scans von Paperless bestätigen, dass das Dokument weg ist. [Der Vergleich](https://dennis-otto.github.io/paperless-sync/alternatives.html) stellt jeden Weg, Paperless und Nextcloud zu verbinden, nebeneinander, mit Anleitungen für den Umstieg von einem Mount oder vom WebDAV-Container.

Die [Dokumentations-Website](https://dennis-otto.github.io/paperless-sync/) zeigt diese Anleitung auf Deutsch und Englisch, zusammen mit den [FAQ](docs/faq.de.md) und der [Fehlerbehebung](docs/troubleshooting.de.md), und auf Englisch mit dem [Vergleich mit den Alternativen](https://dennis-otto.github.io/paperless-sync/alternatives.html), der [Architektur](docs/architecture.md), dem [Sicherheitsdesign](docs/security.md), der [Automatisierung](docs/automation.md), den [Entscheidungen](docs/decisions/README.md) und der [Roadmap](docs/roadmap.md).

<sub>💛 Wenn Paperless Sync dir hilft, [bewerte es im App Store](https://apps.nextcloud.com/apps/paperless_sync), damit andere es finden, oder [unterstütze seine Entwicklung](https://github.com/sponsors/Dennis-Otto).</sub>

<!-- --8<-- [start:how-it-works] -->

## So funktioniert es

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/diagrams/how-paperless-sync-moves-documents.de.dark.svg">
  <img src="docs/images/diagrams/how-paperless-sync-moves-documents.de.svg" alt="Wie Paperless Sync Dokumente bewegt: Paperless Sync liest die fertigen Dokumente und den Papierkorb von Paperless-ngx über seine REST-API. Es schreibt, benennt und verschiebt ihre Dateien im Ordner Archiv von Nextcloud, nach Korrespondent, Typ und Jahr, und spiegelt den Papierkorb nach Archiv/_Gelöscht. In der Gegenrichtung holt es die Dateien des Ordners Eingang ab und lädt sie als neue Dokumente zu Paperless-ngx hoch; ein fehlgeschlagener Import kommt in den Ordner Fehler. Paperless-ngx ist grün, die Ordner von Nextcloud sind blau.">
</picture>

Im eingestellten Intervall startet der Cron von Nextcloud einen Lauf der App in Nextcloud. Er liest die fertigen Dokumente von Paperless und schreibt jedes in das Archiv in den Dateien des eingestellten Nextcloud-Zielbenutzers, an den Pfad, den die Pfadvorlage aus seinen Metadaten bildet; andere Benutzer sehen das Archiv, wenn dieser Benutzer es mit ihnen teilt. Ändern sich die Metadaten in Paperless, wandert die Datei; kommt ein Dokument in den Papierkorb von Paperless, wandert seine Kopie nach `_Gelöscht`. Dateien, die jemand in `Eingang` legt, gehen den umgekehrten Weg, zu Paperless.

<!-- --8<-- [end:how-it-works] -->
<!-- --8<-- [start:requirements] -->

<a id="requirements"></a>

## Voraussetzungen

<!-- On the website, the hook in .github/mkdocs_project.py writes the versions of appinfo/info.xml into the fields. -->

- **Nextcloud <span data-app="nextcloud">33–35</span>**, mit dem System-Cron für seine Hintergrundjobs, wie Nextcloud es empfiehlt: *Administrationseinstellungen → Grundeinstellungen → Hintergrund-Jobs → Cron*. Jeder Lauf der App ist einer dieser Jobs.
- **PHP <span data-app="php">8.2+</span>** auf dem Nextcloud-Server.
- **Paperless-ngx 2.16 oder neuer, 3.x eingeschlossen**. Jede Anfrage der App verlangt Version 9 der Paperless-API, die diese Versionen bereitstellen, damit ein neuer Standard von Paperless die Antworten, die die App liest, nicht verändern kann; die Einstellungsseite nennt die Version von Paperless, die der letzte Lauf erreicht hat. Paperless braucht eine Adresse, die der Nextcloud-Server über HTTP oder HTTPS erreicht; für eine Adresse deines lokalen Netzwerks, etwa `http://paperless:8000` in Docker oder `http://192.168.1.20:8000`, schalte die Einstellung [Paperless läuft in meinem lokalen Netzwerk](#paperless-im-lokalen-netzwerk) ein.
- **Ein Paperless-Konto für die App**, das kein Superuser ist, mit einem API-Token. Es muss die zu spiegelnden Dokumente anzeigen und herunterladen, ihre Korrespondenten, Dokumenttypen, Speicherpfade und Tags anzeigen und für den Eingang Dokumente hinzufügen dürfen; gehören ihm die Dokumente, spiegelt es den Papierkorb von Paperless sofort. [Paperless-Verbindung](#paperless-verbindung) listet die genauen Berechtigungen auf, und **Verbindung testen** prüft sie.
- **Platz in den Dateien des Zielbenutzers**, des Nextcloud-Benutzers, dem das Archiv gehört: Das Archiv zählt zum Kontingent dieses Benutzers.

| Getestet mit | Versionen | Wie |
| --- | --- | --- |
| Nextcloud | <span data-app="nextcloud">33–35</span>, jeweils das aktuelle Release | die End-to-End-Tests in Docker, jede Nacht und bei jeder Änderung der App, auf SQLite, PostgreSQL, MariaDB und S3-Objektspeicher |
| Paperless-ngx | 2.20, das letzte Release von 2.x, und das aktuelle Release von 3.x | die [Vertragstests](https://github.com/Dennis-Otto/paperless-sync/blob/main/tests/e2e/README.md#contract-tests-against-a-real-paperless-ngx) gegen ein echtes Paperless in Docker, jede Woche und bei jeder Änderung daran, wie die App mit Paperless spricht |

<!-- --8<-- [end:requirements] -->
<!-- --8<-- [start:quick-start-section] -->

## Schnellstart

<!-- --8<-- [start:quick-start] -->

1. Installiere **Paperless Sync** aus dem [Nextcloud App Store](https://apps.nextcloud.com/apps/paperless_sync) unter *Apps* oder mit `occ app:install paperless_sync`. Der System-Cron von Nextcloud muss laufen.
2. Lege in Paperless-ngx unter *Benutzer & Gruppen* ein eigenes Konto für die App an, keinen Superuser, mit der Berechtigung **Anzeigen** für Dokumente, Korrespondenten, Dokumenttypen, Speicherpfade, Tags und UI-Einstellungen und, wenn du den Eingang nutzt, **Hinzufügen** für Dokumente und **Anzeigen** für Paperless-Aufgaben. Mach es zum Eigentümer der zu spiegelnden Dokumente, damit ihr Papierkorb das Archiv sofort erreicht, und erstelle sein API-Token unter *Mein Profil*.
3. Öffne in Nextcloud **Administrationseinstellungen → Paperless Sync**. Gib die Paperless-Adresse und das Token ein, wähle den Nextcloud-Benutzer, dem das Archiv gehört, aus den Benutzern, die das Feld anbietet, und klicke auf **Verbindung testen**: Die Checkliste darunter zeigt jede Berechtigung, die dem Konto fehlt, und was zu vergeben ist. Für ein Paperless unter einer Adresse deines Netzwerks, etwa `http://paperless:8000`, schalte zuerst **Paperless läuft in meinem lokalen Netzwerk** ein. Lass die Synchronisation ausgeschaltet und speichere; das Speichern führt dieselben Prüfungen aus.
4. Starte einen **Testlauf** und lies seine Zusammenfassung: Er listet auf, was ein Lauf ändern würde, ohne etwas zu ändern.
5. Schalte die Synchronisation ein und speichere. Der Cron von Nextcloud führt sie im eingestellten Intervall aus, und die Dokumente erscheinen in `Dokumente/Paperless/Archiv` dieses Benutzers, auf einem Nextcloud mit der Standardsprache Deutsch; sonst in `Documents/Paperless/Archive`.

<!-- --8<-- [end:quick-start] -->
<!-- --8<-- [end:quick-start-section] -->
<!-- --8<-- [start:features] -->

## Funktionen

### Archiv

- Ein Ordnerbaum in den Dateien des eingestellten Nextcloud-Benutzers, nach Korrespondent, Dokumenttyp und Jahr, aus einer [Pfadvorlage](#variablen-der-pfadvorlage), die du ändern kannst, mit Basis-, Archiv-, Eingangs-, Fehler- und Gelöscht-Ordnern deiner Wahl
- Variablen mit den Namen, die Paperless-ngx für seine eigenen Dateinamen verwendet, etwa die Archivseriennummer, der Eigentümer, die Tags, der Name des Monats und benutzerdefinierte Felder, und eine Vorschau unter der Vorlage, die schon beim Tippen zeigt, wohin die neuesten Dokumente kommen, bevor du speicherst
- Das Archiv-PDF jedes Dokuments oder seine Originaldatei
- Umbenennen und Verschieben, wenn sich die Metadaten in Paperless ändern, ohne leere Ordner zurückzulassen
- Jede Kopie bleibt in Nextcloud dieselbe Datei: Ein neuer Inhalt ihres Dokuments, etwa eine neue Archivversion, aktualisiert sie an Ort und Stelle, sodass ihre Freigaben, öffentlichen Links, Kommentare, Tags, Favoriten und Versionen bei ihr bleiben
- Dokumente im Posteingang von Paperless warten, bis sie fertig sind, und ein Dokument, das einen ausgeschlossenen Tag oder wieder den Posteingangs-Tag bekommt, bleibt draußen, seine Kopie wird entfernt
- Eine feste Markierung wie `[P123]` in jedem Dateinamen, die [Paperless Unified Search](https://github.com/Dennis-Otto/paperless-unified-search) versteht
- Auf Wunsch [Metadaten neben dem Archiv](#metadaten-neben-dem-archiv): eine JSON-Datei für jedes Dokument mit seinen Tags, Notizen, benutzerdefinierten Feldern und Daten, und ein Verzeichnis des ganzen Archivs als CSV, sodass sich das Archiv auch ohne Paperless selbst beschreibt

### Eingang

- Dateien in einem Eingangsordner von Nextcloud, auf Wunsch auch in seinen Unterordnern, gehen als neue Dokumente zu Paperless
- Jede Datei kann ihre Tags mitbringen: einen für jeden ihrer Unterordner, wie es der Consume-Ordner von Paperless mit `PAPERLESS_CONSUMER_SUBDIRS_AS_TAGS` tut, und Tags für jeden Import; die App legt die Tags an, die Paperless fehlen, wenn du sie lässt
- Die App verfolgt die Paperless-Aufgabe jeder Datei: Nach einem Erfolg entfernt sie die Datei oder behält sie, wie du wählst; nach einem Fehler verschiebt sie die Datei in den Fehlerordner, neben den Grund
- Kann Paperless eine Datei gerade nicht annehmen, wartet das Hochladen und versucht es erneut, nach 15 Minuten bis zu einem Tag

### Sicherheit

- Die Synchronisation bleibt aus, bis du sie einschaltest, und ein Testlauf listet jede Änderung auf, die ein Lauf machen würde, ohne sie zu machen
- Der Papierkorb von Paperless wird in einen eigenen Ordner gespiegelt; endgültiges Löschen ist standardmäßig aus und wartet, wenn es an ist, auf die eingestellte Zahl vollständiger Scans von Paperless in Folge
- Eine Konfliktregel für eine Datei im Weg: ersetzen, oder überspringen und den Konflikt melden; die eigenen Kopien der App sind nie im Weg
- Keine WebDAV-Zugangsdaten und kein Passwort eines Nextcloud-Benutzers: Die App schreibt über die Dateisystem-API von Nextcloud
- Das API-Token von Paperless bleibt im Anmeldedaten-Speicher von Nextcloud, erreicht nie den Browser und geht nur an die Adresse, mit der es gespeichert wurde
- Ein Paperless in deinem lokalen Netzwerk, etwa `http://paperless:8000`, ohne den Schutz von Nextcloud gegen Server-Side Request Forgery für den ganzen Server abzuschalten

### Betrieb

- Der Cron von Nextcloud führt die Synchronisation im Intervall aus, das du einstellst, und die Batchgröße begrenzt die Änderungen eines Laufs
- [Änderungen in Sekunden](#änderungen-in-sekunden): Ein Arbeitsablauf von Paperless ruft den Webhook der App auf, wenn er ein Dokument hinzufügt oder ändert, und seine Kopie ändert sich sofort, ohne einen Container neben Paperless, seinen Medienordner oder ein Passwort von Nextcloud
- Läufe auf Abruf und Testläufe aus den Einstellungen, jeder mit einem Bericht seiner Änderungen und Fehler in der Sprache des Administrators, Fehler zuerst, mit einem Filter für jede Art von Änderung, Links zu den Dateien und einem Knopf, der ihn für einen Fehlerbericht kopiert, und mit dem Status des letzten Laufs; ein Testlauf probiert Änderungen der Einstellungen aus, bevor du sie speicherst, und ein Lauf auf Abruf läuft als Hintergrundjob, sodass kein Proxy ihn abschneidet
- [Auswahlfelder in den Einstellungen](#zielbenutzer-und-tags) bieten die Benutzer von Nextcloud für den Zielbenutzer und die Tags von Paperless für die ausgeschlossenen Tags und die Tags jedes Imports an und markieren einen Benutzer oder einen Tag, den es nicht gibt, sodass sich ein Tippfehler vor dem ersten Lauf zeigt
- Der [Aktivitätenstrom](#aktivität) des Zielbenutzers erzählt, was jeder Lauf geändert hat, mit Links zu seinen Dateien, und jeden Import, mit einem Link zum neuen Dokument in Paperless
- Auf Englisch und Deutsch: Die Einstellungen, ihre Meldungen und der Aktivitätenstrom folgen der Sprache von Nextcloud, die Zeiten dem Gebietsschema deiner persönlichen Einstellungen
- [Benachrichtigungen, Einrichtungsprüfungen und eine Status-API](#benachrichtigungen) sagen den Administratoren, wenn die Spiegelung immer wieder scheitert oder stehen geblieben ist, auch in Uptime Kuma oder Home Assistant
- [`occ`-Befehle](#kommandozeile) für Skripte, Cron-Jobs und Überwachung: ein Lauf oder ein Testlauf mit eigener oder ohne Batchgröße, der Status und neu geschriebene Kopien, mit JSON-Ausgabe und Exit-Codes
- Signierte Releases: dasselbe Paket im Nextcloud App Store und auf GitHub, mit einer SBOM und signierter Build-Provenienz

<!-- --8<-- [end:features] -->

## Screenshots

Nach dem Schnellstart: Ein Testlauf listet jede Datei auf, die ein Lauf schreiben, verschieben oder löschen würde, ohne etwas zu ändern, und *Jetzt synchronisieren* macht die Änderungen. Die Bilder zeigen die englische Oberfläche; auf Deutsch tragen die Knöpfe und Abschnitte die Namen, die diese Anleitung nennt.

![Die Einstellungen von Paperless Sync: Testlauf starten listet die Dokumente auf, die ein Lauf exportieren würde, dann exportiert Jetzt synchronisieren sie, und der Status wechselt zu abgeschlossen](docs/images/dry-run.gif)

Ein paar Tage später zeigt ein Testlauf jede Art von Änderung auf einmal: neue Dokumente, einen neuen Titel, ein Dokument im Papierkorb von Paperless, eines mit einem ausgeschlossenen Tag und einen abgeschlossenen Import aus dem Eingang.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/report-dark.png">
  <img alt="Der Bericht eines Testlaufs unter der Überschrift Testlauf – nichts wurde geändert, mit dem Knopf Bericht kopieren: die Zahlen der Änderungen, die nicht 0 sind, die unveränderten Dokumente in einem Satz, ein Filter für jede Art von Änderung und eine Liste mit einem farbigen Abzeichen, dem Dokument und der Datei jeder Änderung" src="docs/images/report-light.png">
</picture>

Das Archiv in Dateien, ein Ordner je Korrespondent, Dokumenttyp und Jahr, mit der ID jedes Dokuments im Namen:

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/archive-dark.png">
  <img alt="Nextcloud Dateien mit dem Ordnerbaum des Archivs, geöffnet bis City Utilities, Invoice, 2026, mit zwei Stromrechnungen, benannt nach Datum, Titel und Paperless-ID" src="docs/images/archive-light.png">
</picture>

Die Einstellungen der App in den Administrationseinstellungen von Nextcloud:

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/settings-dark.png">
  <img alt="Die Administrationseinstellungen von Paperless Sync: der Status des letzten Laufs, die Verbindung zu Paperless und der Eigentümer des Archivs" src="docs/images/settings-light.png">
</picture>

Die [Anleitung](docs/guide.de.md#die-einstellungen-abschnitt-für-abschnitt) zeigt jeden Abschnitt der Einstellungen.

<!-- --8<-- [start:usage] -->

## Verwendung

Öffne nach der Installation **Administrationseinstellungen → Paperless Sync**. Richte die Verbindung ein, während die Synchronisation ausgeschaltet bleibt: Das Speichern prüft sie, wenn du das Token eingibst. Starte einen Testlauf, prüfe die Zusammenfassung und schalte erst dann die geplante Synchronisation ein.

Die Standard-Pfadvorlage des Archivs ist:

```text
{{ correspondent }}/{{ document_type }}/{{ created_year }}/{{ created }} - {{ title }} [P{{ id }}]{{ extension }}
```

Sie ergibt Pfade wie:

```text
Dokumente/Paperless/Archiv/Beispiel GmbH/Rechnung/2026/2026-08-26 - Beispielrechnung [P123].pdf
```

Jeder Teil der Vorlage wird zu einem Ordner. Der Basisordner enthält neben dem Archiv den Eingangsordner, mit den Dateien für Paperless, und den Fehlerordner, mit fehlgeschlagenen Importen; der Gelöscht-Ordner im Archiv enthält die Kopien der Dokumente im Papierkorb von Paperless:

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/diagrams/the-folders-of-the-default-path-template.de.dark.svg">
  <img src="docs/images/diagrams/the-folders-of-the-default-path-template.de.svg" alt="Die Ordner der Standard-Pfadvorlage: Der Basisordner Dokumente/Paperless enthält drei Ordner: Eingang, den Eingangsordner; Archiv, den Archivordner; und Fehler, den Fehlerordner. Archiv enthält einen Ordner je Korrespondent, etwa Beispiel GmbH, und _Gelöscht, den Gelöscht-Ordner. Im Ordner eines Korrespondenten liegt ein Ordner je Dokumenttyp, etwa Rechnung, mit einem Ordner je Jahr, etwa 2026, und darin die Datei 2026-08-26 - Beispielrechnung [P123].pdf, gebildet aus created, title, der Markierung mit der id und der extension.">
</picture>

Feste Markierungen wie `[P123]` passen zur unabhängigen App [Paperless Unified Search](https://github.com/Dennis-Otto/paperless-unified-search).

Änderst du in Paperless den Titel, die Tags, den Korrespondenten oder den Typ eines Dokuments, wandert seine Datei an ihren neuen Pfad und behält in Nextcloud ihre Freigaben, Kommentare und Versionen, und die Desktop- und Mobil-Clients benennen sie nur um. Nur eine Änderung des Inhalts, etwa eine neue Archivversion, nachdem Paperless das Dokument neu verarbeitet hat, lädt es erneut herunter, und das Herunterladen geht in dieselbe Datei, an ihrem neuen Pfad, wenn sie einen hat, sodass die Datei ihre Freigaben, Kommentare und Favoriten behält; in einem geplanten Lauf behält Nextcloud den vorigen Inhalt als Version der Datei. Ein Lauf unterscheidet beides an den Prüfsummen der Datei: Er behält die SHA-256 und die MD5 jedes Herunterladens und vergleicht sie mit der Prüfsumme, die Paperless meldet, MD5 bis Paperless-ngx 2.x und SHA-256 seit 3.0. Für eine Datei, die eine frühere Version der App exportiert hat und von der nur die SHA-256 gespeichert ist, liest die erste Änderung unter Paperless-ngx 2.x die Datei in Nextcloud einmal, um ihre MD5 zu ergänzen, sodass auch sie ohne Herunterladen wandert. Ein Herunterladen geht in Stücken nach Nextcloud, sodass ein Dokument jeder Größe in den Speicher von PHP passt. Ein Lauf fragt Paperless nur nach den Feldern der Dokumente, die er liest, nicht nach ihrem Text, und nach der Prüfsumme eines Dokuments nur einmal je Revision, auch wenn das Dokument auf einen späteren Lauf wartet; ein Lauf über ein Archiv ohne Änderungen schreibt nichts in die Datenbank.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/diagrams/how-a-run-tells-new-metadata-from-new-content.de.dark.svg">
  <img src="docs/images/diagrams/how-a-run-tells-new-metadata-from-new-content.de.svg" alt="Wie ein Lauf neue Metadaten von neuem Inhalt unterscheidet: Ein Dokument, das noch nie exportiert wurde, wird heruntergeladen. Bei einem exportierten Dokument vergleicht ein Lauf die Revision von Paperless mit der gespeicherten. Bei einer neuen Revision nimmt er die Prüfsumme der Datei, die er für diese Revision behalten hat, oder fragt Paperless danach, MD5 bis Paperless-ngx 2.x und SHA-256 seit 3.0, und behält sie mit der Revision, sodass ein Dokument, das auf einen späteren Lauf wartet, nur einmal fragt; er sucht die Prüfsumme unter der SHA-256 und der MD5, die er beim Herunterladen gespeichert hat. Ist die Revision gleich oder die Prüfsumme darunter, ist der Inhalt gleich: Die Datei bleibt, wo sie ist, oder wandert an ihren neuen Pfad. Hat eine frühere Version der App nur die SHA-256 gespeichert und meldet Paperless eine MD5, berechnet der Lauf beide Prüfsummen der gespiegelten Datei: Ist ihre SHA-256 die gespeicherte und ihre MD5 die von Paperless, ist der Inhalt ebenfalls gleich. Sonst lädt der Lauf das Dokument in eine temporäre Datei, berechnet beide Prüfsummen in einem Durchgang, schreibt es in Stücken nach Nextcloud, in die Datei des Dokuments, wenn es eine hat, die ihre ID behält und zuerst an ihren neuen Pfad wandert, und speichert die Prüfsummen. Die Fragen sind indigo, die Anfragen an Paperless-ngx grün und die Ergebnisse in Nextcloud blau.">
</picture>

<!-- --8<-- [end:usage] -->
<!-- --8<-- [start:configuration] -->

## Konfiguration

### Paperless-Verbindung

Verwende ein eigenes Dienstkonto in Paperless, das kein Superuser ist, mit nur den Berechtigungen der Funktionen, die du nutzt. In Paperless stehen sie unter *Benutzer & Gruppen*, in den Berechtigungen des Kontos:

| Funktion | Berechtigung in Paperless | Was die App damit tut |
| --- | --- | --- |
| Immer | **Anzeigen** für Dokumente, und Zugriff auf jedes zu spiegelnde Dokument, am besten als sein Eigentümer, wie unten beschrieben | listet die Dokumente auf und lädt sie herunter |
| Archivexport | **Anzeigen** für Korrespondenten, Dokumenttypen, Speicherpfade und Tags | bildet die Pfade und überspringt die ausgeschlossenen Tags |
| Gelöscht-Ordner | Eigentum an den Dokumenten, wie unten beschrieben | sieht sie sofort im Papierkorb von Paperless |
| Nextcloud-Eingang | **Hinzufügen** für Dokumente und **Anzeigen** für Paperless-Aufgaben | lädt die Dateien des Eingangs hoch und erfährt, ob Paperless sie importiert hat |
| [Tags des Eingangs](#tags-des-eingangs) | **Anzeigen** für Tags, und **Hinzufügen** für Tags mit *Fehlende Tags anlegen* | findet die Tags der Dateien des Eingangs und legt die an, die Paperless fehlen |
| Verbindungs-Checkliste | **Anzeigen** für UI-Einstellungen | liest die Berechtigungen des Kontos, damit **Verbindung testen** die des Eingangs und das Eigentum an den Dokumenten prüfen kann |

Der Papierkorb von Paperless zeigt einem Konto nur die Dokumente, die ihm gehören, und die ohne Eigentümer: Eine Berechtigung auf einem Dokument eines anderen zählt dort nicht. Ein Superuser sieht den ganzen Papierkorb, aber sein Token beherrscht ganz Paperless, jedes Dokument und jeden Benutzer, also nimm keinen für diese App. Mach stattdessen das Konto der App zum Eigentümer der Dokumente und behalte den Zugriff der Menschen, denen sie jetzt gehören:

- **Neue Dokumente:** Lege in Paperless einen Arbeitsablauf mit dem Auslöser *Dokument hinzugefügt* und einer Aktion *Zuweisung* an, die das Konto der App als Eigentümer zuweist und den Benutzern oder Gruppen, denen die Dokumente jetzt gehören, Rechte zum Anzeigen und Bearbeiten gibt.
- **Vorhandene Dokumente:** Wähle sie in der Dokumentliste aus, wähle *Berechtigungen*, setze das Konto der App als Eigentümer und gib denselben Benutzern oder Gruppen Rechte zum Anzeigen und Bearbeiten. Lass *Mit bestehenden Berechtigungen zusammenführen* aus: Ist es an, behält Paperless den bisherigen Eigentümer und setzt einen nur für Dokumente ohne Eigentümer. Ist es aus, ersetzt der Dialog die Berechtigungen der ausgewählten Dokumente, also nenne jeden, der Zugriff behalten soll.

Nur der Eigentümer eines Dokuments oder ein Superuser kann seinen Eigentümer ändern. Der Papierkorb von Paperless führt diese Dokumente dann für das Konto der App und für Superuser, nicht für die Menschen, denen sie vorher gehörten.

Ohne Eigentum erreicht der Papierkorb das Archiv trotzdem, nur später: Ein Dokument im Papierkorb, das das Konto nicht sehen kann, gilt als fehlend, und seine Kopie wandert nach der eingestellten Zahl vollständiger Scans in den Gelöscht-Ordner. Lass in diesem Fall *Auch Kopien von Dokumenten löschen, die ohne den Paperless-Papierkorb verschwunden sind* aus: Ist es zusammen mit dem endgültigen Löschen an, wird eine solche Kopie dann gelöscht, obwohl sich das Dokument in Paperless noch wiederherstellen lässt. Ein Testlauf zeigt einen Hinweis, wenn Dokumente fehlen, während der Papierkorb keine führt (`TRASH EMPTY` auf der Kommandozeile), und die [Verbindungs-Checkliste](#verbindungs-checkliste) zählt die Dokumente, die das Konto sieht, ohne dass sie ihm gehören.

Das API-Token wird im Anmeldedaten-Speicher von Nextcloud gespeichert und nie an den Browser zurückgegeben. Es geht nur an die Paperless-Adresse, mit der es gespeichert wurde: Um dich mit einer anderen Adresse zu verbinden, gib das Token erneut ein. Das gilt auch, wenn sich die Adresse an der Einstellungsseite vorbei ändert, mit `occ` oder der Provisioning-API von Nextcloud; die Synchronisation wartet dann auf das Token. Speichern, **Verbindung testen** und Trennen fragen nach deinem Passwort, außer du hast es in den letzten 30 Minuten bestätigt.

#### Verbindungs-Checkliste

**Verbindung testen**, unter den Verbindungseinstellungen, prüft die Einstellungen des Formulars, ohne sie zu speichern, und listet auf, was es gefunden hat, mit dem, was für jede fehlgeschlagene Prüfung zu tun ist:

<!-- --8<-- [end:configuration] -->

<!-- The picture comes from tests/e2e/screenshots.mjs, with the token of an account of the mock that lacks three permissions. -->
<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/settings-checklist-dark.png">
  <img alt="Die Verbindungs-Checkliste nach Verbindung testen: Paperless-ngx antwortet, das Konto sieht die Dokumente, Korrespondenten, Dokumenttypen und Speicherpfade, der Papierkorb hat einen Hinweis, dass das Konto ein Dokument eines anderen Eigentümers sieht, das der Papierkorb von Paperless auslässt, und die Tags, das Hochladen aus dem Eingang und die Tags des Eingangs sind fehlgeschlagen, jeweils mit den Berechtigungen, die in Paperless zu vergeben sind" src="docs/images/settings-checklist-light.png">
</picture>

<!-- --8<-- [start:configuration-checklist] -->

| Zeile | Was sie prüft | Wenn sie scheitert |
| --- | --- | --- |
| Paperless | Paperless antwortet unter der Adresse mit Version 9 seiner API, wie Paperless-ngx 2.16 und neuer, und nimmt das Token an | nicht gespeichert |
| Dokumente | das Konto darf Dokumente anzeigen, und wie viele es sieht; gar keine bekommen einen Hinweis | nicht gespeichert |
| Papierkorb, Korrespondenten, Dokumenttypen, Speicherpfade, Tags | mit eingeschaltetem Archivexport: das Konto darf jede Liste anzeigen; der Papierkorb bekommt einen Hinweis, wenn das Konto Dokumente sieht, die ihm nicht gehören, oder ein Superuser ist | mit dem Fehler gespeichert |
| Hochladen aus dem Eingang | mit eingeschaltetem Eingang: das Konto hat die Berechtigungen **Hinzufügen** für Dokumente und **Anzeigen** für Paperless-Aufgaben | mit dem Fehler gespeichert |
| Tags des Eingangs | mit Tags für die Dateien des Eingangs: das Konto hat die Berechtigung **Anzeigen** für Tags, und **Hinzufügen** für Tags mit *Fehlende Tags anlegen* | mit dem Fehler gespeichert |
| Nextcloud-Ordner | der Zielbenutzer darf Dateien im Basisordner anlegen | nicht gespeichert |

Ohne **Anzeigen** für UI-Einstellungen nennt Paperless der App die Berechtigungen des Kontos und seine ID nicht, also bekommen die Zeilen des Papierkorbs, des Hochladens und der Tags des Eingangs statt eines Ergebnisses einen Hinweis, der diese Berechtigung nennt. Jede Zeile hat ihren Zustand als Wort, *Bestanden*, *Hinweis* oder *Fehlgeschlagen*, neben ihrem Zeichen. Ein Fehler nennt die Ursache, etwa ein Token, das Paperless ablehnt, ein Zertifikat, dem Nextcloud nicht vertraut, eine Adresse in deinem Netzwerk oder eine Adresse ohne Paperless-API, etwa die Anmeldeseite eines Proxys, und was zu tun ist. Eine Änderung der Adresse, des Tokens, des Zielbenutzers, des Basisordners, des lokalen Netzwerks, der Module oder der Tags des Eingangs nimmt die Checkliste weg, bis zum nächsten Test.

#### Paperless im lokalen Netzwerk

Nextcloud lehnt Verbindungen zu Adressen des lokalen Netzwerks ab, etwa `http://paperless:8000` in Docker, `http://paperless.lan` oder `http://192.168.1.20:8000`, zum Schutz gegen Server-Side Request Forgery. Läuft Paperless unter einer solchen Adresse, schalte **Paperless läuft in meinem lokalen Netzwerk** ein und speichere. Nextcloud erlaubt dann eine lokale Adresse nur für die Anfragen von Paperless Sync an die gespeicherte Paperless-Adresse. Jede andere App und jede andere Anfrage bleibt geschützt, was der Systemschalter `allow_local_remote_servers` für den ganzen Server aufheben würde.

Ohne die Einstellung sagt das Speichern, was zu tun ist:

> Nextcloud sperrt Verbindungen zu lokalen Adressen wie paperless. Wenn Paperless in deinem Netzwerk läuft, schalte „Paperless läuft in meinem lokalen Netzwerk“ ein und speichere die Einstellungen.

Die Einstellung hilft auch, wenn der Name von Paperless nur in der hosts-Datei des Servers steht, etwa mit `extra_hosts` in Docker: Ohne sie schlägt Nextcloud Namen nur im DNS nach, und das Speichern sagt, dass es keinen DNS-Eintrag für den Namen gefunden hat.

Hast du für diese App früher `allow_local_remote_servers` eingeschaltet, schalte die Einstellung ein, speichere und schalte den Schalter dann mit `occ config:system:delete allow_local_remote_servers` wieder aus. [Das Sicherheitsdesign](https://dennis-otto.github.io/paperless-sync/security.html#a-paperless-in-the-local-network) erklärt auf Englisch, was die Einstellung erlaubt.

### Speichern, pausieren und trennen

Das Speichern prüft zuerst jede Einstellung. Ist die geplante Synchronisation an oder gibst du ein Token ein, führt es auch die [Verbindungs-Checkliste](#verbindungs-checkliste) aus, und der Knopf heißt **Verbindung testen und speichern**. Es lehnt Einstellungen ab, mit denen kein Lauf funktionieren kann, etwa ein Token, das Paperless ablehnt, oder einen Basisordner, in den der Zielbenutzer nicht schreiben kann, sodass der Zeitplan nie mit ihnen läuft. Eine Berechtigung, die nur einem Modul fehlt, etwa die für die Tags, wird mit den Einstellungen gespeichert, und die Checkliste nennt sie, damit du sie danach in Paperless vergeben kannst. Ist der Zeitplan aus und kein neues Token eingegeben, erreicht nichts Paperless, bis du einen Lauf startest, und der Knopf heißt **Speichern** und speichert die Einstellungen ohne den Test; **Verbindung testen** prüft sie dann, ohne zu speichern:

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/diagrams/what-saving-the-settings-tests.de.dark.svg">
  <img src="docs/images/diagrams/what-saving-the-settings-tests.de.svg" alt="Was das Speichern der Einstellungen prüft: Das Speichern prüft zuerst jede Einstellung; eine ungültige wird nicht gespeichert. Ist die geplante Synchronisation an oder wurde ein Token eingegeben, führt das Speichern die Verbindungs-Checkliste aus. Ist Paperless nicht erreichbar, lehnt es das Token ab oder lässt das Konto keine Dokumente sehen, oder kann der Zielbenutzer keine Dateien im Basisordner anlegen, werden die Einstellungen nicht gespeichert; die Seite sagt, warum, und, wenn nur der Zeitplan den Test verlangt hat, dass das Ausschalten des Zeitplans die Einstellungen speichert. Fehlt nur eine Berechtigung eines Moduls, etwa die für die Tags, werden die Einstellungen gespeichert, und die Checkliste nennt die Berechtigung, die zu vergeben ist. Ist der Zeitplan aus und kein neues Token eingegeben, werden die Einstellungen ohne Test gespeichert, sodass ein Administrator eine fehlschlagende Synchronisation pausieren kann.">
</picture>

Um die Synchronisation zu pausieren, etwa solange Paperless nicht läuft, sein Token widerrufen oder der Zielbenutzer deaktiviert ist, schalte **Geplante Synchronisation aktivieren** aus und speichere. Ein Speichern, das scheitert, während der Zeitplan an ist, sagt das und setzt den Fokus auf diesen Schalter.

Nach einem Speichern zeigt das Formular die Einstellungen so, wie Nextcloud sie gespeichert hat: die Adresse ohne Schrägstrich am Ende, jeden ausgeschlossenen Tag einmal und einen leeren Ersatznamen als `Unknown`. Solange das Formular Änderungen enthält, die nicht gespeichert sind, sagt die Leiste der Knöpfe das, und der Browser fragt, bevor du die Seite verlässt. **Testlauf starten** probiert die Änderungen dann aus, ohne sie zu speichern, und sein Bericht sagt *Testlauf ungespeicherter Einstellungen*: Eine neue Pfadvorlage, andere Ordner oder eine andere Löschregel zeigen, was sie tun würden, bevor irgendetwas sie verwendet. Er behält die gespeicherte Verbindung zu Paperless und den gespeicherten Zielbenutzer, die nur ein Speichern prüft und mit deinem Passwort bestätigt: Ein Token, das du eingibst, wartet auf das Speichern, und mit einer neuen Adresse, Einstellung zum lokalen Netzwerk oder einem neuen Zielbenutzer bittet dich der Testlauf, zuerst zu speichern. **Jetzt synchronisieren** verwendet nur die gespeicherten Einstellungen und wartet daher, bis du speicherst.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/diagrams/what-a-dry-run-of-unsaved-settings-uses.de.dark.svg">
  <img src="docs/images/diagrams/what-a-dry-run-of-unsaved-settings-uses.de.svg" alt="Was ein Testlauf ungespeicherter Einstellungen verwendet: Mit ungespeicherten Änderungen im Formular sendet Testlauf starten die Einstellungen des Formulars. Weichen ihre Paperless-Adresse, die Einstellung zum lokalen Netzwerk oder der Zielbenutzer von den gespeicherten ab, wird der Testlauf abgelehnt, und die Seite setzt den Fokus auf die Einstellung, die zuerst zu speichern ist. Eine ungültige Einstellung wird abgelehnt, wie es auch das Speichern täte. Sonst liest der Testlauf Paperless mit der gespeicherten Verbindung und dem gespeicherten Token und listet auf, was ein Lauf mit den Einstellungen des Formulars ändern würde, ohne die Einstellungen zu speichern oder eine Datei zu ändern; sein Bericht sagt Testlauf ungespeicherter Einstellungen.">
</picture>

**Trennen** löscht das gespeicherte API-Token und schaltet die geplante Synchronisation und **Paperless läuft in meinem lokalen Netzwerk** aus. Die übrigen Einstellungen, die synchronisierten Dateien und ihr Zustand bleiben, wie sie sind; um dich wieder zu verbinden, gib das Token ein, schalte das lokale Netzwerk wieder ein, wenn Paperless dort läuft, und speichere.

Ergebnisse und Fehler erscheinen in der Leiste der Knöpfe, die unten im Fenster bleibt. **Jetzt synchronisieren** wartet nicht auf den Lauf: Es reiht den Lauf als Hintergrundjob von Nextcloud ein und antwortet sofort, der Status sagt *In der Warteschlange*, bis die nächsten Hintergrundjobs ihn starten, innerhalb von fünf Minuten mit dem System-Cron, und die Leiste sagt, wie der Lauf endete, sobald der Status sein Ende zeigt. Ein Lauf kann so lange dauern, wie er braucht, und kein Proxy vor Nextcloud schneidet ihn ab. **Testlauf starten** antwortet mit seinem Bericht; antwortet stattdessen ein Proxy oder der Webserver für einen langen Testlauf, etwa mit *504 Gateway Time-out*, fragt die Seite nach dem Status: Solange er den Testlauf zeigt, sagt die Seite, dass er im Hintergrund weiterläuft, und folgt ihm bis zu seinem Ende; sonst nennt sie den Statuscode des Webservers, und der Status zeigt, wie er endete.

### Zielbenutzer und Tags

Das Feld **Nextcloud-Zielbenutzer-ID** bietet die Benutzer von Nextcloud an, deren Kontoname, Anzeigename oder E-Mail-Adresse enthält, was du tippst: Wähle einen mit den Pfeiltasten und der Eingabetaste oder mit einem Klick. Es nimmt den Kontonamen, wie ihn *Konten* in der Administration auflistet, nicht den Anzeigenamen, und markiert ein deaktiviertes Konto als *deaktiviert*. Verlässt du das Feld mit einer ID, die Nextcloud nicht kennt, sagt es das sofort: *Nextcloud hat keinen Benutzer mit der ID …*. Das Speichern prüft den Zielbenutzer nur zusammen mit der Verbindung, sodass sich ein Zeitplan immer pausieren lässt; das Feld zeigt einen Tippfehler schon vorher.

Die Felder **Zusätzlich ausgeschlossene Paperless-Tags** und **Tags für jeden Import** bieten die Tags von Paperless an: Tippe einen Teil eines Namens, um sie einzugrenzen, und wähle einen aus. Jeder Tag des Felds hat einen Knopf, der ihn entfernt, und die Rücktaste im leeren Feld entfernt den letzten. Einen Tag, den Paperless noch nicht hat, kannst du ebenfalls tippen, abgeschlossen mit der Eingabetaste oder einem Komma, und ein Speichern, ein Test oder ein Testlauf nimmt auch das Getippte mit. Ein solcher Name wird als *nicht in Paperless* markiert, was auch einen Tippfehler zeigt: Ein Lauf schließt die Dokumente mit diesem Tag aus, sobald Paperless ihn hat, und gibt einem Import diesen Tag, sobald Paperless ihn hat oder, mit *Fehlende Tags anlegen*, legt ihn an. Ein Lauf vergleicht die Namen ohne Rücksicht auf Groß- und Kleinschreibung, und das Feld ebenso: Ein Name, den du tippst, übernimmt die Schreibweise von Paperless. Die Seite liest die Tags mit der gespeicherten Adresse und dem gespeicherten Token, wie ein Lauf sie liest, also erscheinen sie, sobald die Verbindung gespeichert ist, und das Token bleibt auf dem Server. Ein Tag, dessen Name ein Komma enthält, kann in keiner der Listen stehen, weil Kommas die Namen der Einstellung trennen, also bieten die Felder ihn nicht an.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/settings-tags-dark.png">
  <img alt="Die ausgeschlossenen Tags beim Tippen eines Namens: Private, und Drfat, markiert als nicht in Paperless; darunter das Feld mit dem getippten Dr und die offene Liste mit dem Tag Draft von Paperless und der Möglichkeit, Dr so hinzuzufügen, wie es ist" src="docs/images/settings-tags-light.png">
</picture>

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/diagrams/where-the-pickers-of-the-settings-take-their-names-from.de.dark.svg">
  <img src="docs/images/diagrams/where-the-pickers-of-the-settings-take-their-names-from.de.svg" alt="Woher die Auswahlfelder der Einstellungen ihre Namen nehmen: Das Feld des Zielbenutzers fragt die Provisioning-API von Nextcloud nach den Benutzern, deren ID, Name oder E-Mail-Adresse das Getippte enthält, und nach der Benutzer-ID im Feld, die es markiert, wenn Nextcloud keinen solchen Benutzer hat. Die Felder der ausgeschlossenen Tags und der Tags für jeden Import fragen Paperless Sync, das die Tags mit der gespeicherten Adresse und dem gespeicherten API-Token aus Paperless-ngx liest, wie ein Lauf es tut; das Token bleibt auf dem Server. Ein Name, zu dem Paperless keinen Tag hat, lässt sich ebenfalls hinzufügen und wird als nicht in Paperless markiert.">
</picture>

### Eigentümer der Ordner

Dem eingestellten Nextcloud-Zielbenutzer gehören die synchronisierten Ordner. Die App arbeitet über die interne Dateisystem-API von Nextcloud und braucht daher weder das Passwort dieses Benutzers noch ein App-Passwort. Andere Benutzer sehen das Archiv, wenn der Zielbenutzer seinen Ordner mit ihnen teilt oder mit einer Gruppe von ihnen; teile es nur zum Lesen, und teile den Eingangsordner nur mit denen, die Dokumente an Paperless schicken dürfen.

Die App merkt sich, was sie für jeden Zielbenutzer geschrieben hat. Ein anderer Zielbenutzer beginnt ein neues Archiv, das die Läufe neu aus Paperless schreiben, und das Archiv des vorigen bleibt, wie es ist, ohne weitere Aktualisierung.

### Ordnernamen

Eine neue Installation schlägt die Namen ihrer Ordner und die Ersatznamen für Metadaten, die einem Dokument fehlen, in der Standardsprache von Nextcloud vor, `default_language` in seiner `config.php`. Ein Nextcloud ohne Standardsprache und eine Sprache, für die die App noch keine Übersetzung hat, bekommen die englischen Namen; eine regionale Variante, etwa `de_DE`, bekommt die ihrer Sprache:

| Einstellung | Englisch, und jede Sprache ohne Übersetzung | Deutsch: `de`, `de_DE` und die anderen Varianten |
| --- | --- | --- |
| Basisordner | `Documents/Paperless` | `Dokumente/Paperless` |
| Archivordner | `Archive` | `Archiv` |
| Eingangsordner | `Inbox` | `Eingang` |
| Fehlerordner | `Failed imports` | `Fehler` |
| Gelöscht-Ordner | `_Deleted` | `_Gelöscht` |
| Fehlender Korrespondent | `_No correspondent` | `_Ohne Korrespondent` |
| Fehlender Dokumenttyp | `_No document type` | `_Ohne Dokumenttyp` |
| Fehlendes Datum | `_No date` | `_Ohne Datum` |
| Fehlender Titel | `Untitled` | `Ohne Titel` |

Die Einstellungsseite zeigt die vorgeschlagenen Namen vor dem ersten Speichern, sodass du sie vor dem ersten Lauf ändern kannst, und das Speichern behält sie so, wie die Seite sie zeigt. Danach ändert sich ein Name nur, wenn du ihn änderst: Weder eine andere Standardsprache von Nextcloud noch ein Update benennt einen Ordner um. Die Sprache ist die von Nextcloud, nicht die des Administrators, der die Einstellungen öffnet, damit jeder Administrator und jeder geplante Lauf dieselben Namen sehen. Für die deutschen Namen auf einem Nextcloud ohne Standardsprache setze sie vor dem Speichern, mit `occ config:system:set default_language --value=de`, oder tippe die Namen ein.

Frühere Versionen haben in jeder Sprache die deutschen Namen vorgeschlagen. Eine Installation, die eine von ihnen eingerichtet hat, mit einem Zielbenutzer oder mit einem Lauf, der Kopien oder Importe hinterlassen hat, behält sie mit dem Update, auch die, die sie nie gespeichert hat, etwa nach einer Einrichtung mit `occ`, damit ihr Archiv bleibt, wo es ist:

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/diagrams/which-folder-names-an-installation-uses.de.dark.svg">
  <img src="docs/images/diagrams/which-folder-names-an-installation-uses.de.svg" alt="Welche Ordnernamen eine Installation verwendet: Für jeden Ordnernamen und jeden Ersatznamen verwendet eine Installation den Namen, den ihre Einstellungen gespeichert haben, gleich in welcher Sprache. Eine Installation, die eine frühere Version eingerichtet hat, mit einem Zielbenutzer oder mit Kopien und Importen ihrer Läufe, behält für einen Namen, den sie nie gespeichert hat, den deutschen Namen dieser Versionen, etwa Archiv. Eine neue Installation schlägt den Namen in der Standardsprache von Nextcloud vor, wenn die App einen Katalog für diese Sprache oder für ihre allgemeine Sprache hat, etwa Deutsch für de_DE, und sonst auf Englisch, etwa Archive. Das Speichern der Einstellungen behält die Namen, die die Seite zeigt. Die Fragen sind indigo und die Namen in Nextcloud blau.">
</picture>

Das Umbenennen des Basisordners oder des Archivordners eines Archivs in Gebrauch verschiebt seine Dateien in den nächsten Läufen, im Rahmen der Batchgröße, wie es eine neue [Pfadvorlage](#variablen-der-pfadvorlage) tut.

<a id="conflict-policy"></a>

### Dateikonflikt-Regel

Die *Dateikonflikt-Regel* entscheidet, was ein Lauf tut, wenn am Pfad eines Dokuments eine andere Datei als seine eigene Kopie liegt, etwa eine Datei, die jemand dort abgelegt hat, oder eine Kopie, die jemand in Nextcloud bearbeitet hat. Die Kopie, die die App geschrieben hat, ist nie im Weg: Ein neuer Inhalt ihres Dokuments geht in dieselbe Datei.

- **Synchronisierte Datei ersetzen**, der Standard: Der Lauf schreibt das Dokument aus Paperless in die Datei, die dort liegt, und sie bleibt dieselbe Datei mit ihren Freigaben. Nextcloud behält den vorigen Inhalt, etwa eine Bearbeitung, als Version der Datei in einem geplanten Lauf, und in einem Lauf, der mit *Jetzt synchronisieren* gestartet wurde, nur dann, wenn der Administrator, der ihn startet, der Zielbenutzer ist. Eine Datei im Weg einer Kopie, die an einen neuen Pfad wandert, wird stattdessen gelöscht und geht dorthin, wohin die [Löschungen](#löschsicherheit) des Laufs gehen.
- **Überspringen und Konflikt melden**: Der Lauf lässt eine Datei eines anderen, wie sie ist, ebenso eine Kopie, die jemand bearbeitet hat, wenn ihr Dokument einen neuen Inhalt bekommt, und meldet `Nextcloud file conflict at …` als Fehler des Dokuments, in jedem Lauf, bis die Datei weg ist, und schreibt oder verschiebt das Dokument dort nicht. Die Kopie der App, die noch den Inhalt ihres letzten Herunterladens hat, ist kein Konflikt: Der Lauf aktualisiert sie.

> [!WARNING]
> Die Dateien des Archivs sind ein Spiegel von Paperless und mit Absicht nur zum Lesen da: Nichts geht von ihnen zurück zu Paperless. Der nächste Lauf schreibt eine Kopie, die du gelöscht oder umbenannt hast, neu an ihren Pfad, und wenn sich ihr Dokument in Paperless ändert, kann ein Lauf seinen neuen Inhalt über eine Kopie schreiben, die du bearbeitet hast, oder einen Konflikt für sie melden, wenn Konflikte übersprungen werden. Bearbeite Dokumente in Paperless, und kopiere eine Datei aus dem Archiv heraus, um eine eigene Fassung zu behalten.

### Variablen der Pfadvorlage

Die meisten Variablen haben die Namen, die Paperless-ngx für seine eigenen Dateinamen verwendet, also funktionieren viele Vorlagen eines Speicherpfads von Paperless hier, sobald `{{ id }}` hinzukommt. Vorlagen mit Logik von Jinja, mit `custom_fields|get_cf_value(...)` oder mit einer Variablen, die die Tabelle unten nicht nennt, funktionieren nicht. Für ein Dokument, das am 26. August 2026 erstellt und am 1. September hinzugefügt wurde:

| Variable | Wert | Beispiel |
| --- | --- | --- |
| `{{ id }}` | Die ID des Dokuments in Paperless | `123` |
| `{{ title }}` | Sein Titel | `Stromrechnung` |
| `{{ correspondent }}` | Der Name seines Korrespondenten | `Stadtwerke` |
| `{{ document_type }}` | Der Name seines Dokumenttyps | `Rechnung` |
| `{{ storage_path }}` | Der Name seines Speicherpfads in Paperless | `Haushalt` |
| `{{ owner_username }}` | Der Benutzername seines Eigentümers in Paperless | `dennis` |
| `{{ tag_list }}` | Die Namen seiner Tags, sortiert und durch Kommas getrennt | `Energie,Steuern` |
| `{{ asn }}` | Seine Archivseriennummer | `1042` |
| `{{ page_count }}` | Die Zahl seiner Seiten | `2` |
| `{{ created }}` | Das Datum des Dokuments | `2026-08-26` |
| `{{ created_year }}`, `{{ created_year_short }}` | Sein Jahr, vier- oder zweistellig | `2026`, `26` |
| `{{ created_month }}`, `{{ created_month_name }}` | Sein Monat, als Zahl oder mit seinem Namen in der Sprache von Nextcloud | `08`, `August` |
| `{{ created_day }}` | Sein Tag | `26` |
| `{{ added }}`, `{{ added_year }}`, `{{ added_month }}`, `{{ added_day }}` | Wann es zu Paperless hinzugefügt wurde | `2026-09-01`, `2026`, `09`, `01` |
| `{{ original_filename }}` | Der Name der hochgeladenen Datei, ohne ihre Endung | `scan` |
| `{{ extension }}` | Die Endung der exportierten Fassung, mit ihrem Punkt | `.pdf` |
| `{{ custom_fields.<name> }}` | Der Wert des benutzerdefinierten Felds dieses Namens | `{{ custom_fields.Rechnungsnummer }}`: `RE-2026-08` |

Die Vorlage muss `{{ id }}` enthalten. Eine Variable kann mit oder ohne Leerzeichen geschrieben werden, etwa `{{id}}`, und wird als `{{ id }}` gespeichert. Jeder Wert kommt als reiner Text in den Pfad, also bleibt ein `$1` oder ein `{{ title }}` in einem Titel, wie es ist.

Ein Wert, der einem Dokument fehlt, ist leer, etwa ein Eigentümer, Tags, eine Archivseriennummer oder ein benutzerdefiniertes Feld, und ein Ordner, den nur ein solcher Wert benennen würde, heißt `Folder`; ein fehlender Korrespondent, Dokumenttyp, ein fehlendes Datum oder ein fehlender Titel nimmt seinen Ersatznamen aus *Erweiterte Ersatznamen*. Der Name eines benutzerdefinierten Felds wird wie in Paperless geschrieben, mit seinen Leerzeichen und Großbuchstaben, und darf jedes Zeichen außer `{`, `}` und `/` enthalten. Ein Auswahlfeld gibt die Bezeichnung seiner Wahl, ein Dokumentlink die IDs der Dokumente, ein Ja-Nein-Feld `true` oder `false` und ein Geldfeld seinen Betrag so, wie Paperless ihn schreibt, etwa `EUR12.30`. `{{ created_month_name }}` nennt den Monat in der Sprache, die Nextcloud erzwingt oder als Standard hat, `force_language` oder `default_language` in seiner `config.php`, und ohne beide auf Englisch: nie in der Sprache des Administrators, der einen Lauf startet, damit die Pfade in jedem Lauf gleich sind. Eine Änderung dieser Sprache verschiebt die Dateien wie eine Änderung der Vorlage. Die Namen in der Sprache von Nextcloud brauchen die PHP-Erweiterung `intl`; ohne sie sind sie englisch, also verschiebt auch das Installieren oder Entfernen von `intl` die Dateien einer solchen Vorlage.

Die Benutzer und die benutzerdefinierten Felder von Paperless werden nur für eine Vorlage gelesen, die sie verwendet. Für `{{ owner_username }}` braucht das Konto der App in Paperless die Berechtigung, Benutzer anzuzeigen, und für ein benutzerdefiniertes Feld die Berechtigung, benutzerdefinierte Felder anzuzeigen; ohne sie scheitert der Lauf, bevor eine Datei wandert, und nennt die Berechtigung. Ein benutzerdefiniertes Feld, das Paperless nicht hat, etwa eines mit einem Tippfehler oder eines, das umbenannt wurde, stoppt den Lauf ebenfalls, weil sein Wert im Pfad jedes Dokuments leer wäre.

Unter der Vorlage zeigen die Einstellungen, wohin die fünf neuesten Dokumente von Paperless mit der Vorlage kämen, die du tippst, einen Moment nachdem du aufhörst, vor dem Speichern, mit den Ersatznamen und den Schaltern der Seite und einem Hinweis für ein Dokument, das ein Lauf überspringt. Die Knöpfe unter dem Feld fügen eine Variable an der Cursorposition ein. Eine ungültige Vorlage sagt dort, warum, wie es das Speichern täte:

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/path-preview-dark.png">
  <img alt="Die Pfadvorlage in den Einstellungen, mit einem Knopf für jede Variable darunter und den Pfaden der fünf neuesten Dokumente von Paperless mit der getippten Vorlage, vor einem Speichern" src="docs/images/path-preview-light.png">
</picture>

Jeder Dateiname behält die Markierung `[P{{ id }}]` und endet mit `{{ extension }}`: Lässt der letzte Teil der Vorlage sie weg, fügt die App sie an seinem Ende an. Die Markierung hält die Pfade zweier Dokumente auseinander und bindet jede Datei an ihr Dokument.

Jeder Teil des Pfads wird für Nextcloud, macOS und Windows bereinigt: Steuerzeichen und `< > : " / \ | ? *` werden zu `_`, mehrere Leerzeichen zu einem, Leerzeichen und Punkte an den Enden fallen weg, und die Namen von Windows-Geräten, etwa `CON`, `COM1` oder `LPT¹`, bekommen ein führendes `_`.

Nextcloud nimmt Namen mit bis zu 250 Bytes an, und ein kyrillisches Zeichen braucht zwei Bytes, ein chinesisches drei und ein Emoji vier. Also hat ein Ordnername höchstens 200 Bytes und ein Dateiname höchstens 217, was Platz für den temporären Namen eines Schreibvorgangs lässt. Ein Name, der länger wäre, wird nur in seinem Freitext gekürzt: dem Titel, dem Korrespondenten, dem Dokumenttyp, dem Speicherpfad und dem ursprünglichen Dateinamen, der längste zuerst. Der Text der Vorlage, die Daten, die Markierung und die Endung bleiben ganz:

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/diagrams/how-a-part-of-the-path-is-built.de.dark.svg">
  <img src="docs/images/diagrams/how-a-part-of-the-path-is-built.de.svg" alt="Wie ein Teil des Pfads entsteht: Die Werte kommen als reiner Text in den Teil der Vorlage, und der Teil wird bereinigt. Solange er mehr Bytes hat, als Nextcloud erlaubt, wird der längste der Freitextwerte gekürzt, sodass der Text der Vorlage, die Daten, die Markierung und die Endung ganz bleiben. Der Dateiname endet dann mit der Endung.">
</picture>

### Metadaten neben dem Archiv

Mit *Metadaten neben das Archiv schreiben* in *3 · Strukturiertes Archiv*, das von Haus aus aus ist, schreibt jeder Lauf, was Paperless über jedes Dokument weiß, in den Ordner `.paperless` des Archivordners. Dann beschreibt sich das Archiv auch ohne Paperless selbst: wenn du zu einem anderen System wechselst, nur Nextcloud sicherst oder das Archiv jemandem ohne Konto in Paperless gibst. Dateien zeigt den Ordner, sobald *Versteckte Dateien anzeigen* in seinen Einstellungen an ist.

> [!IMPORTANT]
> Die Notizen und die benutzerdefinierten Felder der Dokumente, etwa eine private Bemerkung, eine IBAN oder eine Vertragsnummer, blieben bisher in Paperless. Mit der Einstellung kann sie in `.paperless` lesen, wer den Archivordner öffnen kann, auch über eine Freigabe oder einen öffentlichen Link des Archivordners oder eines Ordners darüber. Prüfe die Freigaben, bevor du sie einschaltest.

- **Eine Begleitdatei für jede Kopie,** `.paperless/P123.json` für das Dokument 123: sein Titel, sein Korrespondent, Dokumenttyp und Speicherpfad mit ihren Namen, seine Tags, wann es erstellt, hinzugefügt und zuletzt geändert wurde, seine Archivseriennummer, sein ursprünglicher Dateiname, MIME-Typ und seine Seitenzahl, seine Notizen mit ihren Verfassern, seine benutzerdefinierten Felder mit ihren Namen, ein Auswahlfeld mit der Bezeichnung seiner Wahl, ob die Kopie das Original oder die Archivversion ist, mit ihrer SHA-256, und die Adresse des Dokuments in Paperless.
- **Ein Verzeichnis des Archivs,** `.paperless/index.csv`, mit einer Zeile für jede Begleitdatei: die ID, der Titel, der Korrespondent, der Dokumenttyp, die Tags und die Daten, der Pfad der Kopie unterhalb des Archivordners, der Zustand des Dokuments, `active`, `trash` oder `missing`, die SHA-256 der Kopie, die Adresse in Paperless und die SHA-256 der Begleitdatei. Es ist CSV nach RFC 4180, in UTF-8 mit Byte-Order-Mark, mit Kommas zwischen den Werten: LibreOffice Calc öffnet es mit einem Doppelklick, und Excel, dessen Doppelklick eine CSV-Datei am Trennzeichen seiner Sprache teilt, im Deutschen am Semikolon, mit *Daten* → *Aus Text/CSV*, das das Komma und UTF-8 selbst erkennt. Ein Wert, den eine Tabellenkalkulation für eine Formel halten würde, einer, der mit `=`, `+`, `-` oder `@` beginnt, beginnt im Verzeichnis mit einem Apostroph, und die Begleitdatei hat ihn, wie er ist.

Die Begleitdatei eines Dokuments, hier mit der Archivversion seiner Kopie:

```json
{
    "version": 1,
    "id": 123,
    "title": "Monthly invoice",
    "correspondent": "Example GmbH",
    "document_type": "Invoice",
    "storage_path": null,
    "tags": [
        "Electricity",
        "Paid"
    ],
    "created": "2026-08-26",
    "added": "2026-08-26T10:00:00+02:00",
    "modified": "2026-09-01T09:00:00+02:00",
    "archive_serial_number": 42,
    "original_file_name": "scan.pdf",
    "mime_type": "application/pdf",
    "page_count": 2,
    "notes": [
        {
            "note": "Paid on 1 September.",
            "created": "2026-09-01T09:00:00+02:00",
            "user": "anna"
        }
    ],
    "custom_fields": [
        {
            "name": "Invoice number",
            "type": "string",
            "value": "INV-2026-08"
        }
    ],
    "file": {
        "version": "archive",
        "sha256": "9fae5fe3947195044d068993bb1c63bd15029aab64fdc4827ee6d9e08b304d84"
    },
    "url": "https://paperless.example.com/documents/123/details"
}
```

Und seine Zeile im Verzeichnis, unter den Namen der Spalten, die Prüfsummen hier gekürzt:

```csv
id,title,correspondent,document_type,tags,created,added,modified,archive_serial_number,path,state,sha256,url,metadata_sha256
123,Monthly invoice,Example GmbH,Invoice,"Electricity, Paid",2026-08-26,2026-08-26T10:00:00+02:00,2026-09-01T09:00:00+02:00,42,Example GmbH/Invoice/2026/2026-08-26 - Monthly invoice [P123].pdf,active,9fae5fe3…,https://paperless.example.com/documents/123/details,5d41402a…
```

Ein Lauf schreibt eine Begleitdatei nur, wenn sich ihr Inhalt ändert, etwa nach einer Änderung des Dokuments in Paperless oder des Namens seines Korrespondenten: Das Verzeichnis hält die SHA-256 jeder Begleitdatei, sodass ein Lauf für alle eine Datei liest. Eine Kopie im Gelöscht-Ordner behält ihre Begleitdatei, und ihre Zeile nennt ihren Pfad dort und den Zustand ihres Dokuments. Eine Begleitdatei geht mit ihrer Kopie, nach denselben Regeln der [Löschsicherheit](#löschsicherheit): wenn ein Lauf die Kopie eines Dokuments löscht, das Paperless gelöscht hat, oder die Kopie eines Dokuments mit einem ausgeschlossenen Tag entfernt.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/diagrams/how-a-run-keeps-the-metadata-next-to-the-archive.de.dark.svg">
  <img src="docs/images/diagrams/how-a-run-keeps-the-metadata-next-to-the-archive.de.svg" alt="Wie ein Lauf die Metadaten neben dem Archiv pflegt: Für jede Kopie, die ein Lauf exportiert, verschiebt oder unverändert vorfindet, baut er die Begleitdatei aus dem, was Paperless über das Dokument weiß, und vergleicht ihre SHA-256 mit der in index.csv. Nur eine Begleitdatei, deren Inhalt sich geändert hat, wird nach .paperless/P123.json geschrieben. Am Ende des Laufs geht die Begleitdatei eines Dokuments, dessen Kopie der Lauf nach den Regeln der Löschsicherheit gelöscht hat, mit ihrer Zeile; die Zeilen der anderen Kopien folgen ihren Pfaden und Zuständen, etwa in den Gelöscht-Ordner, und index.csv wird geschrieben, wenn es sich geändert hat.">
</picture>

Ein Probelauf schreibt keine Metadaten, und ein Aufruf des [Webhooks](#änderungen-in-sekunden) schreibt die Begleitdatei und die Zeile seines Dokuments sofort. Paperless schickt die Notizen der Dokumente nur, solange die Einstellung an ist, und das Konto der App braucht die Berechtigung, benutzerdefinierte Felder anzusehen: Ohne sie exportieren die Läufe die Dokumente, schreiben keine Metadaten und melden einen Fehler, `Metadata: Paperless did not list its custom fields …`. Ein Lauf schreibt höchstens so viele Begleitdateien, wie *Maximale Änderungen je Modul und Lauf* erlaubt, also schreibt der erste Lauf nach dem Einschalten die Begleitdateien so vieler Dokumente, und die nächsten Läufe schreiben den Rest. Wenn Nextcloud eine Begleitdatei nicht schreiben kann, etwa eine gesperrte, zeigt der Bericht einen Fehler, `Metadata P123: …`, und der nächste Lauf versucht sie erneut. Wenn es keine schreiben kann, etwa bei vollem Kontingent, meldet der Lauf das einmal, schreibt keine weitere Begleitdatei, behält die Zeilen derer, die er geschrieben hat, und der nächste Lauf holt sie nach; `Metadata index: …` ist ein Fehler des Verzeichnisses. Lösche `index.csv`, damit der nächste Lauf jede Begleitdatei neu schreibt. Die Einstellung auszuschalten lässt den Ordner, wie er ist.

### Nextcloud-Eingang

Dateien im Eingangsordner, und in seinen Unterordnern, wenn *Unterordner des Eingangs durchsuchen* an ist, gehen zu Paperless, in einem Lauf höchstens so viele, wie die Batchgröße erlaubt. Eine Datei bleibt im Eingang, bis ihre Paperless-Aufgabe Erfolg meldet:

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/diagrams/the-way-of-a-file-from-the-inbox-to-paperless.de.dark.svg">
  <img src="docs/images/diagrams/the-way-of-a-file-from-the-inbox-to-paperless.de.svg" alt="Der Weg einer Datei aus dem Eingang zu Paperless: Im nächsten Lauf wird eine Datei im Ordner Eingang zu Paperless hochgeladen. Ist Paperless nicht erreichbar oder überlastet, wartet die Datei 15 Minuten, dann jedes Mal doppelt so lange, bis zu einem Tag, und wird erneut hochgeladen. Lehnt Paperless sie ab, etwa eine Datei eines nicht unterstützten Typs, bleibt sie im Eingang und wird übersprungen, bis sie neu gespeichert, umbenannt oder ersetzt wird. Nimmt Paperless sie an, verfolgen die nächsten Läufe ihre Paperless-Aufgabe, solange sie läuft: Nach Erfolg wird die Datei aus dem Eingang entfernt oder behalten, wie eingestellt; nach einem Fehler, einem Duplikat oder einer abgebrochenen Aufgabe wandert sie in den Ordner Fehler, neben eine .error.txt mit dem Grund. Einen Tag nach dem Hochladen gilt eine Datei, deren Inhalt Paperless hat, als importiert; eine Aufgabe, die Paperless noch unfertig führt, verschiebt die Datei in den Ordner Fehler, damit ein zweites Hochladen kein Duplikat erzeugen kann; und eine Aufgabe, die Paperless nicht führt, schickt die Datei ebenfalls in die Wartezeit, und sie wird erneut hochgeladen.">
</picture>

Eine fehlgeschlagene Datei behält ihren Unterordner unterhalb des Fehlerordners. Ihre `.error.txt` enthält den Grund, den Paperless genannt hat, etwa einen Dateityp, den es nicht annimmt, oder *It is a duplicate of document #123* unter Paperless-ngx 2.x und unter 3.x mit `PAPERLESS_CONSUMER_DELETE_DUPLICATES`, und die ID der Paperless-Aufgabe, über die die Aufgabenliste von Paperless mehr zeigt. Eine Aufgabe, die Paperless abgebrochen hat, `REVOKED` in seiner Aufgabenliste, verschiebt die Datei ebenfalls dorthin. Der Bericht eines Laufs nennt das Dokument, das ein erfolgreicher Import angelegt hat: Die Einstellungsseite zeigt die Datei als *Importiert*, mit *Paperless hat die Datei als Dokument P456 importiert.*, und die Kommandozeile gibt `IMPORT SUCCESS: Dokumente/Paperless/Eingang/police.pdf -> P456` aus.

Hat Paperless die Aufgabe einer Datei einen Tag nach dem Hochladen nicht abgeschlossen, sucht der Lauf zuerst ein Dokument mit dem Inhalt der Datei, über ihre Prüfsumme. Hat Paperless eines, zählt der Import als Erfolg. Führt Paperless die Aufgabe noch, als wartend oder gestartet, ist vielleicht sein Worker ausgefallen oder seine Warteschlange lang, also kann das Dokument noch erscheinen: Die Datei wandert in den Fehlerordner, mit einem Hinweis, der das sagt, und das Nextcloud-Protokoll hat eine Warnung. Sie wird nicht erneut hochgeladen, weil Paperless-ngx 3.x ein Duplikat als zweites Dokument importiert. Führt Paperless die Aufgabe gar nicht, hat seine Warteschlange sie verloren, wie nach einem Neustart von Redis: Der Lauf meldet einen Fehler, das Nextcloud-Protokoll hat eine Warnung, und die Datei wird nach 15 Minuten erneut hochgeladen, wie nach einem fehlgeschlagenen Hochladen.

Paperless kann eine Datei sofort ablehnen: eine eines Typs, den es nicht annimmt, eine leere oder eine, die zu groß für einen Proxy davor ist, etwa die 100 MB des kostenlosen Tarifs von Cloudflare. Der Bericht dieses Laufs führt die Datei einmal als *Abgelehnt*, mit dem Grund, den Paperless genannt hat (`IMPORT REJECTED` auf der Kommandozeile), und das Nextcloud-Protokoll verzeichnet es als Warnung. Die Läufe danach lassen die Datei im Eingang und überspringen sie, bis sie sich ändert; speichere sie neu, benenne sie um oder ersetze sie, um sie erneut zu übergeben.

Ist Paperless nicht erreichbar oder scheitert es mit einem Serverfehler wie `502 Bad Gateway`, lädt der Lauf keine weiteren Dateien hoch und versucht diese später erneut: nach 15 Minuten, dann jedes Mal nach doppelt so langer Zeit, höchstens einmal am Tag. Die anderen Dateien folgen in den nächsten Läufen.

#### Tags des Eingangs

Eine Datei kann ihre Tags zu Paperless mitbringen, wie es der Consume-Ordner von Paperless mit `PAPERLESS_CONSUMER_SUBDIRS_AS_TAGS` tut. Mit *Unterordner werden zu Tags* bekommt eine Datei in `Eingang/Steuern/2026` die Tags *Steuern* und *2026*, und die *Tags für jeden Import*, etwa *Gescannt* und *Nextcloud*, die das Feld [aus den Tags von Paperless anbietet](#zielbenutzer-und-tags), gehen mit jeder Datei. Beides ist aus, bis du es einschaltest. Ein Name findet den Tag von Paperless mit diesem Namen, gleich in welcher Schreibweise, also findet der Ordner `steuern` den Tag *Steuern*:

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/diagrams/which-tags-a-file-of-the-inbox-gets.de.dark.svg">
  <img src="docs/images/diagrams/which-tags-a-file-of-the-inbox-gets.de.svg" alt="Welche Tags eine Datei des Eingangs bekommt: Eine Datei im Ordner Eingang/Steuern/2026 bekommt die Tags für jeden Import, etwa Gescannt, und mit Unterordner werden zu Tags einen für jeden Ordner unterhalb des Eingangs, der sie enthält, Steuern und 2026. Jeder Name findet den Tag von Paperless mit diesem Namen, gleich in welcher Schreibweise. Mit Fehlende Tags anlegen wird ein Name ohne Tag zu einem neuen Tag von Paperless, der sich keinem anderen Dokument zuweist; ohne die Einstellung geht die Datei ohne diesen Tag, und der Bericht des Laufs und das Nextcloud-Protokoll nennen ihn. Die Datei geht mit den IDs ihrer Tags zu Paperless, und Paperless fügt seine eigenen Posteingangs-Tags hinzu und benennt das neue Dokument nach der Datei.">
</picture>

- **Das Konto der App findet nur die Tags, die es anzeigen darf:** die ohne Eigentümer, seine eigenen und die, die mit ihm geteilt sind. Paperless macht den Menschen, der einen Tag anlegt, zu seinem Eigentümer, also teile einen vorhandenen Tag mit dem Konto (oder entferne seinen Eigentümer), bevor du die Funktion einschaltest; sonst findet das Konto ihn nicht, und mit *Fehlende Tags anlegen* legt der Lauf einen zweiten Tag mit demselben Namen an.
- **Ein Name, zu dem das Konto keinen Tag findet,** hält die Datei nicht auf: Sie geht ohne diesen Tag, und der Bericht des Laufs sagt das einmal, in einem Hinweis auf der Einstellungsseite (`TAGS UNKNOWN: Paperless shows the account no tag named 2026, …` auf der Kommandozeile), mit einer Warnung im Nextcloud-Protokoll. Lege den Tag in Paperless an oder teile ihn mit dem Konto, und die nächsten Dateien bekommen ihn.
- **Mit *Fehlende Tags anlegen*** legt der Lauf einen solchen Tag zuerst an, mit dem Zuweisungsalgorithmus *Keiner*, damit Paperless ihn nicht von selbst einem anderen Dokument zuweist, und ohne Eigentümer, wie es der Consume-Ordner von Paperless tut, damit jedes Konto, das Tags anzeigen darf, ihn sieht, und der Bericht zeigt ihn als *Neuer Tag* (`NEW TAG: 2026` auf der Kommandozeile); die Einstellungsseite zeigt die Tags jedes Imports auch unter seiner Datei. Das Konto der App braucht die Berechtigung **Hinzufügen** für Tags; eine Datei, deren Tag Paperless nicht anlegt, wartet im Eingang und wird später erneut hochgeladen, wie nach einem fehlgeschlagenen Hochladen. Ein Testlauf nennt die Tags, die ein Lauf anlegen würde, und legt keine an.
- **Der Bericht nennt die Tags jeder Datei,** etwa `IMPORT: Dokumente/Paperless/Eingang/Steuern/2026/beleg.pdf (tags: Steuern, 2026)`, und Paperless fügt seine eigenen Posteingangs-Tags hinzu, wie bei jedem neuen Dokument.
- **Der Titel** des neuen Dokuments ist der Name der Datei ohne ihre Endung: Paperless nimmt ihn aus der Datei, wie bei jedem Hochladen.

Jeder, der in den Eingangsordner schreiben darf, kann seine Ordner benennen, also kann er mit *Fehlende Tags anlegen* auch Tags in Paperless anlegen. Schalte es für einen Eingang ein, in den nur Menschen schreiben, denen du vertraust, und nimm für den Rest die Tags für jeden Import.

### Löschsicherheit

Das Verschieben von Paperless-Dokumenten in seinen Papierkorb kann in den eingestellten Ordner `_Gelöscht` gespiegelt werden, unterhalb des Datums, an dem das Dokument in den Papierkorb kam, in der Zeitzone des Zielbenutzers aus den persönlichen Einstellungen von Nextcloud oder sonst in der von Nextcloud, etwa `_Gelöscht/2026-08-27/Beispiel GmbH/Rechnung/2026/…`; behält das Papierkorb-Verhalten Archivdateien am Ort, bleibt die Kopie, wo sie ist. Das Löschen der Kopie eines Dokuments, das Paperless gelöscht hat, ist standardmäßig aus. Ist es an, muss ein Dokument die eingestellte Zahl vollständiger Scans in Folge sowohl in der aktiven Paperless-API als auch in seinem Papierkorb fehlen.

Eine Kopie, die ein Lauf löscht, kommt in den Papierkorb des Zielbenutzers, *Gelöschte Dateien* in Dateien, ebenso die Ordner, die er leert: Nextcloud behält sie dort für die Aufbewahrungszeit seines Papierkorbs, und der Zielbenutzer kann sie wiederherstellen. Das gilt für einen geplanten Lauf, für einen Lauf von *Jetzt synchronisieren*, der ebenfalls als Hintergrundjob läuft, und für einen Lauf von `occ paperless_sync:run`. Ein Hintergrundjob, den Nextcloud nach dem Job einer anderen App ausführt, der die Dateien eines anderen Benutzers eingerichtet hat, löscht sie endgültig, was [#119](https://github.com/Dennis-Otto/paperless-sync/issues/119) verfolgt. Dasselbe gilt für die Kopie, die ein ausgeschlossener Tag entfernt, für eine Datei des Eingangs nach ihrem Import und für eine Datei im Weg einer Kopie, die wandert, die die [Dateikonflikt-Regel](#dateikonflikt-regel) *Synchronisierte Datei ersetzen* löscht. Eine Kopie, deren Dokument einen neuen Inhalt bekommt, bleibt dagegen dieselbe Datei, und ihr voriger Inhalt wird zu einer Version von ihr, nicht zu einer Datei im Papierkorb. Ohne die App *Gelöschte Dateien* von Nextcloud ist jede Löschung endgültig.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/diagrams/what-happens-to-the-copy-of-a-document.de.dark.svg">
  <img src="docs/images/diagrams/what-happens-to-the-copy-of-a-document.de.svg" alt="Was mit der Kopie eines Dokuments geschieht: Ein exportiertes Dokument liegt im Archiv. In Paperless in den Papierkorb verschoben, oder ohne den Papierkorb aus Paperless verschwunden, N vollständige Scans lang, wandert seine Kopie nach _Gelöscht; in Paperless wiederhergestellt, wandert sie zurück ins Archiv. In Paperless endgültig gelöscht, wird die Kopie in _Gelöscht nach N vollständigen Scans in Nextcloud gelöscht, wenn endgültiges Löschen an ist. Eine Kopie im Archiv, deren Dokument N vollständige Scans lang ohne den Papierkorb aus Paperless verschwunden ist, wird direkt gelöscht, wenn endgültiges und direktes Löschen an sind.">
</picture>

N ist die Zahl der *Erforderlichen aufeinanderfolgenden Läufe ohne das Dokument*, standardmäßig drei. Mit dem Papierkorb-Verhalten *Archivdatei am Ort behalten* bleibt die Kopie im Archiv, statt nach `_Gelöscht` zu wandern, und wird von dort gelöscht, wenn die Regeln es erlauben. Ein Dokument, das Paperless wiederherstellt oder wieder auflistet, behält diese Kopie ohne Herunterladen.

Die Kopie eines Dokuments, das aus Paperless verschwindet, ohne durch seinen Papierkorb zu gehen, wandert nach derselben Zahl von Scans in den Gelöscht-Ordner. Sie bleibt am Ort, wenn das Papierkorb-Verhalten Archivdateien am Ort behält, und wird gelöscht, wenn auch das direkte Löschen erlaubt ist.

Nur eine Kopie, die noch da ist, wird verschoben oder gelöscht: Ein Dokument, dessen Kopie schon weg ist oder das nie exportiert wurde, wird nur als im Papierkorb oder als fehlend markiert. Verschiebungen in den Gelöscht-Ordner und Löschungen zählen wie jede andere Änderung zur Batchgröße, auch wenn sie scheitern: Was nicht in einen Lauf passt, wartet auf den nächsten. Eine Kopie, die sich nicht verschieben oder löschen lässt, wird als Fehler ihres Dokuments gemeldet, und der Lauf macht mit den anderen weiter.

Nur ein vollständiger Scan zählt. Paperless listet seine Dokumente Seite für Seite auf, und eine Seite kann zu kurz oder leer zurückkommen, von einem Proxy vor Paperless oder einer Störung seiner Berechtigungen, oder ein Dokument kann hinzukommen oder gelöscht werden, während der Lauf die Seiten liest. Dann sähen Dokumente, die es noch gibt, gelöscht aus. Also zählt ein Scan nur, wenn jede Seite voll zurückkam, mit derselben Zahl von Paperless, und der Lauf jedes Dokument erhalten hat, das Paperless aufgelistet hat; der Lauf liest die Dokumente in der Reihenfolge ihrer IDs, sodass ein neues auf der letzten Seite hinzukommt. Nach einem unvollständigen Scan der Dokumente oder des Papierkorbs zeigt der Bericht einen Hinweis, dass die Liste von Paperless unvollständig zurückkam (`SCAN INCOMPLETE` auf der Kommandozeile), das Nextcloud-Protokoll hat eine Warnung, und der Lauf zählt kein Dokument als fehlend; der nächste vollständige Scan macht weiter. Ein Dokument, das ein Scan wieder auflistet, beginnt seine Zählung fehlender Scans neu. Die Listen der Tags, Korrespondenten, Dokumenttypen und Speicherpfade werden genauso geprüft: Kommt eine von ihnen unvollständig zurück, scheitert der Lauf, bevor er etwas im Archiv ändert, sodass ein Dokument nie mangels seines ausgeschlossenen Tags exportiert wird und nie mangels seines Korrespondenten wandert.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/diagrams/when-a-document-counts-as-missing.de.dark.svg">
  <img src="docs/images/diagrams/when-a-document-counts-as-missing.de.svg" alt="Wann ein Dokument als fehlend gilt: Jeder Lauf liest alle Seiten der Dokumente und des Papierkorbs von Paperless. Kam eine Seite zu kurz oder leer zurück, oder hat sich die Liste während des Scans geändert, meldet der Lauf SCAN INCOMPLETE und zählt kein Dokument als fehlend. Sonst beginnt ein Dokument, das in einer der Listen steht, seine Zählung neu, und eines, das in keiner Liste steht, zählt einen fehlenden Scan mehr; nach N Scans in Folge wandert seine Kopie in den Gelöscht-Ordner oder wird gelöscht, wenn das erlaubt ist, im Rahmen der Batchgröße.">
</picture>

### Batchgröße

Die Batchgröße, *Maximale Änderungen je Modul und Lauf*, begrenzt die Änderungen eines Laufs: Exporte, Verschiebungen, das Entfernen ausgeschlossener Kopien, Verschiebungen in den Gelöscht-Ordner und Löschungen. Eine Änderung, die scheitert, nimmt ebenfalls ihren Platz ein, also lädt ein Lauf, der nicht schreiben kann, etwa einer gegen ein volles Kontingent oder einen Dateinamen, den Nextcloud ablehnt, nicht mehr Dokumente herunter, als die Batchgröße erlaubt. Ein Export, der scheitert, nimmt seinen Platz allerdings nur unter den Exporten ein, sodass Dokumente, deren Export immer wieder scheitert, nie die Verschiebungen von Dokumenten im Papierkorb oder von fehlenden Dokumenten aufhalten. Was nicht in einen Lauf passt, wartet auf den nächsten, und der Bericht zählt es als übersprungen. Das Hochladen aus dem Eingang hat einen eigenen Batch derselben Größe, sodass ein Lauf auch höchstens so viele Dateien zu Paperless hochlädt. Ebenso die Begleitdateien der [Metadaten neben dem Archiv](#metadaten-neben-dem-archiv): Ein Lauf schreibt höchstens so viele.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/diagrams/how-a-run-spends-its-batch.de.dark.svg">
  <img src="docs/images/diagrams/how-a-run-spends-its-batch.de.svg" alt="Wie ein Lauf seinen Batch verbraucht: Ein Dokument, das zu exportieren oder zu verschieben ist, wird übersprungen, wenn der Batch voll ist oder Nextcloud keine Dateien speichern kann. Sonst nimmt es einen Platz im Batch ein. Mit der Konfliktregel Überspringen wird eine Datei im Weg, die nicht die eigene Kopie der App ist, ohne Herunterladen als Konflikt gemeldet. Sonst wird das Dokument heruntergeladen und geschrieben oder verschoben. Ein Fehler wird gemeldet, und das Dokument wartet im nächsten Lauf hinter den anderen; ein volles Kontingent, ein schreibgeschützter oder ein nicht verfügbarer Speicher beendet außerdem die Exporte des Laufs.">
</picture>

Der Lauf nimmt die zuletzt hinzugefügten Dokumente zuerst, sodass ein neues Dokument nicht hinter einem Rückstau wartet, etwa dem ersten Export eines großen Archivs. Ein Dokument, dessen letzter Export oder letzte Verschiebung gescheitert ist, wartet hinter den anderen, das am längsten gescheiterte zuerst, sodass Dokumente, die immer wieder scheitern, den Rest nicht aufhalten. Kann Nextcloud gar keine Dateien im Archiv speichern, weil das Kontingent voll, das Archiv schreibgeschützt oder sein Speicher nicht verfügbar ist, hört der Lauf beim ersten Fehler auf zu exportieren und zu verschieben, und der Bericht zeigt einen Hinweis, dass Nextcloud keine Dateien im Archiv speichern kann (`EXPORT STOPPED` auf der Kommandozeile); die anderen Dokumente folgen im nächsten Lauf, und das Entfernen von Kopien, das Platz schafft, geht weiter. Nextcloud wendet auf die temporäre Datei eines Schreibvorgangs kein Kontingent an, also prüft die App den freien Platz vor jedem Schreiben selbst. Ein Pfad, den Nextcloud von sich aus ablehnt, etwa ein verbotener Dateiname oder ein schreibgeschützter Unterordner, betrifft nur sein Dokument. Mit der Dateikonflikt-Regel *Überspringen und Konflikt melden* wird eine Datei im Weg als Konflikt gemeldet, bevor das Dokument heruntergeladen wird, und ein Testlauf meldet ihn ebenfalls. Die eigene Kopie der App ist nicht im Weg: Der Zustand des Dokuments verzeichnet ihren Pfad, und sie hat noch den Inhalt des letzten Herunterladens, also bekommt sie den neuen Inhalt. Eine Kopie, die jemand in Nextcloud geändert hat, gilt als seine und bleibt, auch wenn das Dokument einen neuen Pfad bekommt; eine Kopie, die ein gescheitertes Schreiben unvollständig hinterlassen hat, ist noch die eigene der App und bekommt den Inhalt im nächsten Lauf.

### Hintergrundjobs

Die App verwendet den nativen Cron-Planer von Nextcloud. Der System-Cron muss zuverlässig laufen. Das eingestellte Intervall, von 5 Minuten bis zu einem Tag, setzt die App durch; jeder Lauf begrenzt seine Änderungen in Nextcloud und sein Hochladen zu Paperless jeweils auf die eingestellte [Batchgröße](#batchgröße). Nextcloud startet den Job der App höchstens alle fünf Minuten, also kann das Intervall nicht kürzer sein; ein Intervall unter fünf Minuten, das eine frühere Version gespeichert hat, wirkt wie fünf Minuten.

Das Intervall zählt ab dem Start oder dem Ende des letzten Laufs, je nachdem, was später ist, und es zählt nur ein Lauf, der synchronisiert: ein geplanter, einer von *Jetzt synchronisieren* oder einer von `occ paperless_sync:run`. Ein Testlauf ändert nichts, also verschiebt er den nächsten Lauf nicht; nur solange er noch läuft, wartet der nächste Lauf auf ihn, da beide die Sperre der Synchronisation brauchen.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/diagrams/when-the-background-job-starts-a-run.de.dark.svg">
  <img src="docs/images/diagrams/when-the-background-job-starts-a-run.de.svg" alt="Wann der Hintergrundjob einen Lauf startet: Der Cron von Nextcloud startet den Hintergrundjob der App höchstens alle fünf Minuten. Ist die geplante Synchronisation aus, tut er nichts. Sonst nimmt er den Start oder das Ende des letzten Laufs, der synchronisiert hat, eines geplanten oder eines von Hand, je nachdem, was später ist, und einen Testlauf nur, solange er noch läuft. Ist seitdem das Intervall vergangen, startet der Job einen Lauf; sonst wartet er auf den nächsten Hintergrundjob.">
</picture>

*Jetzt synchronisieren* reiht einmal einen zweiten Job ein, `OCA\PaperlessSync\Cron\ManualSyncJob`, den die nächsten Hintergrundjobs ausführen. Um ihn sofort zu starten, lass einen Worker von Nextcloud für ihn laufen, etwa in einem Dienst von systemd oder einem eigenen Container: `occ background-job:worker 'OCA\PaperlessSync\Cron\ManualSyncJob'`. Für einen einzelnen Lauf führt `occ background-job:worker --once 'OCA\PaperlessSync\Cron\ManualSyncJob'` den eingereihten Job aus und kehrt zurück. Solange ein anderer Lauf die Sperre hält, etwa ein Testlauf, wartet der Job auf die nächsten Hintergrundjobs.

Ein großes Archiv braucht anfangs viele Läufe: etwa die Zahl der Dokumente geteilt durch die Batchgröße, mal das Intervall. Mit den Standardwerten, einem Batch von 100 und 5 Minuten, brauchen 5.000 Dokumente 50 Läufe, vier Stunden oder mehr. Ein größerer Batch, bis zu 1.000, macht die erste Synchronisation schneller; jeder spätere Lauf ändert nur, was sich in Paperless geändert hat. Oder importiere es in einem Zug auf der [Kommandozeile](#kommandozeile), mit `occ paperless_sync:run --unlimited`.

<a id="changes-within-seconds"></a>

### Änderungen in Sekunden

Ein Lauf folgt dem Intervall, das mindestens 5 Minuten beträgt. Ein Arbeitsablauf von Paperless kann der App stattdessen sofort Bescheid geben: Bei *Dokument hinzugefügt* und *Dokument aktualisiert* ruft er den Webhook der App mit der ID des Dokuments auf, und die Kopie dieses Dokuments wird in Sekunden exportiert, verschoben oder entfernt, nach denselben Regeln wie in einem Lauf. Dafür läuft nichts neben Paperless, kein Container liest seinen Medienordner, und Nextcloud braucht kein Passwort: Paperless schickt die ID, und die App liest das Dokument mit ihrem eigenen Token.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/diagrams/how-a-change-in-paperless-reaches-nextcloud-within-seconds.de.dark.svg">
  <img src="docs/images/diagrams/how-a-change-in-paperless-reaches-nextcloud-within-seconds.de.svg" alt="Wie eine Änderung in Paperless Nextcloud in Sekunden erreicht: Ein Arbeitsablauf von Paperless mit den Auslösern Dokument hinzugefügt und Dokument aktualisiert ruft den Webhook der App mit der ID des Dokuments und dem Geheimnis auf. Ein Aufruf ohne das Geheimnis wird mit 401 abgelehnt, und Nextcloud verzögert die nächsten Aufrufe von dieser Adresse. Mit dem Geheimnis liest die App dieses Dokument mit ihrem eigenen Token aus Paperless und exportiert, verschiebt oder entfernt seine Kopie nach den Regeln eines Laufs, während Paperless auf die Antwort wartet. Solange ein anderer Lauf aktiv ist, oder wenn die Einstellungen es sagen, wartet das Dokument stattdessen auf die nächsten Hintergrundjobs. Dokumente im Papierkorb und gelöschte bleiben bei den geplanten Läufen.">
</picture>

So richtest du ihn ein:

1. Wähle in Abschnitt 6 der Einstellungen, *Änderungen in Sekunden*, **Geheimnis erstellen** und kopiere das Geheimnis: Die Seite zeigt es nur dieses eine Mal, und Nextcloud behält nur seinen SHA-256. Kopiere auch die **Webhook-URL**, etwa `https://cloud.example.com/index.php/apps/paperless_sync/api/v1/webhook`.
2. Öffne in Paperless **Arbeitsabläufe** und füge einen Arbeitsablauf mit den Auslösern **Dokument hinzugefügt** und **Dokument aktualisiert** hinzu, mit den Filtern, die du möchtest.
3. Füge die Aktion **Webhook** hinzu: die Webhook-URL als *Webhook-URL*, *Parameter für Webhook-Inhalt verwenden* und *Webhook-Payload als JSON senden* an, und den Parameter `document` mit dem Wert `{{ doc_id }}`. Paperless-ngx 2.x kennt kein `{{ doc_id }}`: Gib ihm `{{ doc_url }}`, die Adresse des Dokuments, aus der die App die ID nimmt.
4. Füge unter *Webhook-Kopfzeilen* `Authorization` mit dem Wert `Bearer`, einem Leerzeichen und dem Geheimnis hinzu, und speichere den Arbeitsablauf.
5. Ändere in Paperless den Titel eines Dokuments: Seine Kopie wird in Sekunden verschoben, und Abschnitt 6 zeigt den letzten Aufruf, *P123 verschoben*.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/settings-webhook-dark.png">
  <img alt="Der Abschnitt Änderungen in Sekunden direkt nach Geheimnis erstellen: die Webhook-URL und das neue Geheimnis, jeweils mit einem Knopf Kopieren, der Hinweis, das Geheimnis jetzt zu kopieren, weil es nur dieses eine Mal angezeigt wird, die Knöpfe Neues Geheimnis erstellen und Webhook ausschalten, der Schalter Dokument während des Aufrufs spiegeln, der an ist, und die eingeklappten Schritte in Paperless" src="docs/images/settings-webhook-light.png">
</picture>

Der Webhook nimmt `{"document": 123}` als JSON oder als Formular, und die Zahl darf Text oder die Adresse des Dokuments in Paperless sein. Er antwortet:

| Antwort | Wann |
| --- | --- |
| `200` mit `"result"`: `exported`, `moved`, `removed`, `unchanged` oder `excluded` | die Kopie des Dokuments ist aktuell; `excluded` für ein Dokument, das die Einstellungen auslassen, etwa eines im Posteingang von Paperless |
| `404` mit `"result": "missing"` | Paperless zeigt dem Konto das Dokument nicht: in seinem Papierkorb, gelöscht oder neu und noch nicht gespeichert; Paperless versucht es nach etwa 1, 2 und 4 Sekunden noch einmal, was ein neues Dokument erwischt, und den Papierkorb übernehmen die geplanten Läufe |
| `202` mit `"result": "queued"` | das Dokument wartet auf die nächsten Hintergrundjobs: Ein anderer Lauf hält die Sperre, oder *Dokument während des Aufrufs spiegeln* ist aus |
| `400` | der Aufruf nennt kein Dokument |
| `401` | das Geheimnis fehlt oder ist falsch |
| `429` | Nextcloud lehnt die Adresse von Paperless bis zu 30 Minuten lang ab, nach mehr als zehn Aufrufen mit falschem Geheimnis oder nach mehr als 120 Aufrufen in einer Minute |
| `409` mit `"result": "off"` | die geplante Synchronisation oder der Export ist aus, oder Paperless ist nicht verbunden |
| `500` mit `"result": "failed"` | die Kopie ist fehlgeschlagen, etwa wegen einer Datei im Weg; einen fehlgeschlagenen Aufruf versucht Paperless noch dreimal |

Mit *Dokument während des Aufrufs spiegeln* an, dem Standard, ändert sich die Kopie, während Paperless auf die Antwort wartet. Paperless gibt nach fünf Sekunden auf, etwa bei einem großen Download, und der Lauf wird trotzdem fertig. Aus, wartet jedes Dokument auf die nächsten Hintergrundjobs, die der System-Cron alle 5 Minuten startet, im Job `OCA\PaperlessSync\Cron\DocumentSyncJob`; Nextcloud reiht ihn für jedes Dokument einmal ein, wie oft Paperless auch aufruft, und solange ein anderer Lauf aktiv ist, versucht er es eine Minute später noch einmal. Ein Aufruf ändert nichts, solange die geplante Synchronisation aus ist, so wie bevor der erste Testlauf geprüft wurde.

Der Papierkorb, gelöschte Dokumente und der Posteingang bleiben bei den geplanten Läufen, die ganz Paperless sehen: Ein Dokument, das Paperless nicht zeigt, wartet auf die fehlenden Scans, die die [Löschsicherheit](#löschsicherheit) verlangt, also lass die geplante Synchronisation an. Ein Aufruf des Webhooks ist kein Lauf des Status: Die Einstellungsseite, die Status-API und der Zeitplan behalten den letzten Lauf, sodass Aufrufe nie den geplanten Lauf zurückhalten, der Dokumente im Papierkorb findet. Der Aktivitätenstrom des Zielbenutzers zeigt die Änderungen eines Aufrufs wie die eines Laufs.

Der Webhook ist die eine Adresse der App, die ohne Anmeldung bei Nextcloud antwortet. Ein Aufruf ohne das Geheimnis bekommt `401`, und der Brute-Force-Schutz von Nextcloud verzögert die nächsten Aufrufe dieser Adresse, bis zu 25 Sekunden, bis er sie nach mehr als zehn Fehlversuchen bis zu 30 Minuten lang ablehnt; solange die Aufrufe nur langsamer sind, beendet ein Aufruf mit dem Geheimnis die Verzögerung, und `occ security:bruteforce:reset <Adresse>` beendet eine Ablehnung sofort. Jede Adresse darf 120-mal in der Minute aufrufen. **Neues Geheimnis erstellen** ersetzt das Geheimnis sofort, und **Webhook ausschalten** löscht es. [Sicherheit](https://dennis-otto.github.io/paperless-sync/security.html#the-webhook-of-paperless) hat die Einzelheiten.

<!-- --8<-- [end:configuration-checklist] -->

## Status, Bericht und Protokoll

<!-- --8<-- [start:status] -->
Jeder Lauf hinterlässt sein Ergebnis an fünf Stellen, auch ein geplanter Lauf, dem niemand zugesehen hat:

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/diagrams/where-the-outcome-of-a-run-goes.de.dark.svg">
  <img src="docs/images/diagrams/where-the-outcome-of-a-run-goes.de.svg" alt="Wohin das Ergebnis eines Laufs geht: Ein Lauf, geplant, von Hand oder ein Testlauf, schreibt seinen Bericht. Der Status der App behält den Zustand, die Zeiten und den Bericht des letzten Laufs, die die Einstellungsseite bei jedem Besuch zeigt, die Fehler zuerst. Der Fehler eines Dokuments kommt als Warnung ins Nextcloud-Protokoll, wenn er zum ersten Mal auftritt oder sich ändert. Ein Lauf, der etwas geändert hat, und jeder Import, den Paperless abgeschlossen, nicht geschafft oder abgelehnt hat, fügt dem Aktivitätenstrom des Zielbenutzers einen Eintrag mit Links zu den Dateien hinzu, ein Testlauf aber keinen. Eine Spiegelung, die immer wieder scheitert, und jeder Import, der fehlgeschlagen ist oder abgelehnt wurde, schickt eine Benachrichtigung, ein Testlauf aber keine.">
</picture>

Oben auf der Einstellungsseite steht, wie der letzte Lauf endete, wann er fertig war, der letzte Lauf ohne Fehler, ungefähr der nächste geplante Lauf, da die Hintergrundjobs von Nextcloud ihn ein paar Minuten nach dem Intervall starten, und darunter das Paperless, das der letzte Lauf erreicht hat: seine Version, wie Paperless sie nennt, und die Version seiner API, die die App verwendet, etwa *Der letzte Lauf hat Paperless-ngx 3.3.0 über Version 9 seiner API erreicht.* Ein Lauf, der gescheitert ist oder mit Fehlern einzelner Dokumente endete, zeigt sie in einem Kasten darunter, den Fehler, der den Lauf gestoppt hat, zuerst, und der Bericht des letzten Laufs folgt auf die Knöpfe. Beides bleibt nach einem Neuladen, auch für einen geplanten Lauf. Ein Lauf von *Jetzt synchronisieren* ist *In der Warteschlange*, bis die Hintergrundjobs ihn starten. Ein Lauf, der endete, ohne zu sagen wie, weil PHP ihn gestoppt hat, etwa bei einem Neustart des Servers, ist *Unterbrochen*, sobald die Sperre des Laufs in Nextcloud weg ist und der Lauf 15 Minuten lang kein Lebenszeichen gegeben hat: Ein aktiver Lauf schreibt jede Minute eines; der nächste Lauf macht dort weiter, wo er aufgehört hat. Solange ein Lauf eingereiht oder aktiv ist, fragt die Seite nach seinem Status, nach jeder Änderung des Zustands zuerst jede Sekunde, dann alle fünf Sekunden und nach fünf Minuten alle dreißig Sekunden.
<!-- --8<-- [end:status] -->

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/status-error-dark.png">
  <img alt="Der Status von Paperless Sync nach einem Lauf mit einem Fehler: der Zustand Mit Fehlern abgeschlossen in Rot, die Zeiten des letzten Laufs, des letzten erfolgreichen Laufs und des nächsten geplanten Laufs, und darunter ein Kasten, der das Dokument nennt, dessen Herunterladen gescheitert ist" src="docs/images/status-error-light.png">
</picture>

<!-- --8<-- [start:status-report] -->
Der Bericht zeigt in der Sprache des Administrators, was der Lauf geändert hat, oder in einem Testlauf ändern würde, dessen Überschrift *Testlauf – nichts wurde geändert* sagt. Seine Zahlen zeigen die Arten von Änderungen, die vorkamen, und ein Satz die Dokumente, die unverändert blieben, und die, die der Lauf übersprungen hat, etwa für einen späteren Lauf oder wegen eines ausgeschlossenen Tags. Jede Änderung hat ein Abzeichen ihrer Art: rot für Fehler, fehlgeschlagene und abgelehnte Importe, bernsteinfarben für Löschungen, das Entfernen ausgeschlossener Kopien, Verschiebungen in den Gelöscht-Ordner und Hinweise wie einen unvollständigen Scan, grün für abgeschlossene Importe. Die Fehler kommen zuerst, dann die Hinweise, dann die Änderungen in der Reihenfolge des Laufs, und ein Filter über der Liste zeigt die Änderungen einer Art. Ein Administrator, der auch der Zielbenutzer ist und die Dateien daher öffnen darf, öffnet den Ordner einer Datei, die der Lauf geschrieben, verschoben oder am Ort gelassen hat, mit einem Klick auf ihren Pfad. **Bericht kopieren** kopiert die Überschrift, die Zahlen und die Zeilen auf Englisch, wie `occ paperless_sync:run` sie ausgibt, etwa `EXPORT P12: Dokumente/Paperless/…`, für einen Fehlerbericht. Die Gründe von Paperless und die Fehler eines Laufs bleiben in den Worten, in denen der Lauf sie bekommen hat.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/diagrams/how-the-report-shows-a-change.de.dark.svg">
  <img src="docs/images/diagrams/how-the-report-shows-a-change.de.svg" alt="Wie der Bericht eine Änderung zeigt: Für jede Änderung schreibt ein Lauf eine Zeile auf Englisch und daneben einen Eintrag mit der Art der Änderung, der ID des Dokuments, der Datei und einem Detail, etwa dem Grund von Paperless. Die Kommandozeile und Bericht kopieren nehmen die Zeilen. Die Einstellungsseite zeigt die Einträge in der Sprache des Administrators, die Fehler zuerst, dann die Hinweise, dann die Änderungen in der Reihenfolge des Laufs; jeden mit einem farbigen Abzeichen, einem Filter für jede Art und einem Link zum Ordner der Datei in Dateien, wenn der Administrator der Zielbenutzer ist und die Datei dort liegt.">
</picture>

Der Bericht zählt jede Änderung und listet bis zu 100 Zeilen davon auf. Ein Lauf mit mehr Zeilen listet seine Fehler, Löschungen, das Entfernen ausgeschlossener Kopien, Verschiebungen in den Gelöscht-Ordner und fehlgeschlagene Importe zuerst auf, an Stelle von Exporten, Verschiebungen und Importen, und sagt, wie viele Zeilen er auslässt, sodass ein Testlauf nie eine Löschung hinter hundert Importen versteckt; `SCAN INCOMPLETE` und `EXPORT STOPPED` bleiben immer. Der Status behält die ersten 50 Fehler des Laufs und zählt die übrigen.

Das Nextcloud-Protokoll hat eine Warnung, mit der ID des Dokuments und seinem Pfad, wenn ein Fehler eines Dokuments zum ersten Mal auftritt oder sich ändert; ein Fehler, der in jedem Lauf wiederkommt, wird einmal protokolliert. Ein Lauf, der als Ganzes scheitert, wird als Fehler protokolliert.
<!-- --8<-- [end:status-report] -->

### Aktivität

<!-- --8<-- [start:activity] -->
Die App *Aktivität* von Nextcloud erzählt dem Zielbenutzer, was Paperless Sync getan hat, in der Sprache des Benutzers, auch bei einem geplanten Lauf, dem niemand zugesehen hat:

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/diagrams/what-the-activity-stream-shows.de.dark.svg">
  <img src="docs/images/diagrams/what-the-activity-stream-shows.de.svg" alt="Was der Aktivitätenstrom zeigt: Ein Lauf, geplant oder von Hand, der etwas geändert hat, fügt für den Zielbenutzer einen Eintrag hinzu, mit den Zahlen seiner Änderungen und Links zu seinen ersten fünf Dateien und zum Archivordner. Jede Datei des Eingangs, die Paperless importiert hat, fügt einen Eintrag mit einem Link zu ihrem neuen Dokument in Paperless hinzu, jede Datei, die Paperless nicht importieren konnte, einen mit dem Grund und einem Link zur Datei im Fehlerordner, und jede Datei, die Paperless abgelehnt hat, einen mit dem Grund, da die Datei im Eingang bleibt. Ein Testlauf und ein Lauf ohne Änderungen fügen keinen hinzu.">
</picture>

- **Ein Lauf, der etwas geändert hat,** hat einen Eintrag, der seine Änderungen zählt und seine ersten fünf Dateien verlinkt: *Paperless Sync hat 6 Dokumente hinzugefügt oder aktualisiert und 1 Dokument nach _Gelöscht verschoben: a.pdf, b.pdf, c.pdf, d.pdf, e.pdf und 2 weitere*. Ein Link führt auch dann zu seiner Datei, wenn die Datei inzwischen wieder gewandert ist. Der Eintrag öffnet den Archivordner, dessen Reiter *Aktivität* in *Dateien* die Läufe auflistet.
- **Eine Datei des Eingangs, die Paperless importiert hat,** hat einen Eintrag mit einem Link zu ihrem neuen Dokument in Paperless: *scan.pdf wurde als P123 in Paperless importiert*. Der Link beginnt mit der gespeicherten Paperless-Adresse, also öffnet er sich nur dort, wo auch der Browser diese Adresse erreicht, nicht für einen Namen innerhalb von Docker wie `http://paperless:8000`.
- **Eine Datei, die Paperless nicht importieren konnte,** hat einen Eintrag mit dem Grund von Paperless und einem Link zur Datei im Fehlerordner, und **eine Datei, die Paperless abgelehnt hat,** hat einmal einen mit dem Grund: Die Datei bleibt im Eingang, bis sie sich ändert.

Ein Testlauf und ein Lauf ohne Änderungen fügen keinen Eintrag hinzu. Der Filter *Paperless Sync* in der Navigation der App Aktivität zeigt nur die Einträge der App, und in den persönlichen Einstellungen unter *Aktivität* kann sich jeder Benutzer sie auch per E-Mail oder als Benachrichtigungen schicken lassen, was bis dahin aus ist. Die eigenen Einträge der App *Dateien* von Nextcloud erzählen diese Geschichte nicht: Sie lassen die Dateien eines Laufs von Hand aus und zeigen die eines geplanten Laufs als Änderungen eines *entfernten Kontos*.
<!-- --8<-- [end:activity] -->

### Benachrichtigungen

<!-- --8<-- [start:notifications] -->
Eine Spiegelung, die stehen bleibt, ist der schlimmste Fehler einer Sicherung, und niemand schaut jeden Tag auf die Einstellungsseite. Paperless Sync sagt es von selbst, an drei Stellen, ohne einen weiteren Dienst:

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/diagrams/when-the-administrators-hear-of-a-failing-mirror.de.dark.svg">
  <img src="docs/images/diagrams/when-the-administrators-hear-of-a-failing-mirror.de.svg" alt="Wann die Administratoren von einer scheiternden Spiegelung erfahren: Nach jedem Lauf, der kein Testlauf ist, zählt Paperless Sync die Läufe in Folge, die fehlgeschlagen oder mit Fehlern abgeschlossen sind. Ein erfolgreicher Lauf beendet die Reihe und entfernt die Benachrichtigung. Nach dem dritten Lauf in Folge, oder sobald der erste von ihnen vor einem Tag geendet hat, bekommt jeder Administrator eine Benachrichtigung mit dem Fehler des letzten Laufs und einem Link zu den Einstellungen, einmal für die ganze Reihe. Die Einrichtungsprüfungen unter Administration, Übersicht und die Status-API zeigen denselben Zustand jederzeit.">
</picture>

- **Benachrichtigungen.** Drei Läufe in Folge, die fehlgeschlagen oder mit Fehlern abgeschlossen sind, oder weniger, sobald der erste von ihnen vor einem Tag geendet hat, etwa bei einem Intervall von einem Tag, schicken jedem Administrator eine Benachrichtigung mit den ersten 200 Zeichen des Fehlers und einem Link zu den Einstellungen: *Paperless Sync ist 3 Mal in Folge fehlgeschlagen*. Sie kommt einmal für die ganze Reihe, auch per E-Mail oder als Push-Benachrichtigung der Nextcloud-Apps, wo der Benutzer diese eingeschaltet hat, und der nächste erfolgreiche Lauf entfernt sie. Zwei fehlgeschlagene Läufe, etwa während eines Neustarts von Paperless, benachrichtigen niemanden. Der Zielbenutzer bekommt eine Benachrichtigung für jede Datei des Eingangs, die Paperless nicht importieren konnte oder abgelehnt hat, mit ihrem Grund. Ein Testlauf benachrichtigt niemanden.
- **Einrichtungsprüfungen.** *Administration → Übersicht* und `occ setupchecks` zeigen zwei Prüfungen der App. *Paperless Sync* ist ein Fehler, wenn der letzte Lauf gescheitert ist, und eine Warnung, wenn er mit Fehlern abgeschlossen ist oder wenn drei Intervalle lang kein Lauf erfolgreich war, mindestens eine Stunde, etwa wenn die Hintergrundjobs stehen geblieben sind. *Hintergrundjob von Paperless Sync* warnt, wenn geplante Läufe an sind, Nextcloud seine Hintergrundjobs aber mit AJAX oder Webcron ausführt, die sie nur starten, während jemand Nextcloud benutzt. Die Einstellungsseite zeigt ihre Warnungen oben.
- **Die Status-API** gibt einer Überwachung, etwa Uptime Kuma oder Home Assistant, den Zustand der Spiegelung. Nur Administratoren dürfen sie lesen; gib der Überwachung ein App-Passwort eines Administrators, aus *Persönliche Einstellungen → Sicherheit*, hier in der Variablen `APP_PASSWORD`:

```sh
curl --user "admin:$APP_PASSWORD" --header "OCS-APIRequest: true" \
  "https://cloud.example.com/ocs/v2.php/apps/paperless_sync/api/v1/status?format=json"
```

Sie antwortet, mit gekürzten Zahlen in `summary`:

```json
{"ocs": {"meta": {"status": "ok", "statuscode": 200, "message": "OK"}, "data": {
  "healthy": false, "health": "failed", "enabled": true, "state": "failed",
  "lastStarted": 1787911200, "lastEnded": 1787911205, "lastSuccess": 1787900400, "nextRun": 1787912100,
  "error": "Paperless answered 401: Invalid token.", "failedRuns": 3, "failingSince": 1787909405,
  "summary": {"dryRun": false, "exported": 0, "errors": 0},
  "paperlessVersion": "", "paperlessApiVersion": ""}}}
```

`health` ist `failed` oder `errors`, solange der letzte Lauf gescheitert oder mit Fehlern abgeschlossen ist, auch einer von Hand, während geplante Läufe aus sind, dann `off`, solange sie aus sind, `waiting` vor dem ersten erfolgreichen Lauf, `stale`, wie es die Einrichtungsprüfung nennt, oder `ok`; `healthy` ist false für `failed`, `errors` und `stale`. Die Zeiten sind Unix-Zeitstempel, `summary` enthält die Zahlen des letzten Laufs, auch eines Testlaufs, und `error` den Fehler des letzten Laufs der Reihe, der gescheitert ist. In Uptime Kuma schlägt ein Monitor vom Typ *HTTP(s) - Json Query* mit dieser Adresse, dem Header `{"OCS-APIRequest": "true"}`, Basic-Authentifizierung, der Abfrage `ocs.data.healthy` und dem erwarteten Wert `true` Alarm. In Home Assistant tut ein RESTful Binary Sensor dasselbe:

```yaml
rest:
  - resource: https://cloud.example.com/ocs/v2.php/apps/paperless_sync/api/v1/status?format=json
    headers:
      OCS-APIRequest: "true"
    authentication: basic
    username: admin
    password: !secret nextcloud_app_password
    scan_interval: 300
    binary_sensor:
      - name: Paperless Sync Problem
        device_class: problem
        value_template: "{{ not value_json.ocs.data.healthy }}"
```

Die Benachrichtigung einer scheiternden Spiegelung geht an die Gruppe `admin`. Eine andere Gruppe, etwa eine für die Menschen, die sich um das Archiv kümmern, bekommt sie an ihrer Stelle mit `occ config:app:set paperless_sync notification_group --value=archive-admins`; eine Gruppe, die es nicht gibt, überlässt sie den Administratoren, mit einer Warnung im Protokoll.
<!-- --8<-- [end:notifications] -->

## Kommandozeile

<!-- --8<-- [start:command-line] -->
Das `occ` von Nextcloud führt die App aus der Shell aus, für Skripte, eigene Cron-Jobs und Überwachung, etwa in einer Docker- oder All-in-One-Installation: `docker exec --user www-data nextcloud php occ …`, oder `docker exec --user www-data nextcloud-aio-nextcloud php occ …` für All-in-One.

| Befehl | Was er tut |
| --- | --- |
| `occ paperless_sync:run` | Ein Lauf jetzt, in der Shell, so lange er dauert, mit seinem Bericht. Er macht höchstens so viele Änderungen wie die Batchgröße der Einstellungen, wie ein geplanter Lauf. |
| `occ paperless_sync:run --dry-run` | Die Änderungen, die ein Lauf machen würde, ohne sie zu machen. |
| `occ paperless_sync:run --batch-size=1000` | Ein Lauf mit eigener Batchgröße; `--unlimited` macht jede fällige Änderung, etwa für den ersten Import eines großen Archivs. |
| `occ paperless_sync:status` | Der Status des letzten Laufs, wie ihn die Einstellungsseite zeigt, mit seinem Bericht. `--exit-code` sagt mit dem Exit-Code, wie er endete, für eine Prüfung einer Überwachung. |
| `occ paperless_sync:forget 123 P124` | Die nächsten Läufe laden diese Dokumente erneut herunter und schreiben jedes an die Stelle seiner Kopie, etwa nachdem eine Kopie beschädigt wurde; `--all` für jede Kopie, etwa nach einem Vorfall des Speichers. Eine Kopie behält ihren Pfad, ihre Datei-ID, ihre Freigaben und ihre Versionen, und eine Bearbeitung in Nextcloud bekommt wieder den Inhalt von Paperless. |

`--output=json` gibt den Bericht eines Laufs oder den Status als das JSON aus, das die Einstellungsseite liest, in einer Zeile, und `--output=json_pretty` eingerückt; Meldungen über Fehler gehen auf die Standardfehlerausgabe. Ein Lauf auf der Kommandozeile zählt im Status und im Protokoll wie jeder andere Lauf und hält dieselbe Sperre, also läuft er nie neben einem geplanten Lauf oder einem von *Jetzt synchronisieren*.

| Exit-Code | `paperless_sync:run` | `paperless_sync:status --exit-code` | `paperless_sync:forget` |
| --- | --- | --- | --- |
| 0 | Der Lauf ist abgeschlossen. | Der letzte Lauf ist abgeschlossen, oder noch keiner hat geendet. | Jedes Dokument wurde vergessen. |
| 1 | Er ist mit Fehlern einzelner Dokumente oder Dateien abgeschlossen; sein Bericht listet sie auf. | Er ist mit Fehlern abgeschlossen. | Einige der Dokumente haben keine Kopie. |
| 2 | Er ist gescheitert oder nicht gestartet: eine ungültige Option, eine unvollständige Konfiguration oder ein unerreichbares Paperless. | Er ist gescheitert oder wurde unterbrochen. | Er ist gescheitert. |
| 3 | Ein anderer Lauf ist aktiv. | — | Ein Lauf ist aktiv. |

Der erste Import eines großen Archivs in einem Zug: Ein Lauf ohne Grenze kann Stunden dauern. Nextcloud lässt die Sperre eines Laufs nach einer Stunde verfallen (`filelocking.ttl` in `config.php`), aber der Lauf gibt jede Minute ein Lebenszeichen, das geplante Läufe und die von *Jetzt synchronisieren* davon abhält, neben ihm zu starten.

```sh
occ paperless_sync:run --dry-run --unlimited   # wie viele Änderungen fällig sind
occ paperless_sync:run --unlimited             # alle machen
```

Wie ein Lauf von Hand startet, auf der Seite und auf der Kommandozeile:

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/diagrams/how-a-run-by-hand-starts.de.dark.svg">
  <img src="docs/images/diagrams/how-a-run-by-hand-starts.de.svg" alt="Wie ein Lauf von Hand startet: Testlauf starten auf der Einstellungsseite läuft in der Anfrage, ändert nichts, macht höchstens einen Batch an Änderungen und antwortet mit seinem Bericht. Jetzt synchronisieren reiht einen Hintergrundjob ein und antwortet sofort; die Hintergrundjobs von Nextcloud führen ihn aus, innerhalb von fünf Minuten mit dem System-Cron oder sofort mit einem Worker für den Job, so wie sie den geplanten Job ausführen. occ paperless_sync:run läuft in der Shell, so lange es dauert, und gibt seinen Bericht mit einem Exit-Code aus. Jeder Lauf nimmt dieselbe Sperre und schreibt den Status, dem die Einstellungsseite folgt.">
</picture>
<!-- --8<-- [end:command-line] -->

## FAQ und Fehlerbehebung

Die [FAQ](docs/faq.de.md) beantworten die häufigen Fragen, etwa was mit einer Bearbeitung einer gespiegelten Datei geschieht, wohin gelöschte Dateien gehen und wie lange die erste Synchronisation dauert. Die [Fehlerbehebung](docs/troubleshooting.de.md) führt von einer Fehlermeldung, einem Lauf, der scheitert, oder einer Datei, die fehlt, zu ihrer Ursache und ihrer Lösung.

<!-- --8<-- [start:help] -->

## Hilfe und Feedback

- **Eine Frage** zur Einrichtung, oder ob die App etwas kann: Frag in den [Q&A-Diskussionen](https://github.com/Dennis-Otto/paperless-sync/discussions/categories/q-a), wo die Antwort für die nächste Person bleibt, die fragt; Englisch hilft den meisten Lesern, Deutsch ist willkommen.
- **Ein Fehler**: Öffne einen [Fehlerbericht](https://github.com/Dennis-Otto/paperless-sync/issues/new?template=bug_report.yml) mit den Versionen der App und von Nextcloud. Melde eine Sicherheitslücke stattdessen [vertraulich](https://github.com/Dennis-Otto/paperless-sync/security/advisories/new).
- **Eine Idee**: Schlag sie in einem [Funktionswunsch](https://github.com/Dennis-Otto/paperless-sync/issues/new?template=feature_request.yml) vor.
- **Wenn die App dir hilft**: [Bewerte sie im Nextcloud App Store](https://apps.nextcloud.com/apps/paperless_sync), damit andere sie finden, gib dem [Repository](https://github.com/Dennis-Otto/paperless-sync) einen Stern oder [unterstütze seine Entwicklung](https://github.com/sponsors/Dennis-Otto).

<!-- --8<-- [end:help] -->

## Entwicklung

Beiträge sind willkommen. [CONTRIBUTING.md](CONTRIBUTING.md) sagt, wie eine Änderung hineinkommt, und [Development](docs/development.md), wie man die App baut, prüft und testet: `scripts/check.sh`, die End-to-End-Tests in Docker gegen jede unterstützte Version von Nextcloud, die Bilder der Dokumentation, die Website und den Release-Prozess. [Automation](docs/automation.md) zeigt die Bots, die die App prüfen, veröffentlichen und pflegen. Das Projekt folgt [GOVERNANCE.md](GOVERNANCE.md), [SUPPORT.md](SUPPORT.md) und dem [Verhaltenskodex](CODE_OF_CONDUCT.md); diese Seiten sind auf Englisch. Eine Änderung an `README.md` oder an einer Seite der Website mit deutscher Fassung ändert auch die deutsche, etwa diese Datei, und `scripts/check.sh` prüft, dass beide dieselben Überschriften, Ausschnitte und Diagramme haben.

## Qualität und Sicherheit

[![CI](https://github.com/Dennis-Otto/paperless-sync/actions/workflows/ci.yml/badge.svg)](https://github.com/Dennis-Otto/paperless-sync/actions/workflows/ci.yml)
[![Docker E2E](https://github.com/Dennis-Otto/paperless-sync/actions/workflows/e2e.yml/badge.svg)](https://github.com/Dennis-Otto/paperless-sync/actions/workflows/e2e.yml)
[![Secret scan](https://github.com/Dennis-Otto/paperless-sync/actions/workflows/secret-scan.yml/badge.svg)](https://github.com/Dennis-Otto/paperless-sync/actions/workflows/secret-scan.yml)
[![CodeQL](https://github.com/Dennis-Otto/paperless-sync/actions/workflows/codeql.yml/badge.svg)](https://github.com/Dennis-Otto/paperless-sync/actions/workflows/codeql.yml)
[![SBOM](https://github.com/Dennis-Otto/paperless-sync/actions/workflows/sbom.yml/badge.svg)](https://github.com/Dennis-Otto/paperless-sync/actions/workflows/sbom.yml)
[![OpenSSF Scorecard](https://api.scorecard.dev/projects/github.com/Dennis-Otto/paperless-sync/badge)](https://scorecard.dev/viewer/?uri=github.com/Dennis-Otto/paperless-sync)
[![OpenSSF Best Practices](https://www.bestpractices.dev/projects/15277/badge)](https://www.bestpractices.dev/projects/15277)
[![REUSE](https://api.reuse.software/badge/github.com/Dennis-Otto/paperless-sync)](https://api.reuse.software/info/github.com/Dennis-Otto/paperless-sync)

Eine Änderung erreicht `main` nur, wenn die Unit-Tests, die jede Zeile der App ausführen, bestehen, zusammen mit Psalm, CodeQL, dem Secret-Scan, der Prüfung der Abhängigkeiten, der Lizenzprüfung jeder Datei und, wenn die Änderung die App betreffen kann, den End-to-End-Tests in Docker gegen jede unterstützte Version von Nextcloud. Jede Woche führen Vertragstests die App gegen ein echtes Paperless-ngx 2.x und 3.x aus, dessen aufgezeichnete Antworten die Unit-Tests lesen. Jedes Release ist signiert, mit einer SBOM und signierter Build-Provenienz, und wird danach im App Store geprüft; [Automation](docs/automation.md) zeigt jeden Workflow dahinter. Das [Sicherheitsdesign](docs/security.md) sagt, was die App schützt und wie; melde eine Sicherheitslücke [vertraulich](https://github.com/Dennis-Otto/paperless-sync/security/advisories/new).

## Lizenz

[AGPL-3.0-or-later](LICENSE)
