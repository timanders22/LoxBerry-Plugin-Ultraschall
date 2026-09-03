#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Ultraschall Entfernung - gemeinsame Grundlagen

Pfade, Konfiguration, Sensoransteuerung und die Umrechnung in Fuellstand
liegen hier, damit Dienst und Testlauf dieselbe Sicht haben.

Grundlage ist das Plugin von Dietmar Wimmer. Der Messteil wurde fuer
LoxBerry 4 neu geschrieben:

  * Die Miniserver-Adresse kommt aus general.json. Die Originalfassung las
    general.cfg mit configparser - diese Datei gibt es seit LoxBerry 2 nicht
    mehr, der Aufruf endete in einem NoSectionError.
  * Neben dem SRF02 (I2C) wird auch der HC-SR04 (zwei GPIO-Pins) unterstuetzt.
  * Mehrfachmessung mit Median statt einer einzelnen Messung, dazu ein
    Plausibilitaetsbereich.
"""

import json
import os
import re
import time


def lb_wurzel_ermitteln():
    """Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.

    Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
    config/plugins UND webfrontend enthaelt. Trifft die uebliche
    Installation genauso wie eine an einem anderen Ort.
    """
    d = os.path.dirname(os.path.abspath(__file__))
    for _ in range(8):
        if os.path.isdir(os.path.join(d, "config", "plugins")) \
                and os.path.isdir(os.path.join(d, "webfrontend")):
            return d
        eltern = os.path.dirname(d)
        if eltern == d:
            break
        d = eltern
    return ""


# ---------------------------------------------------------------------------
# Pfade - LoxBerry ersetzt die REPLACE-Marken bei der Installation
# ---------------------------------------------------------------------------

PLUGIN_NAME = "REPLACELBPPLUGINDIR"
if PLUGIN_NAME.startswith("REPLACE"):
    PLUGIN_NAME = "ultraschall"

CONFIG_DIR = "REPLACELBPCONFIGDIR"
if CONFIG_DIR.startswith("REPLACE"):
    CONFIG_DIR = lb_wurzel_ermitteln() + "/config/plugins/" + PLUGIN_NAME

LOG_DIR = "REPLACELBPLOGDIR"
if LOG_DIR.startswith("REPLACE"):
    LOG_DIR = lb_wurzel_ermitteln() + "/log/plugins/" + PLUGIN_NAME

HOME_DIR = os.environ.get("LBHOMEDIR") or lb_wurzel_ermitteln()
CONFIG_FILE = os.path.join(CONFIG_DIR, "ultraschall.cfg")
# Eigener Unterordner auf der Ramdisk statt Dateien im Wurzelverzeichnis.
# /run/shm gehoert allen: liegen dort "ultraschall.pid" und
# "ultraschall_status.json" frei herum, kollidieren sie mit jedem anderen
# Plugin, das denselben Namen waehlt, und die Rechte lassen sich nicht
# einzeln setzen. Ein eigener Ordner mit 0755 loest beides.
#
# Seit 1.1.2 traegt der Ordner den PLUGIN_NAMEN und nicht mehr fest
# "ultraschall". Dasselbe Argument eine Ebene weiter gedacht: Haengt LoxBerry
# bei einer Zweitinstallation einen Zaehler an (ultraschall_01), teilten sich
# sonst BEIDE Installationen status.json und dienst.pid.
#   - status.json: die Oberflaeche der zweiten zeigte den Messwert der ersten.
#     Zwei Sensoren, ein angezeigter Wert - und niemand sieht, dass er falsch
#     ist.
#   - dienst.pid: die zweite ueberschriebe die PID der ersten. Ein Stopp
#     traefe den falschen Dienst, und der Waechter hielte einen abgestuerzten
#     Dienst fuer laufend.
# Bei einer einzelnen Installation aendert sich nichts: PLUGIN_NAME ist dann
# genau "ultraschall", der Pfad bleibt derselbe wie bisher.
RAM_DIR = ("/run/shm/" if os.path.isdir("/run/shm") else "/tmp/") + PLUGIN_NAME
try:
    os.makedirs(RAM_DIR, exist_ok=True)
except OSError:
    pass
STATUS_FILE = os.path.join(RAM_DIR, "status.json")
PID_FILE = os.path.join(RAM_DIR, "dienst.pid")

# Bis 1.1.1 lagen beide Dateien eine Ebene hoeher. Alte Reste wegraeumen,
# damit nicht zwei Staende nebeneinander liegen und die Oberflaeche den
# falschen liest.
for _alt in ("/run/shm/ultraschall_status.json", "/run/shm/ultraschall.pid",
             "/tmp/ultraschall_status.json", "/tmp/ultraschall.pid"):
    try:
        if os.path.isfile(_alt):
            os.unlink(_alt)
    except OSError:
        pass

# Die PID-Datei gibt es seit 1.1.1. Vorher wurde der Dienst ueber
# "pgrep -f ultraschall.py" gesucht und mit "pkill -f ultraschall.py"
# beendet. Beides ist unzuverlaessig:
#   - pgrep -f durchsucht die GANZE Befehlszeile, also auch die des
#     Suchbefehls selbst und die jedes Editors, in dem die Datei offen ist,
#   - pkill -f haette bei zwei Exemplaren des Plugins beide erwischt,
#   - ps -C und killall vergleichen den comm-Namen, der bei einem Skript
#     mit Shebang "python3" lautet - die finden gar nichts.
# Beide Ablageorte liegen auf einer Ramdisk: eine verwaiste PID-Datei ist
# spaetestens nach dem naechsten Neustart fort.

# Muss zu plugin.cfg, release.cfg und prerelease.cfg passen.
#
# ZWEIMAL IST DAS SCHON AUSEINANDERGELAUFEN: bis 1.1.1 stand hier 1.0.0,
# und von 1.1.2 bis 1.1.11 blieb es auf 1.1.2 stehen - neun Freigaben lang.
# Die Zustandsdatei und die erste Protokollzeile jedes Starts nannten damit
# eine Fassung, die es nicht mehr gibt. Werkzeuge/fassung_setzen.py setzt
# alle Stellen auf einmal; wer die Nummer von Hand aendert, vergisst diese.
VERSION = "1.2.2"

# ---------------------------------------------------------------------------
# Konfiguration
# ---------------------------------------------------------------------------

# ---------------------------------------------------------------------------
# Vorgaben und Feldtabelle - GEMEINSAME Datei mit der PHP-Seite
# ---------------------------------------------------------------------------
#
# Bis 1.1.12 stand hier eine eigene Liste mit denselben Schluesseln wie in
# webfrontend/html/us_lib.php. Ueber die Sprachgrenze hinweg gibt es keine
# gemeinsame Funktion - also eine gemeinsame DATEI. Bei Gardena bedeutete
# derselbe fehlende Schluessel in der Oberflaeche "an" und im Dienst "aus",
# und gemerkt hat es niemand.
#
# WICHTIG: hier wird NICHT geworfen. Was auf Modulebene steht, ist keine
# Funktion, sondern eine Zuendschnur - ein Fehler dort reisst den Import mit,
# und damit den Dienst, den Einmalabruf und den Knopf im Reiter Test
# zugleich. Bei APC-UPS hat genau das eine Fassung lang jeden Start
# verhindert, ohne dass eine Zeile Arbeit gelaufen waere. Der Aufrufer prueft
# DATEN_FEHLER und entscheidet.

VORGABEN_DATEI = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                              "us_vorgaben.json")


def _daten_lesen():
    try:
        with open(VORGABEN_DATEI, "r", encoding="utf-8") as fh:
            d = json.load(fh)
    except OSError as fehler:
        return None, "{0} ist nicht lesbar: {1}".format(VORGABEN_DATEI, fehler)
    except ValueError as fehler:
        return None, "{0} ist kein gueltiges JSON: {1}".format(VORGABEN_DATEI, fehler)
    if not isinstance(d, dict) or not isinstance(d.get("vorgaben"), dict) \
            or not isinstance(d.get("felder"), dict):
        return None, "{0} hat nicht die erwartete Gestalt".format(VORGABEN_DATEI)
    return d, ""


DATEN, DATEN_FEHLER = _daten_lesen()
VORGABEN = dict(DATEN["vorgaben"]) if DATEN else {}
FELDER = dict(DATEN["felder"]) if DATEN else {}


def felder_zeile():
    """Nur die Felder, die in die Statuszeile des Endpunkts gehoeren."""
    return dict((k, v) for k, v in FELDER.items() if v.get("zeile"))


def konfiguration_lesen(pfad=None):
    """Konfiguration lesen. Erkennt das alte Format des Originalplugins mit.

    Alt (Config::Simple, Abschnitt [ultraschall], Schluessel gross):
        [ultraschall]
        ENABLED=1
        MINISERVER=MINISERVER1
        UDPPORT=12345
    """
    pfad = pfad or CONFIG_FILE
    werte = dict(VORGABEN)
    alt = False
    try:
        with open(pfad, "r", encoding="utf-8", errors="replace") as fh:
            zeilen = fh.read().splitlines()
    except OSError:
        return werte, alt

    for zeile in zeilen:
        t = zeile.strip()
        if not t or t[0] in ";#[":
            continue
        if "=" not in t:
            continue
        schluessel, wert = t.split("=", 1)
        schluessel = schluessel.strip()
        wert = wert.strip().strip('"').strip("'")
        klein = re.sub(r"^ultraschall\.", "", schluessel, flags=re.I).lower()

        # --- alte Schluesselnamen uebernehmen
        if klein == "miniserver":
            alt = True
            # Stand als "MINISERVER1" in der Datei; wir brauchen nur die Nummer.
            nummer = re.sub(r"\D", "", wert) or "1"
            werte["udp_miniserver"] = nummer
            werte["udp"] = "1"
            continue
        if klein == "udpport":
            alt = True
            werte["udp_port"] = wert
            continue

        if klein in VORGABEN:
            werte[klein] = wert
    return werte, alt


def konfiguration_schreiben(werte, pfad=None):
    # Ohne Vorgaben wird NICHT geschrieben - eine aus dem Nichts gebaute
    # Konfiguration waere die zweite Wahrheit, gegen die es die
    # gemeinsame Datei gibt.
    if not VORGABEN:
        return False
    pfad = pfad or CONFIG_FILE
    try:
        os.makedirs(os.path.dirname(pfad), exist_ok=True)
    except OSError:
        pass
    zeilen = ["; Ultraschall Entfernung",
              "; Geschrieben von der Plugin-Oberflaeche.",
              "", "[ultraschall]"]
    for schluessel, vorgabe in VORGABEN.items():
        zeilen.append("{0}={1}".format(schluessel, werte.get(schluessel, vorgabe)))
    # Erst daneben schreiben, dann umbenennen. Ein einfaches open(..., "w")
    # kuerzt die Datei und fuellt sie neu; liest der Dienst genau in diesem
    # Fenster, bekommt er eine leere oder halbe Konfiguration. os.replace ist
    # innerhalb desselben Dateisystems unteilbar.
    tmp = "{0}.tmp.{1}".format(pfad, os.getpid())
    try:
        with open(tmp, "w", encoding="utf-8") as fh:
            fh.write("\n".join(zeilen) + "\n")
            fh.flush()
            os.fsync(fh.fileno())
        os.chmod(tmp, 0o644)
        os.replace(tmp, pfad)
        return True
    except OSError:
        try:
            os.unlink(tmp)
        except OSError:
            pass
        return False


def zahl(werte, schluessel, vorgabe, typ=float, unlesbar=None):
    """Einen Zahlenwert aus der Konfiguration holen.

    EIN UNLESBARER WERT WIRD GEMELDET (seit 1.2.2).

    Der Rueckfall auf die Vorgabe bleibt - der Dienst soll weiterlaufen -,
    aber er geschieht nicht mehr stumm: wer eine Liste in 'unlesbar'
    mitgibt, bekommt (Schluessel, Wert) darin zurueck und kann es sagen.

    Warum das zaehlt: die Oberflaeche nimmt das deutsche Komma an und
    schreibt einen Punkt in die Datei (us_lib.php, Abschnitt Kommazahlen).
    Eine von Hand bearbeitete oder aus dem Originalplugin uebernommene
    Datei kann aber "offset_cm=1,5" enthalten. Bis 1.2.1 wurde daraus
    lautlos die Vorgabe 0 - gemessen wurde ab da ohne Korrektur, und im
    Protokoll stand kein Wort davon. Dasselbe gilt fuer min_cm, max_cm und
    messungen.
    """
    try:
        wert = werte.get(schluessel, "")
        if wert is None or str(wert).strip() == "":
            return typ(vorgabe)
        return typ(str(wert).strip())
    except (TypeError, ValueError):
        if unlesbar is not None:
            unlesbar.append((schluessel, str(wert)))
        return typ(vorgabe)


def miniserver_liste():
    """Miniserver aus general.json.

    Die Originalfassung las general.cfg mit configparser
    (`loxberryconfig.get(miniservername, 'IPADDRESS')`). Die Datei gibt es
    seit LoxBerry 2 nicht mehr - der Aufruf endete in einem NoSectionError,
    und damit lief das Plugin auf LoxBerry 3 und 4 gar nicht.
    """
    pfad = os.path.join(HOME_DIR, "config", "system", "general.json")
    try:
        with open(pfad, "r", encoding="utf-8") as fh:
            daten = json.load(fh)
    except (OSError, ValueError):
        return {}
    out = {}
    for nr, ms in (daten.get("Miniserver") or {}).items():
        if not isinstance(ms, dict):
            continue
        out[str(nr)] = {
            "name": ms.get("Name") or ("Miniserver " + str(nr)),
            "ip": ms.get("Ipaddress") or ms.get("IPAddress") or "",
        }
    return out


def mqtt_zugangsdaten():
    pfad = os.path.join(HOME_DIR, "config", "system", "general.json")
    try:
        with open(pfad, "r", encoding="utf-8") as fh:
            daten = json.load(fh)
    except (OSError, ValueError):
        return None
    for abschnitt in ("Mqtt", "mqtt"):
        block = daten.get(abschnitt)
        if not isinstance(block, dict):
            continue

        def hole(*namen):
            for n in namen:
                if block.get(n):
                    return block[n]
            return None

        host = hole("Brokerhost", "brokerhost")
        if not host:
            continue
        return {"host": str(host),
                "port": int(hole("Brokerport", "brokerport") or 1883),
                "user": hole("Brokeruser", "brokeruser"),
                "pass": hole("Brokerpass", "brokerpass")}
    return None


# ---------------------------------------------------------------------------
# Sensoren
# ---------------------------------------------------------------------------

class SensorFehler(Exception):
    """Sensor nicht ansprechbar oder Bibliothek fehlt."""


class Srf02:
    """SRF02 und verwandte Sensoren (SRF08, SRF10) ueber I2C.

    Ablauf laut Datenblatt: Befehl 0x51 in Register 0 schreibt und startet
    eine Messung in Zentimetern, nach spaetestens 70 ms stehen High- und
    Low-Byte in Register 2 und 3.
    """

    def __init__(self, bus=1, adresse=0x70):
        self.busnummer = int(bus)
        self.adresse = int(adresse)
        self.bus = None

    def oeffnen(self):
        try:
            import smbus
        except ImportError:
            try:
                import smbus2 as smbus   # noqa: N813
            except ImportError as fehler:
                raise SensorFehler(
                    "python3-smbus fehlt. Nachinstallieren mit: "
                    "sudo apt-get install -y python3-smbus") from fehler
        try:
            self.bus = smbus.SMBus(self.busnummer)
        except Exception as fehler:  # noqa: BLE001
            raise SensorFehler(
                "I2C-Bus {0} nicht ansprechbar: {1}. Ist I2C eingeschaltet "
                "und der Benutzer in der Gruppe i2c?".format(
                    self.busnummer, fehler)) from fehler

    def messen(self):
        """Eine Messung in Zentimetern. Rueckgabe: float oder None."""
        if self.bus is None:
            self.oeffnen()
        try:
            self.bus.write_byte_data(self.adresse, 0x00, 0x51)
        except Exception as fehler:  # noqa: BLE001
            raise SensorFehler(
                "Sensor auf Adresse {0:#04x} antwortet nicht: {1}".format(
                    self.adresse, fehler)) from fehler
        time.sleep(0.08)
        try:
            hoch = self.bus.read_byte_data(self.adresse, 0x02)
            niedrig = self.bus.read_byte_data(self.adresse, 0x03)
        except Exception as fehler:  # noqa: BLE001
            raise SensorFehler("Messwert nicht lesbar: {0}".format(fehler)) from fehler
        wert = (hoch << 8) + niedrig
        # 0 heisst beim SRF02: nichts im Messbereich
        return float(wert) if wert > 0 else None

    def schliessen(self):
        try:
            if self.bus:
                self.bus.close()
        except Exception:  # noqa: BLE001
            pass


class HcSr04:
    """HC-SR04 an zwei GPIO-Pins, ueber gpiozero.

    gpiozero bringt einen fertigen Treiber mit und kuemmert sich um die
    Zeitmessung. Auf Bookworm und Trixie laeuft es ueber lgpio; RPi.GPIO
    ist dort abgekuendigt.
    """

    def __init__(self, trigger=23, echo=24, max_cm=400):
        self.trigger = int(trigger)
        self.echo = int(echo)
        self.max_m = max(0.05, float(max_cm) / 100.0)
        self.sensor = None

    def oeffnen(self):
        # gpiozero sucht sich die Ansteuerung erst beim Anlegen des Objekts
        # und warnt dabei lautstark ueber jede, die es nicht nehmen konnte
        # ("PinFactoryFallback"). Diese Warnungen gehen am Protokoll vorbei
        # direkt auf die Standardfehlerausgabe und verdecken die eigentliche
        # Meldung. Deshalb Import und Anlegen zusammen stummschalten - die
        # Ursache steht danach in der Ausnahme, sauber formuliert.
        import warnings
        with warnings.catch_warnings():
            warnings.simplefilter("ignore")
            try:
                from gpiozero import DistanceSensor
            except ImportError as fehler:
                raise SensorFehler(
                    "python3-gpiozero fehlt. Nachinstallieren mit: "
                    "sudo apt-get install -y python3-gpiozero python3-lgpio") from fehler
            try:
                # queue_len=1: geglaettet wird hier selbst per Median, damit
                # beide Sensorarten dasselbe Verfahren benutzen.
                self.sensor = DistanceSensor(echo=self.echo, trigger=self.trigger,
                                             max_distance=self.max_m, queue_len=1)
            except Exception as fehler:  # noqa: BLE001
                raise SensorFehler(
                    "GPIO {0}/{1} nicht ansprechbar: {2}. Laeuft das auf einem "
                    "Raspberry Pi, und ist python3-lgpio installiert?".format(
                        self.trigger, self.echo, fehler)) from fehler

    def messen(self):
        if self.sensor is None:
            self.oeffnen()
        try:
            meter = self.sensor.distance
        except Exception as fehler:  # noqa: BLE001
            raise SensorFehler("Messung fehlgeschlagen: {0}".format(fehler)) from fehler
        if meter is None:
            return None
        wert = meter * 100.0
        # gpiozero liefert bei Zeitueberschreitung den Maximalwert - der ist
        # keine Messung, sondern heisst "nichts gehoert".
        #
        # Der Abzug von 0.5 cm ist entfallen: er verwarf zusaetzlich die
        # letzten fuenf Millimeter des Messbereichs, ohne dass es dafuer einen
        # Grund gab.
        #
        # Was NICHT geht, obwohl es naheliegt: ">" statt ">=". gpiozero
        # begrenzt in DistanceSensor._read mit
        #     return min(1.0, distance / self._max_distance)
        # Bei Zeitueberschreitung ist der Wert also EXAKT der Maximalwert;
        # nachgerechnet fuer max_m 0.5/2.0/4.0/4.5 stimmt die Gleichheit auf
        # die letzte Stelle. Mit ">" traefe die Bedingung nie zu, und ein
        # fehlendes Echo wuerde als gueltige Messung am Bereichsende gemeldet -
        # aus "nichts gehoert" wuerde "Gegenstand in 4 m".
        if wert >= self.max_m * 100.0:
            return None
        return wert

    def schliessen(self):
        try:
            if self.sensor:
                self.sensor.close()
        except Exception:  # noqa: BLE001
            pass


def sensor_aufbauen(cfg):
    art = (cfg.get("sensor") or "srf02").strip().lower()
    if art == "hcsr04":
        return HcSr04(zahl(cfg, "gpio_trigger", 23, int),
                      zahl(cfg, "gpio_echo", 24, int),
                      zahl(cfg, "max_cm", 400, float))
    adresse = str(cfg.get("i2c_adresse", "0x70")).strip()
    try:
        adresse = int(adresse, 16) if adresse.lower().startswith("0x") else int(adresse)
    except ValueError:
        adresse = 0x70
    return Srf02(zahl(cfg, "i2c_bus", 1, int), adresse)


def median(werte):
    werte = sorted(werte)
    n = len(werte)
    if n == 0:
        return None
    if n % 2:
        return werte[n // 2]
    return (werte[n // 2 - 1] + werte[n // 2]) / 2.0


# Laenger als das darf ein Durchgang nicht dauern - siehe messplan().
MESSDAUER_MAX = 180.0


def messplan(cfg, unlesbar=None):
    """Wie viele Messungen in welchem Abstand - und was daran zu sagen ist.

    DIE SCHLEIFE HAT EINE OBERGRENZE (seit 1.2.2).

    anzahl und abstand kommen beide aus der Konfiguration, und bis 1.2.1 gab
    es nach oben nichts. Ein von Hand eingetragenes "messungen=100000" - die
    Datei ist eine gewoehnliche Textdatei - liess den Dienst nicht mehr aus
    dem Durchgang heraus: keine Zustandsdatei, kein MQTT, kein Herzschlag,
    und der Waechter startet ihn nicht neu, weil der Prozess ja laeuft.

    Die Schranke ist bewusst weit: die Oberflaeche laesst hoechstens 25
    Messungen im Abstand von 5 s zu (us_lib.php, Abschnitte Ganzzahlen und
    Kommazahlen), also 120 s. Was ein Formular erzeugen kann, wird hier
    nicht beschnitten - nur das, was kein Formular je erzeugt hat. Und
    beschnitten wird laut, nicht still.

    Rueckgabe: (anzahl, abstand, hinweise).
    """
    unlesbar = [] if unlesbar is None else unlesbar
    anzahl = max(1, zahl(cfg, "messungen", 5, int, unlesbar))
    abstand = max(0.05, zahl(cfg, "messabstand", 0.2, float, unlesbar))
    hinweise = []
    for k, w in unlesbar:
        hinweise.append("Der Wert fuer '{0}' ist keine Zahl: '{1}'. "
                        "Gerechnet wurde mit der Vorgabe.".format(k, str(w)[:40]))
    if (anzahl - 1) * abstand > MESSDAUER_MAX:
        gekuerzt = anzahl
        anzahl = max(1, int(MESSDAUER_MAX / abstand) + 1)
        hinweise.append("Es waren {0} Messungen im Abstand von {1:.2f} s "
                        "eingetragen - laenger als {2:.0f} s. Gemessen wird "
                        "mit {3}.".format(gekuerzt, abstand, MESSDAUER_MAX,
                                          anzahl))
    return anzahl, abstand, hinweise


def messen(cfg, sensor=None):
    """Einen Messdurchgang ausfuehren.

    Rueckgabe: dict mit entfernung, roh (Einzelwerte), verworfen, fehler.
    """
    eigener = sensor is None
    if sensor is None:
        sensor = sensor_aufbauen(cfg)

    unlesbar = []
    anzahl, abstand, hinweise = messplan(cfg, unlesbar)
    schon = len(unlesbar)
    min_cm = zahl(cfg, "min_cm", 3, float, unlesbar)
    max_cm = zahl(cfg, "max_cm", 400, float, unlesbar)
    offset = zahl(cfg, "offset_cm", 0, float, unlesbar)
    # Was messplan() noch nicht kennen konnte, kommt hier dazu - in
    # derselben Form, damit der Anwender nicht zwei Sorten Satz liest.
    for k, w in unlesbar[schon:]:
        hinweise.append("Der Wert fuer '{0}' ist keine Zahl: '{1}'. "
                        "Gerechnet wurde mit der Vorgabe."
                        .format(k, str(w)[:40]))

    roh = []
    verworfen = []
    fehler = ""
    # Ein SENSORfehler ist etwas anderes als "nichts Brauchbares gemessen".
    # Der erste heisst: die Verbindung zum Geraet ist hin, ein Neuoeffnen
    # kann helfen. Der zweite heisst: das Geraet antwortet, die Werte passen
    # nur nicht in den Bereich - da hilft kein Neuoeffnen, und wer es
    # trotzdem in jedem Takt versucht, erzeugt Last und Protokollzeilen ohne
    # Gegenwert. Bis 1.2.1 waren beide Faelle im Feld "fehler" nicht zu
    # unterscheiden, und der Dienst hat den Sensor deshalb NIE neu geoeffnet.
    sensorfehler = False
    try:
        for i in range(anzahl):
            wert = sensor.messen()
            if wert is None:
                verworfen.append(None)
            elif wert < min_cm or wert > max_cm:
                # Ausserhalb des Plausibilitaetsbereichs - nicht weiterreichen.
                verworfen.append(round(wert, 1))
            else:
                roh.append(wert)
            if i < anzahl - 1:
                time.sleep(abstand)
    except SensorFehler as f:
        fehler = str(f)
        sensorfehler = True
    finally:
        if eigener:
            sensor.schliessen()

    mitte = median(roh)
    entfernung = round(mitte + offset, 1) if mitte is not None else None
    # EIN NEGATIVES ERGEBNIS IST KEIN MESSWERT (seit 1.2.2).
    #
    # Bis 1.2.1 stand hier
    #     if entfernung is not None and (entfernung < 0):
    #         entfernung = 0.0
    # Gemessen mit offset_cm = -50 und einem Sensorwert von 30 cm: das
    # Ergebnis war 0.0 cm - und mit leer_cm=100/voll_cm=20 daraus ein
    # Fuellstand von 100 % und der volle Behaelterinhalt in Litern. Der
    # Dienst meldete dazu valid=1. In Loxone stand "randvoll", wo in
    # Wahrheit eine unmoegliche Zahl herauskam.
    #
    # Eine 0 ist hier kein Messwert, sondern ein Rueckfallwert, der BEDIENT
    # statt zu melden - und er ist von einer echten 0 nicht zu unterscheiden.
    # Was rechnerisch hinter dem Sensor liegt, gibt es nicht; dann gibt es
    # auch keinen Wert, und der Grund steht dabei.
    #
    # Der Plausibilitaetsbereich min_cm/max_cm bleibt bewusst auf dem
    # ROHwert. Ihn zusaetzlich auf das Ergebnis anzuwenden waere naheliegend
    # und wuerde bestehende Anlagen treffen, die mit einer Korrektur knapp
    # unter min_cm arbeiten - die Beschriftung des Feldes ("kleinster
    # plausibler Wert") laesst beide Lesarten zu. Ohne eine Messung an einer
    # echten Anlage wird hier nichts umgedeutet.
    if entfernung is not None and entfernung < 0:
        fehler = ("Der Messwert liegt nach der Korrektur ({0:+.1f} cm) bei "
                  "{1:.1f} cm. Ein negativer Abstand ist unmoeglich - "
                  "Korrektur pruefen."
                  .format(offset, entfernung))
        entfernung = None
    if not fehler and entfernung is None:
        fehler = ("Keine brauchbare Messung. {0} von {1} Werten lagen "
                  "ausserhalb von {2:.0f} bis {3:.0f} cm oder blieben aus."
                  .format(len(verworfen), anzahl, min_cm, max_cm))
    # Was an der Konfiguration nicht stimmte, faehrt im Ergebnis mit. Der
    # Dienst meldet es einmal (durchgang), us_messen.py zeigt es an.
    return {"entfernung": entfernung,
            "roh": [round(w, 1) for w in roh],
            "verworfen": verworfen,
            "fehler": fehler,
            "sensorfehler": sensorfehler,
            "hinweise": hinweise}


def fuellstand(cfg, entfernung):
    """Aus der Entfernung Fuellstand und Inhalt berechnen.

    leer_cm ist der gemessene Abstand bei leerem Behaelter (Sensor oben,
    also der groesste Abstand), voll_cm der bei vollem. Fehlt eines von
    beiden, wird nichts berechnet - dann liefert das Plugin nur die
    Entfernung, wie die Originalfassung.
    """
    leer = cfg.get("leer_cm", "")
    voll = cfg.get("voll_cm", "")
    if entfernung is None or str(leer).strip() == "" or str(voll).strip() == "":
        return None, None
    try:
        leer = float(leer)
        voll = float(voll)
    except ValueError:
        return None, None
    # VERTAUSCHT IST KEIN FUELLSTAND (seit 1.2.2).
    #
    # Bis 1.2.1 wurde nur "leer == voll" abgefangen. Gemessen mit
    # leer_cm=20 und voll_cm=100 (also vertauscht) lief der Fuellstand
    # RUECKWAERTS: 25 cm ergaben 6,2 %, 95 cm ergaben 93,8 %. Je weiter der
    # Wasserspiegel weg ist, desto voller meldete der Behaelter - und beide
    # Felder sind in der Oberflaeche unabhaengig voneinander auf 0..2000
    # geprueft, es gab also nichts, was widersprochen haette. In Loxone
    # steht dann eine plausible Zahl mit umgekehrtem Vorzeichen; das faellt
    # erst auf, wenn jemand in den Behaelter sieht.
    #
    # Der Sensor sitzt oben: leer heisst grosser Abstand, voll heisst
    # kleiner. leer <= voll kann es also nicht geben. Ein Wert entsteht dann
    # nicht - lieber kein Wert als eine Zahl, die richtig aussieht.
    if leer - voll < 0.001:
        return None, None

    anteil = (leer - entfernung) / (leer - voll)
    prozent = max(0.0, min(100.0, anteil * 100.0))

    liter = None
    volumen = cfg.get("volumen_liter", "")
    if str(volumen).strip() != "":
        try:
            liter = round(float(volumen) * prozent / 100.0, 1)
        except ValueError:
            liter = None
    return round(prozent, 1), liter
