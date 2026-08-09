# LoxBerry-Plugin Ultraschall Entfernung

Misst mit einem Ultraschallsensor den Abstand zu einer Fläche und meldet ihn dem
Loxone Miniserver — auf Wunsch umgerechnet in Füllstand (%) und Inhalt (Liter).
Typischer Einsatz: Zisterne, Regenwassertank, Heizöltank, Futtersilo.

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
  der Seitentitel. Beide Sprachdateien haben jetzt **221 Schlüssel und sind
  deckungsgleich**; jeder wird benutzt, keiner fehlt.
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
  `/opt/loxberry/config/system/general.cfg` und greift auf
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
| `<Präfix>/last_error` | letzte Fehlermeldung, sonst leer |

Voreingestelltes Präfix: `ultraschall`. Alle Themen sind **retained**.

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

**Nicht geprüft: der Betrieb an einem echten Sensor.** Weder SRF02 noch HC-SR04
standen zur Verfügung.

## Installation

Über *Plugin-Verwaltung → Plugin installieren* das ZIP oder die Release-Adresse
angeben. Danach im Reiter *Einstellungen* die Bauart wählen, das Plugin
einschalten und speichern. Wurde I2C dabei erst eingeschaltet, ist ein Neustart
nötig.
