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

exit 0
