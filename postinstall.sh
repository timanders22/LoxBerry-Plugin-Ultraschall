#!/bin/sh

# To use important variables from command line use the following code:
COMMAND=$0    # Zero argument is shell command
PTEMPDIR=$1   # First argument is temp folder during install
PSHNAME=$2    # Second argument is Plugin-Name for scipts etc.
PDIR=$3       # Third argument is Plugin installation folder
PVERSION=$4   # Forth argument is Plugin version
#LBHOMEDIR=$5 # Comes from /etc/environment now. Fifth argument is
              # Base folder of LoxBerry

# Combine them with /etc/environment
PCGI=$LBPCGI/$PDIR
PHTML=$LBPHTML/$PDIR
PTEMPL=$LBPTEMPL/$PDIR
PDATA=$LBPDATA/$PDIR
PLOG=$LBPLOG/$PDIR # Note! This is stored on a Ramdisk now!
PCONFIG=$LBPCONFIG/$PDIR
PSBIN=$LBPSBIN/$PDIR
PBIN=$LBPBIN/$PDIR

# Protokolldatei anlegen
mkdir $PLOG
touch $PLOG/$PSHNAME.log
chown loxberry:loxberry $PLOG/$PSHNAME.log

# --- Ultraschall Entfernung ----------------------------------------------
# Rechte.
#
# Ausfuehrbar muss nur sein, was unmittelbar aufgerufen wird: ultraschall.py
# startet der Daemon beim Systemstart, us_messen.py der Reiter Test. Die
# gemeinsame Bibliothek us_common.py wird nur importiert - sie braucht kein
# Ausfuehrungsrecht und bekommt 644.
#
# Schreibrecht hat in beiden Faellen nur der Eigentuemer. 755 heisst nicht
# "jeder darf schreiben", sondern "jeder darf lesen und ausfuehren" - das ist
# fuer ein Programm im bin-Ordner richtig und entspricht dem, was LoxBerry
# fuer die eigenen Skripte setzt.
chmod 755 "$LBPBIN/$PDIR/ultraschall.py" "$LBPBIN/$PDIR/us_messen.py" 2>/dev/null
chmod 644 "$LBPBIN/$PDIR/us_common.py" 2>/dev/null

# I2C einschalten. Der SRF02 haengt am I2C-Bus; ohne dtparam gibt es kein
# /dev/i2c-1. Seit Bookworm liegt die Datei unter /boot/firmware/config.txt -
# die Originalfassung schrieb noch nach /boot/config.txt, was dort ins Leere
# ging beziehungsweise eine Datei anlegte, die niemand liest.
BOOTCFG=""
for kandidat in /boot/firmware/config.txt /boot/config.txt; do
    if [ -f "$kandidat" ]; then
        BOOTCFG="$kandidat"
        break
    fi
done
if [ -n "$BOOTCFG" ]; then
    if grep -qE '^[[:space:]]*dtparam=i2c_arm=on' "$BOOTCFG"; then
        echo "<OK> I2C ist in $BOOTCFG bereits eingeschaltet."
    else
        echo "dtparam=i2c_arm=on" >> "$BOOTCFG"
        echo "<INFO> I2C in $BOOTCFG eingeschaltet. Wirksam nach einem Neustart."
    fi
else
    echo "<INFO> Keine config.txt gefunden - kein Raspberry Pi? I2C bitte selbst einrichten."
fi

# Module beim Start laden - ueber eine eigene Datei unter modules-load.d
#
# Bis 1.1.1 wurde an /etc/modules angehaengt. Der Einwand, dabei entstuenden
# bei wiederholten Laeufen Duplikate, hat sich NICHT bestaetigt: nachgestellt
# mit je zehn Laeufen und fuenf Ausgangslagen (leer, Eintrag vorhanden,
# Eintrag mit Leerraum, auskommentiert, Teilwort "i2c-dev-alt") blieb es in
# jedem Fall bei genau einem Eintrag - der Ausdruck traegt.
#
# Umgestellt wurde trotzdem, aus einem anderen Grund: eine eigene Datei ist
# unteilbar richtig. Sie wird geschrieben, nicht angehaengt; damit KANN kein
# Duplikat entstehen, egal wie oft die Installation laeuft. Und beim
# Deinstallieren laesst sich genau diese Datei wieder entfernen, ohne in
# einer fremden Systemdatei herumzuschneiden.
#
# i2c-bcm2708 ist bewusst nicht mehr dabei: der Treiber heisst seit Jahren
# i2c-bcm2835, und geladen wird er ohnehin ueber den Geraetebaum. Ein Modul
# einzutragen, das es nicht gibt, erzeugt bei jedem Start eine Fehlermeldung
# im Systemprotokoll.
if [ -d /etc/modules-load.d ]; then
    printf '# LoxBerry-Plugin Ultraschall Entfernung\n# Wird beim Deinstallieren wieder entfernt.\ni2c-dev\n' \
        > /etc/modules-load.d/ultraschall.conf 2>/dev/null \
        && echo "<OK> /etc/modules-load.d/ultraschall.conf angelegt." \
        || echo "<INFO> /etc/modules-load.d/ultraschall.conf nicht schreibbar (nicht als root?)."
    # Alten Eintrag aus /etc/modules zuruecknehmen, damit das Modul nicht an
    # zwei Stellen steht.
    if [ -f /etc/modules ] && grep -qE '^[[:space:]]*i2c-(dev|bcm2708)([[:space:]]|$)' /etc/modules; then
        sed -i -E '/^[[:space:]]*i2c-(dev|bcm2708)[[:space:]]*$/d' /etc/modules 2>/dev/null \
            && echo "<INFO> Alte Eintraege aus /etc/modules entfernt."
    fi
elif [ -f /etc/modules ]; then
    # Sehr altes System ohne modules-load.d: dann eben wie bisher.
    if ! grep -qE "^[[:space:]]*i2c-dev([[:space:]]|$)" /etc/modules; then
        echo "i2c-dev" >> /etc/modules
        echo "<INFO> Modul i2c-dev in /etc/modules eingetragen."
    fi
fi
modprobe i2c-dev >/dev/null 2>&1 || true

# Der Dienst laeuft als loxberry. Fuer den I2C-Bus braucht er die Gruppe i2c,
# fuer die GPIO-Pins des HC-SR04 die Gruppe gpio.
for gruppe in i2c gpio; do
    if getent group "$gruppe" >/dev/null 2>&1; then
        usermod -a -G "$gruppe" loxberry 2>/dev/null && \
            echo "<OK> Benutzer loxberry zur Gruppe $gruppe hinzugefuegt." || \
            echo "<INFO> Gruppenzuordnung $gruppe nicht moeglich (nicht als root?)."
    else
        echo "<INFO> Gruppe $gruppe nicht vorhanden - wird erst mit den Paketen angelegt."
    fi
done

# Pruefen, ob die Bausteine wirklich da sind.
for modul in smbus paho.mqtt.client; do
    if python3 -c "import $modul" >/dev/null 2>&1; then
        echo "<OK> Python-Modul $modul vorhanden."
    else
        echo "<WARNING> Python-Modul $modul fehlt."
    fi
done
if python3 -c "import gpiozero" >/dev/null 2>&1; then
    echo "<OK> Python-Modul gpiozero vorhanden (fuer HC-SR04)."
else
    echo "<INFO> gpiozero fehlt - wird nur fuer den HC-SR04 gebraucht."
    echo "<INFO> Nachinstallieren: sudo apt-get install -y python3-gpiozero python3-lgpio"
fi
if command -v i2cdetect >/dev/null 2>&1; then
    echo "<OK> i2c-tools sind vorhanden."
else
    echo "<WARNING> i2c-tools fehlen. Nachinstallieren: sudo apt-get install -y i2c-tools"
fi

echo "<INFO> Naechster Schritt: Reiter Einstellungen -> Sensor waehlen,"
echo "<INFO> einschalten und speichern. Der Reiter Test zeigt, ob der"
echo "<INFO> Sensor antwortet."
echo "<INFO> Wurde I2C gerade erst eingeschaltet, ist ein Neustart noetig."


# ==== NETZ-EINSTELLUNGEN-UPDATE (automatisch eingefuegt, nicht doppeln) ====
# Zurueckspielen aus der Zweitschrift - aber NUR, wenn die Datei des Nutzers
# wirklich verloren ist. Erkannt wird das an dreierlei: sie fehlt, sie ist
# leer, oder sie ist zeichengenau die mitgelieferte Vorgabe (Pruefsumme
# unten). Der letzte Fall ist der eigentliche: genau so sieht die Datei nach
# dem Kopierschritt des Installers aus.
#
# Eine gueltige Konfiguration wird NIE ueberschrieben. Eine Sicherung, die
# echte Einstellungen ersetzt, waere schlimmer als gar keine.
NETZ_BASE="${5:-$LBHOMEDIR}"
NETZ_PDIR="${3:-ultraschall}"
NETZ_CFG="$NETZ_BASE/config/plugins/$NETZ_PDIR"
netz_zurueck() {
    datei=$1; soll=$2
    ziel="$NETZ_CFG/$datei"
    zweit="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.$datei"
    [ -f "$zweit" ] || return 0
    verloren=0
    if [ ! -f "$ziel" ] || [ ! -s "$ziel" ]; then
        verloren=1
    else
        ist=$(sha256sum "$ziel" 2>/dev/null | cut -d" " -f1)
        [ -n "$ist" ] && [ "$ist" = "$soll" ] && verloren=1
    fi
    if [ "$verloren" = "1" ]; then
        if cp -p "$zweit" "$ziel" 2>/dev/null; then
            echo "<OK> $datei aus der Zweitschrift wiederhergestellt."
        else
            echo "<WARNING> $datei liess sich nicht zurueckspielen. Die Sicherung"
            echo "<WARNING> liegt unter $zweit und kann von Hand kopiert werden."
        fi
    fi
}
netz_zurueck "ultraschall.cfg" "1ee3355c6f3812ade3c09e74b5b791217448d949daccda4334832b5822833ea0"

# ---------------------------------------------------------------------------
# DEN DIENST WIEDER ANWERFEN - erst hier, nach dem Zurueckspielen.
#
# preupgrade.sh haelt den laufenden Dienst an, damit er nicht in die neue
# Fassung hineinlaeuft. Bis 1.1.12 hat ihn danach NIEMAND wieder gestartet:
# postinstall und postupgrade taten es nicht, und daemon/daemon laeuft nur
# beim Systemstart. Nach jedem Auto-Update stand das Plugin still, bis
# jemand die Oberflaeche oeffnete und speicherte - und die Installation
# meldete dabei "ALLES ERLEDIGT".
#
# Der Sollmerker ist "enabled" in der Konfiguration, keine eigene Datei:
# der Anwender sieht ihn, und er uebersteht das Update, weil preupgrade ihn
# sichert und die Zeilen darueber ihn zurueckspielen. Ein Merker im
# Datenordner ueberlebte es NICHT - purge_installation laeuft auch im
# Upgrade-Zweig und raeumt data/plugins/<ordner>/ vollstaendig ab.
#
# Ist das Plugin ausgeschaltet, wird NICHTS gestartet. Eine Neuinstallation
# faellt darunter: dort steht enabled=0, und erst sollen Sensor und Masse
# eingetragen werden.
# ---------------------------------------------------------------------------
US_CFG="$NETZ_CFG/ultraschall.cfg"
US_SKRIPT="$LBPBIN/$PDIR/ultraschall.py"
if [ -r "$US_CFG" ] \
   && grep -qiE '^[[:space:]]*enabled[[:space:]]*=[[:space:]]*1[[:space:]]*$' "$US_CFG" \
   && [ -x "$US_SKRIPT" ]; then
    # Als loxberry, nicht als root: der Dienst braucht keine Rootrechte,
    # und liefen PID-Datei und Protokoll root, koennte die Oberflaeche ihn
    # hinterher nicht mehr anhalten.
    su loxberry -s /bin/bash -c "nohup $US_SKRIPT >> $PLOG/ultraschall.log 2>&1 &" >/dev/null 2>&1
    sleep 2
    # Die Wirkung pruefen, nicht den Rueckgabewert des Starts.
    if pgrep -u loxberry -f "$US_SKRIPT" >/dev/null 2>&1; then
        echo "<OK> Messdienst wieder gestartet."
    else
        echo "<INFO> Messdienst noch nicht gestartet - der Waechter"
        echo "<INFO> (cron.05min) holt ihn binnen fuenf Minuten nach."
    fi
else
    echo "<INFO> Das Plugin ist ausgeschaltet - der Messdienst wird nicht gestartet."
fi

exit 0
