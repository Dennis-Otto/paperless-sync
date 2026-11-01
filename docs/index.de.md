---
hide:
  - navigation
  - toc
# A link to the home page shows the social preview of the repository, with the layout
# in .github/social-cards/preview.yml.
social:
  cards_layout: preview
---

# <img src="images/app.svg" alt="" class="off-glb" width="64" height="64"> Paperless Sync { .ps-hero-title }

**Deine fertigen Paperless-ngx-Dokumente als aufgeräumtes Archiv in Nextcloud.**
{ .ps-hero-tagline }

Eine native Nextcloud-App, die fertig bearbeitete Paperless-ngx-Dokumente in ein strukturiertes Archiv in Nextcloud spiegelt und Dateien aus einem Nextcloud-Eingang an Paperless übergeben kann. Paperless bleibt die maßgebliche Quelle.

<!-- On the website, the hook in .github/mkdocs_project.py writes the versions of appinfo/info.xml into the fields. -->
<ul class="ps-requirements" aria-label="Voraussetzungen">
  <li>Nextcloud <span data-app="nextcloud">33–35</span></li>
  <li>PHP <span data-app="php">8.2+</span></li>
  <li>Paperless-ngx 2.16+ mit einem API-Token</li>
  <li>Der System-Cron von Nextcloud</li>
</ul>

[Im App Store holen](https://apps.nextcloud.com/apps/paperless_sync){ .md-button .md-button--primary }
[Ausprobieren](#ausprobieren){ .md-button }
[Schnellstart](#schnellstart){ .md-button }
[Voraussetzungen](guide.de.md#voraussetzungen){ .md-button }
{ .ps-hero-actions }

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/de/archive-dark.gif">
  <img alt="Das Archiv in Nextcloud Dateien: von den Ordnern der Korrespondenten über einen Korrespondenten, einen Dokumenttyp und ein Jahr zu einem Dokument von Paperless, das sich im Viewer von Nextcloud öffnet" src="images/de/archive.gif">
</picture>

## Für wen

<div class="grid cards" markdown>

- :material-home-heart:{ .lg .middle } **Ein Haushalt**

    ---

    Die Unterlagen der Familie liegen in Paperless, und alle finden sie in Nextcloud, auch auf dem Handy: in einem Archiv, das du teilst, oder jede Person mit den Dokumenten, die ihr in Paperless gehören.

    [:octicons-arrow-right-24: Das Archiv nutzen](use.de.md)

- :material-office-building-outline:{ .lg .middle } **Ein kleines Büro**

    ---

    Das Team sendet Dateien aus Nextcloud Dateien an Paperless, Regeln von Flow tun es von selbst, und wer Paperless betreut, bekommt die Einstellungen, ohne Nextcloud zu administrieren.

    [:octicons-arrow-right-24: Die Einstellungen delegieren](guide.de.md#delegating-the-settings)

- :material-nas:{ .lg .middle } **Paperless auf einem NAS**

    ---

    Paperless darf auf einem anderen Gerät laufen: Die App erreicht es über seine REST-API, ohne einen Container daneben und ohne Zugriff auf seinen Medienordner, und ein Schalter erlaubt eine Adresse deines Netzwerks.

    [:octicons-arrow-right-24: Paperless im lokalen Netzwerk](guide.de.md#paperless-im-lokalen-netzwerk)

</div>

## Was es tut

### Archiv

<div class="grid cards ps-features" markdown>

- :material-folder-sync-outline:{ .lg .middle } **Ein strukturiertes Archiv in Nextcloud**

    ---

    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="images/de/path-preview-dark.png">
      <img alt="Die Pfadvorlage in den Einstellungen, mit einem Knopf für jede Variable darunter und den Pfaden der fünf neuesten Dokumente von Paperless mit der getippten Vorlage, vor einem Speichern" src="images/de/path-preview-light.png">
    </picture>

    Die Dokumente erscheinen in Ordnern des eingestellten Nextcloud-Benutzers, standardmäßig zuerst nach Korrespondent, als Archiv-PDF oder Originaldatei. Eine Pfadvorlage bestimmt, wohin jedes kommt, und ihre Vorschau zeigt es schon beim Tippen.

    [:octicons-arrow-right-24: Pfadvorlage](guide.de.md#path-template-variables)

- :material-file-move-outline:{ .lg .middle } **Folgt den Änderungen in Paperless**

    ---

    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="images/de/move-dark.gif">
      <img alt="Nextcloud Dateien im Ordner Example Bank/Letter/2026 des Archivs mit der Kopie 2026-10-07 - Credit card terms [P190].pdf. Eine Bildunterschrift sagt, dass das Dokument in Paperless den Typ Contract und den neuen Titel Credit card agreement erhält, dann, dass der nächste Lauf die Kopie in den Ordner ihres neuen Typs verschiebt und ihr den neuen Namen gibt. Der Zeiger öffnet Example Bank, das jetzt Contract und Statement enthält und keinen Ordner Letter mehr, dann Contract und 2026, wo die Kopie 2026-10-07 - Credit card agreement [P190].pdf heißt" src="images/de/move.gif">
    </picture>

    Ändern sich die Metadaten eines Dokuments in Paperless, wird seine Datei in Nextcloud umbenannt oder verschoben, mit ihren Freigaben, Kommentaren und Versionen. Dokumente im Posteingang von Paperless oder mit einem ausgeschlossenen Tag bleiben draußen, und das Archiv kann nur die Dokumente mit den Tags spiegeln, die du wählst.

    [:octicons-arrow-right-24: Welche Dokumente das Archiv spiegelt](guide.de.md#which-documents-the-archive-mirrors)

- :material-tag-multiple-outline:{ .lg .middle } **Tags und Metadaten kommen mit**

    ---

    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="images/de/archive-dark.png">
      <img alt="Nextcloud Dateien mit dem Ordnerbaum des Archivs, geöffnet bis City Utilities, Invoice, 2026, mit zwei Stromrechnungen, benannt nach Datum, Titel und Paperless-ID, mit ihren Tags von Paperless, Electricity und Paid, als Tags von Nextcloud" src="images/de/archive-light.png">
    </picture>

    Die Tags von Paperless können als Tags von Nextcloud auf den Kopien erscheinen, sodass Dateien nach ihnen filtert und sucht. Eine JSON-Datei für jedes Dokument und ein Index als CSV halten seine Tags, Notizen, benutzerdefinierten Felder und Daten neben dem Archiv fest, das sich dann auch ohne Paperless selbst beschreibt.

    [:octicons-arrow-right-24: Tags in Nextcloud](guide.de.md#tags-in-nextcloud) · [Metadaten](guide.de.md#metadata-next-to-the-archive)

- :material-account-group-outline:{ .lg .middle } **Jedem Benutzer seine Dokumente**

    ---

    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="images/de/settings-connection-dark.png">
      <img alt="Der Abschnitt Verbindung und Eigentümer: Paperless-Adresse, Paperless-API-Token, Nextcloud-Zielbenutzer-ID, Basisordner, die Auswahl Wer die Dokumente erhält mit dem, was jede ihrer Optionen tut, der Schalter Paperless läuft in meinem lokalen Netzwerk und der Knopf Verbindung testen" src="images/de/settings-connection-light.png">
    </picture>

    Ein Benutzer kann das Archiv besitzen und teilen. Oder jeder Benutzer von Nextcloud erhält die Dokumente, die ihm in Paperless gehören, in seinen eigenen Dateien, über die eine Verbindung der App, und auf Wunsch auch die, die Paperless mit ihm teilt.

    [:octicons-arrow-right-24: Eigentum je Benutzer](guide.de.md#per-user-ownership)

</div>

### Eingang und Dateien

<div class="grid cards ps-features" markdown>

- :material-inbox-arrow-up-outline:{ .lg .middle } **Aus einem Nextcloud-Eingang zu Paperless**

    ---

    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="images/de/inbox-dark.gif">
      <img alt="Nextcloud Dateien im Ordner Eingang: Zwei Scans kommen an, und eine Bildunterschrift sagt, dass die nächsten Läufe sie an Paperless schicken und ein Scan den Eingang verlässt, sobald Paperless seinen Import beendet hat. Der Eingang ist wieder leer, und der Zeiger öffnet im Ordnerbaum den Ordner Fehler, der den zweiten Scan neben seiner .error.txt enthält, da Paperless ihn nicht importieren konnte" src="images/de/inbox.gif">
    </picture>

    Dateien in einem Eingangsordner von Nextcloud, auch in seinen Unterordnern, werden mit ihren Tags an Paperless übergeben, und die App verfolgt ihre Paperless-Aufgaben. Ein fehlgeschlagener Import wandert in einen Fehlerordner, neben den Grund.

    [:octicons-arrow-right-24: Nextcloud-Eingang](guide.de.md#nextcloud-inbox)

- :material-send-outline:{ .lg .middle } **An Paperless senden in Dateien**

    ---

    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="images/de/files-send-dark.png">
      <img alt="Nextcloud-Dateien mit der Datei Brief der Bank.pdf im Ordner Scans und ihrem geöffneten Menü, das unter den Aktionen von Dateien An Paperless senden anbietet" src="images/de/files-send-light.png">
    </picture>

    Das Menü einer Datei oder eines Ordners sendet sie an Paperless, für die Mitglieder einer Gruppe, die du wählst. Sie bleibt, wo sie ist, und eine Benachrichtigung verlinkt das neue Dokument. *In Paperless öffnen* im Menü einer Kopie öffnet ihr Dokument.

    [:octicons-arrow-right-24: Aus Dateien senden](guide.de.md#send-from-files)

- :material-sitemap-outline:{ .lg .middle } **Regeln von Flow**

    ---

    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="images/de/flow-rule-dark.png">
      <img alt="Eine Regel von Flow unter Verwaltung → Ablauf: Wenn eine Datei erstellt wird, ihr Datei MIME-Typ PDF-Dokumente ist und sie mit dem kollaborativen Tag An Paperless getaggt ist, sendet An Paperless senden sie mit den Tags Briefe und Bank aus dem Feld Tags in Paperless" src="images/de/flow-rule-light.png">
    </picture>

    Regeln von Flow senden Dateien von selbst, mit eigenen Tags, etwa jedes PDF, das ein Mitglied einer Gruppe hochlädt. Nur die Administratoren erstellen sie.

    [:octicons-arrow-right-24: Durch eine Regel von Flow senden](guide.de.md#durch-eine-regel-von-flow-senden)

- :material-information-outline:{ .lg .middle } **Dateien sagt, was ein Ordner tut**

    ---

    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="images/de/folder-note-dark.png">
      <img alt="Nextcloud Dateien im Ordner Archiv mit einem Hinweis über der Liste: Spiegel von Paperless, aktualisiert vor 5 Minuten. Änderungen hier erreichen Paperless nicht, und die nächsten Läufe können sie rückgängig machen. Bearbeite das Dokument in Paperless. Ein Link öffnet Paperless, und ein Knopf blendet den Hinweis aus. Darunter die Ordner der Korrespondenten" src="images/de/folder-note-light.png">
    </picture>

    Ein Hinweis über der Liste sagt, dass das Archiv ein Spiegel ist, wann der Eingang seine Dateien sendet und wo die Gründe fehlgeschlagener Importe liegen, auch für Menschen, die den Ordner über eine Freigabe erreichen.

    [:octicons-arrow-right-24: Was Bearbeiten, Umbenennen und Löschen bewirken](use.de.md#was-bearbeiten-umbenennen-und-löschen-bewirken)

</div>

### Betrieb

<div class="grid cards ps-features" markdown>

- :material-webhook:{ .lg .middle } **Änderungen in Sekunden**

    ---

    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="images/de/settings-webhook-dark.png">
      <img alt="Der Abschnitt Änderungen in Sekunden direkt nach Geheimnis erstellen: die Webhook-URL und das neue Geheimnis, jeweils mit einem Knopf Kopieren, der Hinweis, das Geheimnis jetzt zu kopieren, weil es nur dieses eine Mal angezeigt wird, die Knöpfe Neues Geheimnis erstellen und Webhook ausschalten, der Schalter Dokument während des Aufrufs spiegeln, der an ist, und die eingeklappten Schritte in Paperless" src="images/de/settings-webhook-light.png">
    </picture>

    Ein Arbeitsablauf von Paperless ruft den Webhook der App auf, wenn er ein Dokument hinzufügt oder ändert, und seine Kopie ändert sich sofort, ohne einen Container neben Paperless, seinen Medienordner oder ein Passwort von Nextcloud.

    [:octicons-arrow-right-24: Änderungen in Sekunden](guide.de.md#changes-within-seconds)

- :material-format-list-checks:{ .lg .middle } **Erste Schritte, einer nach dem anderen**

    ---

    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="images/de/settings-getting-started-dark.png">
      <img alt="Erste Schritte oben in den Einstellungen von Paperless Sync: Mit Paperless verbinden und Wählen, wer die Dokumente erhält, sind erledigt, mit einem Haken, Einen Testlauf machen ist als Nächstes dran und hervorgehoben, Die geplante Synchronisation einschalten und Auf den ersten Lauf warten sind noch offen, und jeder Schritt hat einen Knopf, der an seine Stelle auf der Seite führt" src="images/de/settings-getting-started-light.png">
    </picture>

    Bis der erste Lauf erfolgreich war, öffnen sich die Einstellungen mit den Schritten des Schnellstarts in ihrer sicheren Reihenfolge. Auswahlfelder bieten die Benutzer und die Tags an und markieren einen, den es nicht gibt. Die Administratoren können die Einstellungen an eine Gruppe delegieren.

    [:octicons-arrow-right-24: Erste Schritte](guide.de.md#getting-started-in-the-settings) · [Die Einstellungen delegieren](guide.de.md#delegating-the-settings)

- :material-bell-alert-outline:{ .lg .middle } **Sagt dir, wenn die Spiegelung scheitert**

    ---

    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="images/de/status-error-dark.png">
      <img alt="Der Status von Paperless Sync nach einem Lauf mit einem Fehler: der Zustand Mit Fehlern abgeschlossen in Rot, die Zeiten des letzten Laufs, des letzten erfolgreichen Laufs und des nächsten geplanten Laufs, und darunter ein Kasten, der das Dokument nennt, dessen Herunterladen gescheitert ist" src="images/de/status-error-light.png">
    </picture>

    Benachrichtigungen, Einrichtungsprüfungen und eine Status-API sagen den Administratoren, wenn die Spiegelung immer wieder scheitert oder stehen geblieben ist, auch in Uptime Kuma oder Home Assistant. Der Aktivitätenstrom des Zielbenutzers erzählt, was jeder Lauf geändert und importiert hat.

    [:octicons-arrow-right-24: Benachrichtigungen](guide.de.md#being-notified) · [Aktivität](guide.de.md#aktivität)

- :material-console:{ .lg .middle } **Nach Plan, auf Abruf oder aus einem Skript**

    ---

    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="images/de/settings-schedule-dark.png">
      <img alt="Der Abschnitt Zeitplan und Module: Schalter für die geplante Synchronisation, den Archivexport und den Import aus dem Eingang, das Intervall in Minuten und die maximalen Änderungen je Modul und Lauf" src="images/de/settings-schedule-light.png">
    </picture>

    Der Cron von Nextcloud führt die App in dem Intervall aus, das du einstellst, und die Batchgröße begrenzt die Änderungen eines Laufs. Ein Lauf startet auch aus den Einstellungen, und `occ`-Befehle dienen Skripten, Cron-Jobs und der Überwachung, mit JSON-Ausgabe und Exit-Codes.

    [:octicons-arrow-right-24: Kommandozeile](guide.de.md#kommandozeile)

</div>

### Sicherheit

<div class="grid cards ps-features" markdown>

- :material-eye-check-outline:{ .lg .middle } **Zuerst ein Testlauf**

    ---

    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="images/de/report-dark.png">
      <img alt="Der Bericht eines Testlaufs unter der Überschrift Testlauf – nichts wurde geändert, mit dem Knopf Bericht kopieren: die Zahlen der Änderungen, die nicht 0 sind, die unveränderten Dokumente in einem Satz, ein Filter für jede Art von Änderung und eine Liste mit einem farbigen Abzeichen, dem Dokument und der Datei jeder Änderung" src="images/de/report-light.png">
    </picture>

    Die Synchronisation bleibt aus, bis du sie einschaltest. Ein Testlauf listet auf, was ein Lauf ändern würde, ohne etwas zu ändern. Der Status, die Fehler und der Bericht des letzten Laufs, auch eines geplanten, bleiben auf der Einstellungsseite.

    [:octicons-arrow-right-24: Status, Bericht und Protokoll](guide.de.md#status-bericht-und-protokoll)

- :material-delete-clock-outline:{ .lg .middle } **Vorsichtig beim Löschen**

    ---

    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="images/de/trash-dark.gif">
      <img alt="Nextcloud Dateien im Ordner City Utilities/Invoice/2026 des Archivs mit drei Stromrechnungen. Eine Bildunterschrift sagt, dass Electricity bill March in Paperless in den Papierkorb kommt; nach dem nächsten Lauf enthält der Ordner zwei Rechnungen, und der Zeiger öffnet _Gelöscht, 2026-10-25, City Utilities, Invoice und 2026, wo die Kopie der Rechnung vom März liegt. Eine Bildunterschrift sagt, dass die Rechnung in Paperless wiederhergestellt wird, und der Ordner des Archivs enthält wieder alle drei Rechnungen" src="images/de/trash.gif">
    </picture>

    Der Papierkorb von Paperless wird in einen eigenen Ordner gespiegelt. Endgültiges Löschen ist standardmäßig aus und wartet, wenn es eingeschaltet ist, auf die eingestellte Zahl vollständiger Scans.

    [:octicons-arrow-right-24: Löschsicherheit](guide.de.md#deletion-safety)

- :material-key-chain-variant:{ .lg .middle } **Keine Passwörter in Nextcloud**

    ---

    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="images/de/settings-account-dark.png">
      <img alt="Ein Konto für die App in Paperless anlegen, aufgeklappt: was die Einrichtung tut, das API-Token eines Paperless-Superusers, eingegeben und verborgen, der Benutzername des Kontos, nextcloud-sync, und die Schaltfläche Konto anlegen und speichern" src="images/de/settings-account-light.png">
    </picture>

    Die App arbeitet über die Dateisystem-API von Nextcloud, ohne WebDAV-Zugangsdaten oder ein App-Passwort. Ihr Konto in Paperless ist kein Superuser, und das Paperless-Token bleibt im Anmeldedaten-Speicher von Nextcloud und erreicht nie den Browser.

    [:octicons-arrow-right-24: Das Konto der App](guide.de.md#the-account-of-the-app)

- :material-lan-check:{ .lg .middle } **Eine Verbindung, die sie prüft**

    ---

    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="images/de/settings-checklist-dark.png">
      <img alt="Die Verbindungs-Checkliste nach Verbindung testen: Paperless-ngx antwortet, das Konto sieht die Dokumente, Korrespondenten, Dokumenttypen und Speicherpfade, der Papierkorb hat einen Hinweis, dass das Konto ein Dokument eines anderen Eigentümers sieht, das der Papierkorb von Paperless auslässt, und die Tags, das Hochladen aus dem Eingang und die Tags des Eingangs sind fehlgeschlagen, jeweils mit den Berechtigungen, die in Paperless zu vergeben sind" src="images/de/settings-checklist-light.png">
    </picture>

    *Verbindung testen* prüft jede Berechtigung, die deine Module brauchen, und nennt, was in Paperless zu vergeben ist. Ein Paperless in deinem lokalen Netzwerk funktioniert, ohne den Schutz von Nextcloud vor serverseitiger Anforderungsfälschung für den ganzen Server auszuschalten.

    [:octicons-arrow-right-24: Verbindungs-Checkliste](guide.de.md#verbindungs-checkliste)

</div>

Die Einstellungen sprechen Englisch und Deutsch. Sie, die Aktionen in Dateien und das Formular der Regel in Flow werden in jeder Änderung der App auf Barrierefreiheit nach WCAG 2.1 AA geprüft. Jedes Release ist signiert, dasselbe Paket im Nextcloud App Store und auf GitHub, mit einer SBOM und signierter Build-Provenienz.

--8<-- "README.de.md:how-it-works"

## Ausprobieren

--8<-- "README.de.md:try-it"

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/de/try-it-dark.gif">
  <img alt="Ein Terminal führt docker compose -f demo/compose.yaml up aus: Paperless-ngx und Nextcloud starten, die Einrichtung füllt Paperless mit erfundenen Dokumenten, verbindet die App, startet einen Probelauf und den ersten Lauf und nennt die Adressen der Demo. Dann zeigt Nextcloud Dateien das Archiv, von den Ordnern der Korrespondenten über einen Korrespondenten und einen Dokumenttyp bis zu einem Jahr" src="images/de/try-it.gif">
</picture>

## Schnellstart

--8<-- "README.de.md:quick-start"

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/de/dry-run-dark.gif">
  <img alt="Die Einstellungen von Paperless Sync: Testlauf starten listet die Dokumente auf, die ein Lauf exportieren würde, dann exportiert Jetzt synchronisieren sie, und der Status wechselt zu abgeschlossen" src="images/de/dry-run.gif">
</picture>

Die [Anleitung](guide.de.md) beschreibt die Konfiguration im Detail: das Paperless-Konto, die Ordner, die Pfadvorlage und die Löschsicherheit.

## Mehr erfahren

Die Anleitung, die Seite zum Nutzen des Archivs, die FAQ, die Fehlerbehebung und der Vergleich gibt es auf Deutsch, die übrigen Seiten auf Englisch.

<div class="grid cards" markdown>

- :material-book-open-variant:{ .lg .middle } **Anleitung**

    ---

    Installation, alle Funktionen, die Verwendung und jeder Teil der Konfiguration.

    [:octicons-arrow-right-24: Zur Anleitung](guide.de.md)

- :material-cellphone-text:{ .lg .middle } **Das Archiv nutzen**

    ---

    Für die Menschen, die die Dokumente lesen und senden: sie finden, Dateien an Paperless senden, mit dem Handy scannen, offline halten und teilen.

    [:octicons-arrow-right-24: Das Archiv nutzen](use.de.md)

- :material-lifebuoy:{ .lg .middle } **FAQ und Fehlerbehebung**

    ---

    Was mit Bearbeitungen und Löschungen geschieht, wie lange die erste Synchronisation dauert, und die Lösungen für die Fehlermeldungen.

    [:octicons-arrow-right-24: FAQ](faq.de.md) · [Fehlerbehebung](troubleshooting.de.md)

- :material-compare-horizontal:{ .lg .middle } **Der Vergleich**

    ---

    Paperless Sync neben der Paperless-Integration von Nextcloud, dem WebDAV-Container, rclone und einem Mount, und wie du umsteigst.

    [:octicons-arrow-right-24: Vergleich](alternatives.de.md)

- :material-sitemap-outline:{ .lg .middle } **Architektur**

    ---

    Wie die App in Nextcloud läuft, Paperless über seine REST-API liest und Dateien über die Datei-API von Nextcloud schreibt.

    [:octicons-arrow-right-24: Architektur](architecture.md)

- :material-shield-lock-outline:{ .lg .middle } **Sicherheitsdesign**

    ---

    Was die App schützt, wem sie vertraut, die Bedrohungen mit ihren Gegenmaßnahmen und die Risiken, die bleiben.

    [:octicons-arrow-right-24: Sicherheitsdesign](security.md)

- :material-map-marker-path:{ .lg .middle } **Roadmap**

    ---

    Was Paperless Sync in den nächsten zwölf Monaten vorhat, und was es nicht tun wird.

    [:octicons-arrow-right-24: Roadmap](roadmap.md)

- :material-scale-balance:{ .lg .middle } **Entscheidungen**

    ---

    Die Entscheidungen, die das Projekt prägen, jede mit ihren Gründen.

    [:octicons-arrow-right-24: Entscheidungen](decisions/README.md)

- :material-robot-outline:{ .lg .middle } **Automatisierung**

    ---

    Die Bots, die jede Änderung prüfen, die App veröffentlichen und verifizieren, Issues beantworten und das Repository aktuell halten.

    [:octicons-arrow-right-24: Automatisierung](automation.md)

- :material-hammer-wrench:{ .lg .middle } **Entwicklung und Releases**

    ---

    Wie du die App baust und testest, wie ein Release entsteht, signiert und im Nextcloud App Store veröffentlicht wird, und die Änderungen jeder Version.

    [:octicons-arrow-right-24: Entwicklung](development.md) · [Releases](releases.md) · [Änderungen](changelog.md)

</div>

--8<-- "README.de.md:help"
