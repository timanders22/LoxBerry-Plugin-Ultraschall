#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Ultraschall Entfernung - Messdienst

Misst in einstellbarem Abstand die Entfernung zur Oberflaeche, rechnet sie
auf Wunsch in Fuellstand und Liter um und meldet das Ergebnis per MQTT
retained an den Broker. Der UDP-Weg der Originalfassung bleibt abschaltbar
erhalten.

Grundlage ist das Plugin von Dietmar Wimmer. Neu geschrieben fuer LoxBerry 4:

  * Die Miniserver-Adresse kommt aus general.json statt aus general.cfg.
    Letztere gibt es seit LoxBerry 2 nicht mehr; der Zugriff endete in
    einem NoSectionError, das Plugin lief dort also gar nicht.
  * `sys.exit(-1)` wurde aufgerufen, ohne dass `sys` eingebunden war - war
    das Plugin ausgeschaltet, gab es statt eines sauberen Endes einen
    NameError.
  * Statt einer Einzelmessung mehrere Messungen mit Median und einem
    Plausibilitaetsbereich.
  * SRF02 (I2C) und HC-SR04 (GPIO) statt nur SRF02.
"""

import json
import logging
import os
import signal
import socket
import sys
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import us_common as gem   # noqa: E402

_handlers = []
try:
    os.makedirs(gem.LOG_DIR, exist_ok=True)
    _handlers.append(logging.FileHandler(os.path.join(gem.LOG_DIR, "ultraschall.log")))
except OSError:
    pass
_handlers.append(logging.StreamHandler(sys.stdout))

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)-7s %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
    handlers=_handlers,
)
log = logging.getLogger("ultraschall")


class Mqtt:
    """Duenne Huelle um paho-mqtt. Faellt still aus, wenn Bibliothek oder
    Gateway fehlen - der UDP-Weg funktioniert dann weiter."""

    def __init__(self, praefix):
        self.praefix = praefix
        self.client = None

    def start(self):
        try:
            import paho.mqtt.client as mqtt
        except ImportError:
            log.error("paho-mqtt fehlt - MQTT bleibt aus. "
                      "Paket python3-paho-mqtt nachinstallieren.")
            return False
        zugang = gem.mqtt_zugangsdaten()
        if not zugang:
            log.warning("Kein MQTT-Broker in general.json gefunden")
            return False
        try:
            self.client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION1)
        except (AttributeError, TypeError):
            self.client = mqtt.Client()      # paho-mqtt 1.x
        if zugang["user"]:
            self.client.username_pw_set(zugang["user"], zugang["pass"] or "")
        self.client.will_set(self.praefix + "/online", "0", retain=True)
        try:
            self.client.connect(zugang["host"], zugang["port"], keepalive=60)
        except OSError as fehler:
            log.error("MQTT-Broker %s:%s nicht erreichbar: %s",
                      zugang["host"], zugang["port"], fehler)
            return False
        self.client.loop_start()
        log.info("MQTT verbunden mit %s:%s, Themenpräfix %s",
                 zugang["host"], zugang["port"], self.praefix)
        self.senden("online", "1")
        return True

    def senden(self, unterthema, wert):
        if not self.client:
            return
        try:
            self.client.publish(self.praefix + "/" + unterthema,
                                str(wert), qos=0, retain=True)
        except Exception as fehler:  # noqa: BLE001
            log.error("MQTT-Veröffentlichung fehlgeschlagen: %s", fehler)

    def stop(self):
        if not self.client:
            return
        try:
            self.senden("online", "0")
            self.client.loop_stop()
            self.client.disconnect()
        except Exception:  # noqa: BLE001
            pass


def udp_senden(cfg, wert):
    """Messwert per UDP an den Miniserver - der Weg der Originalfassung."""
    nummer = str(cfg.get("udp_miniserver", "1")).strip() or "1"
    ms = gem.miniserver_liste().get(nummer)
    if not ms or not ms["ip"]:
        return False, "Miniserver {0} nicht in general.json gefunden".format(nummer)
    try:
        port = int(str(cfg.get("udp_port", "")).strip())
    except (TypeError, ValueError):
        return False, "Kein gültiger UDP-Port eingetragen"
    try:
        sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        sock.sendto(str(wert).encode("utf-8"), (ms["ip"], port))
        sock.close()
        return True, "{0}:{1}".format(ms["ip"], port)
    except OSError as fehler:
        return False, str(fehler)


class Dienst:
    def __init__(self):
        self.cfg, alt = gem.konfiguration_lesen()
        if alt:
            log.info("Konfiguration im alten Format erkannt - wird übernommen "
                     "und beim nächsten Speichern neu geschrieben")
        self.praefix = self.cfg.get("themenpraefix") or "ultraschall"
        self.mqtt = Mqtt(self.praefix)
        self.sensor = None
        self.laeuft = True
        self.letzter_stand = {}
        self.config_mtime = self._mtime()
        self._gemeldet = {}

    def _einmal(self, schluessel, text, stufe="error", wieder_nach=3600):
        """Dieselbe Meldung nicht bei jedem Durchgang wiederholen.

        Ein fehlender Sensor bleibt fehlend. Ohne diese Bremse schriebe der
        Dienst bei einem Messtakt von 60 Sekunden 1440 gleichlautende Zeilen
        am Tag ins Protokoll und verdraengte alles Uebrige.
        """
        jetzt = time.time()
        alt = self._gemeldet.get(schluessel)
        if alt and alt[0] == text and (jetzt - alt[1]) < wieder_nach:
            return False
        self._gemeldet[schluessel] = (text, jetzt)
        getattr(log, stufe)("%s", text)
        return True

    def _mtime(self):
        try:
            return os.path.getmtime(gem.CONFIG_FILE)
        except OSError:
            return 0

    def _senden(self, thema, wert, erzwingen=False):
        wert = "" if wert is None else str(wert)
        if not erzwingen and self.letzter_stand.get(thema) == wert:
            return False
        self.letzter_stand[thema] = wert
        self.mqtt.senden(thema, wert)
        return True

    def zustand_schreiben(self, daten):
        try:
            temp = gem.STATUS_FILE + ".tmp"
            with open(temp, "w", encoding="utf-8") as fh:
                json.dump(daten, fh, ensure_ascii=False)
            os.replace(temp, gem.STATUS_FILE)
            os.chmod(gem.STATUS_FILE, 0o644)
        except OSError as fehler:
            log.warning("Zustandsdatei nicht schreibbar: %s", fehler)

    def durchgang(self, erzwingen=False):
        ergebnis = gem.messen(self.cfg, self.sensor)
        entfernung = ergebnis["entfernung"]
        prozent, liter = gem.fuellstand(self.cfg, entfernung)

        if entfernung is None:
            self._einmal("messung", ergebnis["fehler"], "warning")
            self._senden("valid", "0", erzwingen)
            self._senden("last_error", ergebnis["fehler"], erzwingen)
        else:
            self._gemeldet.pop("messung", None)
            log.info("Entfernung %.1f cm%s%s", entfernung,
                     "" if prozent is None else "  Füllstand {0:.1f} %".format(prozent),
                     "" if liter is None else "  {0:.1f} l".format(liter))
            self._senden("valid", "1", erzwingen)
            self._senden("distance", entfernung, erzwingen)
            if prozent is not None:
                self._senden("level", prozent, erzwingen)
            if liter is not None:
                self._senden("liter", liter, erzwingen)
            self._senden("last_error", "", erzwingen)

            if self.cfg.get("udp", "0") == "1":
                ok, wohin = udp_senden(self.cfg, int(round(entfernung)))
                if ok:
                    log.info("Per UDP an %s gesendet: %d", wohin, round(entfernung))
                else:
                    log.warning("UDP fehlgeschlagen: %s", wohin)

        self.zustand_schreiben({
            "zeit": int(time.time()),
            "version": gem.VERSION,
            "sensor": self.cfg.get("sensor", "srf02"),
            "entfernung": entfernung,
            "prozent": prozent,
            "liter": liter,
            "roh": ergebnis["roh"],
            "verworfen": ergebnis["verworfen"],
            "fehler": ergebnis["fehler"],
        })

    def start(self):
        log.info("Ultraschall Entfernung %s startet", gem.VERSION)
        log.info("Konfiguration: %s", gem.CONFIG_FILE)

        if self.cfg.get("enabled", "0") != "1":
            log.warning("Das Plugin ist ausgeschaltet. Im Reiter Einstellungen "
                        "einschalten und speichern.")
            # Kein harter Abbruch: die Konfiguration wird weiter beobachtet,
            # damit ein Einschalten ohne Neustart wirkt.

        if self.cfg.get("mqtt", "1") == "1":
            self.mqtt.start()
        else:
            log.info("MQTT ist ausgeschaltet")

        try:
            self.sensor = gem.sensor_aufbauen(self.cfg)
            self.sensor.oeffnen()
            log.info("Sensor %s bereit", self.cfg.get("sensor", "srf02"))
        except gem.SensorFehler as fehler:
            self._einmal("sensor", str(fehler))
            self.sensor = None

        intervall = max(5, gem.zahl(self.cfg, "intervall", 60, int))
        vollmeldung_alle = max(intervall, gem.zahl(self.cfg, "aktualisierung", 300, int))
        letzte_vollmeldung = 0

        while self.laeuft:
            if self.cfg.get("enabled", "0") == "1":
                if self.sensor is None:
                    try:
                        self.sensor = gem.sensor_aufbauen(self.cfg)
                        self.sensor.oeffnen()
                        if self._gemeldet.pop("sensor", None):
                            log.info("Sensor wieder ansprechbar")
                    except gem.SensorFehler as fehler:
                        self._einmal("sensor", str(fehler))
                        self._senden("valid", "0")
                        self._senden("last_error", str(fehler))
                        self.sensor = None
                if self.sensor is not None:
                    erzwingen = (time.time() - letzte_vollmeldung) >= vollmeldung_alle
                    self.durchgang(erzwingen=erzwingen)
                    if erzwingen:
                        letzte_vollmeldung = time.time()

            if self._mtime() != self.config_mtime:
                log.info("Konfiguration geändert - wird neu eingelesen")
                self.config_mtime = self._mtime()
                neu, _ = gem.konfiguration_lesen()
                sensorwechsel = (neu.get("sensor") != self.cfg.get("sensor")
                                 or neu.get("i2c_bus") != self.cfg.get("i2c_bus")
                                 or neu.get("i2c_adresse") != self.cfg.get("i2c_adresse")
                                 or neu.get("gpio_trigger") != self.cfg.get("gpio_trigger")
                                 or neu.get("gpio_echo") != self.cfg.get("gpio_echo"))
                self.cfg = neu
                self.letzter_stand.clear()
                if sensorwechsel and self.sensor is not None:
                    self.sensor.schliessen()
                    self.sensor = None
                intervall = max(5, gem.zahl(self.cfg, "intervall", 60, int))
                vollmeldung_alle = max(intervall, gem.zahl(self.cfg, "aktualisierung", 300, int))

            # In kleinen Schritten warten, damit ein Signal sofort greift
            ende = time.time() + intervall
            while self.laeuft and time.time() < ende:
                time.sleep(min(1.0, max(0.05, ende - time.time())))

    def stop(self):
        self.laeuft = False
        if self.sensor:
            self.sensor.schliessen()
        self.mqtt.stop()


def main():
    dienst = Dienst()

    def beenden(signum, rahmen):   # noqa: ARG001
        log.info("Signal %s empfangen - beende", signum)
        dienst.laeuft = False

    signal.signal(signal.SIGTERM, beenden)
    signal.signal(signal.SIGINT, beenden)

    try:
        dienst.start()
    except KeyboardInterrupt:
        pass
    finally:
        dienst.stop()
        log.info("Beendet")


if __name__ == "__main__":
    main()
