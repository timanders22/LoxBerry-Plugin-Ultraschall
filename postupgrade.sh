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
    cp -a "$SICHERUNG/." "$PCONFIG/" 2>/dev/null
    if [ -s "$PCONFIG/ultraschall.cfg" ]; then
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

echo "<OK> postupgrade abgeschlossen."
exit 0
