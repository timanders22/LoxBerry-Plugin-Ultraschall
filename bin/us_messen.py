#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Ultraschall Entfernung - einmaliger Messlauf

Wird vom Reiter Test aufgerufen und gibt das Ergebnis als JSON auf die
Standardausgabe. Laeuft unabhaengig vom Dienst, damit man den Sensor
ausprobieren kann, ohne den Dienst zu starten.
"""

import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import us_common as gem   # noqa: E402


def main():
    # Ohne die gemeinsame Datenquelle gibt es keine Vorgaben und keine
    # Grenzen - dann wird gemeldet statt gemessen.
    if not gem.VORGABEN:
        print(json.dumps({
            "entfernung": None, "roh": [], "verworfen": [],
            "fehler": "Vorgaben nicht lesbar: " + gem.DATEN_FEHLER,
            "hinweis": "bin/us_vorgaben.json fehlt - das Plugin ist "
                       "unvollstaendig installiert.",
        }, ensure_ascii=False))
        return
    cfg, _alt = gem.konfiguration_lesen()
    try:
        ergebnis = gem.messen(cfg)
    # HIER STAND EIN "except gem.SensorFehler" (bis 1.2.1). Er konnte nie
    # greifen: gem.messen() faengt SensorFehler selbst ab und gibt ihn im
    # Feld "fehler" zurueck; gem.sensor_aufbauen() wirft ihn nicht. Gemessen
    # an einem Lauf ohne smbus-Bibliothek - die Antwort trug "fehler", aber
    # kein "hinweis": der Zweig wurde nicht durchlaufen. Der sorgfaeltig
    # formulierte Satz "Der Knopf 'Sensor pruefen' zeigt, woran es liegt"
    # hat den Anwender deshalb NIE erreicht.
    #
    # Der Hinweis steht jetzt dort, wo der Fall wirklich ankommt - unten, an
    # der Angabe, die gem.messen() ueber sich selbst macht.
    except Exception as fehler:  # noqa: BLE001
        # Alles Unerwartete ebenfalls als JSON melden - die Oberflaeche kann
        # mit einem Python-Rueckverfolgungsprotokoll nichts anfangen.
        print(json.dumps({
            "entfernung": None,
            "roh": [],
            "verworfen": [],
            "fehler": "{0}: {1}".format(type(fehler).__name__, fehler),
        }, ensure_ascii=False))
        return

    ergebnis["sensor"] = cfg.get("sensor", "srf02")
    if ergebnis.get("sensorfehler"):
        ergebnis["hinweis"] = ("Der Sensor liess sich nicht ansprechen. Der "
                               "Knopf \"Sensor pruefen\" zeigt, woran es liegt.")
    print(json.dumps(ergebnis, ensure_ascii=False))


if __name__ == "__main__":
    main()
