#!/bin/sh

# To use important variables from command line use the following code:
COMMAND=$0    # Zero argument is shell command
PTEMPDIR=$1   # First argument is temp folder during install
PSHNAME=$2    # Second argument is Plugin-Name for scipts etc.
PDIR=$3       # Third argument is Plugin installation folder
PVERSION=$4   # Forth argument is Plugin version
#LBHOMEDIR=$5 # Comes from /etc/environment now. Fifth argument is
              # Base folder of LoxBerry

US_PDIR="${3:-ultraschall}"
# U4 (seit 1.2.18): der Ordnername darf keinen Pfadtrenner tragen - dieselbe
# Schranke wie in preinstall.sh. Bis 1.2.17 stand sie nur dort.
case "$US_PDIR" in
    ''|*/*|*..*) echo "<WARNING> Ordnername '$US_PDIR' unbrauchbar - nichts getan."; exit 0 ;;
esac

# ---------------------------------------------------------------------------
# DIE WURZEL - NUR EINE, DIE NACHWEISLICH EINE IST (seit 1.2.8)
#
# Wurzel ist, was config/plugins UND data/plugins UND
# config/system/general.json traegt (Regeln/06) - fuer $5, fuer LBHOMEDIR
# und fuer die Suche aufwaerts vom eigenen Ablageort. Bis 1.2.7 wurde
# "${5:-$LBHOMEDIR}" ungeprueft genommen: zeigte $5 auf einen fremden Baum,
# spielte dieses Skript dort eine Zweitschrift zurueck (gemessen in WSL,
# Pruefung-Ultraschall-1.2.8, Fall I3); ohne beides entstanden Pfade ab "/".
# Ohne brauchbare Wurzel wird gewarnt; die Systemschritte (I2C, Module,
# Gruppen) laufen trotzdem, alles unter der Wurzel nicht.
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
    echo "<WARNING> Konfiguration, Rechte und Dienststart werden uebersprungen."
fi

PLOG=""
[ -n "$US_BASE" ] && PLOG="$US_BASE/log/plugins/$US_PDIR"   # Ramdisk

# Protokolldatei anlegen.
#
# -p und Anfuehrungszeichen: purge_installation raeumt config/, data/
# und bin/ ab, NICHT log/. Beim Upgrade ist das Verzeichnis also schon
# da, und ein blankes "mkdir" schrieb bei JEDEM Upgrade eine rote Zeile
# ins Installationsprotokoll ("File exists"). Folgenlos - aber eine
# Fehlerzeile, die immer dasteht, stumpft gegen die ab, die zaehlt.
# Dieses Skript laeuft als loxberry (plugininstall.pl, "sudo -n -u loxberry");
# die Datei gehoert deshalb ohnehin loxberry.
if [ -n "$PLOG" ]; then
    mkdir -p "$PLOG"
    touch "$PLOG/$PSHNAME.log"
fi

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
if [ -n "$US_BASE" ]; then
    US_BIN="$US_BASE/bin/plugins/$US_PDIR"
    chmod 755 "$US_BIN/ultraschall.py" "$US_BIN/us_messen.py" 2>/dev/null
    chmod 644 "$US_BIN/us_common.py" "$US_BIN/us_start.sh" 2>/dev/null
fi

# I2C, Kernelmodul und Gruppen - SEIT 1.2.18 NUR NOCH PRUEFEN (U2).
#
# Bis 1.2.17 versuchte dieses Skript, sie einzurichten: dtparam in die
# config.txt, /etc/modules-load.d/ultraschall.conf, ein Eintrag aus /etc/modules
# heraus, modprobe und usermod. Es laeuft aber als loxberry (plugininstall.pl,
# "sudo -n -u loxberry") - alles davon scheiterte immer, mit ehrlicher Meldung.
# Eingerichtet wird jetzt in postroot.sh, das der Installer gleich im Anschluss
# als root ausfuehrt. Hier steht nur noch, was schon da ist und was fehlt.
BOOTCFG=""
for kandidat in /boot/firmware/config.txt /boot/config.txt; do
    if [ -f "$kandidat" ]; then
        BOOTCFG="$kandidat"
        break
    fi
done
if [ -z "$BOOTCFG" ]; then
    echo "<INFO> Keine config.txt gefunden - kein Raspberry Pi? I2C bitte selbst einrichten."
elif grep -qE '^[[:space:]]*dtparam=i2c_arm=on' "$BOOTCFG"; then
    echo "<OK> I2C ist in $BOOTCFG eingeschaltet."
elif grep -qE '^[[:space:]]*dtparam=i2c_arm=off' "$BOOTCFG"; then
    echo "<INFO> I2C ist in $BOOTCFG ausdruecklich ausgeschaltet - postroot.sh aendert das nicht."
else
    echo "<INFO> I2C ist in $BOOTCFG noch nicht eingeschaltet - postroot.sh richtet es gleich im Anschluss als root ein."
fi
if grep -qsE '^[[:space:]]*i2c-dev([[:space:]]|$)' /etc/modules /etc/modules-load.d/*.conf; then
    echo "<OK> i2c-dev ist fuer den Start eingetragen."
else
    echo "<INFO> i2c-dev ist fuer den Start noch nicht eingetragen - postroot.sh richtet es gleich im Anschluss ein."
fi
if ls /dev/i2c-* >/dev/null 2>&1; then
    echo "<OK> $(ls /dev/i2c-* 2>/dev/null | head -n 1) ist vorhanden."
else
    echo "<INFO> Noch kein /dev/i2c-* - postroot.sh laedt das Modul; nach dem Einschalten von I2C ist ein Neustart noetig."
fi
for gruppe in i2c gpio; do
    if ! getent group "$gruppe" >/dev/null 2>&1; then
        echo "<INFO> Gruppe $gruppe gibt es auf diesem System nicht."
    elif id -nG loxberry 2>/dev/null | tr ' ' '\n' | grep -qx "$gruppe"; then
        echo "<OK> loxberry ist in der Gruppe $gruppe."
    else
        echo "<INFO> loxberry ist noch nicht in der Gruppe $gruppe - postroot.sh nimmt ihn gleich im Anschluss auf."
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
echo "<INFO> Hat postroot.sh gleich I2C eingeschaltet oder eine Gruppe gesetzt, sagt es das -"
echo "<INFO> dann ist ein Neustart des LoxBerry noetig."


# ==== NETZ-EINSTELLUNGEN-UPDATE (automatisch eingefuegt, nicht doppeln) ====
# Zurueckspielen aus der Zweitschrift - aber NUR, wenn die Datei des Nutzers
# wirklich verloren ist. Erkannt wird das an dreierlei: sie fehlt, sie ist
# leer, oder sie ist zeichengenau die mitgelieferte Vorgabe (Pruefsumme
# unten). Der letzte Fall ist der eigentliche: genau so sieht die Datei nach
# dem Kopierschritt des Installers aus.
#
# Eine gueltige Konfiguration wird NIE ueberschrieben. Eine Sicherung, die
# echte Einstellungen ersetzt, waere schlimmer als gar keine.
#
# Ohne gepruefte Wurzel endet das Skript hier (seit 1.2.8) - die Warnung
# steht oben. Vorher: NETZ_BASE="${5:-$LBHOMEDIR}" ungeprueft.
[ -n "$US_BASE" ] || exit 0
NETZ_BASE="$US_BASE"
NETZ_PDIR="$US_PDIR"
NETZ_CFG="$NETZ_BASE/config/plugins/$NETZ_PDIR"
# DIE MITGELIEFERTE DATEI IST DER VERGLEICHSSTAND, KEINE PRUEFSUMME
# (seit 1.2.2).
#
# Hier stand ein fest eingetragener SHA-256 der ausgelieferten
# config/ultraschall.cfg. Er stimmte - aber er ist eine zweite Wahrheit
# ueber eine Datei, die im selben Paket liegt, und er stand auf keiner
# Pflegeliste. Nachgestellt: eine einzige geaenderte Vorgabe in der
# mitgelieferten Datei, ohne die Konstante nachzuziehen, und das
# Zurueckspielen unterbleibt; die Zeile darunter liest dann enabled=0
# und meldet "Das Plugin ist ausgeschaltet - der Messdienst wird nicht
# gestartet", obwohl es eingeschaltet war.
#
# Verglichen wird jetzt gegen die Datei selbst. Sie liegt im
# Arbeitsordner des Installers - das SECHSTE Argument, nicht $1: $1 ist
# eine zehnstellige Zufallskennung. Gibt es den Arbeitsordner nicht
# (aeltere LoxBerry-Fassungen), wird NICHT geraten: eine vorhandene,
# nicht leere Konfiguration bleibt dann unangetastet, und postupgrade.sh
# spielt die Sicherung ohnehin ein zweites Mal zurueck.
NETZ_WORKDIR=$6
netz_zurueck() {
    datei=$1
    ziel="$NETZ_CFG/$datei"
    zweit="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.$datei"
    werk="$NETZ_WORKDIR/config/$datei"
    [ -f "$zweit" ] || return 0
    verloren=0
    if [ ! -f "$ziel" ] || [ ! -s "$ziel" ]; then
        verloren=1
    elif [ -f "$werk" ] && cmp -s "$ziel" "$werk"; then
        # Zeichengenau die mitgelieferte Vorgabe - genau so sieht die
        # Datei nach dem Kopierschritt des Installers aus.
        verloren=1
    fi
    if [ "$verloren" = "1" ]; then
        # U4 (seit 1.2.18): ueber eine Nebendatei, mit Vergleich, dann umbenennen. Bis
        # 1.2.17 kopierte "cp -p" direkt - bei voller Platte blieb eine gekappte Datei.
        if cp -p "$zweit" "$ziel.neu" 2>/dev/null && cmp -s "$zweit" "$ziel.neu" \
           && mv -f "$ziel.neu" "$ziel" 2>/dev/null; then
            echo "<OK> $datei aus der Zweitschrift wiederhergestellt."
        else
            rm -f "$ziel.neu" 2>/dev/null
            echo "<WARNING> $datei liess sich nicht zurueckspielen. Die Sicherung"
            echo "<WARNING> liegt unter $zweit und kann von Hand kopiert werden."
        fi
    fi
}
# NUR MIT UPGRADE-MARKE (Durchgang 02.10.2026, Bauart F, Entscheidung 1).
#
# Bis 1.2.10 prueft netz_zurueck nur "fehlt / leer / gleich der Vorgabe" -
# und bei einer Neuinstallation ist die Datei IMMER gleich der Vorgabe. Eine
# liegengebliebene Zweitschrift holte so alte Einstellungen, das alte
# Aktionstoken und enabled=1 zurueck, und der Dienst startete (gemessen,
# Installer-Pruefer N1). Zurueckgespielt wird jetzt nur, wenn preupgrade.sh
# die Marke angelegt hat - kein Altersvergleich (Nr. 1, Frage 17). Ohne Marke
# legt preinstall.sh die Zweitschrift beiseite; ist sie trotzdem da (aeltere
# LoxBerry-Fassung ohne preinstall-Haken), geschieht es hier.
NETZ_MARKE="$NETZ_BASE/data/plugins/$NETZ_PDIR.upgrade_laeuft"
NETZ_ZWEIT="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.ultraschall.cfg"
if [ -f "$NETZ_MARKE" ]; then
    netz_zurueck "ultraschall.cfg"
elif [ -e "$NETZ_ZWEIT" ] || [ -L "$NETZ_ZWEIT" ]; then
    rm -rf "${NETZ_ZWEIT:?}.alt" 2>/dev/null
    if mv -f "$NETZ_ZWEIT" "$NETZ_ZWEIT.alt" 2>/dev/null; then
        chmod 600 "$NETZ_ZWEIT.alt" 2>/dev/null
        echo "<WARNING> Neuinstallation: Einstellungen einer frueheren Installation werden NICHT eingespielt. Beiseitegelegt: $NETZ_ZWEIT.alt (die Deinstallation raeumt sie ab)."
    else
        echo "<WARNING> Neuinstallation: die Zweitschrift $NETZ_ZWEIT einer frueheren Installation liess sich nicht beiseitelegen - sie wird NICHT eingespielt; bitte von Hand entfernen."
    fi
fi

# DAS AKTIONSTOKEN BEI DER ERSTEN EINRICHTUNG (seit 1.2.18, Hausherr 25.10.2026).
#
# Bis 1.2.17 entstand es erst beim ersten Aufruf der Oberflaeche; bis dahin war der
# Endpunkt ohne Token, und eine Pruefung (Reiter Test, Messskript) meldete "Merkmal
# LEER" (am Geraet gemessen 25.10.2026). Jetzt hier - aber NUR bei einer
# Neuinstallation (keine Upgrade-Marke: bei einem Update kommt die Konfiguration samt
# Token aus Zweitschrift bzw. Sicherung zurueck und wird nie angefasst) und nur, wenn
# kein Token darin steht. Ein vorhandenes Token wird nie geaendert. Ueber eine
# Nebendatei, 0600 vor dem Umbenennen, Gegenprobe: genau eine Tokenzeile, alle
# anderen Zeilen unveraendert. Zufall aus /dev/urandom (24 Hexzeichen wie die
# Oberflaeche); ohne brauchbaren Zufall legt die Oberflaeche es spaeter an.
US_TOKCFG="$NETZ_CFG/ultraschall.cfg"
if [ ! -f "$NETZ_MARKE" ] && [ -f "$US_TOKCFG" ] \
   && ! grep -qE '^[[:space:]]*aktionstoken[[:space:]]*=[[:space:]]*[0-9a-fA-F]{16,64}[[:space:]]*$' "$US_TOKCFG"; then
    US_TOK=$(od -An -N12 -tx1 /dev/urandom 2>/dev/null | tr -d ' \n')
    if [ "${#US_TOK}" = 24 ] \
       && grep -vE '^[[:space:]]*aktionstoken[[:space:]]*=' "$US_TOKCFG" > "$US_TOKCFG.neu" 2>/dev/null \
       && echo "aktionstoken=$US_TOK" >> "$US_TOKCFG.neu" \
       && chmod 600 "$US_TOKCFG.neu" 2>/dev/null \
       && [ "$(grep -c '^aktionstoken=' "$US_TOKCFG.neu")" = 1 ] \
       && [ "$(grep -vE '^[[:space:]]*aktionstoken[[:space:]]*=' "$US_TOKCFG" | md5sum)" = "$(grep -v '^aktionstoken=' "$US_TOKCFG.neu" | md5sum)" ] \
       && mv -f "$US_TOKCFG.neu" "$US_TOKCFG" 2>/dev/null; then
        echo "<OK> Aktionstoken angelegt (24 Zeichen) - Adressen im Miniserver ggf. eintragen (Reiter Einbindung, Bestehende Anlagen)."
    else
        rm -f "$US_TOKCFG.neu" 2>/dev/null
        echo "<INFO> Das Aktionstoken wurde hier nicht angelegt - die Oberflaeche legt es beim ersten Aufruf an."
    fi
fi

# DIE KONFIGURATION TRAEGT DAS AKTIONSTOKEN - ALSO 0600 (seit 1.2.6).
#
# Aus dem Archiv kommt sie mit 0644 an, und beide Schreibwege der
# Oberflaeche setzen seit 1.2.6 selbst 0600 - aber erst beim ersten
# Speichern. Bis dahin stuende sie offen. Hausstandard seit 03.09.2026
# (Regeln/05). Die Zweitschrift daneben setzt preupgrade.sh schon lange
# so; am Geraet gemessen am 13.09.2026 war die Kopie geschuetzt und das
# Original nicht.
if [ -f "$NETZ_CFG/ultraschall.cfg" ]; then
    if chmod 600 "$NETZ_CFG/ultraschall.cfg" 2>/dev/null; then
        echo "<OK> Rechte der Konfiguration auf 0600 gesetzt."
    else
        echo "<WARNING> Rechte der Konfiguration liessen sich nicht auf 0600"
        echo "<WARNING> setzen. Sie traegt das Aktionstoken - von Hand:"
        echo "<WARNING>   chmod 600 $NETZ_CFG/ultraschall.cfg"
    fi
fi

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
# Der Startblock steht seit 1.2.18 in bin/us_start.sh (U1) - postupgrade.sh
# laedt dieselbe Datei nach dem Zurueckspielen der Sicherung.
US_START="$US_BASE/bin/plugins/$US_PDIR/us_start.sh"
if [ -r "$US_START" ]; then
    . "$US_START"
    us_dienst_starten "$US_BASE" "$US_PDIR" "$PLOG"
else
    echo "<INFO> us_start.sh fehlt - der Waechter startet den Messdienst binnen fuenf Minuten."
fi

exit 0
