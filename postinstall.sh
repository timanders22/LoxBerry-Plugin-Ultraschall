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
# Ausfuehrbar machen. Ohne das startet der Daemon beim Systemstart nicht.
chmod 755 "$LBPBIN/$PDIR"/*.py 2>/dev/null

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

# Module beim Start laden
if [ -f /etc/modules ]; then
    for modul in i2c-bcm2708 i2c-dev; do
        if ! grep -qE "^[[:space:]]*$modul([[:space:]]|$)" /etc/modules; then
            echo "$modul" >> /etc/modules
            echo "<INFO> Modul $modul in /etc/modules eingetragen."
        fi
    done
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
