#!/bin/sh
# Ultraschall Entfernung - postupgrade
#
# command <TEMPFOLDER-KENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <WORKDIR>
#
# ---------------------------------------------------------------------------
# WARUM HIER NUR NOCH DIE KONFIGURATION ZURUECKKOMMT
#
# Bis 1.1.1 stand in dieser Datei eine wortgetreue Kopie des halben
# postinstall.sh: I2C in der config.txt einschalten, Kernelmodule eintragen,
# Gruppen zuordnen, Rechte setzen. Das war ueberfluessig - der Installer
# fuehrt postinstall OHNE Bedingung aus (plugininstall.pl, kein
# if ($isupgrade) davor) und postupgrade erst danach. Alles war zu diesem
# Zeitpunkt bereits erledigt.
#
# Schlimmer als die verlorene Zeit war die Verdopplung selbst: zwei Kopien
# derselben Logik laufen zwangslaeufig auseinander. Wer eine aendert und die
# andere vergisst, bekommt ein Plugin, das sich nach einer Neuinstallation
# anders verhaelt als nach einem Upgrade - und sucht den Grund lange.
#
# Was ein Upgrade wirklich braucht, steht hier.
# ---------------------------------------------------------------------------

COMMAND=$0
PTEMPDIR=$1   # Zufallskennung, KEIN Pfad
PSHNAME=$2
PDIR=$3
PVERSION=$4
#LBHOMEDIR=$5 # Comes from /etc/environment now.
PWORKDIR=$6   # Arbeitsordner des Installers (absolut)

US_PDIR="${3:-ultraschall}"
# U4 (seit 1.2.18): der Ordnername darf keinen Pfadtrenner tragen - dieselbe
# Schranke wie in preinstall.sh. Bis 1.2.17 stand sie nur dort.
case "$US_PDIR" in
    ''|*/*|*..*) echo "<WARNING> Ordnername '$US_PDIR' unbrauchbar - nichts getan."; exit 0 ;;
esac

# ---------------------------------------------------------------------------
# DIE WURZEL - NUR EINE, DIE NACHWEISLICH EINE IST (seit 1.2.8)
#
# Wie in preupgrade.sh und postinstall.sh: config/plugins UND data/plugins
# UND config/system/general.json (Regeln/06), fuer $5, LBHOMEDIR und die
# Suche aufwaerts. Bis 1.2.7 stand unten US_BASE="${5:-$LBHOMEDIR}"
# ungeprueft - zeigte $5 auf einen fremden Baum, loeschte dieses Skript dort
# eine Marke (gemessen in WSL, Pruefung-Ultraschall-1.2.8, Fall G1), und
# PCONFIG kam aus $LBPCONFIG; ohne die Variable legte "mkdir -p" einen
# Ordner ab "/" an. Ohne brauchbare Wurzel wird gewarnt statt vollzogen.
# ---------------------------------------------------------------------------
us_ist_wurzel() {
    [ -n "$1" ] && [ -d "$1/config/plugins" ] && [ -d "$1/data/plugins" ] \
        && [ -f "$1/config/system/general.json" ]
}
us_wurzel_suchen() {
    v=$(cd "$(dirname "$(readlink -f "$0")")" 2>/dev/null && pwd)
    i=0
    while [ -n "$v" ] && [ "$v" != "/" ] && [ $i -lt 8 ]; do
        if us_ist_wurzel "$v"; then echo "$v"; return 0; fi
        v=$(dirname "$v"); i=$((i + 1))
    done
    return 1
}
US_BASE=""
for us_k in "${5:-}" "${LBHOMEDIR:-}"; do
    if us_ist_wurzel "$us_k"; then US_BASE="$us_k"; break; fi
done
[ -n "$US_BASE" ] || US_BASE=$(us_wurzel_suchen)
if [ -z "$US_BASE" ]; then
    echo "<WARNING> Keine LoxBerry-Wurzel gefunden (Argument 5: '${5:-}', LBHOMEDIR: '${LBHOMEDIR:-}')."
    echo "<WARNING> Verlangt sind config/plugins, data/plugins und config/system/general.json."
    echo "<WARNING> Es wird nichts zurueckgespielt und keine Marke entfernt."
    exit 0
fi
PCONFIG="$US_BASE/config/plugins/$US_PDIR"
# Der Sicherungsort wird aus DEMSELBEN Argument gerechnet wie in
# preupgrade.sh - siehe die ausfuehrliche Begruendung dort. Ein Merker
# .upgrade_pfad im Konfigurationsordner stand hier bis 02.09.2026 an erster
# Stelle; purge_installation entfernt dieses Verzeichnis, bevor dieses Skript
# laeuft, der Zweig war also tot.
if [ -n "$PWORKDIR" ] && [ -d "$PWORKDIR" ]; then
    SICHERUNG="$PWORKDIR/ultraschall_upgrade"
else
    SICHERUNG="/tmp/${PDIR}.SAVE"
fi

# GEMESSEN WIRD DIE WIRKUNG, NICHT DIE EXISTENZ DES ORDNERS
# (seit 1.2.2).
#
# Bis 1.2.1 stand hier "if [ -d $SICHERUNG ]" und dahinter ein
# "cp -a ... && echo <OK> Konfiguration wiederhergestellt". Beides
# gelingt auch ueber einem LEEREN Ordner: preupgrade.sh legt ihn mit
# mkdir -p an, und scheitert das cp dort (unlesbare Datei, voller
# Arbeitsspeicher unter data/system/tmp), bleibt er leer. Nachgestellt:
# das Protokoll meldete zweimal OK, und in der Datei stand die
# Werkseinstellung - Sensortyp, Behaeltermasse und Takt waren fort.
#
# "Nachgezaehlt statt behauptet" steht im uninstall dieses Plugins
# ausdruecklich als Grundsatz. Hier war er nicht angewandt.
mkdir -p "$PCONFIG" 2>/dev/null
if [ -d "$SICHERUNG" ] && [ -s "$SICHERUNG/ultraschall.cfg" ]; then
    echo "<INFO> Spiele gesicherte Konfiguration zurueck aus $SICHERUNG"
    # U4 (seit 1.2.18): die Konfiguration ueber eine Nebendatei, mit Vergleich, dann
    # umbenennen; der uebrige Ordner (Praefixliste) danach wie bisher mit cp -a. Bis
    # 1.2.17 kopierte ein einziges "cp -a" alles direkt - bei voller Platte blieb eine
    # gekappte Konfiguration.
    if cp -p "$SICHERUNG/ultraschall.cfg" "$PCONFIG/ultraschall.cfg.neu" 2>/dev/null \
       && cmp -s "$SICHERUNG/ultraschall.cfg" "$PCONFIG/ultraschall.cfg.neu" \
       && mv -f "$PCONFIG/ultraschall.cfg.neu" "$PCONFIG/ultraschall.cfg" 2>/dev/null; then
        find "$SICHERUNG" -mindepth 1 -maxdepth 1 ! -name ultraschall.cfg \
            -exec cp -a {} "$PCONFIG/" \; 2>/dev/null
    else
        rm -f "$PCONFIG/ultraschall.cfg.neu" 2>/dev/null
    fi
    if [ -s "$PCONFIG/ultraschall.cfg" ] && cmp -s "$SICHERUNG/ultraschall.cfg" "$PCONFIG/ultraschall.cfg"; then
        echo "<OK> Konfiguration wiederhergestellt."
    else
        echo "<ERROR> Die Konfiguration liess sich NICHT zurueckspielen."
        echo "<ERROR> Die Sicherung liegt unter $SICHERUNG."
        echo "<ERROR> Die Einstellungen bitte nachsehen und neu eintragen."
    fi
elif [ -s "$PCONFIG/ultraschall.cfg" ]; then
    # postinstall.sh hat sie aus der Zweitschrift neben dem Ordner
    # schon zurueckgeholt - der zweite von zwei Wegen. Kein Grund zur
    # Warnung.
    echo "<OK> Die Konfiguration steht bereits - nichts zurueckzuspielen."
else
    echo "<WARNING> Keine gesicherte Konfiguration unter $SICHERUNG gefunden."
    echo "<WARNING> Die Einstellungen bitte einmal nachsehen."
fi

# Hier stand "rm -f $MERKER". Mit dem Merker ist auch das entfallen - die
# Variable gab es danach nicht mehr, und "rm -f ''" ist kein Aufraeumen.

# Der Arbeitsordner des Installers wird von LoxBerry selbst aufgeraeumt.
# Nur der Rueckfallweg unter /tmp gehoert uns.
case "$SICHERUNG" in
    /tmp/*) rm -rf "$SICHERUNG" ;;
esac

# Reste aus 1.1.1: Zustands- und PID-Datei lagen frei im Wurzelverzeichnis
# der Ramdisk. Seit 1.1.2 gibt es dafuer einen eigenen Unterordner; die alten
# Dateien wuerden sonst als zweiter Stand liegen bleiben.
rm -f /run/shm/ultraschall_status.json /run/shm/ultraschall.pid \
      /tmp/ultraschall_status.json /tmp/ultraschall.pid 2>/dev/null

# ---------------------------------------------------------------------------
# ENDPUNKT FUER BESTEHENDE ANLAGEN AN (seit 1.2.18, E6, Uebergang wie Gruppen 9-16)
#
# Seit 1.2.18 antwortet der Endpunkt webfrontend/html/index.php nur mit
# endpunkt_ein=1; die Paketvorgabe ist 0 (Neuinstallation: aus). Eine bestehende
# Anlage hatte den Endpunkt bis 1.2.17 immer offen - fehlt der Schluessel in ihrer
# Konfiguration, kommt er hier ausdruecklich mit 1 dazu, in die Konfiguration UND in
# die Zweitschrift. Ueber eine Nebendatei, mit Gegenprobe (die alte Datei ist Byte
# fuer Byte der Anfang der neuen), 0600. Scheitert es, gilt die Lese-Regel in
# us_config_read() (dieselbe Wirkung). Warum hier: das Zurueckspielen macht dieses
# Skript, NACH postinstall.sh (plugininstall.pl :1336 vor :1362).
# ---------------------------------------------------------------------------
us_ep_ergaenzen() {
    us_d=$1
    [ -f "$us_d" ] || return 1
    grep -q '^[[:space:]]*\[ultraschall\]' "$us_d" || return 1
    grep -qE '^[[:space:]]*endpunkt_ein[[:space:]]*=' "$us_d" && return 2
    if {
           cat "$us_d"
           [ -n "$(tail -c 1 "$us_d")" ] && echo
           echo "endpunkt_ein=1"
       } > "$us_d.neu" 2>/dev/null \
       && head -c "$(wc -c < "$us_d")" "$us_d.neu" | cmp -s - "$us_d" \
       && [ "$(tail -n 1 "$us_d.neu")" = "endpunkt_ein=1" ] \
       && chmod 600 "$us_d.neu" 2>/dev/null \
       && mv -f "$us_d.neu" "$us_d" 2>/dev/null; then
        return 0
    fi
    rm -f "$us_d.neu" 2>/dev/null
    return 1
}
us_ep_ergaenzen "$PCONFIG/ultraschall.cfg"; US_EP1=$?
us_ep_ergaenzen "$US_BASE/config/plugins/$US_PDIR.backup.ultraschall.cfg"; US_EP2=$?
if [ "$US_EP1" = 0 ]; then
    echo "<INFO> Konfiguration ergaenzt: endpunkt_ein=1 (bestehende Anlage - der Endpunkt fuer Loxone bleibt erreichbar; abschalten im Reiter Einbindung)."
    [ "$US_EP2" = 0 ] && echo "<INFO> Die Zweitschrift traegt endpunkt_ein=1 ebenfalls."
elif [ "$US_EP1" = 1 ] && [ -s "$PCONFIG/ultraschall.cfg" ]; then
    echo "<INFO> endpunkt_ein liess sich nicht eintragen - es gilt die Lese-Regel der Oberflaeche (Endpunkt bleibt an)."
fi

# ---------------------------------------------------------------------------
# DEN DIENST NACH DEM ZURUECKSPIELEN STARTEN (seit 1.2.18, U1)
#
# postinstall.sh startet ihn schon - mit der Konfiguration, die es zu dem
# Zeitpunkt gab. Fehlte die Zweitschrift, kam enabled=1 erst hier zurueck, und bis
# 1.2.17 startete niemand (der Waechter nach bis zu fuenf Minuten). Dieselbe Datei
# wie in postinstall.sh; laeuft der Dienst schon, sagt sie es.
# ---------------------------------------------------------------------------
PLOG="$US_BASE/log/plugins/$US_PDIR"
US_START="$US_BASE/bin/plugins/$US_PDIR/us_start.sh"
if [ -r "$US_START" ]; then
    . "$US_START"
    us_dienst_starten "$US_BASE" "$US_PDIR" "$PLOG"
else
    echo "<INFO> us_start.sh fehlt - der Waechter startet den Messdienst binnen fuenf Minuten."
fi

# ---------------------------------------------------------------------------
# DIE MARKE AUS preupgrade.sh - ALS LETZTES (seit 1.2.7)
#
# Nach diesem Skript laeuft nur noch postroot.sh (seit 1.2.18, als root,
# plugininstall.pl :1389); es startet nichts und braucht die Marke nicht -
# deshalb wird sie hier entfernt. Der Installer ruft preroot, preinstall,
# preupgrade, postinstall, postupgrade, postroot in dieser Reihenfolge
# (Regeln/06).
#
# Erst NACH dem Start. Den Dienst starten postinstall.sh und der Block
# darueber - die Marke liegt also waehrend des ganzen Starts und weist jeden
# anderen Starter ab. Die umgekehrte Reihenfolge - Marke weg,
# dann starten - hat an der Vorlage Chromecast4lox 1.3.10 in WSL gemessen
# vier Dienste erzeugt: zwischen dem Entfernen und dem Augenblick, in dem
# der neue Dienst dasteht, sieht ein Waechterlauf weder die Marke noch
# einen laufenden Dienst.
#
# Entfernt wird immer, auch wenn der Start unterblieb (Plugin ausgeschaltet,
# Sicherung misslungen) - sonst sperrte die Marke den Waechter eine Stunde
# lang, ohne dass irgendwo stuende, warum. US_BASE ist oben geprueft.
US_MARKE="$US_BASE/data/plugins/$US_PDIR.upgrade_laeuft"
rm -f "$US_MARKE" 2>/dev/null
if [ -e "$US_MARKE" ]; then
    echo "<WARNING> Die Marke $US_MARKE liess sich nicht entfernen."
    echo "<WARNING> Der Messdienst startet bis zu einer Stunde lang nicht."
fi

echo "<OK> postupgrade abgeschlossen."
exit 0
