#!/bin/bash

# Ultraschall Entfernung - preinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <WORKDIR>
#
# Neu im Durchgangsbau vom 02.10.2026 (Bauart F, Entscheidung 1 vom
# 29.09.2026, X-1; Muster: Govee 0.9.24, Abfahrts-Assistent 1.6.16). Der
# Installer ruft dieses Skript bei JEDEM Einbau auf, nach dem Aufraeumen der
# alten Fassung und VOR dem Kopieren von Konfiguration, Cron-Datei und
# Oberflaeche (sbin/plugininstall.pl: preupgrade :846, purge :874,
# preinstall :877, Cron :990 - Geraet/2026-09-05/08_plugininstall.pl).
#
# Eine Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh als Erstes anlegt
# (kein Altersvergleich). Dann tut es nichts: die Zweitschrift braucht
# postinstall.sh fuer den Fall, dass die Sicherung im Arbeitsordner fehlt.
#
# Ohne Marke ist es eine NEUINSTALLATION. Eine liegengebliebene Zweitschrift
# <ordner>.backup.ultraschall.cfg (Einstellungen mit Aktionstoken) aus einer
# frueheren Installation geht nach <name>.alt, gemeldet mit genau einer
# <WARNING>. Gemessen bis 1.2.10 (Installer-Pruefer N1): eine Neuinstallation
# holte Einstellungen, ALTES Token und enabled=1 zurueck und startete den
# Messdienst mit einem fremden Sensortyp. postinstall.sh prueft die Marke
# seither ebenfalls; dieses Skript legt beiseite, bevor ueberhaupt etwas
# kopiert ist. Nichts liest .alt; die Deinstallation raeumt es ab.

ARGV3=$3
ARGV5=$5
# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset).
LBHOMEDIR="${LBHOMEDIR:-$5}"
PFOLDER="${ARGV3:-ultraschall}"
BASE="${ARGV5:-$LBHOMEDIR}"

# Wurzelsuche wie in preupgrade.sh, postinstall.sh und postupgrade.sh: ohne
# config/plugins, data/plugins UND config/system/general.json wird nichts
# angefasst (Regeln/06).
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts beiseitegelegt."
    exit 0
fi
# Der Ordnername darf keinen Pfadtrenner tragen, sonst griffe mv/rm daneben.
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac

if [ -f "$BASE/data/plugins/$PFOLDER.upgrade_laeuft" ]; then
    # Aktualisierung: nichts zu tun.
    exit 0
fi

ZIEL="$BASE/config/plugins/$PFOLDER.backup.ultraschall.cfg"
if [ -e "$ZIEL" ] || [ -L "$ZIEL" ]; then
    rm -rf "${ZIEL:?}.alt" 2>/dev/null
    if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null; then
        [ -f "$ZIEL.alt" ] && [ ! -L "$ZIEL.alt" ] && chmod 600 "$ZIEL.alt" 2>/dev/null
        echo "<WARNING> Neuinstallation: Einstellungen einer frueheren Installation werden NICHT eingespielt. Beiseitegelegt: $ZIEL.alt (die Deinstallation raeumt sie ab)."
    else
        echo "<WARNING> Neuinstallation: Einstellungen einer frueheren Installation werden NICHT eingespielt. Nicht zu verschieben, bitte von Hand entfernen: $ZIEL"
    fi
fi
exit 0
