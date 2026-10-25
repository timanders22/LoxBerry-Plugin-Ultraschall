# Ultraschall Entfernung - gemeinsamer Startblock (seit 1.2.18, U1)
#
# Geladen mit "." von postinstall.sh UND postupgrade.sh. Bis 1.2.17 stand dieser
# Block nur in postinstall.sh. Fehlte die Zweitschrift, spielte erst postupgrade.sh
# die Sicherung mit enabled=1 zurueck - und startete nicht; der Waechter holte den
# Dienst binnen fuenf Minuten nach (Pruefbericht U1, Probe u1). Jetzt fragt
# postupgrade.sh nach dem Zurueckspielen dasselbe wie postinstall.sh: laeuft einer,
# ist das Plugin eingeschaltet, dann starten.
#
# Eine Datei statt zwei Kopien: zwei Kopien derselben Logik laufen auseinander
# (Kopfkommentar von postupgrade.sh). Sie liegt im bin-Ordner, den der Installer
# vor postinstall kopiert (plugininstall.pl :958).
#
# Diese Datei definiert nur Funktionen und beendet nie das aufrufende Skript.
# Aufruf: us_dienst_starten <LoxBerry-Wurzel> <Ordner> <Protokollordner>

# DIENST ARGUMENTWEISE ERKENNEN (seit 1.2.8) - dieselbe Bauart wie
# daemon/daemon: GENAU zwei Argumente, argv[0] ein Python-Interpreter,
# argv[1] Zeichen fuer Zeichen US_SKRIPT. Ohne Benutzerfilter: ein Dienst unter
# anderem Benutzer ist trotzdem ein laufender Dienst.
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
    [ "$us_a1" = "$US_SKRIPT" ] || return 1
    case "${us_a0##*/}" in
        python|python3|python3.*) return 0 ;;
    esac
    return 1
}
us_dienste() {
    for us_d in /proc/[0-9]*; do
        grep -qaF "$US_SKRIPT" "$us_d/cmdline" 2>/dev/null || continue
        us_ist_dienst "${us_d#/proc/}" && echo "${us_d#/proc/}"
    done
}

us_dienst_starten() {
    US_SKRIPT="$1/bin/plugins/$2/ultraschall.py"
    US_CFG="$1/config/plugins/$2/ultraschall.cfg"
    US_PLOG="$3"
    # EINE STARTSPERRE FUER ALLE STARTWEGE (Durchgang 02.10.2026, I3).
    # Waechter, daemon, die Hakenskripte und der Knopf der Oberflaeche sperren auf
    # dieselbe Datei (flock auf ultraschall.py, Deskriptor 9) und fragen erst
    # DANACH, ob schon einer laeuft. Der Dienst erbt die Sperre NICHT (9<&-).
    US_GESPERRT=1
    US_GEOEFFNET=0
    if command -v flock >/dev/null 2>&1 && [ -r "$US_SKRIPT" ]; then
        exec 9<"$US_SKRIPT"
        US_GEOEFFNET=1
        flock -w 15 9 || US_GESPERRT=0
    fi
    US_LAEUFT=$(us_dienste)
    if [ "$US_GESPERRT" = "0" ]; then
        echo "<INFO> Ein anderer Startweg haelt gerade die Startsperre - der Waechter"
        echo "<INFO> (cron.05min) startet den Messdienst nach, falls noetig."
    elif [ -n "$US_LAEUFT" ]; then
        # KEIN ZWEITER DIENST (seit 1.2.8).
        echo "<OK> Der Messdienst laeuft bereits (PID $(echo $US_LAEUFT)) - kein zweiter Start."
    elif [ -r "$US_CFG" ] \
       && grep -qiE '^[[:space:]]*enabled[[:space:]]*=[[:space:]]*1[[:space:]]*$' "$US_CFG" \
       && [ -x "$US_SKRIPT" ]; then
        # OHNE "su" (seit 1.2.2): beide Hakenskripte laufen schon als loxberry
        # (plugininstall.pl, "sudo -n -u loxberry"). EIGENE STARTDATEI, nicht
        # das Protokoll (seit 1.2.6); ">" statt ">>". 9<&-: der Dienst erbt die
        # Startsperre nicht (I3).
        mkdir -p "$US_PLOG" 2>/dev/null
        nohup "$US_SKRIPT" > "$US_PLOG/ultraschall_start.log" 2>&1 9<&- &
        sleep 2
        # Die Wirkung pruefen, nicht den Rueckgabewert des Starts.
        if [ -n "$(us_dienste)" ]; then
            echo "<OK> Messdienst gestartet."
        else
            echo "<INFO> Messdienst noch nicht gestartet - der Waechter"
            echo "<INFO> (cron.05min) holt ihn binnen fuenf Minuten nach."
        fi
    else
        echo "<INFO> Das Plugin ist ausgeschaltet - der Messdienst wird nicht gestartet."
    fi
    # Die Startsperre freigeben - das aufrufende Skript laeuft danach weiter.
    [ "$US_GEOEFFNET" = "1" ] && exec 9<&-
    return 0
}
