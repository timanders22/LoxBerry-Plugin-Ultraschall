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
# LoxBerry loescht config/plugins/<ordner> beim Upgrade nicht - es kopiert
# aber die MITGELIEFERTE config/ultraschall.cfg darueber
# (plugininstall.pl: cp -r $tempfolder/config/* $lbhomedir/config/plugins/...).
# Ohne diese Sicherung stuenden nach jedem Upgrade Sensortyp, Adressen,
# Behaeltermasse und Takt wieder auf Werkseinstellung. Die Reihenfolge im
# Installer passt dazu: preupgrade -> Konfig kopieren -> postupgrade.
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
# Den benutzten Ort hinterlegen, damit postupgrade.sh ihn nicht erneut raten
# muss - das waere die eine Stelle, an der beide auseinanderlaufen koennen.
mkdir -p "$PCONFIG" 2>/dev/null
echo "$SICHERUNG" > "$PCONFIG/.upgrade_pfad" 2>/dev/null

echo "<INFO> Sicherungsordner: $SICHERUNG"
if cp -a "$PCONFIG/." "$SICHERUNG/" 2>/dev/null; then
    echo "<OK> Konfiguration gesichert."
else
    echo "<INFO> Keine bestehende Konfiguration gefunden - nichts zu sichern."
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
if [ -s "$NETZ_CFG/ultraschall.cfg" ]; then
    cp -p "$NETZ_CFG/ultraschall.cfg" "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.ultraschall.cfg" 2>/dev/null \
        && chmod 0600 "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.ultraschall.cfg" 2>/dev/null
fi
echo "<INFO> Zweitschrift der Einstellungen angelegt."

exit 0
