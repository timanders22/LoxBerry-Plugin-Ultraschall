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

/* Diese Datei liegt im UNANGEMELDETEN Bereich, weil der Loxone-Endpunkt
 * daneben liegt und sie braucht. Sie ist eine Bibliothek und kein Endpunkt -
 * ein unmittelbarer Aufruf bekommt 403 und sonst nichts. Erkennbar ist er
 * daran, dass der Webserver GENAU diese Datei als Skript ausfuehrt.
 *
 * Ohne diese Wache waere sie eine Adresse, die jeder im Heimnetz aufrufen
 * kann und die nichts tut - harmlos, aber sie gehoert nicht dazu. */
if (isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "ULTRA;OK=0;ERR=KEIN_ENDPUNKT\n";
    exit;
}

if (!function_exists('us_e')) {
    function us_e($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

/** Basisverzeichnisse ermitteln - funktioniert installiert wie im Archiv. */

/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

function us_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = getenv('LBHOMEDIR');
    if (!$home) {
        $home = lb_wurzel_ermitteln();
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
    // 'html' steht seit 1.2.0 mit in dieser Liste: die Bibliothek ist
    // dorthin gezogen, und im entpackten Archiv ergibt basename(__DIR__)
    // damit 'html'. Ohne den Eintrag suchte das Plugin seine
    // Konfiguration unter config/plugins/html/ - gemessen an der
    // Endpunktadresse, die /plugins/html/index.php lautete.
    if ($dir === '' || $dir === '.' || $dir === '/' || $dir === 'htmlauth' || $dir === 'html' || $dir === 'plugins') {
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

/**
 * Die gemeinsame Datenquelle: bin/us_vorgaben.json.
 *
 * Bis 1.1.12 fuehrten diese Datei und bin/us_common.py je eine eigene Liste
 * mit denselben Schluesseln. Ueber die Sprachgrenze hinweg gibt es keine
 * gemeinsame Funktion - also eine gemeinsame DATEI. Bei Gardena bedeutete
 * derselbe fehlende Schluessel in der Oberflaeche "an" und im Dienst "aus";
 * gemerkt hat es niemand.
 *
 * Faellt die Datei aus, wird NICHTS geraten: us_defaults() gibt ein leeres
 * Feld zurueck, die Oberflaeche zeigt einen Kasten und verweigert das
 * Speichern, und der Reiter Test sagt es in einer eigenen Zeile. Eine
 * erfundene Ersatzliste waere genau die zweite Wahrheit, gegen die es diese
 * Datei gibt.
 *
 * Rueckgabe: array|null
 */
function us_daten()
{
    static $d = false;
    if ($d !== false) {
        return $d;
    }
    $d = null;
    foreach (array(us_paths()['bindir'] . '/us_vorgaben.json',
                   dirname(dirname(__DIR__)) . '/bin/us_vorgaben.json') as $k) {
        if (is_file($k)) {
            $j = json_decode((string) @file_get_contents($k), true);
            if (is_array($j) && isset($j['vorgaben']) && is_array($j['vorgaben'])
                && isset($j['felder']) && is_array($j['felder'])) {
                $d = $j;
            }
            break;
        }
    }
    return $d;
}

/** Voreinstellungen. Leeres Feld heisst: die Datenquelle fehlt. */
function us_defaults()
{
    $d = us_daten();
    return ($d === null) ? array() : $d['vorgaben'];
}

/**
 * Die Feldtabelle. Aus ihr entstehen ALLE drei Dinge, die sonst auseinander-
 * laufen: die Loxone-Vorlage, die Statuszeile des Endpunkts und die Tabelle
 * im Reiter MQTT.
 */
function us_felder()
{
    $d = us_daten();
    return ($d === null) ? array() : $d['felder'];
}

/** Nur die Felder, die in die Statuszeile gehoeren. */
/**
 * Die Felder, aus denen die MQTT-Vorlage virtuelle Eingaenge macht.
 *
 * Textthemen bleiben draussen: das nachgebaute Vorlagenformat ist nur fuer
 * Zahlenwerte belegt, und ein Eingang mit Analog="true" auf einen Text zeigt
 * dauerhaft 0. Das Gateway legt sie beim ersten Empfang selbst an.
 *
 * Es gibt diese Funktion seit 1.2.2, weil der Hinweistext daneben die Zahl
 * nennt. Bis dahin zaehlte er us_status_themen(), also ALLE acht Themen,
 * waehrend die Datei sieben Befehle enthielt - gemessen an der erzeugten
 * Vorlage. Wer sie einlas, zaehlte sieben Eingaenge und suchte den achten.
 */
function us_felder_vorlage()
{
    $aus = array();
    foreach (us_felder() as $name => $f) {
        if ($f['art'] !== 'text') {
            $aus[$name] = $f;
        }
    }
    return $aus;
}

function us_felder_zeile()
{
    $aus = array();
    foreach (us_felder() as $name => $f) {
        if (!empty($f['zeile'])) {
            $aus[$name] = $f;
        }
    }
    return $aus;
}

/**
 * Der Suchtext fuer Loxone - an EINER Stelle.
 *
 * Das fuehrende Semikolon ist nicht Schmuck: Loxone sucht woertlich und nimmt
 * den ERSTEN Treffer in der Zeile. Ohne das Trennzeichen faende das Muster
 * fuer ein kurzes Feld auch die Stelle in einem laengeren, das darauf endet.
 * In diesem Haus ist das dreimal aufgetreten.
 *
 * Vorlage, Feldtabelle und Baustein-Liste rufen alle diese Funktion - eine
 * abgeschriebene Zeile in einer Sprachdatei laeuft sonst wieder auseinander.
 */
function us_check($feld)
{
    return '\\i;' . strtoupper($feld) . '=\\i\\v';
}

/**
 * Die Adresse des Endpunkts - ebenfalls an EINER Stelle.
 *
 * $roh laesst die Loxone-Platzhalter stehen. Ein <v> muss <v> bleiben; als
 * %3Cv%3E ginge der Befehl hinaus und taete nichts.
 */
function us_endpunkt_pfad($aktion, $token, $roh = false)
{
    $p = '/plugins/' . us_paths()['plugin'] . '/index.php?token='
       . ($roh ? $token : rawurlencode($token))
       . '&aktion=' . ($roh ? $aktion : rawurlencode($aktion));
    return $p;
}

/**
 * Das Aktionstoken.
 *
 * ES ENTSTEHT GENAU EINMAL - beim ersten Anlegen der Konfiguration, danach
 * nie wieder. Unterschieden wird an array_key_exists(), nicht an empty():
 * "Schluessel fehlt" heisst "noch nie gesetzt", "Schluessel da und leer"
 * heisst "bewusst geleert". Fuer empty() sehen beide gleich aus, und ein
 * Token, das nachwaechst, laesst sich nicht abschalten - der Anwender leert
 * es, und beim naechsten Seitenaufbau steht wieder eines da.
 */
function us_token_neu()
{
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes(12));
    }
    return substr(hash('sha256', uniqid('', true) . mt_rand()), 0, 24);
}

/**
 * Das Merkmal gegen fremde Absender.
 *
 * htmlauth schuetzt gegen den unangemeldeten Aufruf - NICHT dagegen, dass der
 * Browser eines angemeldeten Bedieners ein Formular abschickt, das auf einer
 * fremden Seite steht. Auszuloesen waeren sonst: Dienst anhalten, Dienst neu
 * starten, einen Kalibrierpunkt schreiben, eine untergeschobene Sicherung
 * einspielen und das Token neu wuerfeln.
 *
 * Abgeleitet aus dem Aktionstoken, NICHT gespeichert: ein Schluessel mehr in
 * der Konfiguration ist ein Schluessel mehr, den ein Speicher-Handler
 * vergessen kann. Genau daran sind in diesem Haus dreimal Aktionstoken
 * verlorengegangen.
 */
function us_formtoken($cfg)
{
    $t = isset($cfg['aktionstoken']) ? (string) $cfg['aktionstoken'] : '';
    return ($t === '') ? '' : hash_hmac('sha256', 'formular-v1', $t);
}

/** Das versteckte Feld dazu - in JEDEM Formular. */
function us_fmt($cfg)
{
    return '<input data-role="none" type="hidden" name="formtoken" value="'
         . us_e(us_formtoken($cfg)) . '">';
}

/**
 * Konfiguration lesen. Erkennt das alte Format des Originalplugins mit.
 *
 * $erzeugen darf NUR aus dem angemeldeten Bereich true sein. Der Loxone-
 * Endpunkt liegt im unangemeldeten Bereich und ruft us_config_read(false):
 * wer sich nicht ausweisen kann, legt nichts an - auch nichts Harmloses.
 * In diesem Haus hat genau das zweimal dazu gefuehrt, dass ein einziger
 * Aufruf OHNE Token eine Konfigurationsdatei samt frisch erzeugtem Token
 * hinterlassen hat.
 *
 * Rueckgabe: array($werte, $altesFormat, $lage)
 *   $lage['fehlend']  Schluessel aus den Vorgaben, die in der Datei fehlen
 *   $lage['fremd']    Schluessel in der Datei, die es in den Vorgaben nicht
 *                     gibt - sie werden GENANNT und stehengelassen
 *   $lage['ergaenzt'] was gerade nachgetragen wurde
 *   $lage['quelle']   'ok' oder 'keine_datenquelle'
 */
function us_config_read($erzeugen = false)
{
    $vorgaben = us_defaults();
    $werte = $vorgaben;
    $alt = false;
    $lage = array('fehlend' => array(), 'fremd' => array(), 'ergaenzt' => array(),
                  'quelle' => $vorgaben ? 'ok' : 'keine_datenquelle');
    $file = us_paths()['config'];
    if (!$vorgaben) {
        return array($werte, $alt, $lage);
    }
    if (!is_file($file)) {
        $lage['fehlend'] = array_keys($vorgaben);
        /* FEHLT DIE DATEI, WIRD SIE ANGELEGT - aber nur aus dem angemeldeten
         * Bereich (seit 1.2.2).
         *
         * Bis 1.2.1 kehrte diese Stelle sofort zurueck, VOR dem
         * $erzeugen-Block weiter unten. Damit wurde genau der Zweig
         * uebersprungen, fuer den er geschrieben ist, und das Ergebnis war
         * eine Sackgasse, aus der die Oberflaeche nicht mehr herausfand:
         *
         *   Datei fehlt -> kein Aktionstoken -> us_formtoken() liefert ''
         *   -> der Wachposten weist JEDEN POST ab -> nichts laesst sich
         *   speichern -> die Datei entsteht auch beim Speichern nicht.
         *
         * Gemessen an einem nachgebauten LoxBerry: nach dem Loeschen der
         * Datei blieb sie fort, und jeder Klick auf Speichern lieferte "Das
         * Formular kam nicht von dieser Seite und wurde abgewiesen."
         *
         * Der unangemeldete Endpunkt ruft weiterhin us_config_read(false)
         * und legt nichts an - wer sich nicht ausweisen kann, hinterlaesst
         * auch nichts Harmloses. */
        if ($erzeugen) {
            $werte['aktionstoken'] = us_token_neu();
            if (us_config_write($werte)) {
                $lage['ergaenzt'] = $lage['fehlend'];
                $lage['fehlend'] = array();
            }
        }
        return array($werte, $alt, $lage);
    }
    $gesehen = array();
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
            $gesehen['udp_miniserver'] = true;
            $gesehen['udp'] = true;
            continue;
        }
        if ($klein === 'udpport') {
            $alt = true;
            $werte['udp_port'] = $wert;
            $gesehen['udp_port'] = true;
            continue;
        }
        if (array_key_exists($klein, $vorgaben)) {
            $werte[$klein] = $wert;
            $gesehen[$klein] = true;
        } else {
            /* Ein Schluessel, den es in den Vorgaben nicht gibt. Er ist
             * wirkungslos - und genau das ueberrascht: man hat etwas
             * eingestellt, es steht in der Datei, und es tut nichts. Er wird
             * GENANNT und stehengelassen; ihn zu loeschen waere anmassend,
             * denn niemand weiss, ob dort der Rest einer aelteren Fassung
             * steht oder etwas, das der naechsten schon gehoert. */
            $lage['fremd'][] = $klein;
        }
    }
    $lage['fehlend'] = array_values(array_diff(array_keys($vorgaben), array_keys($gesehen)));

    if ($erzeugen && $lage['fehlend']) {
        /* Die Konfiguration wird VERVOLLSTAENDIGT, nicht nur ergaenzt.
         *
         * Ergaenzen heisst: beim Lesen tritt fuer einen fehlenden Schluessel
         * seine Vorgabe ein. Die Datei bleibt dann lueckenhaft, und "fehlt"
         * ist von "steht auf dem Vorgabewert" nicht zu unterscheiden.
         * Vervollstaendigen heisst: einmal hinschreiben. Danach sieht man in
         * der Datei, was gilt.
         *
         * Das Aktionstoken entsteht dabei GENAU EINMAL - naemlich nur, wenn
         * der Schluessel FEHLT. Steht er da und ist leer, hat ihn jemand
         * bewusst geleert; dann waechst er nicht nach. Fuer empty() sehen
         * beide Faelle gleich aus, und ein nachwachsendes Token laesst sich
         * nicht abschalten. */
        if (in_array('aktionstoken', $lage['fehlend'], true)) {
            $werte['aktionstoken'] = us_token_neu();
        }
        if (us_config_write($werte)) {
            $lage['ergaenzt'] = $lage['fehlend'];
            $lage['fehlend'] = array();
        }
    }
    return array($werte, $alt, $lage);
}

/**
 * Eingaben pruefen und zurechtruecken - EINE Stelle fuer BEIDE Wege.
 *
 * Bis 1.1.11 stand diese Pruefung ausschliesslich im Speichern-Zweig von
 * index.php. Das Zurueckspielen einer Sicherung ging vollstaendig daran
 * vorbei und schrieb roh durch. Am 26.08.2026 an einem echten Webserver
 * gemessen: eine Datei mit den Werten
 *
 *     sensor=laserpistole   gpio_trigger=999999   intervall=0
 *     min_cm=9000  max_cm=1  themenpraefix=a b/c"d
 *
 * wurde mit "8 Werte uebernommen" angenommen. Der Dienst meldete danach
 * einen Sensor "bereit", den es nicht gibt (sensor_aufbauen() faellt still
 * auf den SRF02 zurueck - wer einen HC-SR04 hat, misst ab da auf einem Bus,
 * an dem nichts haengt), und verwarf mit 9000 bis 1 cm JEDE Messung.
 *
 * Rueckgabe: array($werte, $maengel)
 *
 * Was der Aufrufer damit macht, entscheidet er selbst - und die beiden Wege
 * entscheiden verschieden:
 *   Formular   zurechtruecken, alles Uebrige speichern, Maengel daneben
 *              melden (Hausregel "Beanstandungen melden, nicht das ganze
 *              Speichern verhindern")
 *   Sicherung  eine einzige Beanstandung lehnt die GANZE Datei ab und
 *              aendert nichts (Hausregel "eine halb gueltige Datei
 *              ueberschreibt NICHTS")
 */
function us_pruefen($roh)
{
    $v = us_defaults();
    $m = array();

    $hol = function ($k) use ($roh) {
        return isset($roh[$k]) && !is_array($roh[$k]) ? trim((string) $roh[$k]) : '';
    };
    // Nur Steuerzeichen und Anfuehrungszeichen raus - nie eine Positivliste.
    // Ein preg_replace, das alles ausser einer Positivliste entfernt,
    // zerstoert eingefuegte Werte, ohne es zu sagen.
    $saeubern = function ($s) {
        return trim(preg_replace('/[\x00-\x1F\x7F"\']+/u', '', (string) $s));
    };
    /* Maskiert wird das ARGUMENT, nicht die Meldung.
     *
     * Die Beanstandung traegt Auszeichnung und wird deshalb ROH in die Seite
     * geschrieben. Der beanstandete Wert kommt aber aus dem Formular oder aus
     * einer hochgeladenen Datei - er gehoert durch die Maskierfunktion, sonst
     * steht fremdes Markup in der Oberflaeche. */
    $ruege = function ($feld, $wert) use (&$m) {
        $m[] = sprintf(us_t('FEHLER.WERT_UNZULAESSIG'), us_e($feld),
                       us_e(substr((string) $wert, 0, 40)));
    };
    // sprintf('%.2f', 0) ergibt "0.00"; die Nullen und der Punkt fallen weg,
    // "-0" wird zu "0" - sonst stuende das in der Konfigurationsdatei.
    $zahltext = function ($f) {
        $s = rtrim(rtrim(sprintf('%.2f', (float) $f), '0'), '.');
        return ($s === '' || $s === '-' || $s === '-0') ? '0' : $s;
    };

    // --- Auswahl -----------------------------------------------------------
    $sensor = $hol('sensor');
    if (array_key_exists($sensor, us_sensoren())) {
        $v['sensor'] = $sensor;
    } else {
        $ruege('sensor', $sensor);
    }

    /* --- Haken. Sie kommen als '1'/'0' herein, nicht als isset().
     *
     * Alles andere ist eine Beanstandung. Aus dem Formular kann sie nie
     * kommen - dort werden die drei vorher ausdruecklich umgesetzt. Aus
     * einer Datei sehr wohl, und dann steht sonst ein Wert in der
     * Konfiguration, den niemand geschrieben hat. */
    foreach (array('enabled', 'mqtt', 'udp') as $k) {
        $w = $hol($k);
        if ($w !== '0' && $w !== '1') {
            $ruege($k, $w);
        }
        $v[$k] = ($w === '1') ? '1' : '0';
    }

    // --- Themenpraefix ------------------------------------------------------
    /* EIN LEERES PRAEFIX IST EINE BEANSTANDUNG, KEIN RUECKFALL
     * (seit 1.2.2).
     *
     * Bis 1.2.1 stand hier am Ende
     *     $v['themenpraefix'] = ($sauber !== '') ? $sauber : $v['themenpraefix'];
     * und $v kommt aus us_defaults(). Ein geleertes Feld fiel damit
     * stillschweigend auf die VORGABE zurueck, nicht auf den bisherigen
     * Wert - und weil $sauber bei leerer Eingabe gleich $p ist, gab es
     * auch keine Beanstandung. Gemessen: Praefix 'keller', Feld geleert,
     * gespeichert -> 'ultraschall', ohne ein Wort. Der Dienst wird beim
     * Speichern neu gestartet und veroeffentlicht ab da unter einem
     * anderen Praefix; im Miniserver kommt nichts mehr an.
     *
     * Der bisherige Wert steht im Rohsatz, den der Aufrufer mitgibt -
     * der Speicher-Handler legt ihn ueber den GESPEICHERTEN Stand. Beim
     * Zurueckspielen einer Sicherung ist er der Wert aus der Datei. In
     * beiden Faellen ist er das Richtige. */
    $p = $saeubern($hol('themenpraefix'));
    $sauber = preg_replace('/[^A-Za-z0-9_-]+/', '', $p);
    if ($p === '') {
        $ruege('themenpraefix', $p);
        if (isset($roh['themenpraefix_bisher'])
            && (string) $roh['themenpraefix_bisher'] !== '') {
            $v['themenpraefix'] = (string) $roh['themenpraefix_bisher'];
        }
    } elseif ($sauber === '') {
        // Nur unerlaubte Zeichen - dann bleibt nichts uebrig.
        $ruege('themenpraefix', $p);
        if (isset($roh['themenpraefix_bisher'])
            && (string) $roh['themenpraefix_bisher'] !== '') {
            $v['themenpraefix'] = (string) $roh['themenpraefix_bisher'];
        }
    } else {
        if ($sauber !== $p) {
            // Ein Schraegstrich oder ein Leerzeichen im Praefix ergibt
            // Themen, die das Gateway anders benennt, als die Vorlage
            // sie anlegt.
            $ruege('themenpraefix', $p);
        }
        $v['themenpraefix'] = $sauber;
    }

    // --- I2C-Adresse --------------------------------------------------------
    $adr = strtolower($saeubern($hol('i2c_adresse')));
    if (preg_match('/^0x[0-9a-f]{1,2}$/', $adr)) {
        $v['i2c_adresse'] = $adr;
    } else {
        $ruege('i2c_adresse', $adr);
    }

    // --- Ganzzahlen ---------------------------------------------------------
    $ganze = array(
        'i2c_bus'        => array(0, 20),
        'gpio_trigger'   => array(0, 27),
        'gpio_echo'      => array(0, 27),
        'messungen'      => array(1, 25),
        'intervall'      => array(5, 86400),
        'aktualisierung' => array(5, 86400),
        'udp_miniserver' => array(1, 20),
    );
    foreach ($ganze as $k => $g) {
        $w = $hol($k);
        // preg_match statt ctype_digit: die Erweiterung ctype ist auf einem
        // LoxBerry nicht zugesichert.
        if (preg_match('/^-?[0-9]{1,7}$/', $w)
            && (int) $w >= $g[0] && (int) $w <= $g[1]) {
            $v[$k] = (string) (int) $w;
        } else {
            $ruege($k, $w);
        }
    }

    // --- Kommazahlen. Komma statt Punkt kommt bei deutscher Tastatur
    //     staendig vor und ist kein Fehler. --------------------------------
    $kommas = array(
        'messabstand' => array(0.05, 5),
        'min_cm'      => array(0, 1000),
        'max_cm'      => array(1, 2000),
        'offset_cm'   => array(-500, 500),
    );
    foreach ($kommas as $k => $g) {
        $w = str_replace(',', '.', $hol($k));
        if ($w !== '' && is_numeric($w) && (float) $w >= $g[0] && (float) $w <= $g[1]) {
            $v[$k] = $zahltext($w);
        } else {
            $ruege($k, $w);
        }
    }

    // --- Felder, die leer bleiben duerfen: dann wird nicht umgerechnet. -----
    $leerbar = array(
        'leer_cm'       => array(0, 2000),
        'voll_cm'       => array(0, 2000),
        'volumen_liter' => array(0, 1000000),
    );
    foreach ($leerbar as $k => $g) {
        $w = str_replace(',', '.', $hol($k));
        if ($w === '') {
            $v[$k] = '';
        } elseif (is_numeric($w) && (float) $w >= $g[0] && (float) $w <= $g[1]) {
            $v[$k] = $zahltext($w);
        } else {
            $ruege($k, $w);
            $v[$k] = '';
        }
    }

    // --- UDP-Port -----------------------------------------------------------
    $port = $saeubern($hol('udp_port'));
    if ($port === '') {
        $v['udp_port'] = '';
    } elseif (preg_match('/^[0-9]{1,5}$/', $port)
              && (int) $port >= 1 && (int) $port <= 65535) {
        $v['udp_port'] = (string) (int) $port;
    } else {
        $ruege('udp_port', $port);
        $v['udp_port'] = '';
    }

    /* --- Aktionstoken --------------------------------------------------
     *
     * Es MUSS hier stehen, auch wenn kein Formular es anzeigt: us_pruefen()
     * beginnt bei den Vorgaben, und was hier fehlt, faellt beim Speichern
     * auf die Vorgabe zurueck. Fuer das Token ist die Vorgabe leer - ohne
     * diesen Block hat jedes Speichern es geloescht, ohne ein Wort zu sagen.
     * Gemessen an 1.2.0 vor der Behebung: ein Speichern im Reiter MQTT, und
     * jede Adresse im Miniserver waere tot gewesen.
     *
     * Leer ist zulaessig - so sieht es vor der ersten Einrichtung aus. */
    $tok = strtolower($saeubern($hol('aktionstoken')));
    if ($tok === '') {
        $v['aktionstoken'] = '';
    } elseif (preg_match('/^[0-9a-f]{16,64}$/', $tok)) {
        $v['aktionstoken'] = $tok;
    } else {
        $ruege('aktionstoken', $tok);
        $v['aktionstoken'] = '';
    }

    // --- Zusammenhaenge, die erst nach den Einzelwerten greifen -------------
    $vorgabe = us_defaults();
    if ($v['gpio_trigger'] === $v['gpio_echo']) {
        // Ein Pin kann nicht beides sein - sonst laeuft der Treiber ins Leere.
        $m[] = us_t('FEHLER.GPIO_GLEICH');
        $v['gpio_trigger'] = $vorgabe['gpio_trigger'];
        $v['gpio_echo']    = $vorgabe['gpio_echo'];
    }
    if ((float) $v['min_cm'] >= (float) $v['max_cm']) {
        // Vertauscht ist zurechtrueckbar - und die Zahlen des Anwenders
        // bleiben erhalten. Nur wenn auch das nichts hilft (beide gleich),
        // gelten wieder die Vorgaben.
        $m[] = us_t('FEHLER.MIN_MAX');
        $tausch = $v['min_cm'];
        $v['min_cm'] = $v['max_cm'];
        $v['max_cm'] = $tausch;
        if ((float) $v['min_cm'] >= (float) $v['max_cm']) {
            $v['min_cm'] = $vorgabe['min_cm'];
            $v['max_cm'] = $vorgabe['max_cm'];
        }
    }
    /* leer_cm und voll_cm duerfen nicht vertauscht sein (seit 1.2.2).
     *
     * Der Sensor sitzt oben: bei leerem Behaelter ist der Abstand GROSS,
     * bei vollem klein. leer_cm <= voll_cm kann es also nicht geben. Bis
     * 1.2.1 pruefte nur der Dienst auf Gleichheit, und bei vertauschten
     * Werten lief der Fuellstand rueckwaerts - gemessen mit leer_cm=20 und
     * voll_cm=100 ergaben 25 cm einen Fuellstand von 6,2 %, 95 cm einen von
     * 93,8 %. Beide Felder sind einzeln auf 0..2000 geprueft; es gab nichts,
     * was widersprochen haette.
     *
     * ZURECHTGERUECKT WIRD HIER NICHT. Bei min_cm/max_cm ist ein Tausch
     * eindeutig richtig, hier nicht: welcher der beiden Werte bei leerem
     * und welcher bei vollem Behaelter gemessen wurde, weiss nur der
     * Anwender. Gemeldet wird es, und der Dienst rechnet solange keinen
     * Fuellstand - lieber kein Wert als eine Zahl mit umgekehrtem
     * Vorzeichen. */
    if ($v['leer_cm'] !== '' && $v['voll_cm'] !== ''
        && (float) $v['leer_cm'] <= (float) $v['voll_cm']) {
        $m[] = us_t('FEHLER.LEER_VOLL');
    }
    if ($v['udp'] === '1' && $v['udp_port'] === '') {
        $m[] = us_t('FEHLER.UDP_OHNE_PORT');
    }

    return array($v, $m);
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
    // Erst fragen, dann anlegen. Ein mkdir mit recursive=true auf ein
    // vorhandenes Verzeichnis meldet eine Warnung; das @ unterdrueckt nur
    // die Anzeige, ein gesetzter Fehlerbehandler sieht sie trotzdem - und
    // im Prueflauf steht sie dann als Befund da.
    if (!is_dir(dirname($file))) {
        @mkdir(dirname($file), 0775, true);
    }
    $vorgaben = us_defaults();
    if (!$vorgaben) {
        // Ohne Datenquelle wird NICHT geschrieben. Eine aus dem Nichts
        // gebaute Konfiguration waere die zweite Wahrheit, gegen die es
        // bin/us_vorgaben.json gibt.
        return false;
    }
    $txt = "; Ultraschall Entfernung\n; Geschrieben von der Plugin-Oberflaeche.\n"
         . '; Stand ' . date('d.m.Y H:i') . "\n\n[ultraschall]\n";
    foreach ($vorgaben as $k => $vorgabe) {
        $v = array_key_exists($k, $werte) ? $werte[$k] : $vorgabe;
        $v = str_replace(array("\r", "\n"), array('', ' '), (string) $v);
        $txt .= $k . '=' . trim($v) . "\n";
    }
    /* Fremde Schluessel bleiben stehen.
     *
     * Diese Funktion baut die Datei aus den Vorgaben neu auf - ohne diesen
     * Block verschwaende jeder Speichervorgang still, was ein Anwender oder
     * eine aeltere Fassung dort abgelegt hat. Genannt werden sie im Reiter
     * Test; ausgewertet werden sie nicht. */
    $fremd = array();
    // Dieselbe Regel: die Datei fehlt regelmaessig - beim allerersten
    // Anlegen gibt es sie noch gar nicht.
    $us_alt_roh = is_file($file) ? (string) @file_get_contents($file) : '';
    foreach (preg_split('/\R/', $us_alt_roh) as $zeile) {
        $t = trim($zeile);
        if ($t === '' || $t[0] === ';' || $t[0] === '#' || $t[0] === '[') {
            continue;
        }
        $pos = strpos($t, '=');
        if ($pos === false) {
            continue;
        }
        $k = strtolower(preg_replace('/^ultraschall\./i', '',
                                     trim(substr($t, 0, $pos))));
        if (!array_key_exists($k, $vorgaben)
            && !in_array($k, array('miniserver', 'udpport'), true)) {
            $fremd[] = $t;
        }
    }
    if ($fremd) {
        $txt .= "\n; Unbekannte Schluessel - stehengelassen, nicht ausgewertet.\n"
              . implode("\n", $fremd) . "\n";
    }
    /* Erst daneben schreiben, dann umbenennen.
     *
     * Ein einfaches file_put_contents kuerzt die Datei und fuellt sie neu.
     * Der Python-Dienst liest dieselbe Datei und prueft sie im Sekundentakt
     * auf Aenderungen - trifft er das Fenster, liest er eine leere oder
     * halbe Konfiguration. rename() ist im selben Dateisystem unteilbar:
     * der Dienst sieht entweder die alte oder die neue Datei. Die
     * Gegenseite in us_common.konfiguration_schreiben() macht es genauso. */
    /* RECHTE VOR DEM INHALT, UND DIE LAENGE WIRD VERGLICHEN (seit 1.2.2).
     *
     * Bis 1.2.1 stand hier "schreiben, dann chmod" und ein Vergleich gegen
     * false. Zwei Loecher:
     *
     * 1. file_put_contents liefert bei einem TEILweisen Schreibvorgang die
     *    Zahl der geschriebenen Bytes, nicht false. Ein volles Dateisystem
     *    ergab damit eine halbe Konfiguration, die per rename() ueber die
     *    gute geschoben wurde - und die Oberflaeche meldete "gespeichert".
     *    Diese Datei traegt das Aktionstoken; ein abgeschnittener
     *    Schreibvorgang kostet jede Adresse im Miniserver.
     * 2. Zwischen Anlegen und chmod stand die Datei mit den Rechten der
     *    umask da. Sie ist zwar kein Passwortspeicher, traegt aber das
     *    Aktionstoken - und das ist der Schluessel zum Endpunkt.
     */
    $tmp = $file . '.tmp.' . getmypid();
    $fh = @fopen($tmp, 'c');
    if ($fh === false) {
        return false;
    }
    @chmod($tmp, 0644);
    $ok = (@ftruncate($fh, 0) !== false)
          && (@fwrite($fh, $txt) === strlen($txt));
    @fflush($fh);
    @fclose($fh);
    if (!$ok) {
        @unlink($tmp);
        return false;
    }
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
    $datei = '/proc/' . (int) $pid . '/cmdline';
    $roh = is_file($datei) ? @file_get_contents($datei) : false;
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

    // Erst fragen, dann oeffnen. Das @ unterdrueckt die Anzeige, nicht
    // einen mit set_error_handler() eingehaengten Aufnehmer - und genau
    // so haengt sich rendern.py ein. Vor dem ersten Dienststart gibt es
    // die PID-Datei nicht; das ist der Normalfall und keine Meldung wert.
    $pid = is_file($p['pid']) ? (int) @file_get_contents($p['pid']) : 0;
    if ($pid > 0 && us_ist_dienst($pid, $skript)) {
        return $pid;
    }

    // /proc gibt es nur auf Linux. Auf einem Pruefstand unter Windows
    // stuenden hier sonst drei Warnungen je Seitenaufruf.
    if (!is_dir('/proc')) {
        return 0;
    }
    foreach ((array) @scandir('/proc') as $eintrag) {
        if (preg_match('/^[0-9]+$/', (string) $eintrag) === 1
            && us_ist_dienst((int) $eintrag, $skript)) {
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

/**
 * Die Themen - aus der Feldtabelle, nicht aus einer zweiten Liste.
 *
 * Bis 1.1.12 stand hier eine eigene Aufzaehlung mit eingebauten Texten. Aus
 * ihr entstanden die Tabelle im Reiter, die Loxone-Vorlage und der Kommentar
 * darin; der Dienst hatte seine eigene. Drei Stellen fuer dieselbe Sache
 * laufen auseinander, und in diesem Haus ist genau das mehrfach passiert -
 * einmal legte die Vorlage 20 virtuelle Eingaenge an, die Zeile lieferte 17
 * und MQTT 15.
 *
 * Rueckgabe je Thema: array(kurzer Text, art)
 */
function us_status_themen()
{
    $aus = array();
    foreach (us_felder() as $name => $f) {
        $aus[$name] = array(us_t('THEMA.' . strtoupper($name)), $f['art']);
    }
    return $aus;
}

/** Der lange Erklaertext zu einem Thema - fuer die Tabelle, nicht fuer Loxone. */
function us_thema_lang($name)
{
    return us_t('THEMA_LANG.' . strtoupper($name));
}

/**
 * Die Statuszeile des Endpunkts - aus derselben Tabelle wie die Vorlage.
 *
 * Textfelder bleiben draussen ('zeile' => 0): ein Semikolon oder ein
 * Gleichheitszeichen in einer Fehlermeldung zerlegt die Zeile, die Loxone
 * mit einer Befehlserkennung liest, und der Miniserver sieht nur noch den
 * Anfang.
 */
function us_zeile($werte)
{
    $teile = array('ULTRA');
    foreach (us_felder_zeile() as $name => $f) {
        $w = array_key_exists($name, $werte) ? $werte[$name] : '';
        if ($w === null) {
            $w = '';
        }
        $teile[] = strtoupper($name) . '=' . $w;
    }
    return implode(';', $teile);
}

/**
 * Die VOLLSTAENDIGE Antwortzeile des Endpunkts - an EINER Stelle.
 *
 * us_zeile() baut den Mittelteil aus der Feldtabelle. Der Endpunkt haengte
 * bis 1.2.1 OK davor und ALTER dahinter, und die Oberflaeche zeigte als
 * Beispiel us_zeile() ALLEIN. Gemessen:
 *
 *   angezeigt : ULTRA;DISTANCE=123.4;LEVEL=42.1;...;ZAEHLER=418
 *   gesendet  : ULTRA;OK=1;DISTANCE=123.4;...;ZAEHLER=418;ALTER=7
 *
 * Zwei Stellen, die dasselbe zusammensetzen, laufen auseinander - hier waren
 * es ausgerechnet die beiden Felder, ueber die eine Ausfallerkennung laeuft.
 * Wer die Zeile aus der Oberflaeche abschrieb, baute eine Anlage ohne sie.
 *
 * OK sagt, ob der Wert AKTUELL ist - nicht, ob irgendwann einmal eine
 * Messung gelungen ist. ALTER ist -1, solange es keine gab.
 */
function us_zeile_voll($werte, $frisch, $alter)
{
    return 'ULTRA;OK=' . ($frisch ? '1' : '0')
         . ';' . substr(us_zeile($werte), strlen('ULTRA;'))
         . ';ALTER=' . ((int) $alter < 0 ? -1 : (int) $alter);
}

/** Dieselbe Zeile mit Beispielwerten - fuer die Anzeige in der Oberflaeche. */
function us_zeile_beispiel()
{
    return us_zeile_voll(array(
        'distance' => '123.4', 'level' => '42.1', 'liter' => '2105',
        'valid' => '1', 'online' => '1', 'ts' => '1787000000',
        'zaehler' => '418',
    ), true, 7);
}

/**
 * Die beiden Felder, die der Endpunkt ZUSAETZLICH zur Feldtabelle sendet.
 *
 * Sie stehen bewusst nicht in us_felder(): daraus entstehen die
 * Importvorlagen fuer den MQTT- und den UDP-Weg, und dort gibt es weder OK
 * noch ALTER - beide rechnet erst der Endpunkt beim Abruf. In der Tabelle
 * der Befehlserkennungen gehoeren sie dagegen aufgefuehrt, denn die schreibt
 * der Anwender von Hand ab.
 */
function us_felder_endpunkt()
{
    return array(
        'ok'    => array('einheit' => '', 'min' => 0, 'max' => 1),
        'alter' => array('einheit' => 's', 'min' => -1, 'max' => 2147483647),
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

/**
 * Ein Befehl der Vorlage - fuer beide Bauformen dieselbe Reihenfolge.
 *
 * Die Reihenfolge stammt aus den Ausfuhren von Loxone Config vom 12.08.2026,
 * nicht aus dem Gedaechtnis. $adresse gibt es nur bei der UDP-Bauform: dort
 * steht hinter Comment ein zusaetzliches Address="", das die HTTP-Bauform
 * nicht hat.
 *
 * DREI DINGE, die bis 1.1.12 falsch waren:
 *   - Analog="true" stand an ALLEN Befehlen, auch an digitalen und am
 *     Textfeld. Config schreibt es nur bei den analogen und laesst das
 *     Attribut bei den digitalen fort.
 *   - Signed="true" stand ueberall. Config setzt es genau dort, wo die
 *     Untergrenze negativ ist.
 *   - MinVal/MaxVal standen pauschal auf +-2147483647. Loxone zieht daraus
 *     die Reglergrenzen und die Plausibilitaetspruefung; wer alles offen
 *     laesst, verschenkt beides.
 */
function us_xml_cmd($c, $adresse = false)
{
    $o  = "\t" . '<VirtualIn' . ($adresse ? 'Udp' : 'Http') . 'Cmd ';
    $o .= 'Title="' . us_x($c['title']) . '" ';
    $o .= 'Comment="' . us_x($c['comment']) . '" ';
    if ($adresse) {
        $o .= 'Address="" ';
    }
    $o .= 'Check="' . us_x($c['check']) . '" ';
    if ((float) $c['min'] < 0) {
        $o .= 'Signed="true" ';
    }
    if ($c['art'] === 'analog') {
        $o .= 'Analog="true" ';
    }
    $o .= 'SourceValLow="0" DestValLow="0" SourceValHigh="100" DestValHigh="100" ';
    $o .= 'DefVal="0" ';
    $o .= 'MinVal="' . us_x($c['min']) . '" ';
    $o .= 'MaxVal="' . us_x($c['max']) . '" ';
    $o .= 'Unit="' . us_x($c['einheit'] === '' ? '' : '<v.1> ' . $c['einheit']) . '" ';
    $o .= 'HintText=""';
    $o .= '/>' . "\r\n";
    return $o;
}

/**
 * Der Rumpf einer Vorlage.
 *
 * templateType unterscheidet die drei Bauformen: 1 = UDP-Eingang,
 * 2 = HTTP-Eingang, 3 = Ausgang. Das Info-Element ist das ERSTE Kindelement;
 * 36 Plugin-Ordner des Bestandes setzen es, dieses bis 1.1.12 nicht.
 */
function us_xml_rumpf($wurzel, $typ, $kopf, $cmds, $adresse = false)
{
    $crlf = "\r\n";
    $o  = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<' . $wurzel . ' ';
    $o .= 'HintText="" ';
    $o .= 'Title="' . us_x($kopf['title']) . '" ';
    $o .= 'Comment="' . us_x($kopf['comment']) . '" ';
    $o .= 'Address="' . us_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= ($wurzel === 'VirtualInUdp'
           ? 'Port="' . us_x($kopf['port']) . '"'
           : 'PollingTime="' . us_x($kopf['polling']) . '"');
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="' . $typ . '" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        $o .= us_xml_cmd($c, $adresse);
    }
    $o .= '</' . $wurzel . '>' . $crlf;
    return $o;
}

/**
 * Vorlage erzeugen. $art ist 'mqtt_in' oder 'udp_in'.
 *
 * Der Kommentar eines Befehls wird in Loxone Config zum ANZEIGENAMEN, nicht
 * zur Dokumentation - gemessen am 18.08.2026 an einer echten Projektdatei.
 * Er kommt deshalb aus [THEMA] und ist kurz gehalten; alles, was erklaert
 * werden muss, steht im Kommentar des WURZELelements, den man einmal liest.
 *
 * Rueckgabe: array(dateiname, inhalt)
 */
function us_vorlage($cfg, $art)
{
    $praefix = us_cfg($cfg, 'themenpraefix', 'ultraschall');
    $fuss = us_t('VORLAGE.KOPF');

    if ($art === 'udp_in') {
        // Der UDP-Weg der Originalfassung: ein einziger Wert, die Entfernung
        // in cm, ohne Namen davor. Deshalb nur ein Eintrag - und Address ist
        // hier der ABSENDER, also der LoxBerry.
        $port = us_roh($cfg, 'udp_port');
        $f = us_felder();
        $d = isset($f['distance']) ? $f['distance'] : array('min' => 0, 'max' => 2000,
                                                            'einheit' => 'cm', 'art' => 'analog');
        return array('ultraschall_udp.xml', us_xml_rumpf('VirtualInUdp', '1', array(
            'title'   => 'Ultraschall Entfernung',
            'address' => '',
            'port'    => $port !== '' ? $port : '0',
            'comment' => $fuss,
        ), array(array(
            'title'   => 'Ultraschall_Entfernung',
            'comment' => us_t('THEMA.DISTANCE'),
            'check'   => '\\v',
            'art'     => 'analog',
            'min'     => $d['min'],
            'max'     => $d['max'],
            'einheit' => $d['einheit'],
        )), true));
    }

    /* MQTT-Gateway-Eingaenge sind nackte VirtualIn; dafuer kennt Loxone
     * Config kein Vorlagenformat. Der Kunstgriff des Hauses: ein
     * VirtualInHttp mit Dummy-Adresse und einer Abfragezeit von einer Woche.
     * Loxone legt die richtig benannten Eingaenge an, die Werte kommen vom
     * Gateway. Check ist deshalb ein einzelnes Leerzeichen - Config macht
     * daraus ein leeres Feld, genau wie gewollt.
     *
     * TEXTTHEMEN BLEIBEN DRAUSSEN. Das nachgebaute Format ist nur fuer
     * Zahlenwerte belegt; ein Eingang mit Analog="true" auf einen Text zeigt
     * dauerhaft 0. Das Gateway legt sie beim ersten Empfang selbst an, und
     * der Hinweistext sagt, wie viele es sind. */
    $cmds = array();
    foreach (us_felder_vorlage() as $name => $f) {
        $cmds[] = array(
            'title'   => $praefix . '_' . $name,
            'comment' => us_t('THEMA.' . strtoupper($name)),
            'check'   => ' ',
            'art'     => $f['art'],
            'min'     => $f['min'],
            'max'     => $f['max'],
            'einheit' => $f['einheit'],
        );
    }
    return array('ultraschall_eingaenge.xml', us_xml_rumpf('VirtualInHttp', '2', array(
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
            foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
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



/**
 * Autostart UND Fassung des MQTT-Gateways - aus EINEM Lesevorgang.
 *
 * Bis 1.1.12 gab es dafuer drei Leser: us_mqtt_broker(), us_gateway_fassung()
 * und eine Funktion, die mitten im HTML von index.php definiert war und einen
 * harten Systempfad trug. Drei Dateizugriffe je Seitenaufbau, und drei
 * Stellen, die auseinanderlaufen koennen. MG iSmart macht es seit 1.1.0 mit
 * einer.
 *
 * Der Schluessel heisst Gatewayautostart, NICHT Autostart - der zweite
 * existiert nicht, und ein Plugin, das nach ihm sucht, warnt auf JEDER
 * einwandfrei eingerichteten Anlage. Im Bestand ist das fuenfmal aufgetreten.
 *
 * 'fassung' ist 0, wenn sie sich nicht lesen laesst. NICHT auf 1 vorbelegen:
 * "unbekannt" und "Fassung 1" sind verschiedene Aussagen, und die Oberflaeche
 * behandelt sie verschieden.
 */
function us_mqtt_gateway_info()
{
    static $g = false;
    if ($g !== false) {
        return $g;
    }
    $g = null;
    $home = us_paths()['home'];
    if ($home === '' || !is_dir($home)) {
        return $g;
    }
    $d = @json_decode((string) @file_get_contents(
        $home . '/config/system/general.json'), true);
    if (!is_array($d)) {
        return $g;
    }
    foreach (array('Mqtt', 'mqtt') as $ab) {
        if (!isset($d[$ab]) || !is_array($d[$ab])) {
            continue;
        }
        $auto = '';
        foreach (array('Gatewayautostart', 'gatewayautostart') as $sl) {
            if (isset($d[$ab][$sl])) { $auto = $d[$ab][$sl]; break; }
        }
        $fassung = 0;
        foreach (array('Gatewayversion', 'gatewayversion') as $sl) {
            if (isset($d[$ab][$sl]) && (string) $d[$ab][$sl] !== '') {
                $fassung = (int) $d[$ab][$sl];
                break;
            }
        }
        $g = array('autostart' => in_array((string) $auto, array('1', 'true'), true),
                   'fassung'   => $fassung);
        break;
    }
    return $g;
}

/** Nur die Fassung - 0 heisst "nicht feststellbar". */
function us_gateway_fassung()
{
    $g = us_mqtt_gateway_info();
    return ($g === null) ? 0 : (int) $g['fassung'];
}

/**
 * Der Hinweis zum MQTT-Abo - in der Fassung, die zum GATEWAY passt.
 *
 * Der Pflichtsatz "Ohne diesen Eintrag kommt am Miniserver nichts an" gilt
 * fuer Gateway V1. Ab V2 schaltet der LoxBerry-Kern auf der Abonnement-Seite
 * die Knoepfe ab - von Hand eintragen kann man dort nichts mehr, und der
 * Satz schickt jeden V2-Anwender zu einem Eingabefeld, das es nicht gibt.
 *
 * Drei Ausgaenge: ist die Fassung nicht feststellbar, werden BEIDE Faelle
 * genannt statt einer behauptet.
 */
function us_abo_text()
{
    $f = us_gateway_fassung();
    if ($f <= 0) {
        return us_t('TEXT.ABO_UNBEKANNT');
    }
    $gemessen = ' <span class="sm-mono">'
              . sprintf(us_t('TEXT.ABO_GEMESSEN'), $f) . '</span>';
    return us_t($f >= 2 ? 'TEXT.ABO_V2' : 'TEXT.OHNE_DIESEN_EINTRAG_KOMMT_AM_MINIS') . $gemessen;
}

/**
 * Eine Sicherungsdatei einlesen - und dabei NICHTS durchgehen lassen.
 *
 * Die sieben Punkte aus REGELN_2, und der wichtigste ist der dritte: eine
 * halb gueltige Datei ueberschreibt GAR NICHTS. Wer eine Sicherung
 * zurueckspielt, will entweder den ganzen Stand oder gar keinen - eine zur
 * Haelfte uebernommene Konfiguration ist schlimmer als die alte, und man
 * sieht es ihr nicht an.
 *
 * Unbekannte Schluessel sind eine Beanstandung, kein stiller Verlust: sie
 * stammen aus einer anderen Fassung oder einem anderen Plugin.
 *
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], uebernommene Werte).
 */
function us_sicherung_lesen($roh, $bestand = null)
{
    $mangel = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, array(us_t('TEXT.SICH_KEIN_JSON')), 0);
    }
    $bekannt = array_keys(us_defaults());
    $anzahl = 0;
    $gelesen = array();
    foreach ($daten as $k => $w) {
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(us_t('TEXT.SICH_FREMD'), us_e((string) $k));
            continue;
        }
        if (is_array($w)) {
            // "feld": [1,2] waere kein Wert. Unter PHP 8 wuerde daraus beim
            // Umwandeln in eine Zeichenkette ein TypeError.
            $mangel[] = sprintf(us_t('FEHLER.WERT_UNZULAESSIG'), $k, '[]');
            continue;
        }
        $gelesen[$k] = is_bool($w) ? ($w ? '1' : '0') : (string) $w;
        $anzahl++;
    }
    if ($anzahl === 0) {
        $mangel[] = us_t('TEXT.SICH_LEER');
    }

    /* EIN FEHLENDES AKTIONSTOKEN LOESCHT KEINES (seit 1.2.2).
     *
     * us_pruefen() beginnt bei den Vorgaben, und die Vorgabe fuer das Token
     * ist leer. Eine Sicherung aus 1.1.x kennt den Schluessel gar nicht - er
     * kam erst mit 1.2.0 dazu. Gemessen mit einer solchen Datei (21
     * Schluessel): sie wurde mit "21 Werte uebernommen" und NULL
     * Beanstandungen angenommen, und danach war das Token leer.
     *
     * Was das kostet: jede Adresse im Miniserver antwortet mit 403, und bis
     * 1.2.1 kam die Oberflaeche aus diesem Zustand nicht mehr heraus (kein
     * Token -> kein Formularmerkmal -> jeder POST abgewiesen). Genau der
     * Fall, fuer den es die Sicherung gibt - der Umzug auf einen zweiten
     * LoxBerry -, machte das Plugin unbedienbar.
     *
     * Fehlt der Schluessel, bleibt der bestehende Wert stehen. Steht er in
     * der Datei, gilt die Datei: dort ist er gewollt, und beim Umzug ist er
     * genau das, was man mitnehmen will. */
    if (!array_key_exists('aktionstoken', $gelesen)) {
        if ($bestand === null) {
            list($bestand) = us_config_read(false);
        }
        if (is_array($bestand) && isset($bestand['aktionstoken'])
            && (string) $bestand['aktionstoken'] !== '') {
            $gelesen['aktionstoken'] = (string) $bestand['aktionstoken'];
        }
    }

    /* Und jetzt DIESELBE Pruefung wie beim Speichern.
     *
     * Bis 1.1.11 endete die Pruefung hier: bekannter Schluessel, also
     * uebernommen. Damit liess sich ueber die Sicherung alles einschleusen,
     * was das Formular abweist - gemessen an einer Datei mit
     * "sensor=laserpistole" und "min_cm=9000 / max_cm=1", die anstandslos
     * angenommen wurde und den Dienst danach jede Messung verwerfen liess.
     *
     * Fail closed: EINE Beanstandung, und es wird NICHTS geschrieben. Wer
     * eine Sicherung zurueckspielt, will den ganzen Stand oder gar keinen;
     * eine zur Haelfte uebernommene Konfiguration ist schlimmer als die
     * alte, und man sieht es ihr nicht an. */
    if (!$mangel) {
        list($geprueft, $wertmaengel) = us_pruefen($gelesen);
        if (!$wertmaengel) {
            return array($geprueft, array(), $anzahl);
        }
        $mangel = $wertmaengel;
    }
    return array(null, $mangel, $anzahl);
}

/* ==================================================================
 * Selbstpruefung fuer den Reiter Test
 *
 * Jede Zeile: array(Frage, Zustand, Antwort). Zustand ist 'ja', 'nein' oder
 * 'offen' - der dritte ist Pflicht und darf in keiner Zusammenfassung als
 * bestanden zaehlen.
 * ================================================================== */

/** Wo liegt die eigene Oberflaeche? Kandidatenliste, kein fester Pfad. */
function us_oberflaeche_datei()
{
    foreach (array(
        dirname(dirname(__DIR__)) . '/htmlauth/plugins/' . basename(__DIR__) . '/index.php',
        dirname(dirname(dirname(__DIR__))) . '/htmlauth/plugins/' . basename(__DIR__) . '/index.php',
        dirname(__DIR__) . '/htmlauth/index.php',
    ) as $k) {
        if (is_file($k)) {
            return $k;
        }
    }
    return '';
}

/** Wo liegt der Dienst? */
function us_dienst_datei()
{
    $k = us_paths()['bindir'] . '/ultraschall.py';
    if (is_file($k)) {
        return $k;
    }
    $k = dirname(dirname(__DIR__)) . '/bin/ultraschall.py';
    return is_file($k) ? $k : '';
}

/**
 * Den eigenen Endpunkt WIRKLICH aufrufen.
 *
 * Alle uebrigen Zeilen sehen sich Dateien an. Nur diese eine spricht die
 * Stelle an, die spaeter der Miniserver anspricht - und nur sie findet die
 * Klasse, bei der html/ und htmlauth/ installiert in getrennten Baeumen
 * liegen und der Endpunkt mit HTTP 500 und leerem Rumpf antwortet, ohne dass
 * es jemand merkt.
 *
 * 127.0.0.1 ist hier die RICHTIGE Adresse - das widerspricht nicht der Regel
 * "ein Knopf auf 127.0.0.1 kann nie funktionieren": die gilt fuer einen Link,
 * den ein Mensch im Browser anklickt.
 *
 * DREI Ausgaenge, und der dritte ist wichtig: ein Webserver, der nur eine
 * Anfrage zugleich bearbeitet, kann sich waehrend des Seitenaufbaus nicht
 * selbst aufrufen. Ein Kreuz waere dort ein Kreuz, das nichts bedeutet.
 *
 * Zwischengespeichert, weil sonst jeder Klick auf der Seite einen HTTP-Aufruf
 * ausloest - alle Reiter werden mitgerendert.
 */
function us_endpunkt_probe($token, $frisch = false)
{
    $speicher = dirname(us_paths()['status']) . '/endpunkt.json';
    if (!$frisch && is_file($speicher)) {
        $a = @json_decode((string) @file_get_contents($speicher), true);
        if (is_array($a) && isset($a['zeit']) && (time() - (int) $a['zeit']) < 300) {
            return $a;
        }
    }
    $erg = array('zeit' => time(), 'zustand' => 'offen', 'text' => '', 'code' => 0);
    if ($token === '') {
        $erg['zustand'] = 'nein';
        $erg['text'] = us_t('PRUEF.EP_KEIN_TOKEN');
        return $erg;
    }
    $adr = 'http://127.0.0.1' . us_endpunkt_pfad('status', $token) . '&selftest=1';
    $rumpf = false;
    $code = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($adr);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        $rumpf = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } elseif (ini_get('allow_url_fopen')) {
        /* Wo ein Fehlschlag ein VORGESEHENER Ausgang ist, wird der
         * Fehlerbehandler fuer die Dauer des Aufrufs ausgetauscht. Das @
         * unterdrueckt nur die Standardbehandlung - ein mit
         * set_error_handler() eingehaengter Aufnehmer sieht die Warnung
         * trotzdem, und im Prueflauf staende sie dann als Befund da. */
        /* UND DER VERBINDUNGSAUFBAU BRAUCHT SEINE EIGENE SCHRANKE
         * (seit 1.2.2).
         *
         * Das 'timeout' im Kontext gilt nur fuer das LESEN. Fuer den
         * Verbindungsaufbau gilt default_socket_timeout - ab Werk 60
         * Sekunden. Gemessen im Pruefstand: ein Seitenaufbau blieb
         * ueber eine Minute stehen, obwohl drei Sekunden zugesagt sind.
         * Und diese Zeile laeuft bei JEDEM Aufbau der Seite, weil alle
         * Reiter mitgerendert werden. Auf einem LoxBerry ohne php-curl
         * ist das genau der Fall: dort gibt es curl_init() nicht.
         *
         * Zurueckgestellt wird der Wert unmittelbar danach - er ist
         * global, und was hier gesetzt bleibt, trifft jeden weiteren
         * Netzzugriff dieses Prozesses. */
        set_error_handler(function () { return true; });
        $us_sock_alt = ini_get('default_socket_timeout');
        @ini_set('default_socket_timeout', '3');
        $ctx = stream_context_create(array('http' => array(
            'timeout' => 3, 'ignore_errors' => true,
            'follow_location' => 0, 'max_redirects' => 1)));
        $rumpf = file_get_contents($adr, false, $ctx);
        @ini_set('default_socket_timeout', (string) $us_sock_alt);
        restore_error_handler();
        if (isset($http_response_header)) {
            foreach ((array) $http_response_header as $z) {
                if (preg_match('#^HTTP/\S+\s+([0-9]{3})#', $z, $m)) {
                    $code = (int) $m[1];
                }
            }
        }
    } else {
        $erg['text'] = us_t('PRUEF.EP_KEIN_MITTEL');
        return $erg;
    }
    $erg['code'] = $code;
    if ($rumpf === false || $rumpf === '') {
        // Keine Antwort ist HIER kein Kreuz: der eingebaute Pruefserver kann
        // sich nicht selbst aufrufen.
        $erg['zustand'] = 'offen';
        $erg['text'] = sprintf(us_t('PRUEF.EP_KEINE_ANTWORT'), $code);
        return $erg;
    }
    if ($code === 200 && strpos($rumpf, 'SELFTEST;OK=1') !== false) {
        $erg['zustand'] = 'ja';
        $erg['text'] = trim($rumpf);
    } else {
        $erg['zustand'] = 'nein';
        $erg['text'] = sprintf(us_t('PRUEF.EP_FALSCH'), $code, substr(trim($rumpf), 0, 120));
    }
    /* Erst kodieren, dann den Rueckgabewert ansehen, dann schreiben
     * (seit 1.2.2). $erg['text'] traegt bis zu 120 Zeichen rohe Antwort des
     * Endpunkts; ist darin ungueltiges UTF-8, liefert json_encode false,
     * und geschrieben wuerde eine LEERE Datei. Der Zwischenspeicher hoerte
     * dann still auf zu wirken - jeder Aufbau des Reiters Test riefe den
     * Endpunkt wieder ueber HTTP auf. Beide anderen Stellen des Plugins
     * (der Endpunkt selbst und der Sicherungsknopf) pruefen es. */
    $us_js = json_encode($erg);
    if ($us_js !== false) {
        @file_put_contents($speicher, $us_js);
    }
    return $erg;
}

/**
 * Die Pruefzeilen. $netz = false laesst die teuren Zeilen aus - sie laufen
 * nur, wenn der Reiter Test serverseitig der offene ist.
 */
function us_pruefzeilen($cfg, $lage, $netz = false)
{
    $z = array();
    $j = function ($frage, $ok, $text) use (&$z) {
        $z[] = array($frage, $ok ? 'ja' : 'nein', $text);
    };
    $o = function ($frage, $text) use (&$z) {
        $z[] = array($frage, 'offen', $text);
    };

    // --- Grundlage ---------------------------------------------------------
    $daten = us_daten();
    $j(us_t('PRUEF.DATENQUELLE'), $daten !== null,
       $daten !== null
           ? sprintf(us_t('PRUEF.DATENQUELLE_JA'), count(us_defaults()), count(us_felder()))
           : us_t('PRUEF.DATENQUELLE_NEIN'));
    if ($daten === null) {
        // Ueber eine leere Menge wird nicht geurteilt.
        $o(us_t('PRUEF.KONFIG'), us_t('PRUEF.OHNE_DATENQUELLE'));
        return $z;
    }

    // --- Konfiguration -----------------------------------------------------
    $soll = count(us_defaults());
    $fehlt = isset($lage['fehlend']) ? $lage['fehlend'] : array();
    $j(us_t('PRUEF.KONFIG'), !$fehlt,
       $fehlt ? sprintf(us_t('PRUEF.KONFIG_NEIN'), $soll - count($fehlt), $soll,
                        implode(', ', $fehlt))
              : sprintf(us_t('PRUEF.KONFIG_JA'), $soll, $soll));

    $fremd = isset($lage['fremd']) ? $lage['fremd'] : array();
    $j(us_t('PRUEF.FREMD'), !$fremd,
       $fremd ? sprintf(us_t('PRUEF.FREMD_NEIN'), implode(', ', $fremd))
              : us_t('PRUEF.FREMD_JA'));

    // --- Dienst ------------------------------------------------------------
    $pid = us_dienst_pid();
    $j(us_t('PRUEF.DIENST'), $pid > 0,
       $pid > 0 ? sprintf(us_t('PRUEF.DIENST_JA'), $pid) : us_t('PRUEF.DIENST_NEIN'));

    $alter = us_status_alter();
    $takt = (int) us_cfg($cfg, 'intervall', '60');
    $grenze = max(180, 3 * $takt);
    if ($pid <= 0) {
        // Ueber einen Dienst, der gar nicht laeuft, wird kein Herzschlag
        // beurteilt.
        $o(us_t('PRUEF.ARBEITET'), us_t('PRUEF.ARBEITET_OFFEN'));
    } elseif ($alter < 0) {
        $o(us_t('PRUEF.ARBEITET'), us_t('PRUEF.ARBEITET_KEINE_DATEI'));
    } else {
        $j(us_t('PRUEF.ARBEITET'), $alter <= $grenze,
           sprintf(us_t('PRUEF.ARBEITET_TEXT'), $alter, $grenze));
    }

    // --- Token und Endpunkt ------------------------------------------------
    $token = isset($cfg['aktionstoken']) ? (string) $cfg['aktionstoken'] : '';
    $j(us_t('PRUEF.TOKEN'), $token !== '',
       $token !== '' ? sprintf(us_t('PRUEF.TOKEN_JA'), strlen($token))
                     : us_t('PRUEF.TOKEN_NEIN'));

    if ($netz) {
        $ep = us_endpunkt_probe($token);
        $z[] = array(us_t('PRUEF.ENDPUNKT'), $ep['zustand'], $ep['text']);
    } else {
        $o(us_t('PRUEF.ENDPUNKT'), us_t('PRUEF.ENDPUNKT_OFFEN'));
    }

    // --- Die eigene Oberflaeche gegen sich selbst ---------------------------
    $seite = us_oberflaeche_datei();
    if ($seite === '') {
        $o(us_t('PRUEF.REITER'), us_t('PRUEF.KEINE_DATEI'));
        $o(us_t('PRUEF.FORMULARE'), us_t('PRUEF.KEINE_DATEI'));
    } else {
        $q = (string) @file_get_contents($seite);
        // Wer eine Datei liest, um darin etwas NICHT zu finden, prueft
        // zuerst, dass er ueberhaupt etwas gelesen hat.
        if ($q === '') {
            $o(us_t('PRUEF.REITER'), us_t('PRUEF.NICHTS_GELESEN'));
            $o(us_t('PRUEF.FORMULARE'), us_t('PRUEF.NICHTS_GELESEN'));
        } else {
            preg_match('/\$us_reiter_liste = array\(([^)]*)\)/', $q, $m1);
            $liste = array();
            if (!empty($m1[1])) {
                preg_match_all("/'tab-([a-z]+)'/", $m1[1], $m2);
                $liste = $m2[1];
            }
            preg_match_all('/id="tab-([a-z]+)"/', $q, $m3);
            $flaechen = array_values(array_unique($m3[1]));
            sort($liste);
            sort($flaechen);
            $fehlt1 = array_diff($flaechen, $liste);   // Flaeche ohne Eintrag
            $fehlt2 = array_diff($liste, $flaechen);   // Eintrag ohne Flaeche
            $j(us_t('PRUEF.REITER'), $liste && !$fehlt1 && !$fehlt2,
               sprintf(us_t('PRUEF.REITER_TEXT'), count($liste), count($flaechen),
                       ($fehlt1 || $fehlt2)
                           ? implode(', ', array_merge($fehlt1, $fehlt2)) : '-'));

            $formulare = substr_count($q, '<form ');
            $merkmale = substr_count($q, 'us_fmt($us_cfg)');
            $j(us_t('PRUEF.FORMULARE'), $formulare > 0 && $merkmale >= $formulare,
               sprintf(us_t('PRUEF.FORMULARE_TEXT'), $merkmale, $formulare));
        }
    }

    // --- Themenliste gegen den Sendecode ------------------------------------
    $dd = us_dienst_datei();
    if ($dd === '') {
        $o(us_t('PRUEF.THEMEN'), us_t('PRUEF.KEINE_DATEI'));
    } else {
        $q = (string) @file_get_contents($dd);
        if ($q === '') {
            $o(us_t('PRUEF.THEMEN'), us_t('PRUEF.NICHTS_GELESEN'));
        } else {
            preg_match_all('/_senden\(\s*"([a-z_]+)"/', $q, $m4);
            $gesendet = array_values(array_unique($m4[1]));
            $bekannt = array_keys(us_felder());
            sort($gesendet);
            sort($bekannt);
            $nurcode = array_diff($gesendet, $bekannt);
            $nurliste = array_diff($bekannt, $gesendet);
            $j(us_t('PRUEF.THEMEN'), $gesendet && !$nurcode && !$nurliste,
               sprintf(us_t('PRUEF.THEMEN_TEXT'), count($gesendet), count($bekannt),
                       ($nurcode || $nurliste)
                           ? implode(', ', array_merge($nurcode, $nurliste)) : '-'));
        }
    }

    // --- Suchmuster eindeutig ------------------------------------------------
    $namen = array_keys(us_felder_zeile());
    $koll = array();
    foreach ($namen as $a) {
        foreach ($namen as $b) {
            if ($a !== $b && substr(strtoupper($b), -strlen($a)) === strtoupper($a)) {
                $koll[] = $a . ' in ' . $b;
            }
        }
    }
    $j(us_t('PRUEF.MUSTER'), $namen && !$koll,
       $koll ? implode(', ', $koll) : sprintf(us_t('PRUEF.MUSTER_JA'), count($namen)));

    // --- Vorlagen wohlgeformt -------------------------------------------------
    $kaputt = array();
    $wieviel = 0;
    foreach (array('mqtt_in', 'udp_in') as $art) {
        list($name, $xml) = us_vorlage($cfg, $art);
        $wieviel++;
        $alt = libxml_use_internal_errors(true);
        if (simplexml_load_string($xml) === false) {
            $kaputt[] = $name;
        }
        libxml_clear_errors();
        libxml_use_internal_errors($alt);
    }
    $j(us_t('PRUEF.VORLAGEN'), $wieviel > 0 && !$kaputt,
       $kaputt ? implode(', ', $kaputt) : sprintf(us_t('PRUEF.VORLAGEN_JA'), $wieviel));

    // --- MQTT-Gateway ----------------------------------------------------------
    $g = us_mqtt_gateway_info();
    if ($g === null) {
        $o(us_t('PRUEF.GATEWAY'), us_t('PRUEF.GATEWAY_OFFEN'));
    } else {
        $j(us_t('PRUEF.GATEWAY'), $g['autostart'],
           sprintf(us_t('PRUEF.GATEWAY_TEXT'),
                   $g['autostart'] ? us_t('PRUEF.JA') : us_t('PRUEF.NEIN'),
                   $g['fassung'] > 0 ? 'V' . $g['fassung'] : us_t('PRUEF.UNBEKANNT')));
    }

    return $z;
}
