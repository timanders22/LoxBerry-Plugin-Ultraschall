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
import logging.handlers
import os
import signal
import socket
import sys
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import us_common as gem   # noqa: E402

LOGDATEI = os.path.join(gem.LOG_DIR, "ultraschall.log")
LOG_GRENZE = 512000      # ab 500 kB wird gekappt
LOG_REST = 200           # so viele Zeilen bleiben stehen


def log_kappen(pfad=None):
    """Ab 500 kB bleiben die letzten 200 Zeilen stehen.

    log/plugins liegt auf einer Ramdisk. Eine unbegrenzt wachsende Datei
    frisst dort ARBEITSSPEICHER, nicht Plattenplatz - bei einem Messtakt
    von 60 s sind das 1440 Zeilen am Tag.

    Gekappt wird IN der Datei, nicht durch Umbenennen: daemon/daemon und
    us_dienst('start') haengen mit ">>" an dieselbe Datei an. Ein solcher
    Schreiber setzt immer ans aktuelle Ende auf und vertraegt das Kuerzen;
    ein Umbenennen liesse ihn dagegen in der weggeraeumten Datei
    weiterschreiben - der Platz bliebe belegt, ohne dass ihn jemand sieht.
    """
    pfad = pfad or LOGDATEI
    try:
        if not os.path.isfile(pfad) or os.path.getsize(pfad) <= LOG_GRENZE:
            return False
        with open(pfad, "r", encoding="utf-8", errors="replace") as fh:
            rest = fh.readlines()[-LOG_REST:]
        with open(pfad, "w", encoding="utf-8") as fh:
            fh.writelines(rest)
        return True
    except OSError:
        return False


_handlers = []
try:
    os.makedirs(gem.LOG_DIR, exist_ok=True)
    log_kappen()
    # WatchedFileHandler, NICHT FileHandler.
    # Am Geraet gemessen (06.09.2026, LoxBerry 4.0.0.15): log/plugins liegt auf
    # einer Ramdisk (/dev/zram0). Wird sie geleert, ist die Protokolldatei fort -
    # und ein FileHandler, der sie beim Start EINMAL geoeffnet hat, schreibt bis
    # zum naechsten Neustart in einen geloeschten Inode. Sichtbar wird davon
    # nichts. Der WatchedFileHandler prueft bei jeder Zeile Geraetenummer und
    # Inode und oeffnet noetigenfalls neu; er steht in der Standardbibliothek.
    # Aufgefallen am Heimkino-Dienst, der sieben Stunden ohne Protokoll lief.
    _handlers.append(logging.handlers.WatchedFileHandler(LOGDATEI))
except OSError:
    pass
# KEIN zweiter Kanal auf stdout.
#
# daemon/daemon und us_dienst('start') leiten stdout mit ">>" in GENAU
# DIESE Datei um. Bis 1.1.11 stand deshalb jede Zeile zweimal darin.
# Der Umleitung bleibt, wofuer sie da ist: einen Absturz aufzufangen,
# bevor das Protokoll ueberhaupt steht.
#
# Nur wenn sich die Datei nicht anlegen laesst, ist stdout die letzte
# Zuflucht - sonst saehe niemand irgendetwas.
if not _handlers:
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
            self.client = None
            return False
        # paho-mqtt 2.x verlangt eine Angabe, welche Rueckruf-Schnittstelle
        # gemeint ist; 1.x kennt den Parameter nicht.
        #
        # Genommen wird VERSION2, nicht VERSION1: VERSION1 gilt seit 2.0 als
        # veraltet und meldet das bei jedem Start. Das ist hier gefahrlos,
        # weil dieses Plugin GAR KEINE Rueckrufe anmeldet - es veroeffentlicht
        # nur. Die Unterschiede zwischen den beiden Schnittstellen betreffen
        # ausschliesslich die Aufrufform von on_connect, on_message und
        # Geschwistern.
        #
        # WER HIER SPAETER on_connect ERGAENZT, muss die Form von Fassung 2
        # verwenden:
        #     on_connect(client, userdata, flags, reason_code, properties)
        try:
            self.client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
        except AttributeError:
            self.client = mqtt.Client()      # paho-mqtt 1.x
        except (TypeError, ValueError):
            # Sehr fruehe 2.0-Vorabfassungen kannten VERSION2 noch nicht.
            try:
                self.client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION1)
            except Exception:  # noqa: BLE001
                self.client = mqtt.Client()
        if zugang["user"]:
            self.client.username_pw_set(zugang["user"], zugang["pass"] or "")
        self.client.will_set(self.praefix + "/online", "0", retain=True)
        try:
            self.client.connect(zugang["host"], zugang["port"], keepalive=60)
        except OSError as fehler:
            log.error("MQTT-Broker %s:%s nicht erreichbar: %s",
                      zugang["host"], zugang["port"], fehler)
            # DER CLIENT WIRD ZURUECKGENOMMEN (seit 1.2.2).
            #
            # Bis 1.2.1 blieb er hier stehen. Zwei Folgen, beide stumm:
            # senden() prueft nur "if not self.client" und veroeffentlichte
            # danach in einen nie verbundenen Client - bei qos 0 ist die
            # Nachricht fort. Und die Bedingung fuer einen Neuaufbau in
            # start() lautet "mqtt_an != (self.mqtt.client is not None)";
            # mit einem gesetzten Client war sie nie wahr. Nach einem
            # misslungenen ersten Verbindungsaufbau - dem Normalfall beim
            # Systemstart, wenn der Broker noch hochfaehrt - blieb MQTT
            # damit fuer die GANZE Laufzeit des Prozesses tot, und im
            # Broker standen weiter die zurueckbehaltenen Werte von vorher.
            try:
                self.client.loop_stop()
            except Exception:  # noqa: BLE001
                pass
            self.client = None
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
        # Danach gibt es keinen Client mehr. Wer das weglaesst, laesst eine
        # Huelle stehen, an der "ist MQTT an?" spaeter falsch abgelesen wird.
        self.client = None


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
        # Umlaufender Zaehler, 0..999. -1 heisst "noch nie gelaufen";
        # 0 waere ein gueltiger Stand und damit nicht zu unterscheiden.
        #
        # Warum ein Zaehler UND ein Zeitstempel: ein Raspberry Pi hat
        # keine Echtzeituhr. Nach dem Hochfahren steht er in der
        # Vergangenheit, und sobald NTP greift, springt die Zeit. Springt
        # sie nach vorn, wird ein gerechnetes Alter negativ und meldet
        # nach max(0, ...) "gerade eben gemessen". Ein umlaufender Zaehler
        # ist davon unabhaengig - in Loxone genuegt ein Baustein, der auf
        # "unveraendert seit N Minuten" schaut.
        self.zaehler = -1
        # Wann zuletzt WIRKLICH gemessen wurde (Unix-Sekunden, 0 = noch nie).
        # Getrennt vom Herzschlag, siehe durchgang().
        #
        # Uebernommen wird der Stand aus einer vorhandenen Zustandsdatei: ein
        # Neustart des Dienstes ist kein Grund, eine gelungene Messung von
        # vorhin fuer nie geschehen zu erklaeren. Ist keine da oder ist sie
        # unlesbar, bleibt es bei 0 - dann meldet der Endpunkt ALTER und
        # OK=0, und das ist die richtige Richtung.
        self.letzte_messung = 0
        try:
            with open(gem.STATUS_FILE, "r", encoding="utf-8") as fh:
                alt_stand = json.load(fh)
            if isinstance(alt_stand, dict):
                self.letzte_messung = int(alt_stand.get("zeit") or 0)
        except (OSError, ValueError, TypeError):
            pass
        # Wann der naechste MQTT-Verbindungsversuch fruehestens ansteht, und
        # wie lange dann gewartet wird. Siehe mqtt_nachfassen().
        self.mqtt_naechster = 0.0
        self.mqtt_wartezeit = 60.0
        self.mqtt_soll = (self.cfg.get("mqtt", "1") == "1")

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

    def mqtt_nachfassen(self):
        """MQTT verbindet nicht EINMAL, sondern bis es klappt (seit 1.2.2).

        Beim Systemstart startet daemon/daemon diesen Dienst, waehrend das
        MQTT-Gateway und der Broker noch hochfahren. Der erste
        Verbindungsversuch scheitert dann - das ist der Normalfall, kein
        Ausnahmefall. Bis 1.2.1 wurde er genau einmal unternommen; danach
        blieb der Regelweg bis zum naechsten Dienstneustart aus, ohne dass
        nach der einen Fehlerzeile im Protokoll noch etwas darauf hinwies.

        Erst nach einer Minute, dann immer seltener, hoechstens alle fuenf
        Minuten. Und kommt die Verbindung zustande, wird ALLES neu gesendet:
        der Broker kennt die Werte nicht, und der Doppelt-senden-Filter
        haelt sie sonst zurueck.
        """
        if not self.mqtt_soll or self.mqtt.client is not None:
            return False
        jetzt = time.time()
        if jetzt < self.mqtt_naechster:
            return False
        if self.mqtt.start():
            self.mqtt_wartezeit = 60.0
            self.mqtt_naechster = 0.0
            # Der Doppelt-senden-Filter kennt den Broker nicht. Ohne dieses
            # Leeren stuende nach einer wiedergewonnenen Verbindung nur das
            # im Broker, was sich seither zufaellig geaendert hat.
            self.letzter_stand.clear()
            log.info("MQTT-Verbindung nachgeholt - alle Werte werden neu gesendet")
            return True
        self.mqtt_naechster = jetzt + self.mqtt_wartezeit
        self.mqtt_wartezeit = min(300.0, self.mqtt_wartezeit * 2.0)
        return False

    def _senden(self, thema, wert, erzwingen=False):
        wert = "" if wert is None else str(wert)
        if not erzwingen and self.letzter_stand.get(thema) == wert:
            return False
        self.letzter_stand[thema] = wert
        self.mqtt.senden(thema, wert)
        return True

    def zustand_schreiben(self, daten):
        try:
            # Die Nebendatei traegt die PID (seit 1.2.2). Bis 1.2.1 hiess sie
            # fest "<status>.tmp", waehrend konfiguration_schreiben() in
            # us_common.py die PID schon anhaengte. Schreiben zwei Exemplare
            # des Dienstes gleichzeitig - ein Fall, den die PID-Datei nicht
            # ausschliesst, siehe pid_schreiben() -, dann ueberschreibt einer
            # die Nebendatei des anderen, und os.replace zieht eine Mischung
            # an ihren Platz.
            temp = "{0}.tmp.{1}".format(gem.STATUS_FILE, os.getpid())
            with open(temp, "w", encoding="utf-8") as fh:
                json.dump(daten, fh, ensure_ascii=False)
            os.replace(temp, gem.STATUS_FILE)
            os.chmod(gem.STATUS_FILE, 0o644)
        except OSError as fehler:
            log.warning("Zustandsdatei nicht schreibbar: %s", fehler)

    def durchgang(self, erzwingen=False):
        self.zaehler = 0 if self.zaehler < 0 else (self.zaehler + 1) % 1000
        ergebnis = gem.messen(self.cfg, self.sensor)
        # Was an der Konfiguration nicht stimmt, wird gesagt - einmal je
        # Stunde und je Sache. Bis 1.2.1 fiel ein unlesbarer Wert still
        # auf die Vorgabe zurueck; gemessen wurde dann ohne Korrektur,
        # und im Protokoll stand nichts davon.
        for nummer, satz in enumerate(ergebnis.get("hinweise") or []):
            self._einmal("cfg%d" % nummer, satz, "warning")
        entfernung = ergebnis["entfernung"]
        prozent, liter = gem.fuellstand(self.cfg, entfernung)

        if entfernung is None:
            self._einmal("messung", ergebnis["fehler"], "warning")
            self._senden("valid", "0", erzwingen)
            self._senden("last_error", ergebnis["fehler"], erzwingen)
            # NACH EINEM SENSORFEHLER WIRD DER SENSOR NEU AUFGEBAUT
            # (seit 1.2.2).
            #
            # gem.messen() faengt SensorFehler selbst ab und gibt ihn im
            # Ergebnis zurueck. Bis 1.2.1 blieb self.sensor danach stehen,
            # und der Wiederaufbau in start() laeuft nur bei
            # "self.sensor is None". Ein Wackler am I2C-Kabel oder ein kurz
            # stromloser Sensor hiess damit: das alte Busobjekt bleibt, jede
            # weitere Messung scheitert daran, die Meldung wird von _einmal()
            # auf eine je Stunde gedaempft - und die Zeile "Sensor wieder
            # ansprechbar" war auf diesem Weg unerreichbar. Der Fehler
            # ueberlebte bis zum naechsten Dienstneustart, obwohl gerade
            # hier ein Neuoeffnen hilft.
            #
            # NUR bei einem Sensorfehler. "Keine brauchbare Messung" heisst,
            # dass das Geraet antwortet und die Werte nur nicht passen - da
            # hilft kein Neuoeffnen, und wer es in jedem Takt versucht,
            # erzeugt Last ohne Gegenwert.
            if ergebnis.get("sensorfehler") and self.sensor is not None:
                try:
                    self.sensor.schliessen()
                except Exception:  # noqa: BLE001
                    pass
                self.sensor = None
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

        # DER HERZSCHLAG. Er geht in JEDEM Durchgang hinaus, auch wenn sich
        # sonst nichts geaendert hat - der Doppelt-senden-Filter wird fuer
        # diese Themen uebergangen. Sonst waere ausgerechnet der Zeitstempel
        # der aelteste Wert im Broker.
        #
        # Ohne ihn ist ein toter Dienst von einem ruhigen Behaelter nicht zu
        # unterscheiden: die letzten Werte stehen retained im Broker, der
        # virtuelle Eingang behaelt seinen Stand, und in der App sieht alles
        # normal aus. Das Last-Will traegt nur, wenn der Prozess STIRBT -
        # haengt er, bleibt die Verbindung stehen und online auf 1.
        #
        # ZWEI ZEITEN, UND SIE BEANTWORTEN VERSCHIEDENE FRAGEN (seit 1.2.2):
        #
        #   ts (= "zeit")   wann zuletzt WIRKLICH gemessen wurde
        #   herzschlag      wann der Dienst zuletzt einen Durchgang hatte
        #
        # Bis 1.2.1 war beides dasselbe: ts wurde in JEDEM Durchgang
        # aufgefrischt, auch nach einer gescheiterten Messung. Der Endpunkt
        # leitet ONLINE und OK aber ALLEIN aus dem Alter von ts ab
        # (webfrontend/html/index.php). Ein abgeklemmter Sensor meldete
        # damit
        #     ULTRA;OK=1;DISTANCE=;...;VALID=0;ONLINE=1;ALTER=12
        # also einen frischen Wert, den es nicht gab. Nur VALID verriet die
        # Wahrheit - und genau die beiden Felder, die eine Ausfallerkennung
        # in Loxone benutzt, sagten das Gegenteil.
        #
        # Die Hausregel lautet: ein Zeitstempel, auf den eine
        # Ausfallerkennung baut, wird nur nach einem ERFOLGREICHEN Abruf
        # fortgeschrieben.
        #
        # KEIN NEUES MQTT-THEMA. Dass der Dienst ueberhaupt noch arbeitet,
        # beantwortet der umlaufende Zaehler, und der geht wie bisher in
        # jedem Durchgang hinaus. Ein zweites Zeitthema waere ein weiteres
        # Feld in Vorlage, Tabelle, Hilfe und beiden Sprachdateien, ohne
        # eine Frage zu beantworten, die offen ist. "herzschlag" steht
        # deshalb nur in der Zustandsdatei - dort liest es die Oberflaeche,
        # um "laeuft, misst aber nicht" von "laeuft nicht" zu trennen.
        jetzt = int(time.time())
        if entfernung is not None:
            self.letzte_messung = jetzt
        self._senden("ts", self.letzte_messung, True)
        self._senden("zaehler", self.zaehler, True)
        self._senden("online", "1", True)

        self.zustand_schreiben({
            "zeit": self.letzte_messung,
            "herzschlag": jetzt,
            "zaehler": self.zaehler,
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

            # Einmal je Takt nachsehen, ob das Protokoll zu gross wird.
            log_kappen()

            # Und einmal je Takt nachfassen, falls MQTT beim Start nicht
            # zustande kam. Kostet nichts, solange die Verbindung steht.
            self.mqtt_nachfassen()

            if self._mtime() != self.config_mtime:
                log.info("Konfiguration geändert - wird neu eingelesen")
                self.config_mtime = self._mtime()
                neu, _ = gem.konfiguration_lesen()
                # max_cm steht seit 1.2.2 mit in dieser Liste: beim HC-SR04
                # geht der Wert als DistanceSensor(max_distance=...) in das
                # Sensorobjekt ein (us_common.py, HcSr04). Wer den Messbereich
                # von 200 auf 400 cm hebt, behielt bis 1.2.1 bis zum naechsten
                # Dienstneustart die alte Zwei-Meter-Grenze - alles darueber
                # meldete gpiozero als "nichts gehoert", und nichts erklaerte,
                # warum die Aenderung wirkungslos blieb.
                sensorwechsel = (neu.get("sensor") != self.cfg.get("sensor")
                                 or neu.get("i2c_bus") != self.cfg.get("i2c_bus")
                                 or neu.get("i2c_adresse") != self.cfg.get("i2c_adresse")
                                 or neu.get("gpio_trigger") != self.cfg.get("gpio_trigger")
                                 or neu.get("gpio_echo") != self.cfg.get("gpio_echo")
                                 or neu.get("max_cm") != self.cfg.get("max_cm"))
                # Praefix und MQTT-Zustand MITZIEHEN.
                #
                # Bis 1.1.12 wurden hier nur cfg, Takt und Vollmeldung neu
                # gesetzt; self.praefix und self.mqtt blieben, wie sie beim
                # Start waren. Gemessen mit Attrappe: Praefix in der Datei
                # geaendert, Dienst liest neu ein, misst weiter - und
                # veroeffentlicht ueber den ganzen Lauf ausschliesslich unter
                # dem ALTEN Praefix. Sechs Sendungen, keine einzige unter dem
                # neuen. Wer eine Sicherung mit anderem Praefix zurueckspielt,
                # bekam damit ein Plugin, das ins Leere sendet.
                mqtt_neu = (neu.get("themenpraefix") or "ultraschall")
                mqtt_an = neu.get("mqtt", "1") == "1"
                # Entschieden wird am WUNSCH des Anwenders, nicht am Zustand
                # des Clients (seit 1.2.2). Bis 1.2.1 stand hier
                # "mqtt_an != (self.mqtt.client is not None)". Solange die
                # Verbindung steht, sagen beide dasselbe; steht sie nicht,
                # sagte die alte Form bei JEDER Aenderung an der
                # Konfiguration "neu aufbauen" und warf die Wartezeit des
                # Nachfassens weg - oder, mit dem stehengebliebenen Client
                # von 1.2.1, nie.
                if mqtt_neu != self.praefix or mqtt_an != self.mqtt_soll:
                    log.info("MQTT wird neu aufgebaut (Praefix %s -> %s, MQTT %s)",
                             self.praefix, mqtt_neu, "ein" if mqtt_an else "aus")
                    self.mqtt.stop()
                    self.praefix = mqtt_neu
                    self.mqtt = Mqtt(self.praefix)
                    self.mqtt_soll = mqtt_an
                    self.mqtt_wartezeit = 60.0
                    self.mqtt_naechster = 0.0
                    if mqtt_an:
                        self.mqtt.start()
                self.cfg = neu
                self.letzter_stand.clear()
                if sensorwechsel and self.sensor is not None:
                    self.sensor.schliessen()
                    self.sensor = None
                intervall = max(5, gem.zahl(self.cfg, "intervall", 60, int))
                vollmeldung_alle = max(intervall, gem.zahl(self.cfg, "aktualisierung", 300, int))

            # In kleinen Schritten warten, damit ein Signal sofort greift -
            # UND damit eine Aenderung der Konfiguration nicht bis zum Ende
            # des Taktes liegen bleibt.
            #
            # Bis 1.1.1 wurde die Konfiguration nur einmal je Durchgang
            # geprueft. Wer das Plugin einschaltete, wartete bis zu einem
            # vollen Takt - bei der Vorgabe 300 s also fuenf Minuten, in denen
            # nichts geschah und nichts erklaerte, warum.
            #
            # Der Vorschlag, bei ausgeschaltetem Plugin einfach kuerzer zu
            # schlafen, deckt nur die Haelfte ab: dasselbe Warten trifft, wer
            # den Takt von 300 auf 10 stellt. Ein stat() je Sekunde kostet
            # nichts und loest beide Faelle.
            ende = time.time() + intervall
            while self.laeuft and time.time() < ende:
                if self._mtime() != self.config_mtime:
                    break
                time.sleep(min(1.0, max(0.05, ende - time.time())))

    def stop(self):
        self.laeuft = False
        if self.sensor:
            self.sensor.schliessen()
        self.mqtt.stop()


def pid_schreiben():
    """Eigene PID hinterlegen, damit Oberflaeche und uninstall den Dienst
    finden, ohne die Befehlszeile durchsuchen zu muessen."""
    try:
        with open(gem.PID_FILE, "w", encoding="utf-8") as fh:
            fh.write(str(os.getpid()))
    except OSError as err:
        log.warning("PID-Datei %s nicht schreibbar: %s", gem.PID_FILE, err)


def pid_entfernen():
    """Nur die eigene PID-Datei loeschen - nicht die eines zweiten Exemplars,
    das inzwischen gestartet sein koennte."""
    try:
        with open(gem.PID_FILE, encoding="utf-8") as fh:
            if fh.read().strip() != str(os.getpid()):
                return
        os.unlink(gem.PID_FILE)
    except OSError:
        pass


def main():
    # Ein unbekannter Schalter darf nicht den Dienst starten.
    #
    # "ultraschall.py --selbstest" (mit Tippfehler) fiel bis 1.1.12 still
    # durch und landete in der Dienstschleife. Wer ein Werkzeug von Hand
    # aufruft, soll bei einem Vertipper eine Antwort sehen, keinen Prozess.
    #
    # "--einmal" GIBT ES SEIT 1.2.2 NICHT MEHR - und vorher gab es ihn auch
    # nicht wirklich. Der Schalter stand in der Positivliste, wurde
    # angenommen, und danach las KEINE Zeile sys.argv wieder: der Aufruf
    # landete in der Dienstschleife. Wer der eingebauten Hilfe folgte,
    # startete damit ein ZWEITES Exemplar neben dem laufenden Dienst - beide
    # am selben Sensor, beide schreiben dieselbe Zustandsdatei, und
    # dienst.pid zeigte danach auf das zweite. Der Stopp-Knopf und
    # preupgrade.sh trafen den falschen Prozess.
    #
    # Nachgebaut wurde er nicht: den Einmalabruf gibt es bereits, und zwar
    # als eigenes Werkzeug (bin/us_messen.py). Der Reiter Test ruft es auf
    # und weist dabei ausdruecklich aus, ob wirklich gemessen wurde oder ob
    # der Stand des laufenden Dienstes gezeigt wird. Ein zweiter Weg zum
    # selben Zweck waere die zweite Wahrheit.
    for a in sys.argv[1:]:
        sys.stderr.write("Unbekannter Schalter: {0}\n".format(a))
        sys.stderr.write("Dieses Skript kennt keine Schalter - es ist der "
                         "Dauerdienst.\n")
        sys.stderr.write("Eine einzelne Messung macht bin/us_messen.py.\n")
        sys.exit(2)

    # Ohne die gemeinsame Datenquelle wird nicht geraten, sondern abgebrochen.
    if not gem.VORGABEN:
        log.error("Vorgaben nicht lesbar: %s", gem.DATEN_FEHLER)
        log.error("Das Plugin ist unvollstaendig installiert. bin/us_vorgaben.json fehlt.")
        sys.exit(1)

    dienst = Dienst()

    def beenden(signum, rahmen):   # noqa: ARG001
        log.info("Signal %s empfangen - beende", signum)
        dienst.laeuft = False

    signal.signal(signal.SIGTERM, beenden)
    signal.signal(signal.SIGINT, beenden)

    pid_schreiben()
    try:
        dienst.start()
    except KeyboardInterrupt:
        pass
    finally:
        dienst.stop()
        pid_entfernen()
        log.info("Beendet")


if __name__ == "__main__":
    main()
