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

PCONFIG=$LBPCONFIG/$PDIR
PBIN=$LBPBIN/$PDIR

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
# Ueber die PID-Datei, nicht ueber "pkill -f ultraschall.py": das traefe auch
# einen Editor mit offener Datei oder ein zweites Exemplar des Plugins.
# Geprueft wird das ZWEITE Argument der Befehlszeile gegen den vollen Pfad.
# ---------------------------------------------------------------------------
# Der Ramdisk-Ordner traegt seit 1.1.2 den PLUGIN-Ordnernamen, nicht mehr fest
# "ultraschall" - sonst teilten sich eine Zweitinstallation (ultraschall_01)
# und die erste dieselbe PID-Datei, und dieses Upgrade beendete den Dienst der
# FALSCHEN Installation. Bei einer einzelnen Installation ist $PDIR genau
# "ultraschall", der Pfad bleibt also derselbe.
if [ -d /run/shm ]; then RAMDIR="/run/shm/$PDIR"; else RAMDIR="/tmp/$PDIR"; fi
SKRIPT="$LBHOMEDIR/bin/plugins/$PDIR/ultraschall.py"
# Seit 1.1.2 liegt die PID-Datei in einem eigenen Unterordner; der alte Ort
# wird mitgeprueft, damit auch ein Upgrade von 1.1.1 den Dienst findet.
for PIDDATEI in "$RAMDIR/dienst.pid" /run/shm/ultraschall.pid /tmp/ultraschall.pid; do
    [ -f "$PIDDATEI" ] || continue
    P=$(cat "$PIDDATEI" 2>/dev/null)
    if [ -n "$P" ] && kill -0 "$P" 2>/dev/null \
       && tr '\0' '\n' < "/proc/$P/cmdline" 2>/dev/null | head -2 | grep -qxF "$SKRIPT"; then
        kill "$P" 2>/dev/null
        sleep 2
        kill -9 "$P" 2>/dev/null
        echo "<INFO> Laufenden Messdienst angehalten (PID $P)."
    fi
    rm -f "$PIDDATEI"
done


# ==== NETZ-EINSTELLUNGEN-UPDATE (automatisch eingefuegt, nicht doppeln) ====
# Zweitschrift NEBEN den Konfigurationsordner, zusaetzlich zur bisherigen
# Sicherung. Grund: der Installer kopiert config/* aus dem Archiv ueber
# config/plugins/<ordner> (plugininstall.pl Zeile 899, cp -r ohne -n) und
# ueberschreibt dabei die Datei des Nutzers. Bisher haing die Rettung allein
# an postupgrade.sh. Laeuft das aus irgendeinem Grund nicht durch, greift
# jetzt postinstall.sh auf diese Zweitschrift zu - sie liegt ausserhalb des
# ueberschriebenen Ordners und wird vom Installer nicht angefasst.
NETZ_BASE="${5:-$LBHOMEDIR}"
NETZ_PDIR="${3:-ultraschall}"
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
