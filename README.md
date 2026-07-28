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
