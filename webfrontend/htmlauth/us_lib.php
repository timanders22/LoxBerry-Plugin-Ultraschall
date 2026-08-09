<?php
/**
 * Ultraschall Entfernung - gemeinsame Hilfsfunktionen
 *
 * Die Konfiguration liegt im selben Format, das bin/us_common.py liest und
 * schreibt. Beide Seiten muessen sich hier einig sein.
 *
 * Loest die Perl-CGI-Oberflaeche der Originalfassung ab (webfrontend/cgi/
 * index.cgi mit HTML::Template und zwei Sprachdateien). Alles auf Deutsch.
 *
 * Eigenes Praefix "us_", weil LBWeb::lbheader() SDK-Globale setzt und sonst
 * Namen kollidieren.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

if (!function_exists('us_e')) {
    function us_e($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

/** Basisverzeichnisse ermitteln - funktioniert installiert wie im Archiv. */
function us_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = getenv('LBHOMEDIR');
    if (!$home && is_dir('/opt/loxberry')) {
        $home = '/opt/loxberry';
    }
    /* LBPPLUGINDIR ist die Auskunft von LoxBerry selbst und hat Vorrang.
     *
     * Die frueheren Rueckfaelle trafen beide daneben: Installiert liegt diese
     * Datei unter webfrontend/htmlauth/plugins/<ordner>/, also ergab
     * basename(dirname(dirname(__DIR__))) den Wert "htmlauth" und
     * basename(dirname(__DIR__)) den Wert "plugins" - nie einen Plugin-Ordner.
     * Uebrig blieb immer der feste Name. Bei einer Zweitinstallation
     * (ultraschall_01) zeigte damit alles auf die erste.
     *
     * Jetzt wird der Ordner aus dem eigenen Ablageort genommen; der feste Name
     * greift nur, wo der ermittelte nachweislich keiner sein kann. */
    $dir = getenv('LBPPLUGINDIR');
    if (!$dir) {
        $dir = basename(__DIR__);
    }
    if ($dir === '' || $dir === '.' || $dir === '/' || $dir === 'htmlauth' || $dir === 'plugins') {
        $dir = 'ultraschall';
    }
    // Muss zu RAM_DIR, STATUS_FILE und PID_FILE in bin/us_common.py passen -
    // dort wird der Ordner seit 1.1.2 ebenfalls aus dem Plugin-Namen gebildet.
    // Wer hier den festen Namen stehen laesst, laesst die Oberflaeche an einer
    // anderen Stelle nachsehen, als der Dienst schreibt.
    $ramdir = (is_dir('/run/shm') ? '/run/shm/' : '/tmp/') . $dir;
    $status = $ramdir . '/status.json';
    $pid    = $ramdir . '/dienst.pid';
    if ($home) {
        $p = array(
            'home'   => $home,
            'plugin' => $dir,
            'config' => $home . '/config/plugins/' . $dir . '/ultraschall.cfg',
            'bindir' => $home . '/bin/plugins/' . $dir,
            'logdir' => $home . '/log/plugins/' . $dir,
            'status' => $status,
            'pid'    => $pid,
        );
    } else {
        $base = dirname(dirname(__DIR__));
        $p = array(
            'home'   => '',
            'plugin' => $dir,
            'config' => $base . '/config/ultraschall.cfg',
            'bindir' => $base . '/bin',
            'logdir' => sys_get_temp_dir(),
            'status' => $status,
            'pid'    => $pid,
        );
    }
    return $p;
}

/** Voreinstellungen. Muessen zu VORGABEN in us_common.py passen. */
function us_defaults()
{
    return array(
        'enabled'        => '0',
        'sensor'         => 'srf02',
        'i2c_bus'        => '1',
        'i2c_adresse'    => '0x70',
        'gpio_trigger'   => '23',
        'gpio_echo'      => '24',
        'messungen'      => '5',
        'messabstand'    => '0.2',
        'min_cm'         => '3',
        'max_cm'         => '400',
        'offset_cm'      => '0',
        'leer_cm'        => '',
        'voll_cm'        => '',
        'volumen_liter'  => '',
        'intervall'      => '60',
        'aktualisierung' => '300',
        'themenpraefix'  => 'ultraschall',
        'mqtt'           => '1',
        'udp'            => '0',
        'udp_miniserver' => '1',
        'udp_port'       => '',
    );
}

/**
 * Konfiguration lesen. Erkennt das alte Format des Originalplugins mit.
 * Rueckgabe: array($werte, $altesFormat)
 */
function us_config_read()
{
    $werte = us_defaults();
    $alt = false;
    $file = us_paths()['config'];
    if (!is_file($file)) {
        return array($werte, $alt);
    }
    foreach (preg_split('/\R/', (string) @file_get_contents($file)) as $zeile) {
        $t = trim($zeile);
        if ($t === '' || $t[0] === ';' || $t[0] === '#' || $t[0] === '[') {
            continue;
        }
        $pos = strpos($t, '=');
        if ($pos === false) {
            continue;
        }
        $schluessel = trim(substr($t, 0, $pos));
        $wert = trim(trim(substr($t, $pos + 1)), "\"'");
        $klein = strtolower(preg_replace('/^ultraschall\./i', '', $schluessel));

        // Altes Format: MINISERVER=MINISERVER1, UDPPORT=12345
        if ($klein === 'miniserver') {
            $alt = true;
            $nr = preg_replace('/\D/', '', $wert);
            $werte['udp_miniserver'] = $nr !== '' ? $nr : '1';
            $werte['udp'] = '1';
            continue;
        }
        if ($klein === 'udpport') {
            $alt = true;
            $werte['udp_port'] = $wert;
            continue;
        }
        if (array_key_exists($klein, $werte)) {
            $werte[$klein] = $wert;
        }
    }
    return array($werte, $alt);
}

/** Wert lesen, mit Vorgabe. Leere Werte sind hier zulaessig. */
function us_cfg($cfg, $key, $default = '')
{
    return isset($cfg[$key]) && $cfg[$key] !== '' ? $cfg[$key] : $default;
}

/** Rohwert lesen - leer bleibt leer (fuer die Kalibrierfelder). */
function us_roh($cfg, $key)
{
    return isset($cfg[$key]) ? (string) $cfg[$key] : '';
}

/** Konfiguration schreiben - Format wie us_common.py es erwartet. */
function us_config_write($werte)
{
    $file = us_paths()['config'];
    @mkdir(dirname($file), 0775, true);
    $txt = "; Ultraschall Entfernung\n; Geschrieben von der Plugin-Oberflaeche.\n\n[ultraschall]\n";
    foreach (us_defaults() as $k => $vorgabe) {
        $v = array_key_exists($k, $werte) ? $werte[$k] : $vorgabe;
        $v = str_replace(array("\r", "\n"), array('', ' '), (string) $v);
        $txt .= $k . '=' . trim($v) . "\n";
    }
    /* Erst daneben schreiben, dann umbenennen.
     *
     * Ein einfaches file_put_contents kuerzt die Datei und fuellt sie neu.
     * Der Python-Dienst liest dieselbe Datei und prueft sie im Sekundentakt
     * auf Aenderungen - trifft er das Fenster, liest er eine leere oder
     * halbe Konfiguration. rename() ist im selben Dateisystem unteilbar:
     * der Dienst sieht entweder die alte oder die neue Datei. Die
     * Gegenseite in us_common.konfiguration_schreiben() macht es genauso. */
    $tmp = $file . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $txt) === false) {
        return false;
    }
    @chmod($tmp, 0644);
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/** Zustandsdatei des Dienstes lesen. */
function us_status()
{
    $f = us_paths()['status'];
    if (!is_file($f)) {
        return null;
    }
    $j = @json_decode((string) @file_get_contents($f), true);
    return is_array($j) ? $j : null;
}

/** Wie alt ist die Zustandsdatei in Sekunden? -1 = keine. */
function us_status_alter()
{
    $s = us_status();
    if (!$s || !isset($s['zeit'])) {
        return -1;
    }
    return max(0, time() - (int) $s['zeit']);
}

/**
 * Gehoert die PID unserem Messdienst?
 *
 * /proc/<pid>/cmdline trennt die Argumente mit Nullbytes. Geprueft wird das
 * ERSTE Argument gegen den vollen Pfad des Skripts - der Dienst wird immer
 * als "<pfad>/ultraschall.py" gestartet (Shebang), bei einem Aufruf ueber
 * den Interpreter steht er an zweiter Stelle. Beide Faelle sind abgedeckt.
 */
function us_ist_dienst($pid, $skript)
{
    $roh = @file_get_contents('/proc/' . (int) $pid . '/cmdline');
    if ($roh === false || $roh === '') {
        return false;
    }
    $args = explode("\0", $roh);
    return (isset($args[0]) && $args[0] === $skript)
        || (isset($args[1]) && $args[1] === $skript);
}

/**
 * PID des laufenden Dienstes, 0 wenn keiner laeuft.
 *
 * Bis 1.1.0 stand hier "pgrep -o -f ultraschall.py". Das durchsucht die
 * ganze Befehlszeile jedes Prozesses und trifft damit auch einen Editor,
 * in dem die Datei offen ist, oder ein zweites Exemplar des Plugins.
 * Massgeblich ist jetzt die PID-Datei, die der Dienst selbst schreibt;
 * findet sich dort nichts Brauchbares, wird /proc argumentweise
 * durchgesehen - ohne Teilstringsuche.
 */
function us_dienst_pid()
{
    $p = us_paths();
    $skript = $p['bindir'] . '/ultraschall.py';

    $pid = (int) @file_get_contents($p['pid']);
    if ($pid > 0 && us_ist_dienst($pid, $skript)) {
        return $pid;
    }

    foreach ((array) @scandir('/proc') as $eintrag) {
        if (ctype_digit((string) $eintrag) && us_ist_dienst((int) $eintrag, $skript)) {
            return (int) $eintrag;
        }
    }
    return 0;
}

/** Dienst starten, stoppen, neu starten. */
function us_dienst($aktion)
{
    $p = us_paths();
    $skript = $p['bindir'] . '/ultraschall.py';
    $meldungen = array();
    if (in_array($aktion, array('stop', 'restart'), true)) {
        // Gezielt die eigene PID beenden statt "pkill -f ultraschall.py" -
        // das haette bei zwei Exemplaren des Plugins beide erwischt.
        $pid = us_dienst_pid();
        if ($pid > 0) {
            @exec('kill ' . (int) $pid . ' 2>&1', $meldungen);
            for ($i = 0; $i < 10 && us_dienst_pid() === $pid; $i++) {
                sleep(1);
            }
            if (us_dienst_pid() === $pid) {
                @exec('kill -9 ' . (int) $pid . ' 2>&1', $meldungen);
                sleep(1);
            }
            $meldungen[] = 'angehalten (PID ' . $pid . ')';
        } else {
            $meldungen[] = 'lief nicht';
        }
        @unlink($p['pid']);
    }
    if (in_array($aktion, array('start', 'restart'), true)) {
        if (!is_file($skript)) {
            return 'Dienst nicht gefunden: ' . $skript;
        }
        $log = $p['logdir'] . '/ultraschall.log';
        @exec('nohup ' . escapeshellarg($skript) . ' >> ' . escapeshellarg($log)
            . ' 2>&1 & echo gestartet', $meldungen);
        sleep(3);
    }
    return implode("\n", $meldungen);
}

/** Miniserver aus general.json. */
function us_miniservers()
{
    $out = array();
    $f = us_paths()['home'] . '/config/system/general.json';
    if (!is_file($f)) {
        return $out;
    }
    $j = @json_decode((string) @file_get_contents($f), true);
    if (!is_array($j) || !isset($j['Miniserver']) || !is_array($j['Miniserver'])) {
        return $out;
    }
    foreach ($j['Miniserver'] as $nr => $ms) {
        $out[(string) $nr] = array(
            'name' => isset($ms['Name']) ? $ms['Name'] : ('Miniserver ' . $nr),
            'ip'   => isset($ms['Ipaddress']) ? $ms['Ipaddress']
                    : (isset($ms['IPAddress']) ? $ms['IPAddress'] : ''),
        );
    }
    return $out;
}

/** Adresse des MQTT-Brokers, nur zur Anzeige, ohne Kennwort. */
function us_mqtt_broker()
{
    $f = us_paths()['home'] . '/config/system/general.json';
    if (!is_file($f)) {
        return '';
    }
    $j = @json_decode((string) @file_get_contents($f), true);
    if (!is_array($j)) {
        return '';
    }
    foreach (array('Mqtt', 'mqtt') as $a) {
        foreach (array('Brokerhost', 'brokerhost') as $h) {
            if (!empty($j[$a][$h])) {
                $port = 1883;
                foreach (array('Brokerport', 'brokerport') as $pk) {
                    if (!empty($j[$a][$pk])) {
                        $port = (int) $j[$a][$pk];
                    }
                }
                return $j[$a][$h] . ':' . $port;
            }
        }
    }
    return '';
}

/** Zustandsthemen. */
function us_status_themen()
{
    return array(
        'distance'   => array('Entfernung zur Oberfl&auml;che in cm', 'analog'),
        'level'      => array('F&uuml;llstand in Prozent &mdash; nur bei eingetragener Kalibrierung', 'analog'),
        'liter'      => array('Inhalt in Litern &mdash; nur bei eingetragenem Gesamtvolumen', 'analog'),
        'valid'      => array('1 = die letzte Messung war brauchbar, 0 = nicht', 'digital'),
        'online'     => array('1 = der Dienst l&auml;uft', 'digital'),
        'last_error' => array('Letzte Fehlermeldung, leer wenn alles gut ging', 'text'),
    );
}

/** Sensorarten. */
function us_sensoren()
{
    return array(
        'srf02'  => 'SRF02 am I2C-Bus (auch SRF08, SRF10)',
        'hcsr04' => 'HC-SR04 an zwei GPIO-Pins',
    );
}

/** Logdatei-Kandidaten. */
function us_log_file()
{
    $c = glob(us_paths()['logdir'] . '/*.log');
    if (!$c) {
        return '';
    }
    usort($c, function ($a, $b) { return filemtime($b) - filemtime($a); });
    return $c[0];
}

/** Die letzten N Zeilen einer Datei, neueste zuerst. */
/**
 * Die letzten $max Zeilen einer Datei, neueste zuerst.
 *
 * Bis 1.1.1 wurde die ganze Datei mit file_get_contents() eingelesen und
 * anschliessend fast alles weggeworfen. Der Hinweis auf den Speicher war
 * berechtigt - der vorgeschlagene Weg ueber exec("tail") ist aber der
 * langsamste von dreien. An einer Protokolldatei an der Rotationsgrenze
 * gemessen, in PHP 7.4 und 8.1:
 *
 *   ganz einlesen        rund 0,3 ms   Spitze rund 1,4 MB
 *   exec("tail -n 300")  rund 1,9 ms   Spitze rund  75 kB
 *   rueckwaerts (fseek)  rund 0,05 ms  Spitze rund 125 kB
 *
 * Ein Prozessstart kostet mehr, als das Einlesen je gespart hat - und er
 * braucht eine Shell, die man wieder absichern muss.
 */
function us_log_tail($file, $max = 300, $block = 8192)
{
    if ($file === '' || !is_file($file)) {
        return array();
    }
    $fp = @fopen($file, 'rb');
    if ($fp === false) {
        return array();
    }
    fseek($fp, 0, SEEK_END);
    $pos = ftell($fp);
    $puffer = '';
    $zeilen = array();
    while ($pos > 0 && count($zeilen) <= $max) {
        $lese = (int) min($block, $pos);
        $pos -= $lese;
        fseek($fp, $pos, SEEK_SET);
        $puffer = fread($fp, $lese) . $puffer;
        $zeilen = preg_split('/\R/', $puffer);
    }
    fclose($fp);
    $zeilen = array_values(array_filter(array_map('rtrim', $zeilen),
        function ($l) { return trim($l) !== ''; }));
    return array_slice(array_reverse($zeilen), 0, $max);
}

/**
 * Fuellstand aus der Entfernung - dieselbe Rechnung wie us_common.fuellstand().
 * Rueckgabe: array(prozent|null, liter|null)
 */
function us_fuellstand($cfg, $entfernung)
{
    $leer = us_roh($cfg, 'leer_cm');
    $voll = us_roh($cfg, 'voll_cm');
    if ($entfernung === null || trim($leer) === '' || trim($voll) === '') {
        return array(null, null);
    }
    $leer = (float) $leer;
    $voll = (float) $voll;
    if (abs($leer - $voll) < 0.001) {
        return array(null, null);
    }
    $prozent = max(0.0, min(100.0, ($leer - $entfernung) / ($leer - $voll) * 100.0));
    $liter = null;
    $vol = trim(us_roh($cfg, 'volumen_liter'));
    if ($vol !== '') {
        $liter = round((float) $vol * $prozent / 100.0, 1);
    }
    return array(round($prozent, 1), $liter);
}

/* ==================================================================
 * Loxone-Vorlagen
 *
 * Nachbau der Bausteine aus LoxBerry::LoxoneTemplateBuilder; das Modul
 * gibt es nur in Perl. Attributreihenfolge, CRLF als Zeilenende und der
 * Tabulator vor den Kindelementen entsprechen dem Original.
 * ================================================================== */

function us_x($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function us_xml_virtual_in_http($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp ';
    $o .= 'Title="' . us_x($kopf['title']) . '" ';
    $o .= 'Comment="' . us_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . us_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . us_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . us_x($c['title']) . '" ';
        $o .= 'Comment="' . us_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'Check="' . us_x(isset($c['check']) ? $c['check'] : ' ') . '" ';
        $o .= 'Signed="true" ';
        $o .= 'Analog="true" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="100" ';
        $o .= 'DestValHigh="100" ';
        $o .= 'DefVal="0" ';
        $o .= 'MinVal="-2147483647" ';
        $o .= 'MaxVal="2147483647"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

function us_xml_virtual_in_udp($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInUdp ';
    $o .= 'Title="' . us_x($kopf['title']) . '" ';
    $o .= 'Comment="' . us_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . us_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'Port="' . us_x(isset($kopf['port']) ? $kopf['port'] : '0') . '"';
    $o .= '>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInUdpCmd ';
        $o .= 'Title="' . us_x($c['title']) . '" ';
        $o .= 'Comment="' . us_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'Check="' . us_x(isset($c['check']) ? $c['check'] : '\v') . '" ';
        $o .= 'Signed="true" ';
        $o .= 'Analog="true" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="100" ';
        $o .= 'DestValHigh="100" ';
        $o .= 'DefVal="0" ';
        $o .= 'MinVal="-2147483647" ';
        $o .= 'MaxVal="2147483647"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInUdp>' . $crlf;
    return $o;
}

/**
 * Vorlage erzeugen. $art ist 'mqtt_in' oder 'udp_in'.
 * Rueckgabe: array(dateiname, inhalt)
 */
function us_vorlage($cfg, $art)
{
    $praefix = us_cfg($cfg, 'themenpraefix', 'ultraschall');
    $fuss = 'Erzeugt vom LoxBerry-Plugin Ultraschall Entfernung (' . date('d.m.Y') . ')';

    if ($art === 'udp_in') {
        // Der UDP-Weg der Originalfassung: ein einziger Wert, die Entfernung
        // in cm, ohne Namen davor. Deshalb nur ein Eintrag.
        $port = us_roh($cfg, 'udp_port');
        return array('ultraschall_udp.xml', us_xml_virtual_in_udp(array(
            'title'   => 'Ultraschall Entfernung',
            'address' => '',
            'port'    => $port !== '' ? $port : '0',
            'comment' => $fuss,
        ), array(
            array('title' => 'Ultraschall_Entfernung',
                  'comment' => 'Entfernung in cm, roher Zahlenwert',
                  'check' => '\v'),
        )));
    }

    $cmds = array();
    foreach (us_status_themen() as $schluessel => $info) {
        $cmds[] = array(
            'title'   => $praefix . '_' . $schluessel,
            'comment' => strip_tags(html_entity_decode($info[0], ENT_QUOTES, 'UTF-8')),
            'check'   => ' ',
        );
    }
    return array('ultraschall_eingaenge.xml', us_xml_virtual_in_http(array(
        'title'   => 'Ultraschall Entfernung',
        'address' => 'http://localhost',
        'polling' => '604800',
        'comment' => $fuss,
    ), $cmds));
}

/* ==================================================================
 * Sprache (Pflicht: Deutsch und Englisch)
 *
 * Englisch ist die Rueckfallebene, nicht Deutsch: wer eine dritte Sprache
 * eingestellt hat, versteht eher Englisch. Deshalb muss language_en.ini
 * immer vollstaendig sein.
 * ================================================================== */

function us_sprache()
{
    $sprache = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $sprache = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $sprache = getenv('LBLANG');
    }
    $sprache = strtolower(substr((string) $sprache, 0, 2));
    return in_array($sprache, array('de', 'en'), true) ? $sprache : 'en';
}

/**
 * Text zu einem Schluessel "ABSCHNITT.SCHLUESSEL".
 *
 * Ist der Schluessel unbekannt, wird er selbst zurueckgegeben - so faellt
 * beim Durchsehen sofort auf, was noch fehlt, statt dass die Seite leer
 * bleibt.
 */
function us_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        // Installiert liegen die Dateien unter
        // <home>/templates/plugins/<ordner>/lang/ - der Ordnername ergibt
        // sich aus dem Ablageort dieser Datei.
        $home = getenv('LBHOMEDIR');
        if (!$home || !is_dir($home)) {
            foreach (array('/opt/loxberry', '/home/loxberry/loxberry') as $k) {
                if (is_dir($k)) { $home = $k; break; }
            }
        }
        $ordner = basename(dirname(__FILE__));
        $pfad = $home . '/templates/plugins/' . $ordner . '/lang';
        if (!is_dir($pfad)) {
            // Nicht installiert (Entwicklung): neben dem Plugin nachsehen.
            $pfad = dirname(dirname(dirname(__FILE__))) . '/templates/lang';
        }
        $texte = @parse_ini_file($pfad . '/language_' . us_sprache() . '.ini',
                                 true, INI_SCANNER_RAW);
        if (!is_array($texte)) { $texte = array(); }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) { $texte = array_replace_recursive($rueck, $texte); }
        // parse_ini_file mit INI_SCANNER_RAW liefert die Werte samt der
        // Anfuehrungszeichen zurueck, in die sie in der Datei stehen muessen.
        // Die gehoeren nicht in die Ausgabe.
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) { continue; }
            foreach ($paare as $s => $w) {
                $texte[$ab][$s] = trim((string) $w, '"');
            }
        }
    }
    list($a, $s) = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$a][$s]) ? $texte[$a][$s] : $schluessel;
}
