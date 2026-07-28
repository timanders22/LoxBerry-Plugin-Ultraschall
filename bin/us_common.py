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

# ---------------------------------------------------------------------------
# Pfade - LoxBerry ersetzt die REPLACE-Marken bei der Installation
# ---------------------------------------------------------------------------

PLUGIN_NAME = "REPLACELBPPLUGINDIR"
if PLUGIN_NAME.startswith("REPLACE"):
    PLUGIN_NAME = "ultraschall"

CONFIG_DIR = "REPLACELBPCONFIGDIR"
if CONFIG_DIR.startswith("REPLACE"):
    CONFIG_DIR = "/opt/loxberry/config/plugins/" + PLUGIN_NAME

LOG_DIR = "REPLACELBPLOGDIR"
if LOG_DIR.startswith("REPLACE"):
    LOG_DIR = "/opt/loxberry/log/plugins/" + PLUGIN_NAME

HOME_DIR = os.environ.get("LBHOMEDIR", "/opt/loxberry")
CONFIG_FILE = os.path.join(CONFIG_DIR, "ultraschall.cfg")
STATUS_FILE = "/run/shm/ultraschall_status.json"
if not os.path.isdir("/run/shm"):
    STATUS_FILE = "/tmp/ultraschall_status.json"

VERSION = "1.0.0"

# ---------------------------------------------------------------------------
# Konfiguration
# ---------------------------------------------------------------------------

VORGABEN = {
    "enabled":        "0",
    "sensor":         "srf02",      # srf02 | hcsr04
    "i2c_bus":        "1",
    "i2c_adresse":    "0x70",
    "gpio_trigger":   "23",
    "gpio_echo":      "24",
    "messungen":      "5",
    "messabstand":    "0.2",
    "min_cm":         "3",
    "max_cm":         "400",
    "offset_cm":      "0",
    "leer_cm":        "",           # Abstand bei leerem Behaelter
    "voll_cm":        "",           # Abstand bei vollem Behaelter
    "volumen_liter":  "",
    "intervall":      "60",
    "aktualisierung": "300",
    "themenpraefix":  "ultraschall",
    "mqtt":           "1",
    "udp":            "0",
    "udp_miniserver": "1",
    "udp_port":       "",
}


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
    try:
        with open(pfad, "w", encoding="utf-8") as fh:
            fh.write("\n".join(zeilen) + "\n")
        os.chmod(pfad, 0o644)
        return True
    except OSError:
        return False


def zahl(werte, schluessel, vorgabe, typ=float):
    try:
        wert = werte.get(schluessel, "")
        if wert is None or str(wert).strip() == "":
            return typ(vorgabe)
        return typ(str(wert).strip())
    except (TypeError, ValueError):
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
        if wert >= self.max_m * 100.0 - 0.5:
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


def messen(cfg, sensor=None):
    """Einen Messdurchgang ausfuehren.

    Rueckgabe: dict mit entfernung, roh (Einzelwerte), verworfen, fehler.
    """
    eigener = sensor is None
    if sensor is None:
        sensor = sensor_aufbauen(cfg)

    anzahl = max(1, zahl(cfg, "messungen", 5, int))
    abstand = max(0.05, zahl(cfg, "messabstand", 0.2, float))
    min_cm = zahl(cfg, "min_cm", 3, float)
    max_cm = zahl(cfg, "max_cm", 400, float)
    offset = zahl(cfg, "offset_cm", 0, float)

    roh = []
    verworfen = []
    fehler = ""
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
    finally:
        if eigener:
            sensor.schliessen()

    mitte = median(roh)
    entfernung = round(mitte + offset, 1) if mitte is not None else None
    if entfernung is not None and (entfernung < 0):
        entfernung = 0.0
    if not fehler and entfernung is None:
        fehler = ("Keine brauchbare Messung. {0} von {1} Werten lagen "
                  "ausserhalb von {2:.0f} bis {3:.0f} cm oder blieben aus."
                  .format(len(verworfen), anzahl, min_cm, max_cm))
    return {"entfernung": entfernung,
            "roh": [round(w, 1) for w in roh],
            "verworfen": verworfen,
            "fehler": fehler}


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
    if abs(leer - voll) < 0.001:
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
