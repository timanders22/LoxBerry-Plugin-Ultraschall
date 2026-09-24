#!/bin/sh
# Ultraschall Entfernung - preupgrade
#
# command <TEMPFOLDER-KENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <WORKDIR>

COMMAND=$0    # Zero argument is shell command
PTEMPDIR=$1   # Zufallskennung des Installers - KEIN Pfad, siehe unten
PSHNAME=$2    # Second argument is Plugin-Name for scipts etc.
PDIR=$3       # Third argument is Plugin installation folder
PVERSION=$4   # Forth argument is Plugin version
#LBHOMEDIR=$5 # Comes from /etc/environment now.
PWORKDIR=$6   # Arbeitsordner des Installers (absolut), neuere Fassungen

US_PDIR="${3:-ultraschall}"

# ---------------------------------------------------------------------------
# DIE WURZEL - NUR EINE, DIE NACHWEISLICH EINE IST (seit 1.2.8)
#
# Wurzel ist, was config/plugins UND data/plugins UND
# config/system/general.json traegt (Regeln/06) - fuer $5, fuer LBHOMEDIR
# und fuer die Suche aufwaerts vom eigenen Ablageort (der Installer ruft
# dieses Skript aus seinem Arbeitsordner unter data/system/tmp auf).
#
# Bis 1.2.7 stand hier US_BASE="${5:-$LBHOMEDIR}" ohne Pruefung. War beides
# leer, legte das Skript "/data/plugins" an und schrieb die Marke dorthin;
# zeigte $5 auf einen fremden Baum, landete sie dort (gemessen in WSL,
# Pruefung-Ultraschall-1.2.8, Faelle P1a/P1b). Ohne brauchbare Wurzel wird
# jetzt gewarnt statt vollzogen.
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
    echo "<WARNING> Es wird nichts gesichert, keine Marke angelegt und kein Dienst angehalten."
    exit 0
fi
PCONFIG="$US_BASE/config/plugins/$US_PDIR"

# ---------------------------------------------------------------------------
# MARKE "AKTUALISIERUNG LAEUFT" (seit 1.2.7)
#
# Als ERSTES, vor jedem anderen Schritt - auch vor dem Sichern. Wer sie
# hinter eine Bedingung stellt (etwa "nur wenn es etwas zu sichern gibt"),
# hat sie in genau den Faellen nicht, in denen etwas schiefgeht.
#
# Wozu sie dient: zwischen diesem Skript und dem letzten Hakenskript
# (postupgrade.sh) liegt eine Luecke. Der Installer baut die Cron-Datei in
# dieser Zeit neu ein; an der Einspeisebremse ist am Geraet fast eine Minute
# gemessen (Regeln/06). Laeuft cron/cron.05min in dieser Luecke, findet er
# keine PID-Datei - purge_installation loescht sie mit data/plugins/<ordner>/
# - und startet den Dienst. Dasselbe gilt fuer einen Systemstart mitten in
# der Aktualisierung (daemon/daemon) und fuer den Knopf "Dienst neu starten"
# in der Oberflaeche.
#
# Sie liegt NEBEN dem Datenordner, nicht darin: purge_installation entfernt
# data/plugins/<ordner>/ vollstaendig und naehme sie mit.
#
# In dieser Linie ist die Marke ueberwiegend VORSORGE: gemessen (WSL,
# 18.09.2026) steigt cron/cron.05min in der Luecke schon an der fehlenden
# Konfiguration aus. Offen bleibt die Zeit NACH postinstall.sh - das Skript
# holt die Konfiguration aus der Zweitschrift zurueck, und ab da fehlt dem
# Waechter nichts mehr. Genau diese Spanne und die beiden anderen Startwege
# deckt die Marke ab.
#
# US_BASE ist oben gegen general.json geprueft (seit 1.2.8); data/plugins
# gibt es dort also, und angelegt wird nichts mehr.
US_MARKE="$US_BASE/data/plugins/$US_PDIR.upgrade_laeuft"
date +%s > "$US_MARKE" 2>/dev/null
if [ -s "$US_MARKE" ]; then
    echo "<OK> Dienststart bis zum Ende der Installation gesperrt."
else
    echo "<WARNING> Die Marke $US_MARKE liess sich nicht anlegen - der Waechter"
    echo "<WARNING> kann den Messdienst waehrend der Installation starten."
fi

# ---------------------------------------------------------------------------
# WARUM GESICHERT WIRD
#
# BERICHTIGT IN 1.2.2. Hier stand: "LoxBerry loescht
# config/plugins/<ordner> beim Upgrade nicht - es kopiert aber die
# MITGELIEFERTE config/ultraschall.cfg darueber."
#
# Der zweite Halbsatz stimmt, der erste nicht. purge_installation hat
# ZWEI Aufrufstellen, und eine davon liegt IM Upgrade-Zweig; sie
# entfernt config/plugins/<ordner>/ UND data/plugins/<ordner>/
# vollstaendig. Nachgemessen an sbin/plugininstall.pl (Zweig master):
#
#   :858   if ($isupgrade) {
#   :886       &purge_installation;        <- im Upgrade-Zweig
#   :916/:920  danach wird config/plugins/<ordner>/ neu angelegt und
#              der Archivinhalt hineinkopiert
#   :233   &purge_installation("all")      <- der zweite Aufruf, beim
#              Deinstallieren
#
# Das ist keine Wortklauberei: die falsche Praemisse ist genau die, aus
# der bis zum 02.09.2026 ein Merker im Konfigurationsordner entstand,
# der dort nie ankommen konnte (siehe unten). Dreissig Zeilen tiefer
# stand in derselben Datei bereits das Richtige.
#
# Ohne diese Sicherung stuenden nach jedem Upgrade Sensortyp, Adressen,
# Behaeltermasse und Takt wieder auf Werkseinstellung. Die Reihenfolge
# im Installer: preupgrade -> purge_installation -> Konfig aus dem
# Archiv kopieren -> postinstall -> postupgrade. Es gibt genau ein
# Rettungsfenster (hier) und zwei Rueckgabefenster (postinstall aus der
# Zweitschrift, postupgrade aus dem Arbeitsordner).
#
# WOHIN GESICHERT WIRD
#
# Bis 1.1.1 nach /tmp/<ordner>.SAVE. Berechtigt ist der Einwand, dass /tmp
# auf dem LoxBerry fluechtig ist: ein Stromausfall mitten im Upgrade, und die
# Sicherung ist fort.
#
# Nicht berechtigt ist der uebliche Zusatz, man solle statt dessen "$1"
# nehmen, das sei der Pfad des Installers. Ist es nicht: der Installer ruft
#   "$script" "$tempfile" "$pname" "$pfolder" "$pversion" "$lbhomedir" "$tempfolder"
# auf, und $tempfile ist eine Zufallskennung aus zehn Zeichen
# (&generate(10)). Der absolute Arbeitsordner kommt als SECHSTES Argument.
# Ein "cp ... $1/config" schluege schlicht fehl.
#
# Der Arbeitsordner liegt unter data/system/tmp und wird vom Installer selbst
# aufgeraeumt - erst NACH postupgrade. Genau dorthin wird jetzt gesichert,
# mit Rueckfall auf den alten Weg fuer aeltere LoxBerry-Fassungen.
# ---------------------------------------------------------------------------

if [ -n "$PWORKDIR" ] && [ -d "$PWORKDIR" ]; then
    SICHERUNG="$PWORKDIR/ultraschall_upgrade"
else
    echo "<INFO> Kein Arbeitsordner uebergeben - Rueckfall auf /tmp"
    SICHERUNG="/tmp/${PDIR}.SAVE"
fi
mkdir -p "$SICHERUNG" 2>/dev/null

# HIER STAND EIN MERKER .upgrade_pfad IM KONFIGURATIONSORDNER, den
# postupgrade.sh als ersten von drei Wegen lesen sollte - mit der
# Begruendung, das sei "die eine Stelle, an der beide auseinanderlaufen".
#
# Er kann dort nie ankommen: purge_installation entfernt genau dieses
# Verzeichnis, bevor postupgrade laeuft. Nachgestellt: nach preupgrade da,
# nach dem Abraeumen weg. Der Zweig war tot und das rm -f darauf ebenfalls.
#
# Gefaehrlich war es nicht - der Rueckfall auf den Arbeitsordner traegt -,
# aber die Zusicherung sagte das Gegenteil dessen, was der Code tut. Beide
# Skripte rechnen den Pfad aus DEMSELBEN Argument aus, und das ist die eine
# Stelle, an der sie nicht auseinanderlaufen koennen.
#
# Ausgebaut am 02.09.2026. Die Schwesterlinie Smartmeter classic hatte
# denselben Merker schon in 2.3.14 aus demselben Grund entfernt.

echo "<INFO> Sicherungsordner: $SICHERUNG"
# Die beiden Faelle sind ENTGEGENGESETZT und bekommen deshalb zwei
# Meldungen (seit 1.2.2): bis 1.2.1 sagte der else-Zweig "nichts zu
# sichern" - auch dann, wenn eine vorhandene Konfiguration sich NICHT
# kopieren liess. Einmal ist nichts zu retten, einmal ist die Rettung
# misslungen.
if [ ! -s "$PCONFIG/ultraschall.cfg" ]; then
    echo "<INFO> Keine bestehende Konfiguration gefunden - nichts zu sichern."
elif cp -a "$PCONFIG/." "$SICHERUNG/" 2>/dev/null \
     && [ -s "$SICHERUNG/ultraschall.cfg" ]; then
    echo "<OK> Konfiguration gesichert."
else
    echo "<ERROR> Die vorhandene Konfiguration liess sich NICHT sichern."
    echo "<ERROR> Nach dem Upgrade die Einstellungen bitte nachsehen."
fi

# ---------------------------------------------------------------------------
# Laufenden Dienst anhalten, damit er nicht in die neue Fassung hineinlaeuft.
#
# Nicht ueber "pkill -f ultraschall.py": das traefe auch einen Editor mit
# offener Datei oder ein zweites Exemplar des Plugins.
#
# ARGUMENTWEISE, ALLE, UND VOR JEDEM SIGNAL GEPRUEFT (seit 1.2.8).
# Bis 1.2.7 galt ein Prozess als Dienst, wenn eines der ersten beiden
# Argumente der Skriptpfad war; gesucht wurde nur ueber die PID-Datei; und
# nach "kill" folgte "sleep 2; kill -9" auf dieselbe Nummer, ohne zu fragen,
# ob sie noch dem Dienst gehoert. Gemessen in WSL
# (Pruefung-Ultraschall-1.2.8): ein zweiter Dienst ohne PID-Datei lief
# weiter (P2), "tail <skript> -f" und ein Prozess mit dem Skriptpfad als
# Namen wurden beendet (P3/P3b), und ein Prozess, der sich nach SIGTERM per
# exec ersetzt, bekam das harte Signal (P4).
#
# Ein Treffer hat GENAU zwei Argumente: argv[0] ist ein Python-Interpreter,
# argv[1] ist Zeichen fuer Zeichen SKRIPT - dieselbe Bauart wie
# daemon/daemon. Gesucht wird ueber /proc (Prozesse des Dienstbenutzers)
# und ueber die PID-Dateien. Dieses Skript laeuft als loxberry und kann
# ohnehin nur dessen Prozesse beenden.
# ---------------------------------------------------------------------------
# Der Ramdisk-Ordner traegt seit 1.1.2 den PLUGIN-Ordnernamen, nicht mehr fest
# "ultraschall" - sonst teilten sich eine Zweitinstallation (ultraschall_01)
# und die erste dieselbe PID-Datei, und dieses Upgrade beendete den Dienst der
# FALSCHEN Installation. Bei einer einzelnen Installation ist $PDIR genau
# "ultraschall", der Pfad bleibt also derselbe.
if [ -d /run/shm ]; then RAMDIR="/run/shm/$US_PDIR"; else RAMDIR="/tmp/$US_PDIR"; fi
SKRIPT="$US_BASE/bin/plugins/$US_PDIR/ultraschall.py"
US_UID=$(id -u loxberry 2>/dev/null || id -u)
us_ist_dienst() {
    [ -r "/proc/$1/cmdline" ] || return 1
    us_n=0
    us_a0=""
    us_a1=""
    while IFS= read -r us_arg; do
        us_n=$((us_n + 1))
        [ "$us_n" = 1 ] && us_a0="$us_arg"
        [ "$us_n" = 2 ] && us_a1="$us_arg"
    done <<US_ARGUMENTE
$(tr '\0' '\n' < "/proc/$1/cmdline" 2>/dev/null)
US_ARGUMENTE
    [ "$us_n" = 2 ] || return 1
    [ "$us_a1" = "$SKRIPT" ] || return 1
    case "${us_a0##*/}" in
        python|python3|python3.*) return 0 ;;
    esac
    return 1
}
# Seit 1.1.2 liegt die PID-Datei in einem eigenen Unterordner; der alte Ort
# wird mitgeprueft, damit auch ein Upgrade von 1.1.1 den Dienst findet.
us_dienste() {
    {
        for us_d in /proc/[0-9]*; do
            grep -qaF "$SKRIPT" "$us_d/cmdline" 2>/dev/null || continue
            us_p=${us_d#/proc/}
            us_ist_dienst "$us_p" || continue
            [ "$(stat -c %u "$us_d" 2>/dev/null)" = "$US_UID" ] || continue
            echo "$us_p"
        done
        for us_pd in "$RAMDIR/dienst.pid" /run/shm/ultraschall.pid /tmp/ultraschall.pid; do
            [ -f "$us_pd" ] || continue
            us_p=$(cat "$us_pd" 2>/dev/null)
            case "$us_p" in
                ''|*[!0-9]*) ;;
                *) us_ist_dienst "$us_p" && echo "$us_p" ;;
            esac
        done
    } | sort -un
}
US_ZIEL=$(us_dienste)
if [ -n "$US_ZIEL" ]; then
    kill $US_ZIEL 2>/dev/null
    for i in 1 2 3 4 5 6 7 8 9 10; do
        [ -n "$(us_dienste)" ] || break
        sleep 1
    done
    # Vor dem harten Signal NEU gesucht, nicht die Liste von eben.
    US_REST=$(us_dienste)
    if [ -n "$US_REST" ]; then
        kill -9 $US_REST 2>/dev/null
        sleep 1
    fi
    US_UEBRIG=$(us_dienste)
    if [ -n "$US_UEBRIG" ]; then
        echo "<WARNING> Der Messdienst laeuft weiter (PID $(echo $US_UEBRIG))."
    else
        echo "<INFO> Laufenden Messdienst angehalten (PID $(echo $US_ZIEL))."
    fi
fi
rm -f "$RAMDIR/dienst.pid" /run/shm/ultraschall.pid /tmp/ultraschall.pid 2>/dev/null


# ==== NETZ-EINSTELLUNGEN-UPDATE (automatisch eingefuegt, nicht doppeln) ====
# Zweitschrift NEBEN den Konfigurationsordner, zusaetzlich zur bisherigen
# Sicherung. Grund: der Installer kopiert config/* aus dem Archiv ueber
# config/plugins/<ordner> (plugininstall.pl Zeile 899, cp -r ohne -n) und
# ueberschreibt dabei die Datei des Nutzers. Bisher haing die Rettung allein
# an postupgrade.sh. Laeuft das aus irgendeinem Grund nicht durch, greift
# jetzt postinstall.sh auf diese Zweitschrift zu - sie liegt ausserhalb des
# ueberschriebenen Ordners und wird vom Installer nicht angefasst.
# Dieselbe gepruefte Wurzel wie oben (seit 1.2.8; vorher "${5:-$LBHOMEDIR}"
# ungeprueft - ohne beides zeigte die Zweitschrift nach /config/plugins).
NETZ_BASE="$US_BASE"
NETZ_PDIR="$US_PDIR"
NETZ_CFG="$NETZ_BASE/config/plugins/$NETZ_PDIR"
# Die Meldung stand bis 1.2.1 AUSSERHALB dieses if und hinter zwei
# Befehlen mit 2>/dev/null - sie erschien also auch dann, wenn gar keine
# Konfiguration da war oder das Kopieren scheiterte. Die Zweitschrift
# ist der einzige Rettungsweg, der purge_installation ueberlebt; ihr
# Vorhandensein gehoert gemessen, nicht behauptet.
NETZ_ZWEIT="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.ultraschall.cfg"
if [ ! -s "$NETZ_CFG/ultraschall.cfg" ]; then
    echo "<INFO> Keine Einstellungen vorhanden - keine Zweitschrift noetig."
elif cp -p "$NETZ_CFG/ultraschall.cfg" "$NETZ_ZWEIT" 2>/dev/null \
     && [ -s "$NETZ_ZWEIT" ]; then
    chmod 0600 "$NETZ_ZWEIT" 2>/dev/null
    echo "<INFO> Zweitschrift der Einstellungen angelegt."
else
    echo "<WARNING> Die Zweitschrift der Einstellungen liess sich nicht anlegen."
fi

exit 0
