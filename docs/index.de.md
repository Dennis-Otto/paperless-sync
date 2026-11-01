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
[Schnellstart](#schnellstart){ .md-button }
[Voraussetzungen](guide.de.md#voraussetzungen){ .md-button }
{ .ps-hero-actions }

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/de/archive-dark.gif">
  <img alt="Das Archiv in Nextcloud Dateien: von den Ordnern der Korrespondenten über einen Korrespondenten, einen Dokumenttyp und ein Jahr zu einem Dokument von Paperless, das sich im Viewer von Nextcloud öffnet" src="images/de/archive.gif">
</picture>

## Was es tut

<div class="grid cards" markdown>

- :material-folder-sync-outline:{ .lg .middle } **Ein strukturiertes Archiv in Nextcloud**

    ---

    Die Dokumente erscheinen in Ordnern des eingestellten Nextcloud-Benutzers, oder jedes Benutzers mit den Dokumenten, die ihm in Paperless gehören, standardmäßig zuerst nach Korrespondent, gebildet aus einer einstellbaren Pfadvorlage, als Archiv-PDF oder Originaldatei.

- :material-file-move-outline:{ .lg .middle } **Folgt den Änderungen in Paperless**

    ---

    Ändern sich die Metadaten eines Dokuments in Paperless, wird seine Datei in Nextcloud umbenannt oder verschoben. Dokumente im Posteingang von Paperless oder mit einem ausgeschlossenen Tag bleiben draußen, und das Archiv kann nur die Dokumente mit den Tags spiegeln, die du wählst.

- :material-inbox-arrow-up-outline:{ .lg .middle } **Aus einem Nextcloud-Eingang zu Paperless**

    ---

    Dateien in einem Eingangsordner von Nextcloud, auch in seinen Unterordnern, werden an Paperless übergeben, und die App verfolgt ihre Paperless-Aufgaben.

- :material-delete-clock-outline:{ .lg .middle } **Vorsichtig beim Löschen**

    ---

    Der Papierkorb von Paperless wird in einen eigenen Ordner gespiegelt. Endgültiges Löschen ist standardmäßig aus und wartet, wenn es eingeschaltet ist, auf die eingestellte Zahl vollständiger Scans.

- :material-eye-check-outline:{ .lg .middle } **Zuerst ein Testlauf**

    ---

    Ein Testlauf listet auf, was ein Lauf ändern würde, ohne etwas zu ändern. Der Status, die Fehler und der Bericht des letzten Laufs, auch eines geplanten, bleiben auf der Einstellungsseite, und der Aktivitätenstrom des Zielbenutzers erzählt, was jeder Lauf geändert und importiert hat.

- :material-key-chain-variant:{ .lg .middle } **Keine Passwörter in Nextcloud**

    ---

    Die App arbeitet über die Dateisystem-API von Nextcloud, ohne WebDAV-Zugangsdaten oder ein App-Passwort. Das Paperless-Token bleibt im Anmeldedaten-Speicher von Nextcloud und erreicht nie den Browser.

</div>

--8<-- "README.de.md:how-it-works"

## Schnellstart

--8<-- "README.de.md:quick-start"

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/de/dry-run-dark.gif">
  <img alt="Die Einstellungen von Paperless Sync: Testlauf starten listet die Dokumente auf, die ein Lauf exportieren würde, dann exportiert Jetzt synchronisieren sie, und der Status wechselt zu abgeschlossen" src="images/de/dry-run.gif">
</picture>

Die [Anleitung](guide.de.md) beschreibt die Konfiguration im Detail: das Paperless-Konto, die Ordner, die Pfadvorlage und die Löschsicherheit.

## Mehr erfahren

Die Anleitung, die Seite zum Nutzen des Archivs, die FAQ und die Fehlerbehebung gibt es auf Deutsch, die übrigen Seiten auf Englisch.

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

    [:octicons-arrow-right-24: Vergleich](alternatives.md)

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
