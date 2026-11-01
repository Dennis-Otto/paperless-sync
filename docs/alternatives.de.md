---
title: "Paperless-ngx und Nextcloud: die Wege, sie zu verbinden"
description: "Paperless-ngx und Nextcloud: Paperless Sync im Vergleich mit der Paperless-Integration von Nextcloud, dem WebDAV-Container paperless-nextcloud-sync, rclone-Skripten, einem Mount des Medienordners mit externem Speicher und dem Consume-Ordner, der Mail und den Apps von Paperless, mit Anleitungen für den Umstieg ohne Duplikate."
hide:
  - navigation
---

<!-- The comparison as of the date below, the German twin of alternatives.md. When a release
of Paperless Sync changes a row of the table, change the row in both pages; the sources at
the end say where the facts of the others come from. -->

<!-- The title of the front matter is the one that search engines and the browser tab show; the navigation calls the page Vergleich. -->
<!-- markdownlint-disable-next-line MD025 -->
# Paperless-ngx und Nextcloud: die Wege, sie zu verbinden

[← README](https://github.com/Dennis-Otto/paperless-sync/blob/main/README.de.md) · [Anleitung](guide.de.md) · [Automatisierung](automation.md) · [Architektur](architecture.md)

Paperless-ngx hält deine Dokumente, und Nextcloud ist der Ort, an dem du mit deinen Dateien arbeitest, im Web, am Computer und auf dem Handy. Sechs Wege bringen die beiden zusammen, und sie lösen verschiedene Probleme: Manche bringen die Dokumente von Paperless nach Nextcloud, manche bringen Dateien nach Paperless, und manche passen gut zusammen. Diese Seite vergleicht sie, wie sie im Oktober 2026 sind, hilft dir bei der Wahl und zeigt, wie du von einem Mount des Medienordners oder vom WebDAV-Container zu Paperless Sync umziehst, ohne Duplikate.

## Welcher passt?

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/diagrams/which-way-to-connect-paperless-ngx-and-nextcloud-fits.de.dark.svg">
  <img src="images/diagrams/which-way-to-connect-paperless-ngx-and-nextcloud-fits.de.svg" alt="Welcher Weg, Paperless-ngx und Nextcloud zu verbinden, passt: Wenn nur Dateien nach Paperless sollen, sendet die Paperless-Integration von Nextcloud einzelne Dateien von Hand, der Eingang von Paperless Sync nimmt jede Datei, die in einen Ordner von Nextcloud kommt, auf Wunsch mit ausgeschaltetem Export, und der Consume-Ordner, der Mail-Abruf und die Apps von Paperless nehmen Scans von einem Handy, einer Mail oder einem Scanner. Wenn die Dokumente von Paperless in Nextcloud Dateien erscheinen sollen, lautet die Frage, ob du in deiner Nextcloud Apps installieren kannst. Wenn nicht, etwa bei einer gehosteten Nextcloud, kopieren der WebDAV-Container oder ein rclone-Skript sie über WebDAV. Wenn ja und eine schreibgeschützte Sicht auf die eigenen Dateien von Paperless genügt, passt ein Mount mit externem Speicher, eines lokalen Ordners oder über SMB oder SFTP; für ein aufgeräumtes Archiv nach Korrespondent, Typ und Jahr, einen Eingang zu Paperless und Löschungen erst nach einer Bestätigung Paperless Sync. Die Fragen sind orange, die Optionen grün.">
</picture>

## Die Optionen auf einen Blick

| | [Paperless Sync](#paperless-sync) | [Paperless-Integration](#die-paperless-integration-von-nextcloud) | [WebDAV-Container](#der-webdav-container-paperless-nextcloud-sync) | [rclone-Skript](#ein-skript-mit-rclone-oder-webdav) | [Mount](#ein-mount-des-medienordners-mit-externem-speicher) | [Paperless selbst](#die-eigenen-wege-von-paperless) |
| --- | --- | --- | --- | --- | --- | --- |
| **Richtung** | Paperless → Nextcloud, und ein Eingangsordner, ein Klick in Dateien oder eine Regel von Flow → Paperless | Nextcloud → Paperless | Paperless → Nextcloud | Paperless → Nextcloud | die eigenen Dateien von Paperless, in Nextcloud gezeigt | nach Paperless |
| **Läuft** | in Nextcloud, als Hintergrundjob, oder in der Shell mit `occ` | in Nextcloud, wenn jemand klickt | in einem privilegierten Container neben Paperless | als geplanter Job auf dem Server von Paperless | in Nextcloud | in Paperless |
| **Liest Paperless über** | seine REST-API, mit einem Token | seine REST-API, mit einem Token | seinen Medienordner, nur lesend | seinen Medienordner | seinen Medienordner, lokal oder über SMB oder SFTP | — |
| **Gespeichertes Nextcloud-Passwort** | keines | keines | ein Passwort oder App-Passwort eines Kontos | ein App-Passwort, in `rclone.conf` | keines bei einem lokalen Ordner | — |
| **Ordner** | seine eigene Pfadvorlage: Korrespondent, Typ, Jahr oder beliebige Metadaten | — | `PAPERLESS_FILENAME_FORMAT` von Paperless | `PAPERLESS_FILENAME_FORMAT` von Paperless | `PAPERLESS_FILENAME_FORMAT` von Paperless | — |
| **Neuer Titel oder Korrespondent** | die Datei wird umbenannt oder verschoben und behält ihre Freigaben, Kommentare und Versionen | — | folgt, da Paperless seine Datei umbenennt | ein neuer Upload, die alte Datei gelöscht | beim nächsten Scan gesehen, als neue Datei ohne die Freigaben der alten | — |
| **Verzögerung** | Sekunden mit dem Webhook eines Arbeitsablaufs von Paperless, sonst das Intervall, standardmäßig 5 Minuten; sobald das Dokument den Posteingang von Paperless verlassen hat | ein Klick | Sekunden | der Zeitplan des Jobs | bis Nextcloud den Ordner scannt | — |
| **In Paperless gelöschtes Dokument** | seine Kopie wandert in einen eigenen Ordner und wird erst gelöscht, wenn du es erlaubst, nachdem vollständige Scans es bestätigen | — | sofort gelöscht | sofort gelöscht durch `rclone sync` | sofort weg | — |
| **Vorschau der Änderungen** | ein Testlauf, auf der Einstellungsseite oder mit `occ` | — | — | `--dry-run` | — | — |
| **Bearbeitungen in Nextcloud** | bleiben in der Kopie, bis sich ihr Dokument in Paperless ändert, erreichen Paperless nie | — | beim nächsten Start überschrieben | beim nächsten Lauf überschrieben | ändern die eigenen Dateien von Paperless, außer der Mount ist schreibgeschützt | — |
| **Paperless auf einem anderen Server** | ja, über HTTP oder HTTPS | ja | braucht seinen Medienordner | braucht seinen Medienordner | über SMB oder SFTP | — |
| **Benutzer** | ein Archiv eines Nextcloud-Benutzers, mit anderen geteilt, oder für jeden Benutzer eines mit den Dokumenten, die ihm in Paperless gehören | jeder Benutzer mit einem eigenen Token | ein Konto, mit anderen geteilt | ein Konto, mit anderen geteilt | die Benutzer und Gruppen des Mounts | — |
| **Braucht** | Nextcloud <span data-app="nextcloud">33–35</span> mit System-Cron und seine Administration | ein Paperless-Token für jeden Benutzer | Docker, FUSE und einen privilegierten Container | rclone und einen Planer | die App External storage support, mit eingeschaltetem Teilen | — |
| **Gepflegt von** | Dennis Otto, mit signierten Releases | Nextcloud GmbH, „with limited effort“ | Flo-R1der | dir selbst | Nextcloud | dem Team von Paperless-ngx |

## Wann was passt

### Paperless Sync

Wähle es, wenn du die fertigen Dokumente von Paperless als aufgeräumtes Archiv in Nextcloud haben möchtest, nach Korrespondent, Typ und Jahr oder einem beliebigen Pfad aus ihren Metadaten, das sich selbst aktuell hält, und wenn auch Dateien aus Nextcloud an Paperless gehen sollen. Es läuft in Nextcloud: kein Container, kein Zugriff auf den Medienordner von Paperless, kein Nextcloud-Passwort, und Paperless darf auf einem anderen Server laufen. Jede Kopie bleibt dieselbe Datei in Nextcloud, mit ihren Freigaben, Kommentaren und Versionen, wenn ihr Dokument einen neuen Titel oder einen neuen Inhalt bekommt. Eine Löschung in Paperless nimmt eine Kopie nie sofort mit: Die Kopie wandert in einen eigenen Ordner und wird erst gelöscht, wenn du es erlaubst und vollständige Scans von Paperless es bestätigen. Ein Testlauf zeigt jede Änderung, bevor sie geschieht. Mit dem [Webhook](guide.de.md#changes-within-seconds) eines Arbeitsablaufs von Paperless kommt ein neues oder geändertes Dokument innerhalb von Sekunden an. Die [Anleitung](guide.de.md) hat die Einzelheiten.

Es ist nicht die richtige Wahl, wenn:

- du in deiner Nextcloud keine Apps installieren kannst, etwa bei einer Nextcloud eines Anbieters: Der [WebDAV-Container](#der-webdav-container-paperless-nextcloud-sync) und [rclone](#ein-skript-mit-rclone-oder-webdav) arbeiten über WebDAV;
- deine Nextcloud älter ist als die Versionen in den [Voraussetzungen](guide.de.md#requirements);
- jeder Benutzer sich mit einem eigenen Paperless-Token verbinden soll: Ein Administrator verbindet ein Paperless mit einem Token, und das [Eigentum je Benutzer](guide.de.md#per-user-ownership) gibt jedem Benutzer die Dokumente, die ihm dort gehören;
- eine zweite Kopie jedes Dokuments zu viel für die Festplatte ist: Das Archiv ist eine Kopie, als PDF/A oder Originaldatei.

### Die Paperless-Integration von Nextcloud

Die App [Paperless integration](https://apps.nextcloud.com/apps/integration_paperless) der Nextcloud GmbH fügt der App Dateien die Aktion *Upload to Paperless* hinzu. Jeder Benutzer trägt die Adresse von Paperless und ein eigenes API-Token in seinen persönlichen Einstellungen ein und sendet die Dateien, die er auswählt. Sie bringt nichts von Paperless nach Nextcloud, und ihr README sagt, dass sie mit begrenztem Aufwand gepflegt wird.

Wähle sie, wenn jede Person Dateien mit ihrem eigenen Paperless-Konto senden soll. Paperless Sync hat [*An Paperless senden*](guide.de.md#send-from-files) ebenfalls im Menü von Dateien, mit dem Konto der App, für eine Gruppe, die die Administratoren wählen, und *In Paperless öffnen* für die Kopien seines Archivs; die beiden passen trotzdem gut zusammen: Die Integration sendet eine Datei mit dem Konto ihres Benutzers, der Eingang von Paperless Sync nimmt, was in einem Ordner landet, und Paperless Sync bringt die fertigen Dokumente zurück.

### Der WebDAV-Container paperless-nextcloud-sync

[paperless-nextcloud-sync](https://github.com/Flo-R1der/paperless-nextcloud-sync) von Flo-R1der ist ein Docker-Container neben Paperless. Er liest den Medienordner von Paperless, bindet einen Ordner von Nextcloud über WebDAV mit FUSE ein, wofür der Container privilegiert läuft, und kopiert jede Änderung innerhalb von Sekunden, mit einem Dateiwächter. Die Ordner sind die von `PAPERLESS_FILENAME_FORMAT`. Er braucht das Passwort oder ein App-Passwort eines Nextcloud-Kontos, und eine Datei, die den Medienordner verlässt, verlässt Nextcloud sofort; bei seinem Start löscht er außerdem jede Datei seines Ordners, die Paperless nicht hat.

Wähle ihn, wenn du in Nextcloud keine Apps installieren kannst oder wenn deine Cloud ownCloud, OpenCloud oder ein anderer WebDAV-Dienst ist.

### Ein Skript mit rclone oder WebDAV

Ein geplantes `rclone sync` auf dem Server von Paperless kopiert den Medienordner über WebDAV nach Nextcloud, und das tut auch ein Skript mit `rsync` auf einen WebDAV-Mount. Alles liegt in deiner Hand: jede Cloud, die rclone kennt, jeder Zeitplan, `--dry-run` für eine Vorschau und `rclone copy` statt `rclone sync`, wenn nichts gelöscht werden soll. Die Ordner sind die von `PAPERLESS_FILENAME_FORMAT`, ein umbenanntes Dokument ist ein neuer Upload, und das App-Passwort liegt in `rclone.conf`.

Wähle es, wenn du das Skript selbst schreiben und pflegen möchtest.

### Ein Mount des Medienordners mit externem Speicher

Die App External storage support von Nextcloud bindet den Medienordner von Paperless, `documents/archive` oder `documents/originals`, als Ordner für Benutzer und Gruppen ein: einen lokalen Ordner, wenn beide auf einem Server laufen, oder eine Freigabe über SMB oder SFTP. Es gibt keine Kopie, weil Nextcloud die Dateien von Paperless selbst zeigt. Das ist auch der Haken. Paperless warnt, dass eine von Hand verschobene oder umbenannte Datei in seinem Medienordner als fehlend gemeldet wird, also binde ihn schreibgeschützt ein. Und Nextcloud erfährt von dem, was Paperless ändert, erst, wenn es den Ordner scannt: wenn jemand ihn öffnet, oder mit `occ files_external:scan` aus einem Cron-Job. Bis dahin verpassen die Desktop-Clients eine Änderung tief im Baum, und eine Datei, die Paperless umbenennt, für einen neuen Titel, kommt als neue Datei zurück, ohne die Freigaben, Tags und Kommentare der alten. Die Ordner sind die von `PAPERLESS_FILENAME_FORMAT`; ohne es sind die Dateien nach ihrer Nummer benannt, etwa `0000123.pdf`.

Wähle ihn, wenn beide auf einem Server laufen, eine schreibgeschützte Sicht genügt und eine zweite Kopie zu viel Speicherplatz belegen würde.

### Die eigenen Wege von Paperless

Um Dokumente nach Paperless zu bringen, hat Paperless seinen Consume-Ordner, der jede Datei nimmt, die in ihn kommt, den Mail-Abruf, der die Anhänge von Mails nach Regeln nimmt, das Hochladen in seiner Weboberfläche und die Apps seiner Community für Handys, die das [Wiki von Paperless-ngx](https://github.com/paperless-ngx/paperless-ngx/wiki/Related-Projects) auflistet. Sie passen zu jeder Option oben.

Der Eingang von Paperless Sync ist ein Consume-Ordner in Nextcloud: Eine Datei, die ihn aus der Desktop- oder Handy-App von Nextcloud, über eine Freigabe oder von einem Scanner erreicht, der in Nextcloud speichert, geht an Paperless, ohne einen Mount des Consume-Ordners, und ein fehlgeschlagener Import landet in einem Fehlerordner, neben dem Grund.

### Zusammen

- **Paperless Sync und [Paperless Unified Search](https://github.com/Dennis-Otto/paperless-unified-search)**: Die Suche von Nextcloud findet den Text, den Paperless in einem Dokument erkannt hat, und öffnet seine Kopie im Archiv.
- **Paperless Sync und die Paperless-Integration**: Dateien von Hand mit dem Paperless-Konto jedes Benutzers über die Integration, mit dem Konto der App über *An Paperless senden* von Paperless Sync, und Ordner mit dem Eingang von Paperless Sync.
- **Paperless Sync und der Mail-Abruf und die Apps von Paperless**: Ein Dokument, das Paperless auf irgendeinem Weg erreicht, erscheint mit dem nächsten Lauf in Nextcloud.

## Zu Paperless Sync umziehen

Am Ende beider Anleitungen steht eine Kopie jedes fertigen Dokuments, in den Ordnern, die die Menschen kennen, und der alte Weg ist abgeschaltet. Standardmäßig lässt Paperless Sync die Dokumente aus, die noch im Posteingang von Paperless sind, und die mit einem ausgeschlossenen Tag, und jeder Lauf zählt sie als übersprungen; der Mount und der Container zeigen sie, also hat jede Anleitung einen Schritt, um über sie zu entscheiden.

### Von einem Mount mit externem Speicher

Der Mount zeigt die Dateien von Paperless in den Ordnern von `PAPERLESS_FILENAME_FORMAT`. Paperless Sync baut dieselben Ordner aus den Metadaten von Paperless, also behalte den Mount, bis sein Archiv vollständig ist:

1. **Schlage das Format nach.** Finde `PAPERLESS_FILENAME_FORMAT` in der Konfiguration von Paperless, etwa in `docker-compose.env`, und sieh nach, ob der Mount `documents/archive` zeigt, die PDF/A-Versionen, oder `documents/originals`.
2. **Schreibe die Pfadvorlage.** Nimm das Format und übersetze seine Platzhalter mit der Tabelle unten. Beende den letzten Teil mit einem Leerzeichen und `[P{{ id }}]{{ extension }}`: Jeder Dateiname von Paperless Sync trägt die Markierung seines Dokuments, und Paperless fügt die Erweiterung von selbst hinzu. Das Format `{{ created_year }}/{{ correspondent }}/{{ title }}` wird zur Vorlage `{{ created_year }}/{{ correspondent }}/{{ title }} [P{{ id }}]{{ extension }}`.
3. **Setze die Namen fehlender Werte.** Paperless nennt einen fehlenden Korrespondenten oder Dokumenttyp `none`, außer `PAPERLESS_FILENAME_FORMAT_REMOVE_NONE` ist an. Um diese Ordner zu behalten, schreibe `none` in *Fehlender Korrespondent* und *Fehlender Dokumenttyp*.
4. **Wähle die Datei und den Ordner.** Wähle das Archiv-PDF für `documents/archive` oder die Originaldatei für `documents/originals`. Wähle einen Basisordner des Zielbenutzers außerhalb des Mounts: nie einen darin, wo die App in den Medienordner von Paperless schreiben würde.
5. **Starte einen Testlauf** und vergleiche seine Pfade mit denen des Mounts. Ändere die Vorlage, bis sie übereinstimmen, soweit die Tabelle es erlaubt; Paperless ersetzt manche Zeichen, etwa `:`, durch `-`, Paperless Sync durch `_`.
6. **Schalte die Synchronisation ein.** Ein Lauf macht höchstens so viele Änderungen wie die Batchgröße, standardmäßig 100, also kommt ein großes Archiv in mehreren Läufen; für den ersten Export darfst du die Batchgröße auf bis zu 1.000 erhöhen.
7. **Entscheide über den Posteingang.** Der Mount zeigt jedes Dokument, auch die noch im Posteingang von Paperless. Paperless Sync lässt sie aus, solange *Paperless-Posteingangsdokumente überspringen* an ist, wie es standardmäßig der Fall ist, und lässt auch die Dokumente mit einem der *Zusätzlich ausgeschlossenen Paperless-Tags* aus; jeder Lauf zählt sie als übersprungen. Wenn die Benutzer des Mounts sie weiter sehen sollen, stelle sie in Paperless fertig, oder schalte die Einstellung aus und entferne die Tags, die sie sehen sollen, aus dieser Liste.
8. **Teile das Archiv** mit den Benutzern und Gruppen des Mounts, nur lesend, wenn sie die Kopien nicht ändern sollen, sobald der Status einen Lauf ohne Fehler zeigt und ein Testlauf nichts mehr exportiert: Dann enthält das Archiv jedes Dokument, das die Einstellungen hereinlassen, und die übersprungenen sind die aus Schritt 7.
9. **Entferne den Mount** unter *Administrationseinstellungen → Externer Speicher*. Das entfernt nur den Mount; die Dateien von Paperless bleiben, wie sie sind.

| `PAPERLESS_FILENAME_FORMAT` | Paperless Sync |
| --- | --- |
| `{{ title }}`, `{{ correspondent }}`, `{{ document_type }}` | dasselbe |
| `{{ created }}`, `{{ created_year }}`, `{{ created_month }}` | dasselbe |
| `{{ added }}`, `{{ added_year }}` | dasselbe |
| `{{ doc_pk }}` | `{{ id }}`, ohne die führenden Nullen: `123`, nicht `0000123` |
| `{{ original_name }}` | `{{ original_filename }}` |
| ein Dokument mit einem [Speicherpfad](https://docs.paperless-ngx.com/advanced_usage/#storage-paths), das Paperless nach der Vorlage dieses Pfads ablegt | noch keines: `{{ storage_path }}` setzt nur den Namen des Speicherpfads ein, als einen Ordner |
| `{{ created_year_short }}`, `{{ created_month_name }}`, `{{ created_day }}`, `{{ added_month }}`, `{{ added_day }}`, `{{ asn }}`, `{{ tag_list }}` | dasselbe; der Name eines Monats steht in der Sprache von Nextcloud |
| `{{ owner_username }}` | dasselbe; das Konto der App braucht die Berechtigung, Benutzer anzuzeigen |
| ein benutzerdefiniertes Feld, über `get_cf_value("Name")` | `{{ custom_fields.Name }}`; das Konto der App braucht die Berechtigung, benutzerdefinierte Felder anzuzeigen |
| `{{ created_month_name_short }}`, `{{ added_year_short }}`, `{{ added_month_name }}`, `{{ added_month_name_short }}`, `{{ tags }}` und Jinja-Logik | noch keines: lass sie weg |

### Von paperless-nextcloud-sync

Der Container kopiert den Medienordner von Paperless in einen Ordner eines Nextcloud-Kontos, den dieses Konto mit anderen geteilt hat. Paperless Sync übernimmt diesen Ordner als seinen Basisordner, damit jede Freigabe weiter funktioniert, und baut sein Archiv darin. Die alten Dateien kann es nicht an Ort und Stelle übernehmen: Jeder Dateiname von Paperless Sync trägt die Markierung seines Dokuments, etwa `[P123]`, also ist kein Pfad des Archivs der einer alten Datei, und die alten Ordner gehen, sobald das Archiv vollständig ist:

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/diagrams/the-folder-of-the-container-becomes-the-base-folder-of-paperless-sync.de.dark.svg">
  <img src="images/diagrams/the-folder-of-the-container-becomes-the-base-folder-of-paperless-sync.de.svg" alt="Der Ordner des Containers wird zum Basisordner von Paperless Sync: Dem Konto des Containers gehört der Ordner Paperless, den es wie zuvor mit anderen teilt. Während des Umzugs enthält der Ordner die alten Ordner des Containers, etwa 2025 und 2026, und die Ordner von Paperless Sync: Archiv, das Archiv, Eingang, den Eingang zu Paperless, und Fehler, die Dateien, die Paperless nicht importieren konnte. Sobald das Archiv vollständig ist, gehen die alten Ordner in den Papierkorb des Kontos, und die Freigabe zeigt nur noch die Ordner von Paperless Sync. Die Ordner von Paperless Sync sind blau, die alten Ordner orange.">
</picture>

1. **Stoppe den Container** und entferne ihn aus der Compose-Datei von Paperless. Bei jedem Start löscht er die Dateien seines Ordners, die der Medienordner von Paperless nicht hat, also würde er das Archiv von Paperless Sync löschen. Sein Ordner und seine Freigaben bleiben, wie sie sind.
2. **Wähle das Konto und den Ordner.** In den Einstellungen von Paperless Sync ist der Zielbenutzer das Konto von `WEBDRIVE_USER`, und der Basisordner ist der Ordner von `WEBDRIVE_URL`, der Teil nach `/remote.php/dav/files/<user>/`, etwa `Paperless`.
3. **Schreibe die Pfadvorlage** für die Ordner von `PAPERLESS_FILENAME_FORMAT`, wie in den Schritten 2 und 3 [von einem Mount](#von-einem-mount-mit-externem-speicher), und wähle das Archiv-PDF, wenn der Container `documents/archive` gelesen hat, wie sein README es vorschlägt.
4. **Starte einen Testlauf**, dann schalte die Synchronisation ein. Das Archiv wächst in `Archiv`, neben den alten Ordnern, in so vielen Läufen, wie die Batchgröße braucht.
5. **Entscheide über den Posteingang**, wie in Schritt 7 [von einem Mount](#von-einem-mount-mit-externem-speicher): Der Container hat jedes Dokument kopiert, auch die, die Paperless Sync auslässt: standardmäßig die noch im Posteingang von Paperless und die mit einem ausgeschlossenen Tag.
6. **Lösche die alten Ordner**, sobald der Status einen Lauf ohne Fehler zeigt und ein Testlauf nichts mehr exportiert: alles im Basisordner außer `Archiv`, `Eingang` und `Fehler`. Sie gehen in den Papierkorb des Kontos, aus dem du sie wiederherstellen kannst, und jedes Dokument im Archiv hat dann eine Kopie.
7. **Öffne den Eingang für andere**, wenn sie Dateien an Paperless senden sollen: Eine schreibgeschützte Freigabe des Basisordners erlaubt es ihnen nicht, also teile `Eingang` mit ihnen mit der Berechtigung zum Hochladen.

Die Namen `Archiv`, `Eingang` und `Fehler` sind die, die eine neue Installation auf Deutsch vorschlägt; eine Installation auf Englisch nennt sie `Archive`, `Inbox` und `Failed imports`.

## Quellen

Geprüft am 13. Oktober 2026:

- [Der Nextcloud App Store](https://apps.nextcloud.com/apps/integration_paperless) und das [Repository der Paperless-Integration](https://github.com/nextcloud/integration_paperless): die Version 1.0.14, ihre Aktion *Upload to Paperless*, die Einstellungen jedes Benutzers und der Stand ihrer Pflege.
- [Das Repository von paperless-nextcloud-sync](https://github.com/Flo-R1der/paperless-nextcloud-sync): sein README, mit einem eigenen Vergleich, und seine Skripte `sync_full.sh` und `sync_live.sh`.
- [File name handling](https://docs.paperless-ngx.com/advanced_usage/#file-name-handling) und [adding documents](https://docs.paperless-ngx.com/usage/#adding-documents-to-paperless-ngx) in der Dokumentation von Paperless-ngx.
- [External storage](https://docs.nextcloud.com/server/latest/admin_manual/configuration_files/external_storage_configuration_gui.html) im Administrationshandbuch von Nextcloud und die Dokumentation von [rclone sync](https://rclone.org/commands/rclone_sync/).

Hat sich eine Tatsache geändert? [Öffne ein Issue](https://github.com/Dennis-Otto/paperless-sync/issues/new?template=bug_report.yml) oder einen Pull Request, und die Seite folgt.
