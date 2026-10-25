#!/bin/sh
# Ultraschall Entfernung - postroot (seit 1.2.18, U2)
#
# command <TEMPFOLDER-KENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <WORKDIR>
#
# ---------------------------------------------------------------------------
# WARUM ES DIESES SKRIPT GIBT
#
# I2C einschalten, das Kernelmodul i2c-dev eintragen und laden, den Benutzer
# loxberry in die Gruppen i2c und gpio aufnehmen - das braucht root. Bis 1.2.17
# versuchte postinstall.sh es; das laeuft als loxberry (plugininstall.pl, "sudo -n
# -u loxberry") und scheiterte deshalb IMMER, mit ehrlicher Meldung und dem
# Befehl fuer die Hand (Pruefbericht U2). Wer kein SSH kann, hatte damit einen
# SRF02, der nie misst - sofern das System I2C nicht schon mitbrachte.
#
# postroot.sh laeuft als ROOT, ohne sudo, bei Installation UND Upgrade, NACH
# postinstall und postupgrade (plugininstall.pl master bc59d76 :1388-1410; auf
# der Geraetekopie LoxBerry 4.0.0.15 vom 28.09.2026 :1357-1380 gleich).
#
# GRUNDSAETZE
#   * Nur was fehlt. Jede Zeile sagt, was vorher war und was nachher ist.
#     Ein zweiter Lauf aendert nichts (idempotent).
#   * Eine AUSDRUECKLICHE Abschaltung (dtparam=i2c_arm=off) wird nicht
#     ueberschrieben - das ist eine Entscheidung des Anwenders.
#   * /etc/modules wird NIE angefasst (eigener Fund N5): bis 1.2.17 nahm
#     postinstall dort einen i2c-dev-Eintrag heraus (als loxberry scheiterte
#     das immer). Als root gelaenge es - und nach einer Deinstallation, die nur
#     die eigene Datei unter modules-load.d entfernt, waere i2c-dev auf einer
#     Anlage, deren I2C vorher ueber /etc/modules lief, beim naechsten Start weg.
#     Die eigene Datei entsteht deshalb nur, wenn i2c-dev NIRGENDS eingetragen ist.
#   * Rueckgabe IMMER 0: ein Fehler hier soll die Installation nicht als
#     fehlerhaft markieren; die Meldung sagt, was von Hand zu tun ist.
#   * Kein Dienst(neu)start: der Messdienst laeuft schon (postinstall/postupgrade
#     haben ihn gestartet) und sieht neue Gruppen erst nach einem NEUSTART des
#     Rechners - ein "Dienst neu starten" aus der Oberflaeche erbte die Gruppen
#     des Webservers und hilft nicht. Einen Neustartmerker setzt dieses Skript
#     nicht: das SDK kennt reboot_required() nur in Perl (der Installer ruft es
#     bei REBOOT=true in plugin.cfg); auf den meisten Anlagen ist I2C schon da
#     (am Geraet 25.10.2026 gemessen), ein fester Merker waere dort falsch. Die
#     Meldung unten sagt es, wenn wirklich etwas geaendert wurde.
#
# US_PRUEF_WURZEL setzt NUR der Pruefstand (eine Attrappen-Wurzel fuer
# /boot, /etc und /dev); auf dem Geraet ist sie leer.
# ---------------------------------------------------------------------------

PTEMPDIR=$1   # Zufallskennung, KEIN Pfad (unbenutzt)
PSHNAME=$2
PDIR=$3
PVERSION=$4
US_PDIR="${3:-ultraschall}"
case "$US_PDIR" in
    ''|*/*|*..*) echo "<WARNING> Ordnername '$US_PDIR' unbrauchbar - nichts getan."; exit 0 ;;
esac
R="${US_PRUEF_WURZEL:-}"
PATH="$PATH:/usr/local/sbin:/usr/sbin:/sbin:/usr/bin:/bin"
export PATH
NEUSTART=0
GRUPPE=0

# 1. I2C in config.txt (Bookworm/Trixie: /boot/firmware/config.txt, aelter /boot/config.txt)
BOOTCFG=""
for us_k in "$R/boot/firmware/config.txt" "$R/boot/config.txt"; do
    if [ -f "$us_k" ]; then BOOTCFG="$us_k"; break; fi
done
BOOTNAME="${BOOTCFG#"$R"}"
if [ -z "$BOOTCFG" ]; then
    echo "<INFO> Keine config.txt gefunden - kein Raspberry Pi? I2C bitte selbst einrichten."
elif grep -qE '^[[:space:]]*dtparam=i2c_arm=on' "$BOOTCFG"; then
    echo "<OK> I2C ist in $BOOTNAME bereits eingeschaltet."
elif grep -qE '^[[:space:]]*dtparam=i2c_arm=off' "$BOOTCFG"; then
    echo "<WARNING> I2C ist in $BOOTNAME ausdruecklich ausgeschaltet - nicht geaendert."
    echo "<WARNING> Fuer den SRF02 von Hand: sudo raspi-config nonint do_i2c 0"
else
    # Steht vor dem Dateiende ein Abschnittskopf, der nicht [all] ist (z. B. [pi5],
    # [cm4]), gaelte eine angehaengte Zeile nur fuer dieses Modell - dann erst [all].
    us_letzter=$(grep -E '^[[:space:]]*\[[^]]*\]' "$BOOTCFG" | tail -n 1 | tr -d '[:space:]')
    us_sich="$BOOTCFG.ultraschall-$(date +%Y%m%d%H%M%S 2>/dev/null)"
    us_neu="$BOOTCFG.ultraschall-neu"
    if cp -p "$BOOTCFG" "$us_sich" 2>/dev/null && cp -p "$BOOTCFG" "$us_neu" 2>/dev/null \
       && {
            [ -n "$(tail -c 1 "$us_neu")" ] && echo
            [ -n "$us_letzter" ] && [ "$us_letzter" != "[all]" ] && echo "[all]"
            echo "dtparam=i2c_arm=on"
          } >> "$us_neu" 2>/dev/null \
       && head -c "$(wc -c < "$BOOTCFG")" "$us_neu" | cmp -s - "$BOOTCFG" \
       && mv -f "$us_neu" "$BOOTCFG" 2>/dev/null \
       && grep -qE '^[[:space:]]*dtparam=i2c_arm=on' "$BOOTCFG"; then
        echo "<WARNING> I2C in $BOOTNAME eingeschaltet (vorher: keine Zeile; Sicherung ${us_sich#"$R"})."
        echo "<WARNING> WIRKSAM ERST NACH EINEM NEUSTART des LoxBerry."
        NEUSTART=1
    else
        rm -f "$us_neu" 2>/dev/null
        echo "<WARNING> $BOOTNAME ist nicht schreibbar - I2C NICHT eingeschaltet."
        echo "<WARNING> Von Hand: sudo raspi-config nonint do_i2c 0"
    fi
fi

# 2. Kernelmodul i2c-dev beim Start
ETC="$R/etc"
us_wo=""
for us_f in "$ETC/modules" "$ETC"/modules-load.d/*.conf; do
    [ -f "$us_f" ] || continue
    if grep -qE '^[[:space:]]*i2c-dev([[:space:]]|$)' "$us_f"; then
        us_wo="$us_wo ${us_f#"$R"}"
    fi
done
if [ -n "$us_wo" ]; then
    echo "<OK> i2c-dev wird beim Start geladen (eingetragen in:$us_wo)."
elif [ -d "$ETC/modules-load.d" ]; then
    us_ziel="$ETC/modules-load.d/ultraschall.conf"
    if printf '# LoxBerry-Plugin Ultraschall Entfernung\n# Wird beim Deinstallieren wieder entfernt.\ni2c-dev\n' \
           > "$us_ziel.neu" 2>/dev/null \
       && chmod 0644 "$us_ziel.neu" 2>/dev/null \
       && mv -f "$us_ziel.neu" "$us_ziel" 2>/dev/null \
       && grep -qx 'i2c-dev' "$us_ziel"; then
        echo "<OK> i2c-dev war nirgends eingetragen - ${us_ziel#"$R"} angelegt."
    else
        rm -f "$us_ziel.neu" 2>/dev/null
        echo "<WARNING> ${us_ziel#"$R"} liess sich nicht anlegen. Von Hand: echo i2c-dev | sudo tee /etc/modules-load.d/ultraschall.conf"
    fi
else
    echo "<INFO> Kein /etc/modules-load.d - i2c-dev nicht eingetragen (die Datei /etc/modules fasst dieses Plugin nicht an)."
fi

# 3. Modul jetzt laden - nur, wenn kein /dev/i2c-* da ist
us_dev=$(ls "$R"/dev/i2c-* 2>/dev/null | head -n 1)
if [ -n "$us_dev" ]; then
    echo "<OK> ${us_dev#"$R"} vorhanden."
elif modprobe i2c-dev >/dev/null 2>&1; then
    us_dev=$(ls "$R"/dev/i2c-* 2>/dev/null | head -n 1)
    if [ -n "$us_dev" ]; then
        echo "<OK> Modul i2c-dev geladen, ${us_dev#"$R"} vorhanden."
    else
        echo "<INFO> Modul i2c-dev geladen - /dev/i2c-1 entsteht erst nach dem Neustart, wenn I2C eben erst eingeschaltet wurde."
    fi
else
    echo "<INFO> Modul i2c-dev liess sich nicht laden (kein Raspberry Pi?) - es laedt beim naechsten Start ueber modules-load.d."
fi

# 4. Gruppen i2c (SRF02) und gpio (HC-SR04) fuer den Dienstbenutzer loxberry
if ! id loxberry >/dev/null 2>&1; then
    echo "<INFO> Den Benutzer loxberry gibt es hier nicht - Gruppen nicht geprueft."
else
    for us_g in i2c gpio; do
        if ! getent group "$us_g" >/dev/null 2>&1; then
            echo "<INFO> Gruppe $us_g gibt es auf diesem System nicht (kein Raspberry Pi?)."
        elif id -nG loxberry 2>/dev/null | tr ' ' '\n' | grep -qx "$us_g"; then
            echo "<OK> loxberry ist bereits in der Gruppe $us_g."
        elif usermod -a -G "$us_g" loxberry 2>/dev/null \
             && id -nG loxberry 2>/dev/null | tr ' ' '\n' | grep -qx "$us_g"; then
            echo "<OK> loxberry in die Gruppe $us_g aufgenommen (vorher: nicht Mitglied)."
            GRUPPE=1
        else
            echo "<WARNING> loxberry liess sich nicht in die Gruppe $us_g aufnehmen. Von Hand: sudo usermod -a -G $us_g loxberry"
        fi
    done
fi

# 5. Ehrlich sagen, was danach noetig ist
if [ "$NEUSTART" = 1 ] || [ "$GRUPPE" = 1 ]; then
    echo "<WARNING> Die Aenderungen werden erst nach einem Neustart des LoxBerry wirksam. Der"
    echo "<WARNING> Messdienst laeuft schon und sieht neue Gruppen erst nach dem Neustart."
fi
exit 0
