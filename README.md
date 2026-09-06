# LoxBerry-Plugin Ultraschall Entfernung

Misst mit einem Ultraschallsensor den Abstand zu einer Fläche und meldet ihn dem
Loxone Miniserver — auf Wunsch umgerechnet in Füllstand (%) und Inhalt (Liter).
Typischer Einsatz: Zisterne, Regenwassertank, Heizöltank, Futtersilo.

## Neu in 1.2.4

**Der Dienst konnte sein Protokoll verlieren, ohne dass es auffiel.**

Am 06.09.2026 an einem laufenden LoxBerry gemessen — aufgefallen am
Heimkino-Plugin, das sieben Stunden lief und keine Protokolldatei hatte:
`log/plugins` liegt auf einer **Ramdisk** (`/dev/zram0`). Wird sie geleert,
ist die Datei fort — und ein `logging.FileHandler`, der sie beim Start
**einmal** geöffnet hat, schreibt bis zum nächsten Neustart in einen
gelöschten Inode. Es gibt keine Fehlermeldung; es gibt gar nichts.

Diese Fassung benutzt deshalb `logging.handlers.WatchedFileHandler`. Der
prüft bei jeder Zeile Gerätenummer und Inode und öffnet nötigenfalls neu; er
steht in der Standardbibliothek und ist für genau diesen Fall gebaut.

Auf dem Gerät geeicht, in beide Richtungen: mit dem alten Handler ist die
Zeile nach dem Löschen verloren, mit dem neuen steht sie in der wieder
angelegten Datei. Auf einem Windows-Arbeitsplatz lässt sich das nicht
messen — dort kann eine offene Datei gar nicht gelöscht werden.

Dieselbe Bauart hatten APC-UPS NG, BLE-Scanner NG, Heimkino und Ultraschall
Entfernung; alle vier sind am selben Tag nachgezogen worden. Über alle
Plugin-Ordner gezählt (06.09.2026) benutzen jetzt genau diese vier den
`WatchedFileHandler`.

**Eine fünfte Stelle ist offen und soll hier benannt sein, statt zu fehlen:**
Skoda Connect NG stand hier zunächst als Ausnahme mit der Begründung, ein Cron
starte das Programm bei jedem Lauf neu. Nachgemessen trifft das nicht zu: der
Cron ruft dort nur `waechter` und `wachzeichen`; der eigentliche Dienst läuft
dauerhaft (`bin/dienst.sh`, `nohup … &`). In diesem Zweig steht ein
`RotatingFileHandler` — der hält ebenfalls einen offenen Deskriptor und öffnet
nur bei seiner **eigenen** Größenrotation neu, nicht wenn die Datei unter ihm
verschwindet. Die Bauart ist dort also dieselbe, nur in anderem Gewand, und
noch nicht behoben.

**Weiter:** ein umgeschriebenes Wort im deutschen Hilfetext
berichtigt (`haelt` → `hält`).


## Neu in 1.2.3

- **Das Auswahlfeld zeichnet seinen Pfeil selbst.** Bis 1.2.2 kam er von der
  Oberfläche des LoxBerry. Am 05.09.2026 am Gerät gemessen (LoxBerry 4.0.0.15,
  `system/css/components.css`): deren Regel `.lb-content select`
  gibt es erst seit der neuen Oberfläche, und jede eigene Feldregel mit der
  Kurzform `background:` löscht sie wieder. Darauf soll sich eine
  Plugin-Oberfläche nicht verlassen (`Regeln/04`). Sonst ist an dieser
  Fassung nichts geändert.

## Herkunft und Pflege

Grundlage ist das Plugin **Ultraschall Entfernung** von **Dietmar Wimmer**,
Version 0.30 aus dem Jahr 2015
([LoxBerry-Wiki](https://wiki.loxberry.de/plugins/ultraschall_entfernung/start)).

**Die Urheberschaft bleibt bei ihm** — die Autorenangabe in `plugin.cfg` ist
unverändert. Das ist nicht nur eine Frage der Zuordnung: LoxBerry identifiziert
ein Plugin über genau die Felder `NAME` und `EMAIL` im Abschnitt `[AUTHOR]`. Wer
sie ändert, macht daraus für LoxBerry ein anderes Plugin, und jedes Update
schlägt fehl.

> **Zur Lizenz — bitte lesen.** Die Originalfassung enthält **keine
> Lizenzdatei**, nur den Vermerk `#C Dietmar Wimmer 2015` in den Quelltexten.
> Ohne ausdrückliche Lizenz ist fremder Code streng genommen nicht frei
> weiterverwendbar. Diese Fassung nennt den Autor unverändert und versteht sich
> als Weiterpflege eines seit 2015 nicht mehr aktualisierten Plugins.
>
> **Dietmar Wimmer wurde dazu nicht gefragt.** Wer hier etwas beanstandet —
> insbesondere er selbst — melde sich über ein Issue in diesem Repository; die
> Fassung wird dann zurückgezogen oder angepasst.
>
> Für alles, was gegenüber der Originalfassung neu geschrieben wurde, gilt die
> MIT-Lizenz (siehe `LICENSE`). Auf den ursprünglichen Bestand kann sich diese
> Freigabe naturgemäß nicht erstrecken.

## Version 1.2.2 — was still bediente, meldet jetzt

Ein Durchgang Zeile für Zeile durch alle 32 Dateien. Was dabei gefunden
wurde, hat eine gemeinsame Gestalt: **das Plugin hat bedient, wo es hätte
melden müssen** — es lieferte eine plausible Zahl statt einer Fehlanzeige.

### Zahlen, die keine sind

- **Eine negative Entfernung war ein Messwert.** Bis 1.2.1 wurde ein nach
  der Korrektur negatives Ergebnis auf `0.0` gesetzt. Gemessen mit
  `offset_cm = -50` und einem Sensorwert von 30 cm: Ergebnis 0,0 cm, daraus
  mit `leer_cm=100`/`voll_cm=20` ein Füllstand von **100 %** und der volle
  Behälterinhalt in Litern — mit `valid=1`. In Loxone stand „randvoll", wo
  in Wahrheit eine unmögliche Zahl herauskam. Jetzt gibt es keinen Wert,
  und der Grund steht dabei.
- **Vertauschte Kalibrierung lief rückwärts.** Abgefangen war nur
  `leer == voll`. Gemessen mit `leer_cm=20`/`voll_cm=100`: 25 cm ergaben
  6,2 %, 95 cm ergaben 93,8 % — je weiter der Wasserspiegel weg, desto
  voller. Beide Felder sind in der Oberfläche unabhängig voneinander
  geprüft; es gab nichts, was widersprochen hätte. Jetzt wird `leer <= voll`
  als Widerspruch gemeldet (`FEHLER.LEER_VOLL`) und kein Füllstand
  gerechnet.
- **Ein unlesbarer Zahlenwert fiel lautlos auf die Vorgabe zurück.** Die
  Oberfläche nimmt das deutsche Komma an und schreibt einen Punkt in die
  Datei — eine von Hand bearbeitete Datei mit `offset_cm=1,5` wurde aber
  ohne ein Wort zu `0`. Jetzt steht es im Protokoll und im Reiter *Test*.
- **Die Messschleife hatte keine Obergrenze.** `messungen=100000` in der
  Textdatei ließ den Dienst nicht mehr aus dem Durchgang heraus: keine
  Zustandsdatei, kein MQTT, kein Herzschlag — und der Wächter startet ihn
  nicht neu, weil der Prozess ja läuft. Jetzt bei 180 s gekappt, laut. Was
  das Formular zulässt (25 Messungen à 5 s = 120 s), bleibt unangetastet.

### MQTT

- **Ein misslungener erster Verbindungsversuch war endgültig.** Beim
  Systemstart fahren Broker und Gateway noch hoch, der erste Versuch
  scheitert — das ist der Normalfall. Bis 1.2.1 blieb ein halb aufgebauter
  Client stehen; `senden()` prüfte nur `if not $client` und veröffentlichte
  danach in eine nie verbundene Verbindung. MQTT war für die ganze Laufzeit
  des Prozesses tot, und im Broker standen weiter die zurückbehaltenen
  Werte von vorher. Jetzt wird nachgefasst: nach einer Minute, dann immer
  seltener, höchstens alle fünf Minuten — und nach geglückter Verbindung
  wird **alles** neu gesendet, weil der Broker die Werte nicht kennt.

### Der Zeitstempel sagt wieder die Wahrheit

- **`TS` wurde auch ohne Messung aufgefrischt.** Damit meldete der Endpunkt
  `OK=1`, während seit Stunden nichts gemessen wurde. Jetzt wandert `TS` nur
  bei einer **gelungenen** Messung; dass der Dienst lebt, sagt der neue
  Schlüssel `herzschlag` in der Zustandsdatei (kein neues MQTT-Thema — dafür
  gibt es `zaehler`). Ein Dienstneustart übernimmt den letzten Zeitstempel
  aus der Zustandsdatei, statt eine gelungene Messung von vorhin für nie
  geschehen zu erklären.
- **Nach einem Sensorfehler wurde der Sensor nie neu geöffnet.** Ein Wackler
  am I2C-Kabel hieß: das alte Busobjekt bleibt, jede weitere Messung
  scheitert daran, die Meldung wird auf eine je Stunde gedämpft — und der
  Fehler überlebte bis zum nächsten Dienstneustart. Jetzt wird der Sensor
  bei einem **Sensor**fehler geschlossen und neu aufgebaut; bei bloß
  unplausiblen Werten nicht, denn da hilft es nicht.

### Oberfläche

- **Ohne Aktionstoken war die Seite eine Sackgasse.** Fehlte die
  Konfigurationsdatei, gab es kein Token; ohne Token wies der Wachposten
  jeden POST ab — auch den, der ein Token erzeugt hätte. Jetzt legt der
  erste Seitenaufruf die Datei an, der Knopf *Neues Token erzeugen* ist
  immer sichtbar, und er ist die **einzige** Ausnahme, die ohne Token
  angenommen wird.
- **Ein geleertes Themenpräfix fiel still auf `ultraschall` zurück** — der
  Dienst veröffentlichte ab da unter einem anderen Präfix, im Miniserver kam
  nichts mehr an. Jetzt bleibt der bisherige Wert stehen, und es wird
  beanstandet.
- **Eine Sicherung aus 1.1.x löschte das Aktionstoken**, weil sie keines
  enthält. Jetzt bleibt das vorhandene stehen.
- **Die Seite nannte acht virtuelle Eingänge, die Vorlage legt sieben an.**
- Der Speichern-Knopf trägt jetzt `sm-b-aktion`; die Beispielzeile und die
  Befehle-Tabelle nennen `OK` und `ALTER` mit; vier neue Sprachschlüssel in
  **beiden** Dateien (jetzt je 349, deckungsgleich).

### Installation, Dienst, Wächter

- `uninstall/uninstall` suchte die LoxBerry-Wurzel über vier feste `..`
  und löschte `/tmp/ultraschall.SAVE` — bei einer Zweitinstallation traf das
  die Sicherung der **ersten**. Jetzt Aufwärtssuche und nur der eigene
  Ordner.
- `daemon/daemon` legte den Protokollordner unter Umständen als `root` an;
  der Dienst startete beim Systemstart dann gar nicht. Jetzt mit
  Eigentumsübergabe und Nachschau, ob er wirklich läuft.
- `cron/cron.05min` startet den Dienst als `loxberry`, wenn der Lauf selbst
  `root` ist. Unter welchem Benutzer LoxBerry `system/cron/cron.05min`
  ausführt, ist **nicht gemessen** — deshalb wird gefragt (`id -u`), nicht
  angenommen.
- `postinstall.sh` verglich die Konfiguration gegen eine fest eingetragene
  SHA-256-Summe; jetzt gegen die mitgelieferte Datei selbst. Schlägt das
  Eintragen von I2C in `config.txt` fehl, steht es als Warnung da, statt
  still zu bleiben.
- `postupgrade.sh` behauptete die Rücksicherung; jetzt wird sie nachgemessen.
- `--einmal` gibt es nicht mehr. Der Schalter stand in der Positivliste,
  wurde angenommen — und danach las keine Zeile `sys.argv` wieder: der
  Aufruf landete in der Dienstschleife. Wer der eingebauten Hilfe folgte,
  startete ein **zweites** Exemplar neben dem laufenden Dienst, beide am
  selben Sensor. Den Einmalabruf macht `bin/us_messen.py`.
- `ARCHITECTURE=false` statt leer; `bin/us_common.py` trägt wieder die
  richtige Fassungsnummer (sie stand seit 1.2.0 auf `1.2.0`).

### Zeilenenden

`plugin.cfg`, `release.cfg`, `prerelease.cfg` und beide `language_*.ini`
liegen jetzt auch im Arbeitsordner als LF — so, wie der Anwender sie seit
v1.1.11 aus jedem Tag-Archiv bekommt (`.gitattributes` normalisiert beim
Einchecken). Für bestehende Installationen ändert das kein Byte.

### Geprüft

Ein eigener Prüfstand, `Pruefung-Ultraschall-1.2.2/`:

* `messen.py` — **28 Prüfungen an der laufenden Seite** über HTTP gegen
  `php -S` mit **getrennten Bäumen**, unter PHP 7.4.33 **und** 8.4.24: beide
  0 Beanstandungen. Gegen 1.2.1 werden **12 von 12** Eichfällen rot (unter
  7.4 zehn — zwei Fälle prüfen PHP-8-Warnungen, die unter 7.4 Notices sind
  und vom `error_reporting` des Hausstandards unterdrückt werden).
* `kern.py` — **23 Prüfungen am Python-Teil** mit einem Sensor-Stellvertreter:
  0 Beanstandungen, gegen 1.2.1 **15 von 15** Eichfällen rot.

**Ungemessen und nicht behauptet:** der Betrieb an einem echten Sensor (es
gibt keinen), `retain` am laufenden MQTT-Gateway, das Mithören fremder
Themen am Broker, der Cron-Wächter unter einem echten `crond` und der
Endpunkt aus einem echten Miniserver.

## Version 1.2.1 — die Überschrift für Gateway V2

Gemessen am Tag-Archiv (v1.2.0 gegen v1.2.1, Datei für Datei): 1.2.1 hat
**einen** Sprachschlüssel geändert, in beiden Sprachdateien —
`SCHRITT_2_ABO_IM_MQTT_GATEWAY_EINT` von „Schritt 2: Abo im MQTT-Gateway
eintragen" auf „Schritt 2: Das Abo im MQTT-Gateway" (englisch entsprechend).
Dazu die Fassungsnummer in `plugin.cfg`. `release.cfg` und `prerelease.cfg`
tragen im Tag-Archiv v1.2.1 noch die Nummer 1.2.0 — der bekannte Nachlauf:
die beiden Dateien werden erst nach dem Tag gezogen.

Sonst ist keine Datei angefasst worden.

## Version 1.2.0 — Endpunkt, Aktionstoken, Lebenszeichen

### Ein eigener Endpunkt für den Miniserver

Bis 1.1.12 gab es genau einen Weg zum Miniserver: das MQTT-Gateway. Wer es
nicht betreibt oder wer einen Wert **abfragen** statt zugeschickt bekommen
will, stand vor nichts. 1.2.0 hat einen eigenen Endpunkt:

```
http://<loxberry>/plugins/ultraschall/index.php?token=<TOKEN>&aktion=status
-> ULTRA;OK=1;DISTANCE=123.4;LEVEL=42.1;LITER=2105;VALID=1;ONLINE=1;TS=…;ZAEHLER=418;ALTER=7
```

Er liegt in `webfrontend/html/` — dem Baum **ohne** Anmeldung; anders wäre er
für den Miniserver nicht erreichbar. Deshalb ist er durch ein **Aktionstoken**
geschützt, das beim ersten Anlegen der Konfiguration entsteht und im Reiter
*Einbindung in Loxone* steht. Ohne gültiges Token antwortet er mit HTTP 403 und
`ERR=TOKEN`; ist überhaupt keines eingerichtet, mit `ERR=KEIN_TOKEN_EINGERICHTET`
statt wahllos zu antworten. `?selftest=1&token=…` ist die stille Probe: sie
sagt nur, ob das Token stimmt.

Die Suchtexte für die virtuellen Eingänge stehen im Reiter *Einbindung in
Loxone* und tragen alle das führende Semikolon (`\i;DISTANCE=\i\v`). Ohne das
läse ein Eingang mit kurzem Namen den erstbesten längeren mit — der Fehler
meldet sich nie, er zeigt nur die falsche Zahl.

### Lebenszeichen: `ts`, `zaehler`, `online`

Ein Plugin, das schweigt, sieht in Loxone aus wie ein Plugin, dessen Wert sich
nicht ändert. Der Dienst sendet deshalb **in jedem Durchgang** drei Werte, und
zwar an der Doppelmeldungssperre vorbei:

* `ts` — der Zeitpunkt der Messung,
* `zaehler` — 0…999 und wieder von vorn; **-1 heißt: noch kein Durchgang**,
* `online` — 1, solange der Dienst läuft.

Der Endpunkt rechnet daraus `ALTER` in Sekunden und setzt `OK=0`, sobald die
Werte älter sind als `max(180, 3 × Takt)`. In Loxone genügt damit ein Blick auf
`ONLINE`, um zwischen "steht still" und "misst denselben Wert" zu unterscheiden.

### Ein Wachposten vor jedem Formular

Jedes Formular trägt ein Merkmal, das aus dem Aktionstoken abgeleitet, aber
nirgends gespeichert wird. Fehlt es oder stimmt es nicht, wird `$_POST` geleert
und die Seite meldet, dass das Formular nicht von ihr kam. Gemessen: ein
untergeschobenes "Dienst anhalten" und ein untergeschobenes "neues Token"
bewirken beide nichts, dieselben Formulare mit Merkmal wirken.

### Cron-Wächter alle fünf Minuten

`cron/cron.05min` sieht nach, ob der Dienst läuft, während er laufen soll —
Sollmerker ist `enabled=1` in der Konfiguration, nicht eine Datei nebenher. Er
sucht den Prozess **argumentweise** statt über einen Namensschnipsel, damit
eine Zweitinstallation nicht die erste erwischt. Läuft alles, schweigt er.

### Ein eigener Reiter für MQTT

MQTT-Haken und Themenpräfix standen bisher zwischen den Sensoreinstellungen.
Jetzt hat MQTT einen eigenen Reiter, und jedes Formular fasst **ausschließlich
seine eigenen Schlüssel** an. Das ist kein Schönheitsgrund: Ein nicht
angehakter Haken steht überhaupt nicht im Formularinhalt. Läse der
Einstellungszweig alle Felder, würde aus "MQTT ein" beim Speichern der
Sensorwerte still ein "MQTT aus".

### Eine Quelle für Vorgaben und Felder

`bin/us_vorgaben.json` nennt die 22 Vorgabewerte und die 8 Felder mit Art,
Einheit und Bereich. Python und PHP lesen dieselbe Datei. Vorher standen die
Grenzen an drei Stellen, und die Loxone-Vorlage trug `MinVal="-2147483647"` —
eine Zahl, die nichts über den Wertebereich aussagt und in Loxone Config jede
Plausibilitätsprüfung aushebelt. Findet Python die Datei nicht, startet der
Dienst **nicht**; er rät nicht.

### Behoben beim Nachmessen

* **Das Aktionstoken überlebte kein Speichern.** `us_pruefen()` beginnt bei den
  Vorgaben und füllt nur die Schlüssel, die sie selbst prüft — das Token war
  keiner davon, also stand nach jedem Speichern die Vorgabe darin, und die ist
  leer. Wirkung: jede Adresse im Miniserver wäre tot gewesen und die
  Sicherungsdatei wertlos. Gefunden hat es keine Syntaxprüfung, sondern eine
  Messung, die nach dem Speichern **in die Konfigurationsdatei sah**.

## Version 1.1.12 — zwei tödliche Fehler

Kleine Korrekturfassung auf 1.1.11, ohne neue Funktionen.

* **`us_cfg()` ohne Argumente.** Die Oberfläche rief die Funktion an einer
  Stelle ohne ihre Parameter auf. Unter PHP 7.4 wie unter 8.4 gemessen:
  Rückgabewert 255, Seite leer. Der Reiter *Einstellungen* war damit in der
  ausgelieferten Fassung nicht benutzbar.
* **Der Sicherungsblock lief nach dem Seitenkopf.** Ein `header()` nach der
  ersten Ausgabe wirkt nicht; die Sicherungsdatei kam als Text mitten in der
  Seite an. Der Block steht jetzt vor `LBWeb::lbheader()`.
* Eine gemeinsame Prüfung `us_pruefen()` für Formular **und** Sicherungsdatei,
  damit beide Wege nicht auseinanderlaufen.
* Die Logdatei des Dienstes wird bei 500 kB gekappt.
* Der Reiter *Test* behauptete, das MQTT-Gateway sei ein eigenes Plugin. Es ist
  seit LoxBerry 3 Bestandteil des Systems.

## Version 1.1.2 — nachgemessen und korrigiert

### Der Ramdisk-Ordner trägt jetzt den Plugin-Namen

1.1.2 hatte `status.json` und `dienst.pid` aus dem Wurzelverzeichnis der
Ramdisk in einen eigenen Unterordner geholt — mit der Begründung, dort
kollidierten gleichnamige Dateien mit jedem anderen Plugin. Das Argument
stimmt, war aber eine Ebene zu kurz gedacht: Der Ordner hieß fest
`/run/shm/ultraschall`, unabhängig davon, wie die Installation heißt.

Hängt LoxBerry bei einer Zweitinstallation einen Zähler an (`ultraschall_01`),
teilten sich **beide** Installationen dieselben zwei Dateien:

* `status.json` — die Oberfläche der zweiten zeigte den Messwert der ersten.
  Zwei Sensoren, ein angezeigter Wert, und nichts deutet darauf hin, dass er
  vom falschen Behälter stammt.
* `dienst.pid` — die zweite überschriebe die PID der ersten. Ein Stopp träfe
  dann den falschen Dienst, und der Wächter hielte einen abgestürzten Dienst
  für laufend.

Betroffen waren vier Stellen, die zusammenpassen müssen: `bin/us_common.py`
(schreibt), `webfrontend/htmlauth/us_lib.php` (liest), `preupgrade.sh` (hält
den Dienst an) und `uninstall/uninstall` (räumt auf). Alle vier bilden den
Pfad jetzt aus dem Plugin-Ordner. **Bei einer einzelnen Installation ändert
sich nichts** — der Ordner heißt dann weiterhin `ultraschall`.

Dabei fiel auf, dass die Ordner-Ermittlung in `us_lib.php` ohnehin nie
funktionierte: Installiert liegt die Datei unter
`webfrontend/htmlauth/plugins/<ordner>/`, die beiden Rückfälle ergaben also
`htmlauth` und `plugins` — nie einen Plugin-Ordner. Übrig blieb immer der
feste Name. Jetzt hat `LBPPLUGINDIR` Vorrang, danach der eigene Ablageort.


Siebzehn Punkte aus einer Durchsicht. Elf trafen zu, drei teilweise, drei
nicht. Alles wurde nachgestellt, bevor etwas geändert wurde.

### Der vorgeschlagene HC-SR04-Fix hätte den Sensor blind gemacht

Beanstandet war die Zeile `if wert >= self.max_m * 100.0 - 0.5:` — sie
verwerfe alle Messwerte ab 399,5 cm. Das stimmt, und der Abzug ist auch weg.
Die vorgeschlagene Ersetzung durch `if wert > self.max_m * 100.0:` wäre
allerdings ein schwerer Fehler gewesen. gpiozero begrenzt in
`DistanceSensor._read`:

```python
return min(1.0, distance / self._max_distance)
```

Bei einer Zeitüberschreitung ist der Wert also **exakt** der Maximalwert —
nachgerechnet für `max_m` 0,5 / 2,0 / 4,0 / 4,5 stimmt die Gleichheit auf die
letzte Stelle:

| Bedingung | bei Zeitüberschreitung |
|---|---|
| `>= grenze - 0.5` (bisher) | greift — verwirft zusätzlich 5 mm Messbereich |
| `> grenze` (Vorschlag) | **greift nie** — „nichts gehört" wird zu „Gegenstand in 4 m" |
| `>= grenze` (jetzt) | greift |

### Weitere zutreffende Punkte

**`VERSION = "1.0.0"`** in `us_common.py`, während überall sonst 1.1.1 stand.
Jede MQTT-Meldung und die Zustandsdatei nannten damit eine Fassung, die es
nicht mehr gab. Jetzt 1.1.2, wie in den drei cfg-Dateien.

**Testmessung und Dienst griffen gleichzeitig auf die Hardware.** Läuft der
Dienst, wird jetzt nicht mehr selbst gemessen, sondern sein letzter Stand
gelesen — mit Angabe, wie alt er ist, und dem Hinweis, dass man den Dienst
anhalten kann. Der Grund steht im Code: bei I2C serialisiert der Kern zwar
einzelne Übertragungen, aber nicht die Folge aus Schreiben, Warten und Lesen —
heraus kommt ein Wert, der zu keiner der beiden Anfragen gehört, still und
falsch. Bei GPIO belegt lgpio die Leitung ausschließlich, da scheitert der
zweite Zugriff wenigstens laut.

**`timeout 40`** aus dem Webfrontend: jetzt 12 Sekunden, mit eigener Meldung
bei Rückgabewert 124. Ein Sensor, der 40 Sekunden nicht antwortet, antwortet
auch nach zwölf nicht — der Webserver bricht vorher ab.

**Konfiguration nicht atomar geschrieben**, auf beiden Seiten. Der Dienst
prüft die Datei im Sekundentakt auf Änderungen; trifft er das Fenster
zwischen Kürzen und Füllen, liest er eine halbe Konfiguration. Jetzt
`temp + rename` in PHP und `os.replace` in Python.

**Träges Ansprechen bei ausgeschaltetem Plugin.** Die Konfiguration wurde nur
einmal je Durchgang geprüft — bei einem Takt von 300 s also alle fünf
Minuten. Gemessen: Änderung nach 0,5 s ausgelöst, bisher noch nach 3 s in der
Ruhephase (echt bis zu 300 s), jetzt nach 0,50 s erkannt. Der Vorschlag, bei
ausgeschaltetem Plugin kürzer zu schlafen, deckt nur die Hälfte ab: dasselbe
Warten trifft, wer den Takt von 300 auf 10 stellt. Ein `stat()` je Sekunde
löst beide Fälle.

**Dateien frei auf der Ramdisk**, **`Content-Disposition` ohne
Anführungszeichen**, **`su` ohne ausdrückliche Shell**, **`/tmp` als
Sicherungsort beim Upgrade**, **Reste im Uninstall** — alles umgesetzt.

Zum Upgrade noch eine Berichtigung: der übliche Zusatz, man solle `$1`
verwenden, das sei der Pfad des Installers, trifft nicht zu. `$1` ist eine
zehnstellige Zufallskennung (`&generate(10)` in `plugininstall.pl`); der
absolute Arbeitsordner kommt als **sechstes** Argument. Dorthin wird jetzt
gesichert, mit Rückfall auf den alten Weg. Beide Wege nachgestellt: Sensortyp,
Pins, Behältermaße und Takt überstehen das Upgrade, es bleiben keine Reste.

### `paho-mqtt`: umgestellt, weil es hier gefahrlos ist

`CallbackAPIVersion.VERSION1` gilt seit paho 2.0 als veraltet. Die Umstellung
auf VERSION2 ist hier **deshalb** unbedenklich, weil dieses Plugin gar keine
Rückrufe anmeldet — es veröffentlicht nur. Die Unterschiede zwischen den
Schnittstellen betreffen ausschließlich die Aufrufform von `on_connect`,
`on_message` und Geschwistern. Wer hier später einen Rückruf ergänzt, findet
den Hinweis auf die neue Form im Code.

### Was nicht zutraf

**Der Daemon starte nach einem Neustart nicht**, weil `REPLACELBPBINDIR`
nicht ersetzt werde. Es wird ersetzt. In `plugininstall.pl`:

```
s#REPLACELBPBINDIR#$lbhomedir/bin/plugins/$pfolder#g;
```

Die Ersetzung läuft über **alle** Textdateien des Pakets, bevor irgendetwas
kopiert wird. Die vorgeschlagene Abhilfe wäre die Verschlechterung gewesen:
`REPLACEBYBASEFOLDER` und `REPLACEBYSUBFOLDER` stehen **nicht** in der Liste
des Installers — sie wären wörtlich stehen geblieben, und dann hätte der
Daemon tatsächlich nicht mehr gestartet.

**`/etc/modules` werde mit Duplikaten geflutet.** Nachgestellt mit je zehn
Läufen und fünf Ausgangslagen — leer, Eintrag vorhanden, Eintrag mit
Leerraum, auskommentiert, Teilwort `i2c-dev-alt` — blieb es in **jedem** Fall
bei genau einem Eintrag. Der Ausdruck trägt.

Umgestellt wurde trotzdem auf `/etc/modules-load.d/ultraschall.conf`, weil
eine eigene Datei unteilbar richtig ist: sie wird geschrieben, nicht
angehängt, und beim Deinstallieren lässt sie sich entfernen, ohne in einer
fremden Systemdatei zu schneiden. Dabei ist `i2c-bcm2708` entfallen — der
Treiber heißt seit Jahren `i2c-bcm2835` und wird ohnehin über den Gerätebaum
geladen; ein Modul einzutragen, das es nicht gibt, erzeugt bei jedem Start
eine Fehlermeldung im Systemprotokoll.

**`python3-gpiozero` und `python3-lgpio` fehlten in `dpkg/apt`.** Sie stehen
dort, mit Begründung, seit 1.1.0.

**`tail` statt `file_get_contents` beim Protokoll.** Der Speicherhinweis war
berechtigt, `tail` ist aber der langsamste der drei Wege — 1,9 ms gegen
0,05 ms beim Rückwärtslesen mit `fseek`, bei knapp doppeltem Speicherbedarf
gegenüber `tail` und einem Zwanzigstel gegenüber dem bisherigen Weg.

### Nebenbefund: doppelte Installationslogik

`postupgrade.sh` enthielt eine wortgetreue Kopie des halben `postinstall.sh` —
I2C einschalten, Module eintragen, Gruppen zuordnen, Rechte setzen. Der
Installer führt `postinstall` ohne Bedingung aus und `postupgrade` erst
danach; alles war bereits erledigt. Schlimmer als die verlorene Zeit ist die
Verdopplung selbst: zwei Kopien derselben Logik laufen auseinander, und dann
verhält sich das Plugin nach einem Upgrade anders als nach einer
Neuinstallation. `postupgrade.sh` enthält jetzt nur noch das Zurückspielen
der Konfiguration.

## Version 1.1.1 — Abspaltung, Prozesssuche, Hausstandard

### Eigene Kennung als Abspaltung

`plugin.cfg` trug noch **Name und Adresse des ursprünglichen Autors**. LoxBerry
bildet aus Autorname, E-Mail und Plugin-Name den Schlüssel, unter dem es ein
Plugin führt — mit dem fremden Namen wäre diese Abspaltung für LoxBerry
dasselbe Plugin wie das Original gewesen. Der ursprüngliche Autor steht
weiterhin oben unter *Herkunft* und im Kopf der Quelldateien.

**Die Fassungsnummer stand an drei Stellen verschieden:** Ordnername 1.0.0,
`plugin.cfg` und `release.cfg` 1.1.0, `prerelease.cfg` 1.0.0. Wer Vorabfassungen
eingeschaltet hat, wäre damit auf einen Tag `v1.0.0` verwiesen worden. Jetzt
überall 1.1.1.

### Ein neues Symbol

Bis 1.1.0 zeigte das Symbol den Sensor allein — Platine, zwei Kapseln, Wellen
darunter. Das ist das übliche Bild für einen HC-SR04 und sagt nichts darüber,
wofür man ihn hier benutzt. Das neue Symbol zeigt einen Behälter im Schnitt mit
Füllstand und den Sensor darüber: genau das, was das Plugin aus `leer_cm`,
`voll_cm` und dem Behälterinhalt rechnet. Zwei ähnliche Symbole nebeneinander
in der Pluginverwaltung sind eine Falle, keine Verwandtschaftsangabe.

### Den Dienst richtig finden

Der Dienst wurde über `pgrep -o -f ultraschall.py` gesucht und mit
`pkill -f ultraschall.py` beendet — an vier Stellen (Oberfläche, `preupgrade.sh`,
`uninstall`). Beides durchsucht die **ganze Befehlszeile** jedes Prozesses und
trifft damit auch einen Editor, in dem die Datei offen ist, oder ein zweites
Exemplar des Plugins. `ps -C` und `killall` wären keine Alternative: die
vergleichen den *comm*-Namen, der bei einem Skript mit Shebang `python3` lautet
— die finden gar nichts.

Der Dienst schreibt jetzt eine **PID-Datei** (`/run/shm/ultraschall.pid`, auf
der Ramdisk, und er räumt sie beim Beenden selbst weg — aber nur, wenn die
Nummer darin noch seine eigene ist). Gefunden wird er über diese Datei; fehlt
sie, wird `/proc` durchgesehen und das **erste beziehungsweise zweite Argument**
gegen den vollen Skriptpfad verglichen. Gegenprobe mit vier laufenden
Prozessen: das eigene Exemplar wird gefunden, ein zweites unter
`…/ultraschall01/` und ein offenes `tail` nicht — `pgrep -f` lieferte im selben
Test acht Treffer.

### Hausstandard

- **Die Reiter waren `<div>`, keine Verweise**, und der Reiterwunsch kam nur per
  POST. Alle Flächen stehen bis zum Lauf des JavaScripts auf `display:none` —
  ohne JavaScript war die Seite leer, und auf einen Reiter verlinken ging nicht.
  Jetzt echte Links mit `?tab=…`; der Server setzt `sm-active` an Reiter und
  Fläche.
- **Rund 30 sichtbare Texte** liefen noch nicht über `us_t()`: die Meldungen
  nach dem Speichern und Kalibrieren, die Spaltenköpfe der Baustein-Tabelle,
  der Seitentitel. Beide Sprachdateien waren mit 1.1.1 **deckungsgleich**;
  jeder Schlüssel wird benutzt, keiner fehlt. (Die damals genannte Zahl
  221 gilt für 1.1.1; nachgemessen sind es mit 1.2.2 je **349**.)
- **Sieben tote Schlüssel entfernt.** Drei davon (`TEXT.MQTT`,
  `TEXT.STAND_VOR_2`, `TEXT.NEUESTE_ZEILE_ZUERST_NOCH_KEINE_PR`) waren
  Bruchstücke aus einem automatischen Übersetzungslauf, der über eine
  PHP-Grenze hinweg zusammengeklebt hatte — zwei unzusammenhängende Sätze in
  einem Wert. Sie waren nicht einsetzbar und wurden nirgends benutzt.

## Version 1.0.0 — LoxBerry 4 und Hausstandard

**Zur Versionsnummer:** Das Original stand auf `0.30`. `1.0.0` ist für
`LoxBerry::System::plugin_version_compare` echt größer — anders als bei den
datumsbasierten Plugins gibt es hier also keinen Rückschritt. Wer 0.30
installiert hat, bekommt diese Fassung als Update angeboten.

### Warum die Originalfassung auf LoxBerry 3 und 4 nicht läuft

Der erste Grund allein genügt schon:

- **`INTERFACE=1.0` in `plugin.cfg`.** `sbin/plugininstall.pl` lehnt
  Schnittstelle 1.0 seit LoxBerry 2 rundheraus ab
  (`ERR_INTERFACENOTSUPPORTED`). Das Plugin ließ sich gar nicht erst
  installieren — alles Weitere kam nie zum Tragen.

Danach kommen die Fehler in `data/ultraschall.py`. Sie stehen alle im obersten
Abschnitt des Skripts, das Plugin scheitert also schon vor der ersten Messung.
Nachgeprüft in dieser Reihenfolge:

- **Die mitgelieferte Konfigurationsdatei ist leer.** `config/ultraschall.cfg`
  enthält nur die Zeile `[ultraschall]` und zwei Leerzeilen. Der erste Zugriff
  `pluginconfig.get('ultraschall', 'ENABLED')` wirft
  `NoOptionError: No option 'enabled' in section: 'ultraschall'`. Wer das Plugin
  installiert und laufen lässt, ohne vorher zu speichern, kommt keinen Schritt
  weit.
- **`general.cfg` gibt es nicht mehr.** Danach liest das Skript die
  Miniserver-Adresse mit `configparser` aus
  `<LoxBerry-Wurzel>/config/system/general.cfg` und greift auf
  `loxberryconfig.get(miniservername, 'IPADDRESS')` zu. Seit LoxBerry 2 heißt
  die Datei `general.json` und ist JSON; die alte gibt es nicht mehr.
  `configparser` liest eine fehlende Datei kommentarlos als leer — der Zugriff
  endet in `NoSectionError: No section: 'MINISERVER1'`.
- **`sys.exit(-1)` ohne `import sys`.** War das Plugin ausgeschaltet, sollte das
  Skript sauber aussteigen. Eingebunden sind aber nur `smbus`, `socket`,
  `configparser`, `urllib.parse` und `time`. Statt eines geordneten Endes gäbe
  es einen `NameError` — erreichbar wird die Zeile allerdings ohnehin nur, wenn
  die beiden Fehler davor behoben sind.
- **`main()` wird nie aufgerufen.** Die Funktion ist in Zeile 15 definiert, ihr
  Rumpf besteht aus einer einzigen Zuweisung (`separator = ";"`), und ein Aufruf
  steht nirgends. Die eigentliche Arbeit liegt auf Modulebene.
- **Die `while True:`-Schleife läuft genau einmal.** Ihre letzte Anweisung ist
  ein nacktes `exit()`. Die Schleife täuscht Dauerbetrieb vor, den es nicht gibt.
- **`apt` lag in der Wurzel des Pakets.** Seit Schnittstelle 2.0 sucht
  `plugininstall.pl` dort nicht mehr, sondern unter `dpkg/apt`. Die
  Abhängigkeiten wurden also nie installiert.
- **`cron.01min` mitgeliefert, `cron.05min` verlinkt.** Das Paket bringt
  `cron/cron.01min` mit; `postinstall.sh` und `postupgrade.sh` bearbeiten aber
  dreimal `$ARGV5/system/cron/cron.05min/$ARGV2`.
- **`/boot/config.txt`** heißt seit Debian Bookworm `/boot/firmware/config.txt`.
  Der Daemon schrieb ins Leere. Er rief außerdem `apt-get install -y i2c-tools`
  und `adduser loxberry i2c` beim Systemstart auf — beides gehört nicht in ein
  Startskript.
- **Nur ein Messwert je Durchgang**, ohne jede Prüfung. Ein einzelnes Fehlecho
  ging unverändert an den Miniserver.
- **Kleinigkeiten:** die Kommentare in `ultraschall.py` sind Latin-1-Bytes in
  einer Datei, die `# encoding=utf-8` deklariert (`M�chte`, `H�he`). Das ist
  kosmetisch — CPython überliest ungültige Bytes in Kommentaren, in einer
  Zeichenkette wäre es ein `SyntaxError`. Dazu kommen mitgelieferte
  `icons/Thumbs.db` und `templates/.DS_Store`.

### Was diese Fassung anders macht

**Messung**

- Zwei Sensorarten statt einer: **SRF02** am I2C-Bus (auch SRF08, SRF10) und
  **HC-SR04** an zwei GPIO-Pins. Der HC-SR04 läuft über `gpiozero.DistanceSensor`,
  das die Zeitmessung selbst erledigt; angesteuert wird über `lgpio`, weil
  `RPi.GPIO` auf Bookworm und Trixie abgekündigt ist.
- **Mehrfachmessung mit Median.** Aus fünf Werten (einstellbar 1–25) wird der
  mittlere genommen. Anders als beim Mittelwert zieht ein Ausreißer das Ergebnis
  nicht mit.
- **Plausibilitätsbereich.** Werte außerhalb der eingestellten Grenzen werden
  verworfen. Bleibt nichts übrig, meldet das Plugin `valid = 0` statt einer
  erfundenen Zahl.
- **Korrekturwert** für den Abstand zwischen Sensorgehäuse und Bezugspunkt.
- **Dienst statt Cron.** Ein durchlaufender Prozess mit einstellbarem Takt; er
  liest die Konfiguration im Betrieb neu ein, ein Neustart ist nur beim Speichern
  nötig.

**Füllstand**

- Aus zwei Kalibrierpunkten — Abstand bei leer, Abstand bei voll — wird der
  Füllstand in Prozent berechnet, mit angegebenem Gesamtvolumen auch der Inhalt
  in Litern. Beide Punkte lassen sich direkt aus der Oberfläche heraus messen.
- Ohne Kalibrierung verhält sich das Plugin wie das Original und liefert nur die
  Entfernung.

**Weg zum Miniserver**

- **MQTT retained** über das LoxBerry-MQTT-Gateway ist der Regelweg. Nach einem
  Neustart des Miniservers steht der Wert sofort wieder da.
- Der **UDP-Weg der Originalfassung** bleibt erhalten, ist aber abgeschaltet
  voreingestellt. Er überträgt nur die Entfernung als blanke Zahl.

**Oberfläche**

- Neu als `webfrontend/htmlauth/index.php` im Hausstandard, vier Reiter:
  *Einstellungen*, *Einbindung in Loxone*, *Test*, *Logdateien*. Vollständig
  auf Deutsch.
- Die alte Perl-CGI-Oberfläche (`index.cgi` mit `HTML::Template` und je einer
  Sprachdatei) ist entfallen.
- Der Reiter *Test* misst auf Knopfdruck, prüft den I2C-Bus mit `i2cdetect`,
  zeigt die vorhandenen Python-Module und kann ein UDP-Testpaket senden.
- Loxone-Vorlagen werden in PHP erzeugt — Attributreihenfolge, CRLF und
  Tabulatoren entsprechen `LoxBerry::LoxoneTemplateBuilder`, das es nur in Perl
  gibt.

**Installation**

- `INTERFACE=2.0`, Abhängigkeiten in `dpkg/apt`.
- `postinstall.sh` schaltet I2C in `/boot/firmware/config.txt` ein (mit Rückfall
  auf `/boot/config.txt`), trägt die Module in `/etc/modules` ein und nimmt
  `loxberry` in die Gruppen `i2c` und `gpio` auf.
- Der Dienst läuft als `loxberry`, nicht als `root`.

## MQTT-Themen

| Thema | Bedeutung |
|---|---|
| `<Präfix>/distance` | Entfernung in cm |
| `<Präfix>/level` | Füllstand in Prozent (nur mit Kalibrierung) |
| `<Präfix>/liter` | Inhalt in Litern (nur mit Gesamtvolumen) |
| `<Präfix>/valid` | 1 = die letzte Messung war brauchbar |
| `<Präfix>/online` | 1 = der Dienst läuft |
| `<Präfix>/ts` | Zeitpunkt der Messung (Sekunden seit 1970) |
| `<Präfix>/zaehler` | zählt je Durchgang 0…999 und beginnt von vorn; **-1 = noch kein Durchgang** |
| `<Präfix>/last_error` | letzte Fehlermeldung, sonst leer |

Voreingestelltes Präfix: `ultraschall`. Alle Themen sind **retained**.

`ts`, `zaehler` und `online` gehen in **jedem** Durchgang hinaus, auch wenn sich
der Messwert nicht geändert hat — sonst ließe sich ein stehengebliebener Dienst
nicht von einem gleichbleibenden Füllstand unterscheiden.

## Anschluss

| Sensor | Anschluss | Messbereich | Achtung |
|---|---|---|---|
| SRF02 / SRF08 / SRF10 | I2C, Adresse ab Werk `0x70` | ca. 16 cm – 6 m | 3,3 V-tauglich, direkt anschließbar |
| HC-SR04 | GPIO Trigger + Echo (BCM) | ca. 2 cm – 4 m | **5 V** — Echo-Pin über Spannungsteiler auf 3,3 V |

## Grenzen des Verfahrens

Ultraschall braucht eine ebene, möglichst harte, waagrechte Fläche. Schaum,
Textilien und schräg liegendes Schüttgut streuen den Schall. Sitzt der Sensor in
einem engen Rohr, kommen Echos von der Rohrwand mit.

Die Schallgeschwindigkeit hängt von der Temperatur ab: rund 0,17 % je Grad.
Zwischen Winternacht und Sommertag sind das leicht 5 % — bei 200 cm also etwa
10 cm. Weder der SRF02 noch der HC-SR04 gleichen das aus. Wer es genau braucht,
kalibriert bei der Temperatur, die im Betrieb üblich ist.

Das Feld *Gesamtvolumen* ist nur bei senkrechten Wänden verlässlich. Bei einem
liegenden Zylinder oder einer Kugel ist der Zusammenhang zwischen Füllhöhe und
Inhalt nicht linear.

## Stand der Prüfung

Geprüft wurden: Syntax aller Python- und PHP-Dateien, ein vollständiger
Dienstlauf gegen eine SRF02-Attrappe und eine HC-SR04-Attrappe (Messung, Median,
Verwerfen unplausibler Werte, MQTT-Veröffentlichung, Zustandsdatei), das Rendern
der Oberfläche, das Speichern mit ungültigen Eingaben (Kommazahlen, vertauschte
Grenzen, doppelt belegter GPIO-Pin), das Einlesen einer Konfiguration im alten
Format auf beiden Seiten und die erzeugten Loxone-Vorlagen.

Für 1.2.0 kamen hinzu, jeweils am laufenden Aufbau mit **getrennten Bäumen**
(`webfrontend/htmlauth/plugins/…` und `webfrontend/html/plugins/…`) gemessen,
unter PHP 7.4 **und** 8.4:

* der Endpunkt mit richtigem, falschem, fehlendem und gar nicht eingerichtetem
  Token, mit unbekannter Aktion und mit einem Token, das als Feld statt als
  Zeichenkette ankommt — sowie der unmittelbare Aufruf der Bibliothek, der mit
  403 endet statt mit einer Seite;
* der Wachposten in beide Richtungen: ohne Merkmal wirkt kein Formular und die
  Konfiguration bleibt unverändert, mit Merkmal wirkt dasselbe Formular;
* dass der MQTT-Reiter keinen Sensorschlüssel anfasst und der
  Einstellungsreiter keinen MQTT-Schlüssel;
* Sichern und Zurückspielen gegen alle sieben Hausregeln: unveränderte Datei,
  geänderte Datei, halb gültige Datei (ändert nichts und nennt **alle**
  Beanstandungen), unbekannter Schlüssel, kein JSON, leere Datei, keine Datei,
  zu große Datei — und dass die Datei das Aktionstoken trägt;
* dass das Aktionstoken drei Speichervorgänge hintereinander übersteht.

**Nicht geprüft: der Betrieb an einem echten Sensor.** Weder SRF02 noch HC-SR04
standen zur Verfügung. Ebenso ungemessen: das Verhalten am echten MQTT-Gateway
und der Cron-Wächter unter einem echten crond.

## Installation

Über *Plugin-Verwaltung → Plugin installieren* das ZIP oder die Release-Adresse
angeben. Danach im Reiter *Einstellungen* die Bauart wählen, das Plugin
einschalten und speichern. Wurde I2C dabei erst eingeschaltet, ist ein Neustart
nötig.
