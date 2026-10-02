<?php
/**
 * Ultraschall Entfernung - Aktionen des Reiters Test
 *
 * Jede Funktion liefert array(Ueberschrift, Text). Der Text wird von der
 * Oberflaeche maskiert ausgegeben, hier also bewusst als Klartext erzeugt.
 */

/* Die Bibliothek liegt seit 1.2.0 in webfrontend/html/ - neben dem
 * Endpunkt, der sie braucht. Installiert liegen die beiden Baeume GETRENNT:
 *
 *     <home>/webfrontend/htmlauth/plugins/<ordner>/index.php
 *     <home>/webfrontend/html/plugins/<ordner>/us_lib.php
 *
 * Ein '../html/us_lib.php' von hier aus trifft deshalb nur im entpackten
 * Archiv; installiert zeigt es auf htmlauth/plugins/html/, das es nicht gibt,
 * und die Seite endet mit einem fatalen Fehler. Diese Klasse hat in diesem
 * Haus schon fuenf Linien erwischt. Die Kandidatenliste deckt beide Lagen ab
 * und kommt ohne LBPHTMLDIR aus - das gibt es erst, NACHDEM
 * loxberry_system.php geladen ist, und das ist hier noch nicht der Fall. */
$us_lib_gefunden = false;
foreach (array(
    dirname(dirname(__DIR__)) . '/html/plugins/' . basename(__DIR__) . '/us_lib.php',
    dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . basename(__DIR__) . '/us_lib.php',
    dirname(__DIR__) . '/html/us_lib.php',
) as $us_kandidat) {
    if (is_file($us_kandidat)) {
        require_once $us_kandidat;
        $us_lib_gefunden = true;
        break;
    }
}
if (!$us_lib_gefunden) {
    echo '<p><b>Fehler:</b> us_lib.php nicht gefunden. Das Plugin ist '
       . 'unvollstaendig installiert.</p>';
    exit;
}

function us_sh($cmd)
{
    $out = array();
    @exec($cmd . ' 2>&1', $out);
    return implode("\n", $out);
}

/** Eine Zeile "Bezeichnung   Wert" - die Bezeichnung aus der Sprachdatei. */
function us_tz($schluessel, $wert)
{
    return str_pad(us_t($schluessel), 17) . $wert . "\n";
}

/** So lange darf eine Messung aus dem Webfrontend hoechstens dauern. */
define('US_MESSEN_GRENZE', 12);

/**
 * Einen Messwert fuer den Reiter Test besorgen.
 *
 * ZWEI WEGE, UND DER ERSTE IST WICHTIG: laeuft der Dienst, wird NICHT selbst
 * gemessen, sondern der zuletzt vom Dienst geschriebene Stand gelesen - zwei
 * Prozesse am selben Sensor vertragen sich nicht (I2C: ein Wert, der zu
 * keiner Anfrage gehoert; GPIO: der zweite Zugriff scheitert). Die
 * Zeitgrenze fuer den zweiten Weg ist zwoelf Sekunden; ein Webserver bricht
 * nach etwa 30 s ab.
 *
 * Rueckgabe zusaetzlich: 'quelle' = 'dienst' oder 'direkt', und bei 'dienst'
 * das Alter in Sekunden.
 *
 * Alle Texte kommen seit dem Durchgang 02.10.2026 aus den Sprachdateien
 * (O10): bis 1.2.10 waren saemtliche Ausgaben dieses Reiters deutsch, auch
 * in der englischen Oberflaeche, und mit Umschriften ("laeuft").
 */
function us_einmal_messen()
{
    $p = us_paths();

    $pid = us_dienst_pid();
    if ($pid > 0) {
        $s = us_status();
        if (is_array($s)) {
            $s['quelle'] = 'dienst';
            $s['alter'] = isset($s['zeit']) ? max(0, time() - (int) $s['zeit']) : null;
            return $s;
        }
        return array(
            'entfernung' => null, 'roh' => array(), 'verworfen' => array(),
            'quelle' => 'dienst', 'alter' => null,
            'fehler' => sprintf(us_t('TEST.DIENST_OHNE_STAND'), $pid),
        );
    }

    $skript = $p['bindir'] . '/us_messen.py';
    if (!is_file($skript)) {
        return array('fehler' => sprintf(us_t('TEST.MESSEN_FEHLT'), $skript));
    }
    $out = array();
    @exec('timeout ' . US_MESSEN_GRENZE . ' python3 ' . escapeshellarg($skript) . ' 2>&1', $out, $rc);
    $roh = trim(implode("\n", $out));
    $j = @json_decode($roh, true);
    if (!is_array($j)) {
        // 124 ist der Rueckgabewert, mit dem timeout einen Abbruch meldet.
        if ((int) $rc === 124) {
            return array('fehler' => sprintf(us_t('TEST.ABGEBROCHEN'), US_MESSEN_GRENZE));
        }
        return array('fehler' => us_t('TEST.KEINE_ANTWORT') . "\n\n" . substr($roh, 0, 800));
    }
    $j['quelle'] = 'direkt';
    return $j;
}

/** Einzelwerte und Verworfene als Text - fuer "Letzter Messwert" und "Jetzt messen". */
function us_test_werte($j)
{
    $t = us_tz('TEST.L_EINZEL', empty($j['roh']) ? '-' : implode('  ', (array) $j['roh']));
    if (!empty($j['verworfen'])) {
        $liste = array();
        foreach ((array) $j['verworfen'] as $v) {
            $liste[] = $v === null ? us_t('TEST.KEIN_ECHO') : $v;
        }
        $t .= us_tz('TEST.L_VERWORFEN', implode('  ', $liste));
    }
    return $t;
}

function us_test_ausfuehren($was)
{
    $p = us_paths();
    list($cfg, $alt) = us_config_read();
    $sensoren = us_sensoren();
    $sensor = us_cfg($cfg, 'sensor', 'srf02');
    $ja = us_t('TEST.JA');
    $nein = us_t('TEST.NEIN');
    $ein = us_t('TEST.EIN');
    $aus = us_t('TEST.AUS');

    switch ($was) {

        case 'status':
            $pid = us_dienst_pid();
            $herz = us_herzschlag_alter();
            $mess = us_status_alter_roh();
            $grenze = us_ok_grenze($cfg);
            $s = us_status();
            $t  = us_tz('TEST.L_DIENST', $pid ? sprintf(us_t('TEST.LAEUFT_PID'), $pid) : us_t('TEST.LAEUFT_NICHT'));
            $t .= us_tz('TEST.L_EIN', us_cfg($cfg, 'enabled', '0') === '1' ? $ja : $nein);
            $t .= us_tz('TEST.L_ZUSTAND', $herz === null ? us_t('TEST.DATEI_FEHLT')
                : sprintf(us_t('TEST.DATEI_ALTER'), max(0, $herz),
                          $mess === null || !is_array($s) || (int) $s['zeit'] <= 0
                              ? us_t('TEST.NIE') : sprintf(us_t('TEST.SEKUNDEN'), max(0, $mess)),
                          $grenze));
            /* Die Upgrade-Marke gehoert in die Selbstpruefung: solange sie
             * gilt, startet KEIN Startweg den Dienst. */
            $marke = us_marke_alter();
            $t .= us_tz('TEST.L_AKT', $marke < 0 ? us_t('TEST.MARKE_KEINE')
                : sprintf(us_t('TEST.MARKE_LIEGT'), $marke));
            $t .= us_tz('TEST.L_SENSOR', isset($sensoren[$sensor]) ? $sensoren[$sensor] : $sensor);
            if ($sensor === 'hcsr04') {
                $t .= us_tz('TEST.L_GPIO', sprintf(us_t('TEST.GPIO_TEXT'),
                    us_cfg($cfg, 'gpio_trigger', '23'), us_cfg($cfg, 'gpio_echo', '24')));
            } else {
                $t .= us_tz('TEST.L_I2C', sprintf(us_t('TEST.I2C_TEXT'),
                    us_cfg($cfg, 'i2c_bus', '1'), us_cfg($cfg, 'i2c_adresse', '0x70')));
            }
            $t .= us_tz('TEST.L_MESSUNG', sprintf(us_t('TEST.MESSUNG_TEXT'), us_cfg($cfg, 'messungen', '5'),
                us_cfg($cfg, 'min_cm', '3'), us_cfg($cfg, 'max_cm', '400')));
            $t .= us_tz('TEST.L_TAKT', sprintf(us_t('TEST.TAKT_TEXT'), us_cfg($cfg, 'intervall', '60')));
            $t .= us_tz('TEST.L_MQTT', us_cfg($cfg, 'mqtt', '1') === '1' ? $ein : $aus);
            $t .= us_tz('TEST.L_MQTT_ZUSTAND', us_test_mqtt_zustand($cfg, $s, $pid));
            $t .= us_tz('TEST.L_UDP', us_cfg($cfg, 'udp', '0') === '1' ? $ein : $aus) . "\n";
            if ($alt) {
                $t .= us_t('TEST.ALTFORMAT') . "\n\n";
            }
            if (us_cfg($cfg, 'enabled', '0') !== '1') {
                $t .= us_t('TEST.AUSGESCHALTET') . "\n\n";
            } elseif (!$pid) {
                $t .= us_t('TEST.NICHT_GESTARTET') . "\n\n";
            } elseif ($herz !== null && $herz > $grenze) {
                $t .= sprintf(us_t('TEST.STEHT'), $herz, $grenze) . "\n\n";
            } elseif (is_array($s) && array_key_exists('entfernung', $s) && $s['entfernung'] === null
                      && !empty($s['fehler'])) {
                // C1: "laeuft, misst aber nicht" - das zeigt jetzt der Zustand.
                $t .= sprintf(us_t('TEST.MISST_NICHT'), (string) $s['fehler']) . "\n\n";
            }
            $t .= us_sh('ps -o pid,etime,rss,args -C python3 2>/dev/null | grep -iE "ultraschall|PID"');
            return array(us_t('TEXT.ZUSTAND_DES_DIENSTES'), trim($t) !== '' ? $t : us_t('TEST.KEINE_ANGABEN'));

        case 'messwert':
            $s = us_status();
            if (!$s) {
                return array(us_t('TEXT.LETZTER_MESSWERT'), us_t('TEST.KEINE_ZUSTANDSDATEI'));
            }
            $t = sprintf(us_t('TEST.STAND_VOR'), us_status_alter()) . "\n\n";
            if (!isset($s['entfernung']) || $s['entfernung'] === null) {
                $t .= us_tz('TEST.L_ENTFERNUNG', us_t('TEST.KEINE_MESSUNG'));
                if (isset($s['entfernung_letzte']) && is_numeric($s['entfernung_letzte'])) {
                    $t .= us_tz('TEST.L_LETZTE', sprintf('%.1f cm', $s['entfernung_letzte']));
                }
            } else {
                $t .= us_tz('TEST.L_ENTFERNUNG', sprintf('%.1f cm', $s['entfernung']));
            }
            if (isset($s['prozent']) && $s['prozent'] !== null) {
                $t .= us_tz('TEST.L_FUELL', sprintf('%.1f %%', $s['prozent']));
            } else {
                $t .= us_tz('TEST.L_FUELL', us_t('TEST.FUELL_NICHT'));
            }
            if (isset($s['liter']) && $s['liter'] !== null) {
                $t .= us_tz('TEST.L_INHALT', sprintf('%.1f l', $s['liter']));
            } else {
                $t .= us_tz('TEST.L_INHALT', us_t('TEST.INHALT_NICHT'));
            }
            $t .= "\n" . us_test_werte($s);
            if (!empty($s['fehler'])) {
                $t .= "\n" . us_tz('TEST.L_FEHLER', (string) $s['fehler']);
            }
            return array(us_t('TEXT.LETZTER_MESSWERT'), $t);

        case 'messen':
            $j = us_einmal_messen();
            if (isset($j['fehler']) && !array_key_exists('entfernung', $j)) {
                return array(us_t('TEXT.JETZT_MESSEN'), $j['fehler']
                    . (isset($j['hinweis']) ? "\n\n" . $j['hinweis'] : ''));
            }
            $t = us_tz('TEST.L_SENSOR', isset($sensoren[$sensor]) ? $sensoren[$sensor] : $sensor);
            if (isset($j['quelle']) && $j['quelle'] === 'dienst') {
                $t .= us_tz('TEST.L_HERKUNFT', us_t('TEST.HERKUNFT_DIENST')
                    . (isset($j['alter']) && $j['alter'] !== null
                        ? sprintf(us_t('TEST.HERKUNFT_ALT'), (int) $j['alter']) : ''));
                $t .= us_t('TEST.HERKUNFT_ERKL') . "\n";
            }
            $t .= "\n" . us_test_werte($j);
            // Was der Messlauf ueber die KONFIGURATION zu sagen hat (seit 1.2.2).
            if (isset($j['hinweise']) && is_array($j['hinweise']) && count($j['hinweise']) > 0) {
                $t .= us_t('TEST.ZUR_KONFIG') . "\n";
                foreach ($j['hinweise'] as $us_hw) {
                    $t .= '- ' . (string) $us_hw . "\n";
                }
            }
            $t .= "\n";
            if (!isset($j['entfernung']) || $j['entfernung'] === null) {
                $t .= us_tz('TEST.L_ERGEBNIS', us_t('TEST.KEINE_MESSUNG'));
                if (!empty($j['fehler'])) {
                    $t .= "\n" . $j['fehler'] . "\n";
                }
                $t .= "\n" . us_t('TEST.PRUEFEN_KOPF') . "\n" . us_t('TEST.PRUEFEN_1') . "\n"
                    . us_t('TEST.PRUEFEN_2') . "\n" . us_t('TEST.PRUEFEN_3') . "\n";
            } else {
                $t .= us_tz('TEST.L_ERGEBNIS', sprintf(us_t('TEST.ERGEBNIS_WERT'),
                    $j['entfernung'], count((array) $j['roh'])));
                if (!empty($j['fehler'])) {
                    // C2: ein gueltiger Durchgang mit Sensorfehlern sagt es.
                    $t .= us_tz('TEST.L_FEHLER', (string) $j['fehler']);
                }
                list($proz, $liter) = us_fuellstand($cfg, $j['entfernung']);
                if ($proz !== null) {
                    $t .= us_tz('TEST.L_FUELL', sprintf('%.1f %%', $proz));
                    if ($liter !== null) {
                        $t .= us_tz('TEST.L_INHALT', sprintf('%.1f l', $liter));
                    }
                } else {
                    /* C4: bei vertauschten oder gleichen Grenzen gibt es
                     * keinen Wert UND einen Hinweis - bis 1.2.10 lief der
                     * Fuellstand hier rueckwaerts. */
                    $us_fh = us_fuellstand_hinweis($cfg);
                    $t .= "\n" . ($us_fh !== '' ? $us_fh : us_t('TEST.FUELL_NICHT_LANG')) . "\n";
                }
            }
            return array(us_t('TEXT.JETZT_MESSEN'), $t);

        case 'sensor':
            $t = '';
            if ($sensor === 'hcsr04') {
                $t .= sprintf(us_t('TEST.SENSOR_HC'), us_cfg($cfg, 'gpio_trigger', '23'),
                              us_cfg($cfg, 'gpio_echo', '24')) . "\n\n";
                $us_gz = trim(us_sh('python3 -c "import gpiozero; print(gpiozero.__version__)"'));
                $us_lg = trim(us_sh('python3 -c "import lgpio" >/dev/null 2>&1 && echo ok'));
                $t .= us_tz('TEST.L_GPIOZERO', $us_gz !== '' ? $us_gz : us_t('TEST.NICHT_VORHANDEN'));
                $t .= us_tz('TEST.L_LGPIO', $us_lg === 'ok' ? us_t('TEST.VORHANDEN') : us_t('TEST.NICHT_VORHANDEN')) . "\n";
                $t .= us_t('TEST.GRUPPEN') . "\n" . us_sh('id loxberry') . "\n\n";
                $t .= us_t('TEST.GPIO_GERAETE') . "\n" . (us_sh('ls -l /dev/gpiochip* 2>&1') ?: us_t('TEST.KEINE')) . "\n\n";
                $t .= us_t('TEST.SPANNUNG') . "\n";
            } else {
                $bus = us_cfg($cfg, 'i2c_bus', '1');
                $t .= sprintf(us_t('TEST.SENSOR_SRF'), $bus, us_cfg($cfg, 'i2c_adresse', '0x70')) . "\n\n";
                $t .= sprintf(us_t('TEST.GERAETEDATEI'), $bus) . ' '
                    . (file_exists("/dev/i2c-$bus") ? us_t('TEST.VORHANDEN') : us_t('TEST.GD_FEHLT')) . "\n\n";
                $t .= us_t('TEST.GRUPPEN') . "\n" . us_sh('id loxberry') . "\n\n";
                $t .= us_t('TEST.BELEGT') . "\n";
                $scan = us_sh('i2cdetect -y ' . (int) $bus);
                $t .= ($scan !== '' ? $scan : us_t('TEST.I2CDETECT_FEHLT')) . "\n\n";
                $t .= us_t('TEST.SRF_HINWEIS') . "\n";
            }
            return array(us_t('TEXT.SENSOR_PRUEFEN'), $t);

        case 'konfig':
            $t = us_tz('TEST.L_DATEI', $p['config']) . "\n";
            if (is_file($p['config'])) {
                $t .= (string) @file_get_contents($p['config']);
            } else {
                $t .= us_t('TEST.KONFIG_FEHLT') . "\n\n";
                foreach (us_defaults() as $k => $v) {
                    $t .= sprintf("  %-16s %s\n", $k, $v === '' ? us_t('TEST.LEER_WERT') : $v);
                }
            }
            return array(us_t('TEXT.KONFIGURATION_ANZEIGEN'), $t);

        case 'umgebung':
            $t  = us_tz('TEST.L_PYTHON', trim(us_sh('python3 --version')));
            $t .= us_tz('TEST.L_SYSTEM', trim(us_sh('. /etc/os-release 2>/dev/null && echo "$PRETTY_NAME"')));
            $t .= us_tz('TEST.L_MODELL', trim((string) @file_get_contents('/proc/device-tree/model')));
            $t .= us_tz('TEST.L_LOXBERRY', $p['home'] !== '' ? $p['home'] : us_t('TEST.NICHT_GEFUNDEN'));
            $t .= us_tz('TEST.L_PLUGIN', $p['plugin']);
            $t .= us_tz('TEST.L_PROGRAMME', $p['bindir']);
            $t .= us_tz('TEST.L_PROTOKOLLE', $p['logdir']) . "\n";
            $t .= us_t('TEST.MODULE') . "\n";
            foreach (array('smbus', 'smbus2', 'gpiozero', 'lgpio', 'paho.mqtt.client') as $m) {
                $da = trim(us_sh('python3 -c "import ' . $m . '" >/dev/null 2>&1 && echo 1 || echo 0'));
                $t .= sprintf("  %-18s %s\n", $m, $da === '1' ? $ja : $nein);
            }
            $t .= "\n" . us_t('TEST.HILFS') . "\n";
            // pgrep und pkill werden seit 1.1.1 nicht mehr gebraucht (PID-Datei).
            foreach (array('i2cdetect') as $c) {
                $t .= sprintf("  %-18s %s\n", $c, trim(us_sh('command -v ' . $c)) ?: us_t('TEST.FEHLT'));
            }
            $t .= "\n" . us_t('TEST.I2C_MODULE') . "\n" . (us_sh('lsmod | grep -i i2c') ?: us_t('TEST.KEINE'));
            return array(us_t('TEXT.UMGEBUNG_UND_MODULE'), $t);

        case 'mqttinfo':
            $broker = us_mqtt_broker();
            $t = us_tz('TEST.L_BROKER', $broker !== '' ? $broker : us_t('TEST.BROKER_FEHLT'));
            $t .= us_tz('TEST.L_PRAEFIX', us_cfg($cfg, 'themenpraefix', 'ultraschall'));
            /* Die FASSUNG des Gateways entscheidet, was der Anwender tun muss.
             * Ist sie nicht lesbar, werden BEIDE Faelle genannt. */
            $fassung = us_gateway_fassung();
            $t .= us_tz('TEST.L_GW_FASSUNG', $fassung > 0 ? 'V' . $fassung : us_t('TEST.GW_UNBEKANNT'));
            $t .= us_tz('TEST.L_MQTT_ZUSTAND', us_test_mqtt_zustand($cfg, us_status(), us_dienst_pid())) . "\n";
            if ($fassung === 1) {
                $t .= us_t('TEST.GW_V1') . "\n\n";
            } elseif ($fassung >= 2) {
                $t .= us_t('TEST.GW_V2') . "\n\n";
            } else {
                $t .= us_t('TEST.GW_BEIDE') . "\n\n";
            }
            if ($broker === '') {
                // Das Gateway ist KEIN Plugin (seit LoxBerry 3 Teil des Systems).
                $t .= us_t('TEST.GW_KEIN_PLUGIN') . "\n\n";
            }
            $us_vm = array();
            $us_vf = us_paths()['praefixe'];
            if ($us_vf !== '' && is_file($us_vf)) {
                $us_vm = @json_decode((string) @file_get_contents($us_vf), true);
            }
            if (is_array($us_vm) && $us_vm) {
                // M3: was noch abzuraeumen ist, steht hier.
                $t .= sprintf(us_t('TEST.VORGEMERKT'),
                    implode(', ', array_filter($us_vm, 'is_string'))) . "\n\n";
            }
            /* Die Spalte "retained" kommt aus der Feldtabelle
             * (bin/us_vorgaben.json), aus der auch der Dienst liest. */
            $t .= us_t('TEST.THEMEN') . "\n\n";
            $praefix = us_cfg($cfg, 'themenpraefix', 'ultraschall');
            foreach (us_status_themen() as $k => $info) {
                $t .= sprintf("  %-28s %-12s %s\n", $praefix . '/' . $k,
                    !empty($info[2]) ? us_t('TEST.RETAINED') : us_t('TEST.FLUECHTIG'),
                    strip_tags(html_entity_decode($info[0], ENT_QUOTES, 'UTF-8')));
            }
            $t .= "\n" . us_t('TEST.RETAIN_TEXT') . "\n";
            return array(us_t('TEXT.MQTT_GATEWAY'), $t);

        case 'udpinfo':
            $ms = us_miniservers();
            $nr = us_cfg($cfg, 'udp_miniserver', '1');
            $t = us_tz('TEST.L_UDP_VERSAND', us_cfg($cfg, 'udp', '0') === '1' ? $ein : $aus);
            $ziel = sprintf(us_t('TEST.ZIEL_MS'), $nr);
            if (isset($ms[$nr])) {
                $ziel .= ' (' . $ms[$nr]['name'] . ', ' . $ms[$nr]['ip'] . ')';
            } else {
                $ziel .= us_t('TEST.ZIEL_FEHLT');
            }
            $t .= us_tz('TEST.L_ZIEL', $ziel);
            $t .= us_tz('TEST.L_PORT', us_roh($cfg, 'udp_port') !== '' ? us_roh($cfg, 'udp_port') : us_t('TEST.PORT_FEHLT')) . "\n";
            $t .= us_t('TEST.BEKANNTE_MS') . "\n";
            foreach ($ms as $k => $m) {
                $t .= sprintf("  %-3s %-24s %s\n", $k, $m['name'], $m['ip']);
            }
            if (!$ms) {
                $t .= '  ' . us_t('TEST.KEINE') . "\n";
            }
            $t .= "\n" . us_t('TEST.UDP_ERKL') . "\n\n" . us_t('TEST.UDP_BESSER') . "\n";
            return array(us_t('TEXT.UDP_AN_DEN_MINISERVER'), $t);

        case 'udptest':
            if (us_cfg($cfg, 'udp', '0') !== '1') {
                return array(us_t('TEXT.UDP_TESTPAKET_SENDEN'), us_t('TEST.UDP_AUS'));
            }
            $s = us_status();
            $hat = is_array($s) && isset($s['entfernung']) && $s['entfernung'] !== null;
            $wert = $hat ? (int) round($s['entfernung']) : 42;
            $ms = us_miniservers();
            $nr = us_cfg($cfg, 'udp_miniserver', '1');
            $port = us_roh($cfg, 'udp_port');
            if (!isset($ms[$nr]) || $ms[$nr]['ip'] === '') {
                return array(us_t('TEXT.UDP_TESTPAKET_SENDEN'), sprintf(us_t('TEST.UDP_MS_FEHLT'), $nr));
            }
            if (preg_match('/^[0-9]{1,5}$/', trim($port)) !== 1) {
                return array(us_t('TEXT.UDP_TESTPAKET_SENDEN'), us_t('TEST.UDP_PORT_UNG'));
            }
            $sock = @fsockopen('udp://' . $ms[$nr]['ip'], (int) $port, $errno, $errstr, 3);
            if (!$sock) {
                return array(us_t('TEXT.UDP_TESTPAKET_SENDEN'), sprintf(us_t('TEST.UDP_KEINE_VERB'), $errstr, $errno));
            }
            @fwrite($sock, (string) $wert);
            @fclose($sock);
            return array(us_t('TEXT.UDP_TESTPAKET_SENDEN'),
                us_tz('TEST.L_GESENDET', $wert) . us_tz('TEST.L_AN', $ms[$nr]['ip'] . ':' . $port) . "\n"
                . us_t('TEST.UDP_UNBESTAETIGT') . "\n"
                . us_t($hat ? 'TEST.UDP_LETZTER' : 'TEST.UDP_PLATZHALTER') . "\n");

        case 'restart':
            $aus_d = us_dienst('restart');
            $pid = us_dienst_pid();
            return array(us_t('TEXT.DIENST_NEU_STARTEN'),
                ($pid ? sprintf(us_t('TEST.NEU_LAEUFT'), $pid) : us_t('TEST.NEU_LAEUFT_NICHT'))
                . ($aus_d !== '' ? "\n\n" . $aus_d : ''));

        case 'stop':
            /* EHRLICH, WAS GESCHAH (Durchgang 02.10.2026, O7). Bis 1.2.10 stand
             * hier "Der Dienst wurde angehalten." auch dann, wenn keiner lief
             * oder us_dienst() mangels Wurzel gar nichts tat (gemessen,
             * Oberflaechen-Pruefer 9). Gefragt wird VORHER, ob einer laeuft. */
            if ($p['home'] === '') {
                return array(us_t('TEXT.DIENST_ANHALTEN'), us_t('TEST.STOP_KEINE_WURZEL'));
            }
            $vorher = us_dienst_pids();
            $aus_d = us_dienst('stop');
            $pid = us_dienst_pid();
            if (!$vorher) {
                // Die Zeile "lief nicht" aus us_dienst() sagte dasselbe noch einmal.
                return array(us_t('TEXT.DIENST_ANHALTEN'), us_t('TEST.STOP_LIEF_NICHT'));
            } elseif ($pid) {
                $text = sprintf(us_t('TEST.STOP_NOCH'), $pid);
            } else {
                $text = us_t('TEST.STOP_OK');
            }
            return array(us_t('TEXT.DIENST_ANHALTEN'), $text . ($aus_d !== '' ? "\n\n" . $aus_d : ''));
    }

    return array(us_t('TEST.UNBEKANNT_T'), sprintf(us_t('TEST.UNBEKANNT'), $was));
}

/** Der MQTT-Zustand des Dienstes in einem Satz (M2) - aus der Zustandsdatei. */
function us_test_mqtt_zustand($cfg, $s, $pid)
{
    if (us_cfg($cfg, 'mqtt', '1') !== '1') {
        return us_t('TEST.MQTT_Z_AUS');
    }
    if ($pid <= 0 || !is_array($s) || !isset($s['mqtt'])) {
        return us_t('TEST.MQTT_Z_UNBEKANNT');
    }
    if ($s['mqtt'] === 'verbunden') {
        return us_t('TEST.MQTT_Z_VERBUNDEN');
    }
    if ($s['mqtt'] === 'abgewiesen') {
        return sprintf(us_t('TEST.MQTT_Z_ABGEWIESEN'),
                       isset($s['mqtt_grund']) ? (string) $s['mqtt_grund'] : '');
    }
    return us_t('TEST.MQTT_Z_NICHT');
}
