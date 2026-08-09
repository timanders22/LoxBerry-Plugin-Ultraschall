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

PCONFIG=$LBPCONFIG/$PDIR
PBIN=$LBPBIN/$PDIR
MERKER="$PCONFIG/.upgrade_pfad"

# preupgrade.sh hat den tatsaechlich benutzten Ordner hinterlegt.
if [ -r "$MERKER" ]; then
    SICHERUNG=$(cat "$MERKER")
elif [ -n "$PWORKDIR" ] && [ -d "$PWORKDIR" ]; then
    SICHERUNG="$PWORKDIR/ultraschall_upgrade"
else
    SICHERUNG="/tmp/${PDIR}.SAVE"
fi

mkdir -p "$PCONFIG" 2>/dev/null
if [ -d "$SICHERUNG" ]; then
    echo "<INFO> Spiele gesicherte Konfiguration zurueck aus $SICHERUNG"
    # Den eigenen Merker nicht mitkopieren.
    rm -f "$SICHERUNG/.upgrade_pfad" 2>/dev/null
    cp -a "$SICHERUNG/." "$PCONFIG/" 2>/dev/null && \
        echo "<OK> Konfiguration wiederhergestellt."
else
    echo "<WARNING> Keine gesicherte Konfiguration unter $SICHERUNG gefunden."
    echo "<INFO> Die Einstellungen bitte einmal nachsehen."
fi

rm -f "$MERKER" 2>/dev/null

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

echo "<OK> postupgrade abgeschlossen."
exit 0
