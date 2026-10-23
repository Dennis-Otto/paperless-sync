# Fehlerbehebung

[← README](https://github.com/Dennis-Otto/paperless-sync/blob/main/README.de.md) · [Anleitung](guide.de.md) · [FAQ](faq.de.md)

Wenn etwas nicht funktioniert, sagt Paperless Sync, warum, und diese Seite führt von dem, was du siehst, zu seiner Ursache und seiner Lösung. Die [FAQ](faq.de.md) beantworten die Fragen dazu, was die App tut, etwa wohin gelöschte Dateien gehen. Wo du zuerst nachsehen solltest:

- **Das Speichern der Einstellungen scheitert:** Die Meldung neben den Knöpfen, in der Leiste unten in *Administrationseinstellungen → Paperless Sync*, nennt die Ursache.
- **Ein Lauf scheitert oder endet mit Fehlern:** Der Status oben in den Einstellungen zeigt den Zustand des letzten Laufs, auch eines geplanten, mit einem Kasten seiner Fehler, und das [Nextcloud-Protokoll](#das-protokoll-lesen) hat für jeden von ihnen einen Eintrag der App `paperless_sync`. Auf dem Server zeigt `occ paperless_sync:status` dasselbe.
- **Gar kein Lauf startet:** Die [Hintergrundjobs](#kein-lauf-startet) von Nextcloud laufen nicht.
- **Eine Datei fehlt oder liegt an einer unerwarteten Stelle:** Der Bericht des letzten Laufs, oder eines Testlaufs, hat eine Zeile für jede Datei, die der Lauf schreibt, verschiebt oder löscht.

## Der Weg einer Anfrage zu Paperless

**Verbindung testen** in den Einstellungen folgt diesem Weg, und ebenso jedes Speichern, das die Verbindung prüft: Seine [Verbindungs-Checkliste](guide.de.md#verbindungs-checkliste) nennt den Schritt, der gescheitert ist, und was zu tun ist. Jeder Lauf beginnt mit derselben Anfrage nach dem ersten Dokument. Jeder Schritt auf diesem Weg kann mit einem eigenen Fehler scheitern:

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/diagrams/the-way-of-a-request-from-nextcloud-to-paperless-and-the-error-of-each.de.dark.svg">
  <img src="images/diagrams/the-way-of-a-request-from-nextcloud-to-paperless-and-the-error-of-each.de.svg" alt="Der Weg einer Anfrage von Nextcloud zu Paperless und der Fehler jedes Schritts: Eine Anfrage von Paperless Sync durchläuft sieben Schritte, bevor Paperless mit den Dokumenten antwortet. Eine Adresse des lokalen Netzwerks braucht die Einstellung Paperless läuft in meinem lokalen Netzwerk, sonst sperrt Nextcloud sie. Der Name muss sich auflösen lassen und Paperless an seinem Port antworten, sonst enthält die Meldung cURL error 6 oder 7. Ein HTTPS-Zertifikat muss eines sein, dem Nextcloud vertraut, sonst enthält die Meldung cURL error 60. Die Adresse muss zur API von Paperless führen, sonst lautet die Antwort 404 Not Found, oder eine Anmeldeseite, die kein JSON ist. Paperless muss Version 9 seiner API bereitstellen, wie es Paperless-ngx 2.16 und neuer tun, sonst lautet die Antwort 406 Not Acceptable. Das Token muss gültig sein, sonst lautet die Antwort 401 Unauthorized. Zuletzt muss das Konto jede Liste sehen, die ein Lauf liest, sonst lautet die Antwort 403 Forbidden: Verbindung testen markiert diese Liste als fehlgeschlagen, mit der Berechtigung, die zu vergeben ist.">
</picture>

## Meldungen und ihre Lösungen

Die Meldungen der Einstellungsseite stehen hier so, wie ein Nextcloud auf Deutsch sie zeigt, auch die Fehler eines Laufs, die die App erklärt, im Status und im Bericht. Die Meldungen von Paperless und Nextcloud in diesen Fehlern, die Zeilen der Kommandozeile und das Protokoll bleiben auf Englisch, so wie die App sie bekommt und schreibt.

| Was du siehst | Ursache und Lösung |
| --- | --- |
| `Nextcloud sperrt Verbindungen zu lokalen Adressen wie paperless` | Paperless läuft unter einer Adresse deines Netzwerks, die Nextcloud zum Schutz gegen Server-Side Request Forgery ablehnt. **Lösung:** Schalte **Paperless läuft in meinem lokalen Netzwerk** ein und speichere; [Einzelheiten](#ein-paperless-in-deinem-netzwerk). |
| `Nextcloud hat keinen DNS-Eintrag für paperless gefunden` | Der Name steht nur in der hosts-Datei des Servers, etwa mit `extra_hosts` in Docker, oder nirgends. **Lösung:** Schalte **Paperless läuft in meinem lokalen Netzwerk** ein, oder korrigiere den Namen. |
| `cURL error 6: Could not resolve host` | Der Nextcloud-Server findet den Namen von Paperless nicht. **Lösung:** Nimm den Namen oder die Adresse, unter der der Nextcloud-Server Paperless erreicht, nicht dein Browser; [Einzelheiten](#paperless-in-docker). |
| `cURL error 7: Failed to connect` | Unter dieser Adresse und an diesem Port antwortet nichts. **Lösung:** Prüfe den Port, ob Paperless läuft und ob eine Firewall oder ein Docker-Netzwerk dazwischensteht. |
| `cURL error 60: SSL certificate problem` | Paperless hat ein Zertifikat, dem Nextcloud nicht vertraut, etwa ein selbst signiertes. **Lösung:** Importiere sein Zertifikat in Nextcloud; [Einzelheiten](#ein-eigenes-zertifikat). |
| `cURL error 35` mit `wrong version number` | Die Adresse sagt `https://` an einem Port, der HTTP spricht. **Lösung:** Nimm `http://` oder den Port von HTTPS. |
| `404 Not Found` | Der Adresse fehlt der Unterpfad, unter dem Paperless läuft, etwa `/paperless`. **Lösung:** Gib die Adresse ein, unter der du Paperless im Browser öffnest, mit Unterpfad; [Einzelheiten](#hinter-einem-reverse-proxy). |
| `Paperless returned invalid JSON.` | Die Adresse führt zu einer Webseite statt zur API, etwa zur Anmeldung eines Proxys vor Paperless. **Lösung:** Lass den Proxy die Anfragen an `/api/` mit ihrem Token durchreichen; [Einzelheiten](#hinter-einem-reverse-proxy). |
| `Paperless stellt Version 9 seiner API nicht bereit` | Paperless hat mit `406 Not Acceptable` geantwortet: Es läuft eine Version vor Paperless-ngx 2.16, die Version 9 seiner API noch nicht kennt, oder eine so neue, dass sie sie nicht mehr bereitstellt. **Lösung:** Aktualisiere Paperless-ngx auf 2.16 oder neuer; bei einem neueren Paperless-ngx aktualisiere Paperless Sync. |
| `Paperless hat das API-Token abgelehnt (HTTP 401)` oder `401 Unauthorized` | Das Token ist falsch, wurde in Paperless gelöscht, oder sein Konto ist inaktiv. **Lösung:** Erstelle in Paperless ein Token und gib es erneut ein. |
| `403 Forbidden` für `/api/tags/`, `/api/correspondents/`, `/api/document_types/` oder `/api/storage_paths/` | Das Konto darf die Dokumente sehen, aber nicht diese Liste, die jeder Lauf liest. **Verbindung testen**, und jedes Speichern, das die Verbindung prüft, markiert diese Liste in der Verbindungs-Checkliste als fehlgeschlagen, mit der Berechtigung, die zu vergeben ist; das Speichern behält die Einstellungen. **Lösung:** Gib dem Konto in Paperless die Berechtigung, diese Liste anzuzeigen. |
| `Den Nextcloud-Benutzer … gibt es nicht.` oder `Nextcloud hat keinen Benutzer mit der ID …` | Der Zielbenutzer ist kein Nextcloud-Benutzer. **Lösung:** Wähle den Benutzer aus der Liste des Felds, oder gib den Kontonamen ein, wie ihn *Konten* in der Administration auflistet, nicht den Anzeigenamen. |
| `Der eingestellte Nextcloud-Basisordner ist kein beschreibbarer Ordner.` | Der Basisordner ist eine Datei oder eine Freigabe, die der Zielbenutzer nur lesen darf. **Lösung:** Wähle einen Ordner, der dem Zielbenutzer gehört. |
| `Speichere zuerst die Verbindung zu Paperless; dann erscheinen seine Tags hier.` | Die Felder der ausgeschlossenen Tags, der Tags zum Spiegeln und der Tags für jeden Import lesen die Tags mit der gespeicherten Adresse und dem gespeicherten Token, und es gibt noch keine, oder die Adresse hat sich an der Einstellungsseite vorbei geändert. **Lösung:** Gib die Adresse und das Token ein und speichere. Bis dahin tippe die Namen. |
| `Paperless hat seine Tags nicht aufgelistet` | Paperless hat die Liste der Tags verweigert, etwa mit `HTTP 403`, wenn das Konto keine Tags anzeigen darf, oder ist nicht erreichbar. Jeder Lauf liest diese Liste ebenfalls. **Lösung:** Gib dem Konto in Paperless die Berechtigung, Tags anzuzeigen, oder sieh dir die anderen Meldungen dieser Tabelle an. |
| Ein Tag in den Einstellungen sagt *nicht in Paperless* | Paperless hat keinen Tag dieses Namens, den sein Konto anzeigen darf: ein Tippfehler, ein Tag, den du später anlegst, oder einer, der jemand anderem gehört. **Lösung:** Entferne ihn mit seinem Knopf und wähle den Tag aus der Liste, oder lege den Tag in Paperless an oder teile ihn mit dem Konto; für die Tags für jeden Import lässt *Fehlende Tags anlegen* einen Lauf ihn anlegen. Ein so markierter Tag zum Spiegeln hält jeden Export an, wie die nächste Zeile sagt. |
| `Paperless hat keinen Tag namens „…“, den „Nur Dokumente mit diesen Tags spiegeln“ nennt` | Ein Tag zum Spiegeln ist kein Tag von Paperless, den sein Konto anzeigen darf: ein Tippfehler, ein Tag, der in Paperless umbenannt oder gelöscht wurde, oder einer, der jemand anderem gehört. Der Export hält an, bevor sich eine Datei ändert, weil ein Lauf sonst die Kopien der Dokumente entfernen würde, die bleiben sollen; der Eingang läuft weiter. **Lösung:** Entferne den Tag mit seinem Knopf und wähle ihn aus der Liste des Felds, oder lege den Tag in Paperless an oder teile ihn mit dem Konto. |
| `Gib das API-Token erneut ein, um dich mit einer anderen Paperless-Adresse zu verbinden.` | Die Adresse hat sich geändert, und das Token geht nur an die Adresse, mit der es gespeichert wurde. **Lösung:** Gib das Token mit der neuen Adresse ein. |
| `Paperless Sync ist noch nicht vollständig eingerichtet.` | Keine Adresse, kein Token oder kein Zielbenutzer, oder die Adresse hat sich an der Einstellungsseite vorbei geändert, etwa mit `occ`, und wartet auf ihr Token. **Lösung:** Gib den fehlenden Wert ein, oder das Token erneut, und speichere. |
| `Nextcloud file conflict at …` | Konflikte werden übersprungen, und eine Datei eines anderen ist dem Dokument im Weg, oder jemand hat seine Kopie in Nextcloud bearbeitet. **Lösung:** Verschiebe oder lösche diese Datei, oder wähle **Synchronisierte Datei ersetzen**; siehe die [Dateikonflikt-Regel](guide.de.md#dateikonflikt-regel). |
| `EXPORT STOPPED`, auf der Einstellungsseite der Hinweis *Nextcloud kann keine Dateien im Archiv speichern*, und `Not enough free space in Nextcloud`, auf der Einstellungsseite *Nextcloud hat nicht genug freien Speicherplatz* | Das Kontingent des Zielbenutzers ist voll, oder das Archiv ist schreibgeschützt oder sein Speicher nicht verfügbar. **Lösung:** Erhöhe das Kontingent oder schaffe Platz; der nächste Lauf macht weiter. |
| `SCAN INCOMPLETE`, auf der Einstellungsseite der Hinweis, dass eine Liste von Paperless *unvollständig zurückkam oder sich geändert hat* | Eine Seite von Paperless kam zu kurz zurück, oder Dokumente kamen hinzu oder wurden gelöscht, während der Lauf sie las. Der Lauf hat kein Dokument als fehlend gezählt. **Lösung:** Nichts, wenn der nächste Lauf vollständig ist. Bleibt es, such nach einem Proxy, der lange Antworten abschneidet, oder nach Berechtigungen, die sich ändern. |
| `Paperless answered the upload with HTTP 403` | Das Konto darf keine Dokumente hinzufügen, was der Eingang braucht. Das Hochladen wartet und versucht es später erneut. **Lösung:** Gib dem Konto in Paperless die Berechtigung `documents.add_document`, **Hinzufügen** für Dokumente. |
| `Import task …` mit `HTTP 403`, auf der Einstellungsseite *Import-Aufgabe …* | Das Konto darf Dokumente hinzufügen, aber nicht die Aufgaben von Paperless anzeigen, die sie importieren, also erfährt der Eingang nie, ob eine Datei angekommen ist, und behält sie. **Lösung:** Gib dem Konto die Berechtigung **Anzeigen** für Paperless-Aufgaben, `documents.view_paperlesstask`; die Zeile *Hochladen aus dem Eingang* der Verbindungs-Checkliste nennt sie. |
| `IMPORT REJECTED`, auf der Einstellungsseite *Abgelehnt*, mit `HTTP 413` | Ein Proxy vor Paperless lehnt große Dateien ab, etwa die 100 MB des kostenlosen Tarifs von Cloudflare. **Lösung:** Erhöhe die Grenze des Proxys und speichere die Datei dann neu, um sie erneut zu übergeben. |
| `IMPORT REJECTED`, *Abgelehnt*, mit einem anderen Grund | Paperless hat die Datei abgelehnt, etwa eine eines Typs, den es nicht annimmt. **Lösung:** Wandle die Datei um, oder entferne sie aus dem Eingang. |
| Eine Datei im Fehlerordner mit `It is a duplicate of document #123` in ihrer `.error.txt` | Paperless hat dieses Dokument schon, auch in seinem Papierkorb, wenn der Text das sagt. Paperless-ngx 2.x lehnt Duplikate ab; 3.x nur mit `PAPERLESS_CONSUMER_DELETE_DUPLICATES`. **Lösung:** Nichts, oder stelle das Dokument aus dem Papierkorb von Paperless wieder her; lösche die Datei aus dem Fehlerordner. |
| Eine Datei im Fehlerordner mit nur `<traceback object at 0x…>` als Grund in ihrer `.error.txt` | Paperless-ngx 2.x markiert einen fehlgeschlagenen Import zuerst ohne seinen Grund und schreibt ihn einen Moment später; vor der Korrektur von [Issue #161](https://github.com/Dennis-Otto/paperless-sync/issues/161) behielt ein Lauf, der den Import in diesem Moment prüfte, den Platzhalter. Die Aufgabenliste von Paperless zeigt den Grund, über die ID der Aufgabe in der `.error.txt`. **Lösung:** Aktualisiere die App, behebe die Ursache, die die Aufgabenliste nennt, und verschiebe die Datei zurück in den Eingang. |
| Eine Datei im Fehlerordner mit `Paperless cancelled the task before it finished (REVOKED)` | Paperless hat die Aufgabe gestoppt, etwa weil sie abgebrochen oder ihr Worker währenddessen gestoppt wurde. **Lösung:** Verschiebe die Datei zurück in den Eingang, um sie erneut zu übergeben. |
| Eine Datei im Fehlerordner mit `Paperless import not finished` in ihrer `.error.txt` | Paperless führt die Aufgabe der Datei einen Tag nach dem Hochladen noch als wartend oder gestartet und hat kein Dokument mit ihrem Inhalt: Sein Worker ist vielleicht ausgefallen oder seine Warteschlange lang. Die App lädt die Datei nicht erneut hoch, damit Paperless-ngx 3.x sie nicht zweimal importieren kann. **Lösung:** Prüfe, ob der Worker von Paperless läuft. Erscheint das Dokument in Paperless, lösche die Datei; sonst verschiebe sie zurück in den Eingang. |
| `Paperless has not finished the import task … a day after the upload` | Paperless führt die Aufgabe der Datei nicht und hat kein Dokument mit ihrem Inhalt: Seine Warteschlange hat die Aufgabe verloren, wie nach einem Neustart von Redis. Die Datei wird nach 15 Minuten erneut hochgeladen. **Lösung:** Prüfe, ob der Worker von Paperless läuft und ob sein Redis seine Daten behält. |
| Der Status sagt *Fehlgeschlagen* oder *Mit Fehlern abgeschlossen* | Der Lauf, oder einzelne Dokumente, sind gescheitert. **Lösung:** Lies den Kasten der Fehler unter dem Status, dann das [Protokoll](#das-protokoll-lesen). |
| Der Status bleibt bei *In der Warteschlange* | *Jetzt synchronisieren* hat einen Lauf eingereiht, und die Hintergrundjobs von Nextcloud haben ihn nicht gestartet: Sie laufen nicht, oder sie laufen mit *AJAX*, während niemand Nextcloud geöffnet hat. **Lösung:** Siehe [Kein Lauf startet](#kein-lauf-startet), oder führe auf dem Server `occ paperless_sync:run` aus. |
| Der Status sagt *Unterbrochen* | PHP hat den Lauf gestoppt, bevor er sagen konnte, wie er endete, etwa bei einem Neustart des Servers oder seines Containers oder an einer Grenze von PHP; seine Sperre ist weg, und er hat 15 Minuten lang kein Lebenszeichen gegeben, während ein aktiver Lauf jede Minute eines schreibt. **Lösung:** Nichts, wenn der nächste Lauf abgeschlossen wird: Er macht dort weiter, wo dieser aufgehört hat. Sonst such im [Protokoll](#das-protokoll-lesen) nach einem fatalen Fehler von PHP. |
| `Es läuft bereits eine andere Synchronisation.` | Ein Lauf hält die Sperre, etwa ein geplanter oder einer von `occ`, und es läuft immer nur einer. **Lösung:** Warte, bis der Status sein Ende zeigt, und versuche es dann erneut. |
| Der nächste Lauf liegt in der Vergangenheit, oder der Status zeigt keinen geplanten Lauf | Die Hintergrundjobs von Nextcloud laufen nicht, oder die Synchronisation ist aus. **Lösung:** Siehe [Kein Lauf startet](#kein-lauf-startet). |
| Andere Benutzer sehen das Archiv nicht | Es liegt allein in den Dateien des Zielbenutzers. **Lösung:** Teile seinen Ordner; siehe [Andere Benutzer sehen das Archiv nicht](#andere-benutzer-sehen-das-archiv-nicht). |
| Ein Dokument im Papierkorb von Paperless bleibt mehrere Läufe lang im Archiv | Es gehört nicht dem Konto, also zeigt der Papierkorb von Paperless es dem Konto nicht. **Lösung:** Siehe [Dokumente des Papierkorbs kommen spät](#dokumente-des-papierkorbs-kommen-spät). |
| Eine gelöschte Kopie ist nicht in *Gelöschte Dateien* | Sie ist im Papierkorb des Zielbenutzers, den nur dieser Benutzer sieht, oder die App *Gelöschte Dateien* ist aus. **Lösung:** Siehe [Gelöschte Kopien sind nicht im Papierkorb](#gelöschte-kopien-sind-nicht-im-papierkorb). |

## Verbindung

### Ein Paperless in deinem Netzwerk

Nextcloud lehnt Verbindungen zu Adressen des lokalen Netzwerks ab, etwa `http://paperless:8000` in Docker oder `http://192.168.1.20:8000`, zum Schutz gegen Server-Side Request Forgery. Schalte **Paperless läuft in meinem lokalen Netzwerk** im Abschnitt *Verbindung und Eigentümer* ein und speichere: Nextcloud erlaubt dann eine solche Adresse für die Anfragen von Paperless Sync an die gespeicherte Paperless-Adresse, und nur für sie. Schalte für diese App nicht den Systemschalter `allow_local_remote_servers` ein, der den Schutz für jede App aufhebt; hast du es getan, schalte die Einstellung ein, speichere und schalte den Schalter mit `occ config:system:delete allow_local_remote_servers` wieder aus. Die [Anleitung](guide.de.md#paperless-im-lokalen-netzwerk) erklärt die Einstellung.

### Paperless in Docker

Die Adresse ist die, unter der der Nextcloud-Server Paperless erreicht, und sie kann sich von der in deinem Browser unterscheiden. Laufen beide in Containern desselben Docker-Netzwerks, ist es der Name des Dienstes von Paperless und der Port im Container, etwa `http://paperless:8000`, mit eingeschaltetem **Paperless läuft in meinem lokalen Netzwerk**. `http://localhost:8000` zeigt auf den Container von Nextcloud selbst. Prüfe die Adresse vom Nextcloud-Server aus, etwa mit `docker exec -u www-data nextcloud curl -sS http://paperless:8000/api/` mit dem Namen deines Containers; jede Antwort von Paperless, auch `401`, zeigt, dass es erreichbar ist.

### Ein eigenes Zertifikat

Nextcloud vertraut den Zertifikaten der üblichen Zertifizierungsstellen und denen, die in es importiert wurden, nicht denen des Betriebssystems. Für ein selbst signiertes Zertifikat oder eines deiner eigenen Zertifizierungsstelle importiere das Zertifikat der Stelle, im PEM-Format, auf dem Nextcloud-Server:

```sh
occ security:certificates:import /path/to/your-ca.crt
occ security:certificates
```

Der zweite Befehl listet die importierten Zertifikate auf. Speichere danach die Einstellungen erneut.

### Hinter einem Reverse Proxy

Gib die Adresse ein, unter der du Paperless im Browser öffnest, mit seinem Unterpfad, wenn es einen hat, etwa `https://example.com/paperless`, ohne `/api` am Ende: Die App hängt `/api/documents/` und die anderen Pfade der API an. Ein Proxy vor Paperless muss:

- die Anfragen an `/api/` mit ihrem Header `Authorization: Token …` durchreichen, ohne eigene Anmeldung, etwa eine des Single Sign-on, die mit einer Webseite und der Meldung `Paperless returned invalid JSON.` antworten würde;
- Uploads annehmen, die so groß sind wie die Dateien des Eingangs, etwa mit `client_max_body_size` in nginx; der kostenlose Tarif von Cloudflare hört bei 100 MB auf;
- Antworten ganz lassen, auch lange: Ein Scan liest die Dokumente in Seiten von 1.000.

## Läufe

### Kein Lauf startet

Jeder Lauf ist ein Hintergrundjob von Nextcloud. Zeigt der Status keinen nächsten Lauf, ist die Synchronisation aus: Schalte **Geplante Synchronisation aktivieren** ein und wähle **Verbindung testen und speichern**. Liegt der nächste Lauf in der Vergangenheit, laufen die Hintergrundjobs nicht:

1. Öffne *Administrationseinstellungen → Grundeinstellungen → Hintergrund-Jobs*. Dort muss **Cron** stehen, und der letzte Job muss vor ein paar Minuten gelaufen sein. *AJAX* führt Jobs nur aus, während jemand Nextcloud im Browser geöffnet hat, einen Job auf einmal.
2. Richte den System-Cron ein, wie [Nextcloud es beschreibt](https://docs.nextcloud.com/server/latest/admin_manual/configuration_server/background_jobs_configuration.html#cron): alle fünf Minuten `php -f /var/www/html/cron.php` als Benutzer des Webservers. Das Image von Nextcloud für Docker hat keinen eigenen Cron; gib ihm einen zweiten Container desselben Images mit dem Befehl `/cron.sh`, oder einen Cron-Job auf dem Host mit `docker exec -u www-data nextcloud php -f /var/www/html/cron.php`.
3. `occ background-job:list --class 'OCA\PaperlessSync\Cron\SyncJob'` zeigt, wann Nextcloud den Job der App zuletzt gestartet hat, und `occ background-job:list --class 'OCA\PaperlessSync\Cron\ManualSyncJob'` einen Lauf von *Jetzt synchronisieren*, der auf seinen Start wartet.

Nextcloud startet den Job höchstens alle fünf Minuten, und der Job startet einen Lauf, wenn das Intervall der App seit dem letzten vergangen ist; ein Testlauf zählt nicht. Ein Lauf, der noch beschäftigt ist, etwa die erste Synchronisation eines großen Archivs, hält den nächsten zurück, bis er endet. `occ paperless_sync:run` startet sofort einen Lauf in der Shell, ohne die Hintergrundjobs, und sagt, warum er scheitert.

### Das Protokoll lesen

Das Protokoll hat eine Warnung, wenn ein Fehler eines Dokuments zum ersten Mal auftritt oder sich ändert, und einen Fehler, wenn ein Lauf als Ganzes scheitert, jeweils mit der App `paperless_sync`.

- Im Browser: *Administrationseinstellungen → Protokollierung*, aus der App *Log Reader*, deren Spalte *App* `paperless_sync` nennt.
- Auf dem Server: `occ log:tail 50` zeigt die letzten Einträge und `occ log:tail --follow` die neuen, sobald sie kommen. Die Spalte *App* nennt `paperless_sync`.
- In der Datei: `grep '"app":"paperless_sync"' data/nextcloud.log` im Datenverzeichnis von Nextcloud.

Nextcloud protokolliert standardmäßig Warnungen und Fehler. Um zu sehen, dass ein Lauf abgeschlossen wurde, mit seinen Zahlen, stell die Stufe eine Weile mit `occ log:manage --level info` auf Info herunter, und mit `occ log:manage --level warning` zurück. Die App hält das API-Token von Paperless aus dem Protokoll heraus.

### Niemand wurde über eine scheiternde Spiegelung benachrichtigt

Die Benachrichtigung einer scheiternden Spiegelung kommt nach dem dritten Lauf in Folge, der gescheitert oder mit Fehlern abgeschlossen ist, oder sobald der erste von ihnen vor einem Tag geendet hat, und einmal für die ganze Reihe: Zwei gescheiterte Läufe, etwa während eines Neustarts von Paperless, benachrichtigen niemanden, und ein Testlauf auch nicht. Sie geht an die aktiven Mitglieder der Gruppe `admin`, oder der Gruppe, die `occ config:app:get paperless_sync notification_group` nennt; eine Gruppe, die es nicht gibt, überlässt sie den Administratoren, mit einer Warnung im Protokoll. Nextcloud zeigt Benachrichtigungen nur, solange seine App *Benachrichtigungen* aktiviert ist, und schickt sie nur per E-Mail oder aufs Telefon, wie jeder Benutzer es in den persönlichen Einstellungen unter *Benachrichtigungen* eingerichtet hat. Der nächste erfolgreiche Lauf entfernt die Benachrichtigung, also hat eine Spiegelung, die wieder funktioniert, keine. *Administration → Übersicht* und die [Status-API](guide.de.md#benachrichtigungen) zeigen den Zustand der Spiegelung jederzeit.

### Die Übersicht warnt vor den Hintergrundjobs von Nextcloud

Die Prüfung *Hintergrundjob von Paperless Sync* warnt, solange geplante Läufe an sind und Nextcloud seine Hintergrundjobs mit AJAX oder Webcron ausführt. Beide starten einen Job nur, während jemand Nextcloud benutzt, also hat eine Nacht ohne Besucher keine Läufe, und ein Lauf kann auf halbem Weg stoppen, wenn die Anfrage endet. Stell *Hintergrund-Jobs* in *Administration → Grundeinstellungen* auf *Cron*, oder mit `occ background:cron`, und richte den Cron-Job des Systems ein, der `cron.php` alle fünf Minuten als Benutzer des Webservers ausführt, wie es das [Handbuch von Nextcloud](https://docs.nextcloud.com/server/latest/admin_manual/configuration_server/background_jobs_configuration.html#cron) beschreibt.

### Die App Aktivität zeigt nichts von Paperless Sync

Die Einträge gehen allein an den Zielbenutzer, also sieht ein anderes Konto, etwa der Administrator, der den Lauf gestartet hat, keinen von ihnen. Ein Testlauf und ein Lauf, der nichts geändert hat, fügen keinen Eintrag hinzu, und ebenso wenig ein Lauf, der vor seiner ersten Änderung gestoppt hat, etwa einer, der Paperless nicht erreicht; von denen erzählt der Status auf der Einstellungsseite. Nextcloud behält die Einträge nur, solange seine App *Aktivität* aktiviert ist, und ab der Version von Paperless Sync, die sie eingeführt hat: Frühere Läufe haben keine. Der Filter *Paperless Sync* in der Navigation der App Aktivität zeigt nur sie.

### Der Link zu einem neuen Dokument in Paperless öffnet sich nicht

Der Link einer importierten Datei in der App Aktivität, die Benachrichtigung einer aus Dateien gesendeten Datei und *In Paperless öffnen* in Dateien beginnen mit der gespeicherten Paperless-Adresse, also der Adresse, unter der der Nextcloud-Server Paperless erreicht. Ein Name oder eine Adresse, die nur der Server kennt, etwa `http://paperless:8000` in einem Docker-Netzwerk, führt den Browser ins Leere. Öffne das Dokument stattdessen über seine Nummer in Paperless, etwa *P123*, oder speichere eine Adresse, die sowohl der Server als auch die Browser erreichen, etwa die öffentliche Adresse von Paperless hinter seinem Reverse Proxy.

### Dokumente des Papierkorbs kommen spät

Der Papierkorb von Paperless zeigt einem Konto nur die Dokumente, die ihm gehören, und die ohne Eigentümer. Ein Dokument eines anderen, das in den Papierkorb kommt, verschwindet für die App aus beiden Listen, also gilt es als fehlend: Seine Kopie wandert erst nach der eingestellten Zahl vollständiger Scans in den Gelöscht-Ordner, unterhalb des Datums dieses Laufs statt des Datums des Papierkorbs. Sind endgültiges Löschen und *Auch Kopien von Dokumenten löschen, die ohne den Paperless-Papierkorb verschwunden sind* beide an, wird die Kopie dann sogar gelöscht, obwohl sich das Dokument in Paperless noch wiederherstellen lässt.

**Lösung:** Mach das Konto der App zum Eigentümer der Dokumente, mit einem Arbeitsablauf für neue und der Massenbearbeitung der Berechtigungen für die anderen, wie die [Anleitung](guide.de.md#paperless-verbindung) zeigt; beides behält den Zugriff der Menschen, denen sie jetzt gehören. Mach das Konto nicht zum Superuser: Es sähe den ganzen Papierkorb, aber sein Token beherrscht ganz Paperless.

### Eine Änderung in Paperless kommt nicht in Sekunden an

Abschnitt 6 der Einstellungen, *Änderungen in Sekunden*, zeigt den letzten Aufruf des Webhooks und was daraus wurde. Ohne letzten Aufruf hat Paperless die App nicht erreicht; das Protokoll von Paperless hat eine Zeile `Failed attempt sending webhook to …` mit dem Grund:

| Was du siehst | Ursache und Lösung |
| --- | --- |
| Kein letzter Aufruf und nichts im Protokoll von Paperless | Der Arbeitsablauf ist nicht gelaufen: Er braucht die Auslöser *Dokument hinzugefügt* und *Dokument aktualisiert*, und seine Filter müssen zum Dokument passen. **Lösung:** Prüfe den Arbeitsablauf in Paperless, und dass er aktiviert ist. |
| `Connection blocked: … resolves to a non-public address` im Protokoll von Paperless | Paperless lehnt Webhooks an Adressen seines Netzwerks ab, wenn `PAPERLESS_WEBHOOKS_ALLOW_INTERNAL_REQUESTS` `false` ist. **Lösung:** Setze es für ein Nextcloud in deinem Netzwerk auf `true`, oder nimm die öffentliche Adresse von Nextcloud. |
| `Destination port not permitted` oder `Invalid URL scheme` | `PAPERLESS_WEBHOOKS_ALLOWED_PORTS` oder `PAPERLESS_WEBHOOKS_ALLOWED_SCHEMES` von Paperless lassen die Adresse von Nextcloud aus. **Lösung:** Füge dort ihren Port oder ihr Schema hinzu. |
| `401 Unauthorized` | Das Geheimnis fehlt oder ist falsch, oder ein Proxy vor Nextcloud verwirft die Kopfzeile `Authorization`. **Lösung:** Trage unter *Webhook-Kopfzeilen* `Bearer`, ein Leerzeichen und das Geheimnis als Wert der Kopfzeile `Authorization` ein, oder erstelle ein neues Geheimnis; lass den Proxy die Kopfzeile durch, da auch die eigenen Clients von Nextcloud sie brauchen. |
| Aufrufe dauern immer länger | Aufrufe mit falschem Geheimnis haben den Brute-Force-Schutz von Nextcloud die Adresse von Paperless bremsen lassen. **Lösung:** Korrigiere das Geheimnis; der erste Aufruf mit dem richtigen beendet die Verzögerung. |
| `429 Too Many Requests` | Mehr als zehn Aufrufe mit falschem Geheimnis innerhalb von 30 Minuten: Paperless versucht jeden abgelehnten Aufruf noch dreimal, also reichen drei Ereignisse. Nextcloud lehnt die Adresse jetzt ab, bevor die App den Aufruf sieht, also kann das richtige Geheimnis es nicht beenden. **Lösung:** Korrigiere das Geheimnis, dann warte bis zu 30 Minuten, oder führe sofort `occ security:bruteforce:reset <Adresse>` aus. Mehr als 120 Aufrufe in der Minute antworten auch mit 429; die geplanten Läufe holen sie nach. |
| `400 Bad Request` | Der Aufruf nennt kein Dokument. **Lösung:** Schalte *Parameter für Webhook-Inhalt verwenden* und *Webhook-Payload als JSON senden* ein, und füge den Parameter `document` mit `{{ doc_id }}` hinzu, oder `{{ doc_url }}` mit Paperless-ngx 2.x. Ist die Antwort die HTML-Seite *Zugriff über eine nicht vertrauenswürdige Domain*, ruft Paperless Nextcloud mit einem Hostnamen auf, der nicht in `trusted_domains` steht, etwa dem Namen seines Containers. **Lösung:** Füge diesen Hostnamen zu `trusted_domains` in `config.php` hinzu, oder nimm die Adresse, die der Browser verwendet. |
| `404 Not Found`, und der letzte Aufruf sagt, Paperless zeige kein aktives Dokument | Paperless hat aufgerufen, bevor es ein neues Dokument gespeichert hatte, oder das Dokument ist in seinem Papierkorb. Paperless versucht es nach ein paar Sekunden noch einmal, was ein neues Dokument erwischt; den Papierkorb übernehmen die geplanten Läufe. **Lösung:** Nichts zu tun, außer das Dokument ist in Paperless aktiv und das Konto des Tokens kann es nicht sehen: Dann gib diesem Konto die Berechtigung, es anzuzeigen. |
| `409 Conflict`, und der letzte Aufruf sagt, nichts habe sich geändert | Die geplante Synchronisation oder der Export ist aus, oder Paperless ist nicht verbunden. **Lösung:** Schalte sie in den Abschnitten 1 und 2 ein und speichere. |
| `ReadTimeout` im Protokoll von Paperless, aber die Kopie hat sich geändert | Paperless wartet fünf Sekunden auf die Antwort, und ein großes Dokument braucht länger; der Lauf wurde trotzdem fertig. **Lösung:** Nichts zu tun, oder schalte *Dokument während des Aufrufs spiegeln* aus, damit die App sofort antwortet und die Hintergrundjobs das Dokument übernehmen. |
| Der letzte Aufruf sagt *wartet auf die nächsten Hintergrundjobs* | Ein anderer Lauf hielt die Sperre, oder *Dokument während des Aufrufs spiegeln* ist aus. Die nächsten Hintergrundjobs spiegeln das Dokument, innerhalb von fünf Minuten mit dem System-Cron. |
| Der letzte Aufruf sagt *Paperless zeigt kein aktives Dokument* | Das Dokument ist im Papierkorb von Paperless, gelöscht oder für das Konto der App nicht sichtbar. Den Papierkorb übernehmen die geplanten Läufe; zu den Berechtigungen siehe [Dokumente des Papierkorbs kommen spät](#dokumente-des-papierkorbs-kommen-spät). |

## Dateien

### Andere Benutzer sehen das Archiv nicht

Das Archiv liegt allein in den Dateien des Zielbenutzers; die App gibt niemand anderem Zugriff. Teile seinen Ordner, etwa `Dokumente/Paperless/Archiv`, aus *Dateien* mit Benutzern oder mit einer Gruppe, und am besten nur zum Lesen, da nichts von dort zu Paperless zurückgeht und ein Lauf eine bearbeitete Kopie ersetzen oder eine gelöschte neu schreiben kann. Teile den Eingangsordner nur mit denen, die Dokumente an Paperless schicken dürfen: Was ihn erreicht, geht unter dem Konto der App zu Paperless.

### Gelöschte Kopien sind nicht im Papierkorb

Eine Kopie, die ein Lauf löscht, und eine Datei im Weg einer Kopie, die wandert, kommen in den Papierkorb des Zielbenutzers, *Gelöschte Dateien* in *Dateien*, wo nur der Zielbenutzer sie sieht und wiederherstellt: Ein Administrator, der den Lauf gestartet hat, findet sie nicht in einem eigenen Papierkorb. Mit Eigentum je Benutzer kommen die Kopien jedes Benutzers in den Papierkorb dieses Benutzers. Das gilt für jeden Lauf, wer oder was ihn auch startet, auch in einem Prozess, der zuerst die Dateien eines anderen Benutzers eingerichtet hat; in früheren Versionen lief ein Lauf von *Jetzt synchronisieren* in der Anfrage des Administrators und ein Lauf mit Eigentum je Benutzer in den Dateien eines Benutzers, und sie löschten solche Dateien endgültig. Ohne die App *Gelöschte Dateien* von Nextcloud ist jede Löschung endgültig. Eine Kopie, deren Dokument einen neuen Inhalt bekommt, ist keine Löschung: Sie bleibt dieselbe Datei, mit dem vorigen Inhalt als Version.

### An Paperless senden fehlt im Menü einer Datei

Dateien bietet *An Paperless senden* den Mitgliedern der Gruppe in *Gruppe, die Dateien senden darf*, in *4 · Nextcloud-Eingang*, an, standardmäßig den Administratoren, solange die App ihr API-Token hat: Ein leeres Feld oder *Trennen* nimmt die Aktion weg, und eine Gruppe, in der du nicht Mitglied bist, auch. Lade Dateien nach einer Änderung neu. Das Menü lässt die Aktion weg für eine Datei, deren Name eine Markierung wie `[P123]` trägt, eine Kopie eines Dokuments, das Paperless hat, für eine Datei, die du nicht lesen darfst, und im Papierkorb; ein Klick auf eine Datei im Basisordner der App, etwa in ihrem Eingang, antwortet, dass sich die Läufe um sie kümmern: Verschiebe eine Datei in den Eingang, um sie von dort zu senden. Nach dem Klick geht die Datei mit dem nächsten Lauf der Hintergrundjobs von Nextcloud, sie wartet also, solange Cron nicht läuft, wie [Kein Lauf startet](#kein-lauf-startet) beschreibt.

### Eine Datei ist zurück, nachdem ich sie gelöscht oder umbenannt habe

Das Archiv spiegelt Paperless: Der nächste Lauf schreibt eine Kopie, die an ihrem Pfad fehlt, neu. Eine umbenannte oder verschobene Kopie bleibt, wo du sie hingelegt hast, als eigene Datei, die die App nicht mehr aktualisiert. Um ein Dokument aus dem Archiv herauszuhalten, gib ihm einen Tag, den die Einstellungen ausschließen; um es zu entfernen, lösche es in Paperless.

## Neu beginnen

Paperless Sync merkt sich, was es für jeden Zielbenutzer geschrieben hat. Je nachdem, was du neu beginnen willst:

- **Das Archiv neu schreiben:** Schalte die Synchronisation aus, lösche den Archivordner in den Dateien des Zielbenutzers, was ihn in dessen Papierkorb verschiebt, und starte einen Testlauf: Er listet einen Export für jedes Dokument auf. Schalte die Synchronisation wieder ein, und die Läufe schreiben das Archiv neu, im Rahmen der Batchgröße. Die Kopien des Gelöscht-Ordners kommen nicht zurück.
- **Ein neues Archiv beginnen:** Gib einen anderen Zielbenutzer ein. Die App hält die Einträge jedes Benutzers auseinander, also beginnt das neue Archiv leer, und das alte bleibt, wie es ist.
- **Die Verbindung entfernen:** *Trennen* löscht das Token und schaltet den Zeitplan und die Einstellung zum lokalen Netzwerk aus; die übrigen Einstellungen und die Einträge dessen, was es geschrieben hat, bleiben für eine spätere Verbindung. Die [FAQ](faq.de.md#wie-deinstalliere-ich-paperless-sync) sagen, was das Deinstallieren zurücklässt.

Eine Datei des Eingangs, die Paperless abgelehnt hat, wartet, bis sie sich ändert: Speichere sie neu, benenne sie um oder ersetze sie, um sie erneut zu übergeben.
