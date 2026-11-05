<?php
require_once __DIR__ . '/sprachausgabe.php';     // Stamm, fuer die Vorgaben der Sprachansagen schon beim ersten Lesen der Konfiguration
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
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME'])     // die Kommandozeile: der Sender der Sprachansagen laedt diese Datei selbst
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
 * config/plugins UND data/plugins UND config/system/general.json traegt
 * (Regeln/06). Das trifft die uebliche Installation genauso wie eine an
 * einem anderen Ort - und es trifft auch den Fall, dass das Plugin noch als
 * entpacktes Archiv daliegt (dann findet es nichts und gibt einen
 * Leerstring zurueck, was der Aufrufer ohnehin abfangen muss).
 *
 * GENERAL.JSON GEHOERT DAZU (seit 1.2.8). Bis 1.2.7 genuegten
 * config/plugins und webfrontend; ein Rest aus Pruefstaenden (so lag es am
 * 05.09.2026 unter C:\) galt damit als Wurzel, und die Oberflaeche las und
 * schrieb dort. Gemessen in WSL, Pruefung-Ultraschall-1.2.8, Fall H2.
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/data/plugins')
                && is_file($d . '/config/system/general.json')) {
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
    /* Die Marke "Aktualisierung laeuft" liegt NEBEN dem Datenordner, nicht
     * darin: purge_installation entfernt data/plugins/<ordner>/ auch im
     * Upgrade-Zweig. preupgrade.sh legt sie an, postupgrade.sh entfernt sie
     * (seit 1.2.7). Ohne LoxBerry-Wurzel gibt es sie nicht - dann bleibt der
     * Wert leer, und die Pruefung darauf urteilt nicht. */
    if ($home) {
        $p = array(
            'home'   => $home,
            'plugin' => $dir,
            'config' => $home . '/config/plugins/' . $dir . '/ultraschall.cfg',
            'bindir' => $home . '/bin/plugins/' . $dir,
            'logdir' => $home . '/log/plugins/' . $dir,
            'status' => $status,
            'pid'    => $pid,
            'marke'  => $home . '/data/plugins/' . $dir . '.upgrade_laeuft',
            'datadir' => $home . '/data/plugins/' . $dir,
            'praefixe' => $home . '/config/plugins/' . $dir . '/mqtt_praefixe_alt.json',
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
            'marke'  => '',
            'datadir' => sys_get_temp_dir() . '/' . $dir,
            'praefixe' => '',
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
                  'quelle' => $vorgaben ? 'ok' : 'keine_datenquelle', 'token_angelegt' => false);
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
                $lage['token_angelegt'] = true;
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
    /* LESE-REGEL endpunkt_ein (seit 1.2.18, E6/N1). Bis 1.2.17 gab es den Schalter
     * nicht, der Endpunkt war immer offen. Fehlt der Schluessel in einer VORHANDENEN
     * Datei, gilt er als 1 - fuer die Oberflaeche UND den Endpunkt (beide lesen hier).
     * Ohne diese Regel schriebe das Vervollstaendigen darunter die Vorgabe 0 in jede
     * Bestandsdatei, und nach dem ersten Seitenaufbau waere jede Anlage stumm
     * (gemessen: Entwurfsprobe probe_ep, Stand NUR_SPERRE). Ohne Datei gilt die
     * Vorgabe 0 (Neuinstallation). Der Schluessel bleibt "fehlend", damit er mit 1
     * hingeschrieben wird. */
    if (!isset($gesehen['endpunkt_ein']) && array_key_exists('endpunkt_ein', $vorgaben)) {
        $werte['endpunkt_ein'] = '1';
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
        $us_tok_neu = false;
        if (in_array('aktionstoken', $lage['fehlend'], true)) {
            $werte['aktionstoken'] = us_token_neu();
            $us_tok_neu = true;
        }
        if (us_config_write($werte)) {
            $lage['ergaenzt'] = $lage['fehlend'];
            $lage['fehlend'] = array();
            if ($us_tok_neu) {
                $lage['token_angelegt'] = true;
                us_zweitschrift_nachziehen();
            }
        }
    }
    /* EIN LEERES AKTIONSTOKEN WIRD BEIM SEITENAUFRUF ANGELEGT (seit 1.2.18, Hausherr
     * 25.10.2026). Bis 1.2.17 entstand es hier nur, wenn der Schluessel FEHLTE; ein
     * leerer Schluessel blieb leer ("bewusst geleert waechst nicht nach"), und jedes
     * Formular wurde abgewiesen, bis jemand "Neues Token erzeugen" fand (am Geraet
     * gemessen 25.10.2026). Leeren laesst sich das Token in der Oberflaeche nicht -
     * nur von Hand in der Datei -, also gibt es keinen gewollten leeren Zustand, den
     * diese Regel zerstoert. Ein VORHANDENES Token wird nie geaendert. postinstall.sh
     * legt es bei einer Neuinstallation schon an; dies ist der Rueckfall. Schreiben
     * atomar mit 0600 (us_config_write), die Zweitschrift zieht nach, der Hinweis kommt
     * genau bei diesem einen Seitenaufbau. */
    if ($erzeugen && array_key_exists('aktionstoken', $vorgaben)
        && trim((string) $werte['aktionstoken']) === '') {
        $werte['aktionstoken'] = us_token_neu();
        if (us_config_write($werte)) {
            $lage['token_angelegt'] = true;
            us_zweitschrift_nachziehen();
        } else {
            $werte['aktionstoken'] = '';
        }
    }
    return array($werte, $alt, $lage);
}

/**
 * Eingaben pruefen - EINE Stelle fuer BEIDE Wege.
 *
 * Bis 1.1.11 stand diese Pruefung ausschliesslich im Speichern-Zweig von
 * index.php. Das Zurueckspielen einer Sicherung ging vollstaendig daran
 * vorbei und schrieb roh durch (gemessen am 26.08.2026: "sensor=laserpistole",
 * "min_cm=9000 / max_cm=1" wurden angenommen).
 *
 * SEIT DEM DURCHGANG 02.10.2026 WIRD NICHTS MEHR ZURECHTGERUECKT
 * (Entscheidungen Nr. 16 und 19, Bauliste O2/O3). Bis 1.2.10 fiel ein
 * abgewiesenes Feld auf den WERKSWERT zurueck (aus "hcsr04" mit Tippfehler
 * wurde "srf02"), vertauschte Grenzen wurden still getauscht, gleiche
 * GPIO-Pins still auf 23/24 gesetzt, "keller/eins" still zu "kellereins"
 * und "leer_cm=abc" still zu "keine Kalibrierung" - und das Formular
 * speicherte den Rest trotzdem (gemessen, Oberflaechen-Pruefer 2/3/5).
 * Jetzt gilt: jede Unstimmigkeit ist eine Beanstandung, und beide Aufrufer
 * schreiben dann GAR NICHTS. Still bleibt nur, was den Wert nicht aendert:
 * Leerraum am Rand, Komma statt Punkt, die Schreibweise einer Zahl und
 * Kleinbuchstaben in Hexziffern.
 *
 * Rueckgabe: array($werte, $maengel, $falsch)
 *   $falsch  die Namen der beanstandeten Felder - fuer die Markierung im
 *            Formular (X-2, sm-beanstandet und aria-invalid)
 */
function us_pruefen($roh)
{
    $v = us_defaults();
    $m = array();
    $falsch = array();

    $hol = function ($k) use ($roh) {
        return isset($roh[$k]) && !is_array($roh[$k]) ? trim((string) $roh[$k]) : '';
    };
    /* Maskiert wird das ARGUMENT, nicht die Meldung. Die Beanstandung traegt
     * Auszeichnung und wird ROH in die Seite geschrieben; der beanstandete
     * Wert kommt aus dem Formular oder aus einer hochgeladenen Datei. */
    $ruege = function ($feld, $wert) use (&$m, &$falsch) {
        $m[] = sprintf(us_t('FEHLER.WERT_UNZULAESSIG'), us_e($feld),
                       us_e(substr((string) $wert, 0, 40)));
        $falsch[] = $feld;
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
        $v['sensor'] = $sensor;
    }

    /* --- Haken. Sie kommen als '1'/'0' herein, nicht als isset(). Aus dem
     * Formular kann nichts anderes kommen - dort werden die drei vorher
     * ausdruecklich umgesetzt. Aus einer Datei sehr wohl. */
    // endpunkt_ein seit 1.2.18 (E6) - ein Haken wie die drei anderen.
    foreach (array('enabled', 'mqtt', 'udp', 'endpunkt_ein') as $k) {
        $w = $hol($k);
        if ($w !== '0' && $w !== '1') {
            $ruege($k, $w);
        }
        $v[$k] = $w;
    }

    // --- Behaelterform (V1, seit 1.2.18) ---------------------------------------
    $form = $hol('behaelter_form');
    if (!array_key_exists($form, us_behaelterformen())) {
        $ruege('behaelter_form', $form);
    }
    $v['behaelter_form'] = $form;

    // --- Feste Temperatur (V2, seit 1.2.18): leer = aus, sonst -40 bis 60 -------
    $temp = str_replace(',', '.', $hol('temperatur_c'));
    if ($temp === '') {
        $v['temperatur_c'] = '';
    } elseif (is_numeric($temp) && (float) $temp >= -40 && (float) $temp <= 60) {
        $v['temperatur_c'] = $zahltext($temp);
    } else {
        $ruege('temperatur_c', $temp);
        $v['temperatur_c'] = $temp;
    }

    // --- Themenpraefix ------------------------------------------------------
    /* Erlaubt sind Buchstaben, Ziffern, Bindestrich und Unterstrich. Alles
     * andere - leer, Schraegstrich, Leerzeichen, Anfuehrungszeichen - ist eine
     * Beanstandung. Bis 1.2.10 wurde "keller/eins" still zu "kellereins" und
     * gespeichert: der Dienst veroeffentlichte danach unter einem Praefix, das
     * niemand eingegeben hatte, und in Loxone kam nichts mehr an. */
    $p = $hol('themenpraefix');
    if (preg_match('/^[A-Za-z0-9_-]+$/', $p) !== 1) {
        $ruege('themenpraefix', $p);
    }
    $v['themenpraefix'] = $p;

    // --- I2C-Adresse --------------------------------------------------------
    $adr = strtolower($hol('i2c_adresse'));
    if (preg_match('/^0x[0-9a-f]{1,2}$/', $adr) !== 1) {
        $ruege('i2c_adresse', $adr);
    }
    $v['i2c_adresse'] = $adr;

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
            $v[$k] = $w;
        }
    }

    // --- Kommazahlen. Komma statt Punkt kommt bei deutscher Tastatur
    //     staendig vor und ist kein Fehler. --------------------------------
    /* min_cm MINDESTENS 1 (Durchgang 02.10.2026, C3). Mit min_cm=0 ging ein
     * Rohwert 0 - kein Echo, Stoerung - als gueltige Messung "0 cm" hinaus,
     * mit Kalibrierung als "randvoll" (gemessen, Code-Pruefer C3). Der Dienst
     * verwirft eine 0 seither ohnehin; Formular und Sicherung beanstanden
     * die 0, damit niemand glaubt, sie wirke. */
    $kommas = array(
        'messabstand' => array(0.05, 5),
        'min_cm'      => array(1, 1000),
        'max_cm'      => array(1, 2000),
        'offset_cm'   => array(-500, 500),
    );
    foreach ($kommas as $k => $g) {
        $w = str_replace(',', '.', $hol($k));
        if ($w !== '' && is_numeric($w) && (float) $w >= $g[0] && (float) $w <= $g[1]) {
            $v[$k] = $zahltext($w);
        } else {
            $ruege($k, $w);
            $v[$k] = $w;
        }
    }

    // --- Felder, die leer bleiben duerfen: dann wird nicht umgerechnet. -----
    /* Ein unzulaessiger Wert LOESCHT die Kalibrierung nicht mehr (O3): bis
     * 1.2.10 wurde aus "leer_cm=abc" ein leeres Feld und gespeichert - die
     * Kalibrierung war weg. Das Gesamtvolumen muss groesser als 0 sein (C7):
     * mit 0 rechnete der Dienst "0 Liter", mit einer negativen Zahl aus einer
     * Datei negative Liter. */
    $leerbar = array(
        'leer_cm'       => array(0, 2000, false),
        'voll_cm'       => array(0, 2000, false),
        'volumen_liter' => array(0, 1000000, true),
    );
    foreach ($leerbar as $k => $g) {
        $w = str_replace(',', '.', $hol($k));
        if ($w === '') {
            $v[$k] = '';
        } elseif (is_numeric($w) && (float) $w >= $g[0] && (float) $w <= $g[1]
                  && (!$g[2] || (float) $w > 0)) {
            $v[$k] = $zahltext($w);
        } else {
            $ruege($k, $w);
            $v[$k] = $w;
        }
    }

    // --- UDP-Port -----------------------------------------------------------
    $port = $hol('udp_port');
    if ($port === '') {
        $v['udp_port'] = '';
    } elseif (preg_match('/^[0-9]{1,5}$/', $port)
              && (int) $port >= 1 && (int) $port <= 65535) {
        $v['udp_port'] = (string) (int) $port;
    } else {
        $ruege('udp_port', $port);
        $v['udp_port'] = $port;
    }

    /* --- Aktionstoken --------------------------------------------------
     *
     * Es MUSS hier stehen, auch wenn kein Formular es anzeigt: us_pruefen()
     * beginnt bei den Vorgaben, und was hier fehlt, faellt beim Speichern
     * auf die Vorgabe zurueck. Fuer das Token ist die Vorgabe leer - ohne
     * diesen Block hat jedes Speichern es geloescht (gemessen an 1.2.0).
     *
     * Leer ist hier zulaessig - so sieht es vor der ersten Einrichtung aus.
     * Ein leeres Token aus einer SICHERUNG kommt gar nicht erst hier an
     * (us_sicherung_lesen behaelt dann das geltende, O6). */
    $tok = strtolower($hol('aktionstoken'));
    if ($tok === '' || preg_match('/^[0-9a-f]{16,64}$/', $tok) === 1) {
        $v['aktionstoken'] = $tok;
    } else {
        $ruege('aktionstoken', $tok);
        $v['aktionstoken'] = $tok;
    }

    // --- Zusammenhaenge, die erst nach den Einzelwerten greifen -------------
    // Beurteilt wird nur, was einzeln in Ordnung war - sonst stuende zu einem
    // Feld zweimal etwas da.
    $gut = function ($k) use (&$falsch) {
        return !in_array($k, $falsch, true);
    };
    if ($gut('gpio_trigger') && $gut('gpio_echo') && $v['gpio_trigger'] === $v['gpio_echo']) {
        // Ein Pin kann nicht beides sein - sonst laeuft der Treiber ins Leere.
        $m[] = us_t('FEHLER.GPIO_GLEICH');
        $falsch[] = 'gpio_trigger';
        $falsch[] = 'gpio_echo';
    }
    if ($gut('min_cm') && $gut('max_cm') && (float) $v['min_cm'] >= (float) $v['max_cm']) {
        $m[] = us_t('FEHLER.MIN_MAX');
        $falsch[] = 'min_cm';
        $falsch[] = 'max_cm';
    }
    /* leer_cm und voll_cm duerfen nicht vertauscht sein (seit 1.2.2). Der
     * Sensor sitzt oben: bei leerem Behaelter ist der Abstand GROSS, bei
     * vollem klein. Welcher Wert bei leerem und welcher bei vollem Behaelter
     * gemessen wurde, weiss nur der Anwender - also nie tauschen. */
    if ($gut('leer_cm') && $gut('voll_cm') && $v['leer_cm'] !== '' && $v['voll_cm'] !== ''
        && (float) $v['leer_cm'] <= (float) $v['voll_cm']) {
        $m[] = us_t('FEHLER.LEER_VOLL');
        $falsch[] = 'leer_cm';
        $falsch[] = 'voll_cm';
    }
    if ($v['udp'] === '1' && $v['udp_port'] === '') {
        $m[] = us_t('FEHLER.UDP_OHNE_PORT');
        $falsch[] = 'udp_port';
    }

    return array($v, $m, array_values(array_unique($falsch)));
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
    /* KEIN ZEITSTEMPEL IM KOPF (seit 1.2.6).
     *
     * Hier stand eine Zeile mit Datum und Uhrzeit. Sie machte JEDES
     * Speichern zu einer Aenderung der Datei, auch wenn kein Wert
     * anders war - und Werkzeuge/wirkungstest.py, das jedes Formular
     * unveraendert absendet und die Konfiguration vergleicht, schlug
     * genau dann an, wenn zwischen seinen beiden Laeufen die Minute
     * umsprang. Eine Pruefung, die bei jedem Lauf anders ausgeht, ist
     * keine.
     *
     * Nebenbei schrieb die PHP-Seite damit DREI Kopfzeilen und
     * us_common.konfiguration_schreiben() ZWEI: dieselben Werte ergaben
     * je nach Schreiber verschiedene Bytes. Jetzt sind beide Koepfe
     * zeichengleich.
     *
     * Wann die Datei geschrieben wurde, sagt ihr Aenderungsdatum. */
    $txt = "; Ultraschall Entfernung\n; Geschrieben von der Plugin-Oberflaeche.\n"
         . "\n[ultraschall]\n";
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
     *
     * UND SEIT 1.2.6 SIND ES 0600, NICHT 0644.
     *
     * Wer das Aktionstoken lesen kann, kann den Endpunkt abfragen und -
     * weil das Formularmerkmal daraus abgeleitet wird - jedes Formular
     * dieser Oberflaeche absenden. Hausstandard seit 03.09.2026
     * (Regeln/05): eine Konfiguration, die Zugangsdaten traegt, hat
     * 0600. Am Geraet gemessen am 13.09.2026 stand sie auf -rw-r--r--,
     * waehrend die Zweitschrift DANEBEN mit 0600 richtig lag: die Kopie
     * war geschuetzt, das Original nicht.
     *
     * Lesen kann sie weiterhin, wer sie lesen muss: Oberflaeche,
     * Endpunkt und Dienst laufen alle als loxberry.
     */
    $tmp = $file . '.tmp.' . getmypid();
    $fh = @fopen($tmp, 'c');
    if ($fh === false) {
        return false;
    }
    @chmod($tmp, 0600);
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

/**
 * Die Zweitschrift <ordner>.backup.ultraschall.cfg nachziehen (seit 1.2.18), wenn es
 * sie gibt - nach dem Anlegen eines Aktionstokens. Angelegt wird keine: sie entsteht in
 * preupgrade.sh aus der laufenden Datei. Atomar: Nebendatei .neu, 0600 vor dem Inhalt,
 * Laenge verglichen, rename. Rueckgabe: nachgezogen.
 */
function us_zweitschrift_nachziehen()
{
    $p = us_paths();
    if ($p['home'] === '') {
        return false;
    }
    $z = $p['home'] . '/config/plugins/' . $p['plugin'] . '.backup.ultraschall.cfg';
    if (!is_file($z)) {
        return false;
    }
    $inhalt = is_file($p['config']) ? (string) @file_get_contents($p['config']) : '';
    if ($inhalt === '') {
        return false;
    }
    $tmp = $z . '.neu';
    $fh = @fopen($tmp, 'c');
    if ($fh === false) {
        return false;
    }
    @chmod($tmp, 0600);
    $ok = (@ftruncate($fh, 0) !== false) && (@fwrite($fh, $inhalt) === strlen($inhalt));
    @fclose($fh);
    if (!$ok || !@rename($tmp, $z)) {
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

/**
 * Ab wann ein Wert nicht mehr frisch ist - 3 x (Takt + Messdauer).
 *
 * Entscheidung Nr. 4: OK=0 ab dem Dreifachen des Takts. Bis 1.2.10 stand hier
 * max(180, 3 x Takt): bei Takt 5 s galt ein Wert zweieinhalb Minuten lang als
 * frisch (gemessen, Code-Pruefer C7, MQTT-Pruefer M6). Die feste Untergrenze
 * sollte verhindern, dass ein langsamer Durchgang auslost - das bildet die
 * Messdauer ab, (messungen - 1) x messabstand, gedeckelt wie im Dienst
 * (us_common.messplan, 180 s). Durchgang 02.10.2026, C5.
 */
function us_ok_grenze($cfg)
{
    $takt = max(5, (int) us_cfg($cfg, 'intervall', '60'));
    $n = max(1, (int) us_cfg($cfg, 'messungen', '5'));
    $abstand = max(0.05, (float) str_replace(',', '.', us_cfg($cfg, 'messabstand', '0.2')));
    $dauer = min(180.0, ($n - 1) * $abstand);
    return (int) ceil(3 * ($takt + $dauer));
}

/** Alter der letzten gelungenen Messung OHNE Kappung bei 0 - negativ heisst
 *  "Zeitstempel in der Zukunft". null = keine Zustandsdatei oder kein zeit. */
function us_status_alter_roh()
{
    $s = us_status();
    if (!$s || !isset($s['zeit']) || !is_numeric($s['zeit'])) {
        return null;
    }
    return time() - (int) $s['zeit'];
}

/** Alter des Herzschlags (Dienst lebt und hat einen Durchgang beendet). */
function us_herzschlag_alter()
{
    $s = us_status();
    if (!$s || !isset($s['herzschlag']) || !is_numeric($s['herzschlag'])) {
        return null;
    }
    return time() - (int) $s['herzschlag'];
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
 * Gehoert die PID unserem Messdienst? - ARGUMENTWEISE (seit 1.2.8).
 *
 * /proc/<pid>/cmdline trennt die Argumente mit Nullbytes. Ein Treffer hat
 * GENAU zwei Argumente: argv[0] ist ein Python-Interpreter (der Dienst
 * startet ueber seinen Shebang "#!/usr/bin/env python3", der Kernel setzt
 * dann "python3" an die erste Stelle), argv[1] ist Zeichen fuer Zeichen der
 * volle Pfad des Skripts dieser Installation. Dieselbe Bauart wie
 * daemon/daemon (us_ist_dienst dort) und Regeln/06.
 *
 * Bis 1.2.7 genuegte "argv[0] ODER argv[1] ist der Skriptpfad". Damit galt
 * "tail <skript> -f" oder ein Prozess, der sich mit exec -a den Skriptpfad
 * als Namen gibt, als Dienst - und der Knopf "Dienst anhalten" beendete
 * ihn (gemessen in WSL, Pruefung-Ultraschall-1.2.8, Faelle H1a/H1b). Ein
 * Einmallauf mit Schalter ("--mqtt-leeren") ist ebenfalls kein Dienst.
 *
 * Der Benutzer wird nicht geprueft: die Oberflaeche laeuft als loxberry und
 * kann ohnehin nur eigene Prozesse beenden; fuer "laeuft schon einer?" ist
 * ein Dienst unter anderem Benutzer trotzdem ein Dienst (wie daemon/daemon).
 */
function us_ist_dienst($pid, $skript)
{
    $datei = '/proc/' . (int) $pid . '/cmdline';
    $roh = is_file($datei) ? @file_get_contents($datei) : false;
    if ($roh === false || $roh === '') {
        return false;
    }
    $args = explode("\0", rtrim($roh, "\0"));
    if (count($args) !== 2 || $args[1] !== $skript) {
        return false;
    }
    return preg_match('#(^|/)python(3(\.[0-9]+)?)?$#', $args[0]) === 1;
}

/**
 * ALLE laufenden Dienste dieser Installation, aufsteigend (seit 1.2.8).
 *
 * Zwei Quellen: die PID-Datei, die der Dienst selbst schreibt, und /proc
 * argumentweise - die zweite findet auch einen Dienst OHNE PID-Datei (ein
 * zweiter Start ueberschreibt sie). Bis 1.2.7 kam hier nur der ERSTE
 * Treffer zurueck, und "Dienst anhalten" liess einen zweiten laufen
 * (gemessen: zwei Dienste, danach einer - Fall H5).
 *
 * Bis 1.1.0 stand hier "pgrep -o -f ultraschall.py". Das durchsucht die
 * ganze Befehlszeile jedes Prozesses und trifft damit auch einen Editor,
 * in dem die Datei offen ist, oder ein zweites Exemplar des Plugins.
 */
function us_dienst_pids()
{
    $p = us_paths();
    $skript = $p['bindir'] . '/ultraschall.py';
    $gefunden = array();

    // Erst fragen, dann oeffnen. Das @ unterdrueckt die Anzeige, nicht
    // einen mit set_error_handler() eingehaengten Aufnehmer - und genau
    // so haengt sich rendern.py ein. Vor dem ersten Dienststart gibt es
    // die PID-Datei nicht; das ist der Normalfall und keine Meldung wert.
    $pid = is_file($p['pid']) ? (int) @file_get_contents($p['pid']) : 0;
    if ($pid > 0 && us_ist_dienst($pid, $skript)) {
        $gefunden[$pid] = true;
    }

    // /proc gibt es nur auf Linux. Auf einem Pruefstand unter Windows
    // stuenden hier sonst drei Warnungen je Seitenaufruf.
    if (is_dir('/proc')) {
        foreach ((array) @scandir('/proc') as $eintrag) {
            if (preg_match('/^[0-9]+$/', (string) $eintrag) === 1
                && us_ist_dienst((int) $eintrag, $skript)) {
                $gefunden[(int) $eintrag] = true;
            }
        }
    }
    $aus = array_keys($gefunden);
    sort($aus);
    return $aus;
}

/** PID eines laufenden Dienstes, 0 wenn keiner laeuft. Fuer die Frage
 * "laeuft einer?" genuegt die PID-Datei, wenn sie stimmt; sonst /proc. */
function us_dienst_pid()
{
    $p = us_paths();
    $pid = is_file($p['pid']) ? (int) @file_get_contents($p['pid']) : 0;
    if ($pid > 0 && us_ist_dienst($pid, $p['bindir'] . '/ultraschall.py')) {
        return $pid;
    }
    $alle = us_dienst_pids();
    return $alle ? (int) $alle[0] : 0;
}

/**
 * Laeuft gerade eine Aktualisierung dieses Plugins? (seit 1.2.7)
 *
 * Rueckgabe: Alter der Marke in Sekunden, wenn sie GILT; sonst -1.
 *
 * Es gilt nur eine Marke, die hoechstens 3600 s alt ist. Aelter, unlesbar,
 * leer oder aus der Zukunft: sie gilt nicht - eine abgebrochene
 * Installation darf den Dienst nicht fuer immer stilllegen. Ohne
 * LoxBerry-Wurzel gibt es keinen Ablageort; dann wird nicht geurteilt.
 */
function us_marke_alter()
{
    $f = us_paths()['marke'];
    if ($f === '' || !is_file($f)) {
        return -1;
    }
    $roh = trim((string) @file_get_contents($f));
    if ($roh === '' || preg_match('/^[0-9]+$/', $roh) !== 1) {
        return -1;
    }
    $alter = time() - (int) $roh;
    /* U15 (seit 1.2.18): -300 Toleranz - springt die Uhr waehrend der Aktualisierung
     * zurueck (NTP), gilt eine "leicht zukuenftige" Marke weiter. Und max(0, ...) (N8):
     * die Aufrufer pruefen ">= 0"; ein negatives Alter hiesse dort wieder "gilt nicht". */
    if ($alter < -300 || $alter >= 3600) {
        return -1;
    }
    return max(0, $alter);
}

/** Dienst starten, stoppen, neu starten. */
function us_dienst($aktion)
{
    $p = us_paths();
    $skript = $p['bindir'] . '/ultraschall.py';
    $meldungen = array();
    /* NUR AUS DER INSTALLATION (seit 1.2.8).
     *
     * Ohne LoxBerry-Wurzel ist das hier ein ausgepacktes Archiv oder eine
     * Kopie. Bis 1.2.7 startete der Knopf dann den ARCHIVcode - mit
     * unersetzten REPLACE-Marken, also mit dem Ramdisk-Ordner
     * /run/shm/ultraschall einer laufenden Installation und ihrer
     * PID-Datei, die "stop" obendrein loeschte (gemessen in WSL,
     * Pruefung-Ultraschall-1.2.8, Fall H4). Was Prozesse startet oder
     * Signale schickt, laeuft nur aus der Installation. */
    if ($p['home'] === '') {
        return us_t('DIENST.KEINE_WURZEL');
    }
    if (in_array($aktion, array('stop', 'restart'), true)) {
        // Gezielt die eigenen PIDs beenden statt "pkill -f ultraschall.py" -
        // das haette bei zwei Exemplaren des Plugins beide erwischt.
        //
        // ALLE eigenen Dienste, nicht nur den ersten (seit 1.2.8), und vor
        // dem harten Signal NEU gesucht: zwischen den beiden Signalen kann
        // ein Prozess enden und seine Nummer neu vergeben werden.
        $ziel = us_dienst_pids();
        if ($ziel) {
            foreach ($ziel as $pid) {
                @exec('kill ' . (int) $pid . ' 2>&1', $meldungen);
            }
            for ($i = 0; $i < 10 && array_intersect($ziel, us_dienst_pids()); $i++) {
                sleep(1);
            }
            $rest = array_intersect($ziel, us_dienst_pids());
            foreach ($rest as $pid) {
                @exec('kill -9 ' . (int) $pid . ' 2>&1', $meldungen);
            }
            if ($rest) {
                sleep(1);
            }
            $meldungen[] = sprintf(us_t('DIENST.ANGEHALTEN'), implode(', ', $ziel));
            $uebrig = us_dienst_pids();
            if ($uebrig) {
                $meldungen[] = sprintf(us_t('DIENST.LAEUFT_WEITER'), implode(', ', $uebrig));
            }
        } else {
            $meldungen[] = us_t('DIENST.LIEF_NICHT');
        }
        @unlink($p['pid']);
    }
    if (in_array($aktion, array('start', 'restart'), true)) {
        if (!is_file($skript)) {
            return sprintf(us_t('DIENST.NICHT_GEFUNDEN'), $skript);
        }
        /* EINE STARTSPERRE FUER ALLE STARTWEGE (Durchgang 02.10.2026, I3).
         *
         * Gemessen in WSL (Installer-Pruefer D1/D2): zwei Waechterlaeufe in
         * derselben Sekunde, oder daemon und Waechter zugleich, ergaben in
         * 10 von 10 Runden ZWEI Dienste - Pruefen und Starten geschahen ohne
         * Sperre. Waechter, daemon, postinstall.sh und dieser Knopf sperren
         * jetzt alle auf dieselbe Datei (flock auf ultraschall.py) und fragen
         * erst DANACH, ob schon einer laeuft. Gewartet wird wie dort bis 15 s.
         *
         * Die Sperre wird NICHT an den Dienst vererbt: ein von PHP geoeffneter
         * Deskriptor geht an jedes Kind (Gedaechtnis "Sperre vererbt sich an
         * Kinder"), und ein Dienst, der sie haelt, sperrte jeden spaeteren
         * Start fuer immer. Der Start schliesst deshalb genau diesen
         * Deskriptor, und hinterher wird ausdruecklich entsperrt. */
        $sperre = @fopen($skript, 'r');
        $gesperrt = false;
        if ($sperre !== false) {
            for ($i = 0; $i < 150 && !$gesperrt; $i++) {
                if (@flock($sperre, LOCK_EX | LOCK_NB)) {
                    $gesperrt = true;
                } else {
                    usleep(100000);
                }
            }
            if (!$gesperrt) {
                @fclose($sperre);
                $meldungen[] = us_t('DIENST.SPERRE');
                return implode("\n", $meldungen);
            }
        }
        $us_ende = function () use (&$sperre, $gesperrt) {
            if ($sperre !== false) {
                if ($gesperrt) {
                    @flock($sperre, LOCK_UN);
                }
                @fclose($sperre);
                $sperre = false;
            }
        };
        /* ZWEI FRAGEN VOR DEM START (seit 1.2.7).
         *
         * Bis 1.2.6 startete diese Stelle bedingungslos. Gemessen in WSL am
         * 18.09.2026 (Pruefung-Ultraschall-1.2.7, Faelle P1/P2):
         *   ein Dienst laeuft, dann us_dienst('start') -> 2 Prozesse
         *   frische Upgrade-Marke, us_dienst('start')  -> 1 Prozess
         *
         * 1. Laeuft gerade eine Aktualisierung? Der Installer raeumt in
         *    dieser Zeit config/plugins/<ordner>/ und data/plugins/<ordner>/
         *    ab; ein hier gestarteter Dienst laeuft mit der mitgelieferten
         *    Vorgabe-Konfiguration weiter, und postupgrade.sh weiss nichts
         *    von ihm. Die Oberflaeche wird deshalb NICHT gesperrt (dafuer
         *    gibt es in dieser Linie keinen gemessenen Schaden) - aber
         *    gestartet wird nicht. */
        $alter = us_marke_alter();
        if ($alter >= 0) {
            $us_ende();
            $meldungen[] = sprintf(us_t('DIENST.MARKE'), $alter);
            return implode("\n", $meldungen);
        }
        /* 2. Laeuft schon einer? us_dienst_pid() sieht argumentweise nach -
         *    erst in der PID-Datei, dann ueber /proc. Ohne diese Frage legte
         *    ein zweiter Aufruf einen zweiten Dienst an; beide schrieben
         *    dieselbe Zustandsdatei und dieselben MQTT-Themen. */
        $schon = us_dienst_pid();
        if ($schon > 0) {
            $us_ende();
            $meldungen[] = sprintf(us_t('DIENST.LAEUFT_BEREITS'), $schon);
            return implode("\n", $meldungen);
        }
        /* EIGENE STARTDATEI, NICHT DAS PROTOKOLL (seit 1.2.6).
         *
         * Bis 1.2.5 ging die Ausgabe des Dienstes mit ">>" in
         * ultraschall.log - in dieselbe Datei, die der Dienst selbst
         * ueber seinen Handler fuehrt. Wird log/plugins geleert
         * (Ramdisk) oder raeumt die Logwartung auf, schreibt dieser von
         * der SCHALE gehaltene Deskriptor bis zum Prozessende in einen
         * geloeschten Inode; der Handler im Programm faengt sich wieder,
         * die Umleitung nie. Am Geraet gemessen am 13.09.2026: drei
         * Deskriptoren des laufenden Dienstes zeigten auf eine
         * geloeschte Datei.
         *
         * ">" statt ">>": die Datei faengt auf, was VOR dem Protokoll
         * passiert, und wird bei jedem Start geleert. */
        $log = $p['logdir'] . '/ultraschall_start.log';
        $fd = ($sperre !== false) ? us_eigener_deskriptor($skript) : null;
        $befehl = 'nohup ' . escapeshellarg($skript) . ' > ' . escapeshellarg($log)
            . ' 2>&1' . ($fd !== null ? ' ' . $fd . '<&-' : '') . ' &';
        $aus = array();
        if (is_file('/bin/bash')) {
            // bash, nicht sh: dash kennt nur einstellige Deskriptornummern.
            @exec('/bin/bash -c ' . escapeshellarg($befehl) . ' && echo ok', $aus);
        } else {
            @exec($befehl . ' echo ok', $aus);
        }
        if ($aus) {
            $meldungen[] = us_t('DIENST.GESTARTET');
        }
        sleep(3);
        $us_ende();
    }
    return implode("\n", $meldungen);
}

/**
 * Die Nummer des Deskriptors, unter dem DIESER Prozess $pfad offen hat
 * (I3) - ueber /proc/self/fd. null, wenn es nicht genau einer ist oder es
 * /proc nicht gibt; dann bleibt es beim ausdruecklichen Entsperren.
 */
function us_eigener_deskriptor($pfad)
{
    $ziel = @realpath($pfad);
    if ($ziel === false || !is_dir('/proc/self/fd')) {
        return null;
    }
    $treffer = array();
    foreach ((array) @scandir('/proc/self/fd') as $e) {
        if (preg_match('/^[0-9]+$/', (string) $e) !== 1 || (int) $e < 3) {
            continue;
        }
        if (@readlink('/proc/self/fd/' . $e) === $ziel) {
            $treffer[] = (int) $e;
        }
    }
    return count($treffer) === 1 ? $treffer[0] : null;
}

/**
 * Ein frueheres Themenpraefix vormerken (Durchgang 02.10.2026, M3).
 *
 * Wer das Praefix in der Oberflaeche aendert, waehrend der Dienst nicht
 * laeuft oder MQTT aus ist, laesst unter dem alten online=0 zurueckbehalten
 * stehen. Der Dienst raeumt vorgemerkte Praefixe ab, sobald er verbunden
 * ist; die Deinstallation leert alle. Dieselbe Datei schreibt
 * bin/us_common.py (praefix_vormerken). Rueckgabe: steht danach in der Liste.
 */
function us_praefix_vormerken($praefix)
{
    $f = us_paths()['praefixe'];
    $praefix = (string) $praefix;
    if ($f === '' || preg_match('/^[A-Za-z0-9_-]+$/', $praefix) !== 1) {
        return false;
    }
    $liste = array();
    if (is_file($f)) {
        $j = @json_decode((string) @file_get_contents($f), true);
        if (is_array($j)) {
            foreach ($j as $x) {
                if (is_string($x) && preg_match('/^[A-Za-z0-9_-]+$/', $x) === 1
                    && !in_array($x, $liste, true)) {
                    $liste[] = $x;
                }
            }
        }
    }
    if (in_array($praefix, $liste, true)) {
        return true;
    }
    $liste[] = $praefix;
    $tmp = $f . '.tmp.' . getmypid();
    $js = json_encode($liste);
    if ($js === false || @file_put_contents($tmp, $js) !== strlen($js)) {
        @unlink($tmp);
        return false;
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $f)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/* ==================================================================
 * Einmalmeldung nach einem POST (Durchgang 02.10.2026, O1)
 *
 * Hausstandard (Regeln/04, Raumklima 0.11.8): jeder POST-Handler endet mit
 * 303 und exit; das Ergebnis reist in data/plugins/<ordner>/
 * einmalmeldung.json, 0600, wird NUR beim GET gelesen, dabei geloescht und
 * nach 120 s verworfen. Bis 1.2.10 wurde nach jedem POST gerendert: F5
 * speicherte erneut, startete den Dienst erneut neu und meldete nach
 * "Neues Token" einen fremden Absender (gemessen, Oberflaechen-Pruefer 1).
 * Keine Zugangsdaten: das Aktionstoken reist nie mit.
 * ================================================================== */
function us_flash_datei()
{
    return us_paths()['datadir'] . '/einmalmeldung.json';
}

function us_flash_schreiben($daten)
{
    $f = us_flash_datei();
    if (!is_dir(dirname($f))) {
        @mkdir(dirname($f), 0775, true);
    }
    if (isset($daten['eingaben']['werte']) && is_array($daten['eingaben']['werte'])) {
        unset($daten['eingaben']['werte']['aktionstoken']);
    }
    $daten['ts'] = time();
    $js = json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($js === false) {
        return false;
    }
    $tmp = $f . '.tmp.' . getmypid();
    $fh = @fopen($tmp, 'c');
    if ($fh === false) {
        return false;
    }
    @chmod($tmp, 0600);
    $ok = (@ftruncate($fh, 0) !== false) && (@fwrite($fh, $js) === strlen($js));
    @fclose($fh);
    if (!$ok || !@rename($tmp, $f)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/** Liest die Einmalmeldung und LOESCHT sie. null = keine oder zu alt. */
function us_flash_lesen()
{
    $f = us_flash_datei();
    if (!is_file($f)) {
        return null;
    }
    $d = @json_decode((string) @file_get_contents($f), true);
    @unlink($f);
    if (!is_array($d) || !isset($d['ts'])) {
        return null;
    }
    $alter = time() - (int) $d['ts'];
    if ($alter < -5 || $alter > 120) {
        return null;
    }
    return $d;
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
        /* Drittes Glied seit 1.2.6: geht das Thema zurueckbehalten
         * hinaus? Die Angabe kommt aus derselben Datei, aus der der
         * Dienst seine Entscheidung liest - eine zweite Liste in der
         * Oberflaeche waere die zweite Wahrheit. */
        // Themen mit "/" (status/zaehler, seit 1.2.18) haben Schluessel mit "_".
        $aus[$name] = array(us_t('THEMA.' . strtoupper(str_replace('/', '_', $name))), $f['art'],
                            !empty($f['retain']));
    }
    return $aus;
}

/** Der lange Erklaertext zu einem Thema - fuer die Tabelle, nicht fuer Loxone. */
function us_thema_lang($name)
{
    return us_t('THEMA_LANG.' . strtoupper(str_replace('/', '_', $name)));
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
        'srf02'  => us_t('TEXT.SENSOR_SRF02'),
        'hcsr04' => us_t('TEXT.SENSOR_HCSR04'),
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
    if (!is_numeric(trim($leer)) || !is_numeric(trim($voll))) {
        return array(null, null);
    }
    $leer = (float) $leer;
    $voll = (float) $voll;
    /* DIESELBE RECHNUNG WIE PYTHON (Durchgang 02.10.2026, C4). Bis 1.2.10
     * fing diese Seite nur "leer == voll" ab; bei vertauschten Grenzen zeigte
     * der Reiter Test einen rueckwaerts laufenden Fuellstand (20/100 bei
     * 25 cm: 6,3 %), waehrend der Dienst keinen rechnete. Der Sensor sitzt
     * oben: leer heisst grosser Abstand. Kein Wert, und der Reiter Test sagt
     * warum (us_fuellstand_hinweis). */
    if (!is_finite($leer) || !is_finite($voll) || $leer - $voll < 0.001) {
        return array(null, null);
    }
    /* V1 (seit 1.2.18): die Behaelterform rechnet den Hoehenanteil in einen
     * VOLUMENanteil um - dieselbe Rechnung wie us_common.fuellstand(). Eine
     * unbekannte Form ergibt keinen Wert. */
    $form = trim(us_cfg($cfg, 'behaelter_form', 'linear'));
    if (!array_key_exists($form, us_behaelterformen())) {
        return array(null, null);
    }
    $anteil = ($leer - $entfernung) / ($leer - $voll);
    if ($form === 'linear') {
        $prozent = max(0.0, min(100.0, $anteil * 100.0));
    } else {
        $prozent = max(0.0, min(100.0, us_form_anteil($anteil, $form) * 100.0));
    }
    $liter = null;
    $vol = trim(us_roh($cfg, 'volumen_liter'));
    // Gesamtvolumen nur, wenn groesser als 0 (C7) - wie Python.
    if ($vol !== '' && is_numeric($vol) && (float) $vol > 0) {
        $liter = round((float) $vol * $prozent / 100.0, 1);
    }
    return array(round($prozent, 1), $liter);
}

/** Warum kein Fuellstand gerechnet wird, obwohl beide Grenzen eingetragen
 *  sind - '' sonst (C4). */
function us_fuellstand_hinweis($cfg)
{
    $leer = trim(us_roh($cfg, 'leer_cm'));
    $voll = trim(us_roh($cfg, 'voll_cm'));
    if ($leer === '' || $voll === '') {
        return '';
    }
    if (!is_numeric($leer) || !is_numeric($voll)) {
        return us_t('TEST.FUELL_UNLESBAR');
    }
    if ((float) $leer - (float) $voll < 0.001) {
        return sprintf(us_t('TEST.FUELL_VERTAUSCHT'), $leer, $voll);
    }
    return '';
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
    /* SEIT 1.2.18 NUR NOCH DIE UDP-VORLAGE (E1). Die MQTT-Vorlage war ein
     * VirtualInHttp mit http://localhost und einer Abfragezeit von einer Woche -
     * der verbotene Kunstgriff (R2_078; Gedaechtnis gateway-werte-virtueller-
     * eingang). An ihre Stelle tritt die Namenstabelle (us_mqtt_namen) im Reiter
     * Einbindung: einfache Virtuelle Eingaenge mit dem Namen, den das Gateway
     * vergibt. Rueckgabe null fuer jede andere Art. */
    if ($art !== 'udp_in') {
        return null;
    }
    $fuss = us_t('VORLAGE.KOPF');
    // Der UDP-Weg der Originalfassung: ein einziger Wert, die Entfernung
    // in cm, ohne Namen davor. Deshalb nur ein Eintrag - und Address ist
    // hier der ABSENDER, also der LoxBerry.
    $port = us_roh($cfg, 'udp_port');
    $f = us_felder();
    $d = isset($f['distance']) ? $f['distance'] : array('min' => 0, 'max' => 2000,
                                                        'einheit' => 'cm', 'art' => 'analog');
    /* 1.2.15 (Hausherr 13.10.2026): eigener Geraetetitel "Ultraschall Entfernung UDP".
     * Fest im Code, nicht ueber die Sprachdatei: Titel werden in Config zu Namen
     * (Regeln/07, Sprache einer Loxone-Vorlage). Die Untergrenze bleibt 0: auf dem
     * UDP-Weg geht nur ein gueltiger Abstand hinaus, nie -1. */
    return array('ultraschall_udp.xml', us_xml_rumpf('VirtualInUdp', '1', array(
        'title'   => 'Ultraschall Entfernung UDP',
        'address' => '',
        'port'    => $port !== '' ? $port : '0',
        'comment' => $fuss,
    ), array(array(
        'title'   => 'Ultraschall_Entfernung',
        'comment' => us_t('THEMA.DISTANCE'),
        'check'   => '\\v',
        'art'     => 'analog',
        'min'     => 0,
        'max'     => $d['max'],
        'einheit' => $d['einheit'],
    )), true));
}

/* ==================================================================
 * Namenstabelle und Gateway V2 (seit 1.2.18, Gruppe 17, E1)
 *
 * Das MQTT-Gateway legt fuer jedes Thema einen Datenpunkt an, dessen Name aus
 * dem Thema entsteht: "/" (und "%") werden zu "_" (Gedaechtnis
 * mqtt-gateway-v2-verhalten, build_vi_name). In Loxone ist das der Name eines
 * einfachen Virtuellen Eingangs - KEIN VirtualInHttp mit http://localhost (das
 * war bis 1.2.17 die Vorlage "Eingaenge (MQTT)", der verbotene Kunstgriff,
 * R2_078). Alles kommt aus der Feldtabelle bin/us_vorgaben.json: Fehlwert,
 * Haken fuer Gateway V2, Nummer im Musterprojekt, Vermerk "Bestand".
 * ================================================================== */

/** Der Name, den das MQTT-Gateway einem Thema gibt - an EINER Stelle. */
function us_gateway_name($praefix, $thema)
{
    return str_replace(array('/', '%'), '_', (string) $praefix . '_' . (string) $thema);
}

/** Die Namenstabelle: je Thema der Feldtabelle eine Zeile. */
function us_mqtt_namen($cfg)
{
    $praefix = us_cfg($cfg, 'themenpraefix', 'ultraschall');
    $aus = array();
    foreach (us_felder() as $thema => $f) {
        $aus[] = array(
            'name'     => us_gateway_name($praefix, $thema),
            'thema'    => $praefix . '/' . $thema,
            'kurz'     => (string) $thema,
            'text'     => ($f['art'] === 'text'),
            'einheit'  => (string) $f['einheit'],
            'min'      => $f['min'],
            'max'      => $f['max'],
            'fehlwert' => array_key_exists('fehlwert', $f) && $f['fehlwert'] !== null ? (string) $f['fehlwert'] : null,
            'retain'   => !empty($f['retain']),
            'v2'       => isset($f['v2']) ? (string) $f['v2'] : '',
            'muster'   => isset($f['muster']) ? (int) $f['muster'] : 0,
            'bestand'  => !empty($f['bestand']),
        );
    }
    return $aus;
}

/** Die Haken fuer Gateway V2 im Musterprojekt (haken, haken_n = mit Noncached). */
function us_v2_haken($cfg)
{
    $aus = array();
    foreach (us_mqtt_namen($cfg) as $z) {
        if ($z['v2'] === 'haken' || $z['v2'] === 'haken_n') {
            $aus[] = $z;
        }
    }
    return $aus;
}

/**
 * Nachtwelle N3 (1.2.19): die angehakten Datenpunkte aus config/system/subscriptions.json des MQTT-Gateways V2
 * (Subscriptions[].Id je Thema, Noncached; Form wie Govee gv_mqtt_v2_haken_lage(), MGiSmart mg_v2_abos()). Rein.
 * Rueckgabe array(Thema => Noncached bool), null = keine JSON-Daten. Ohne Liste in der Datei: leer (nichts angehakt).
 */
function us_v2_abos($daten)
{
    if (!is_array($daten)) { return null; }
    $liste = array();
    foreach (array('Subscriptions', 'subscriptions') as $s) {
        if (isset($daten[$s]) && is_array($daten[$s])) { $liste = $daten[$s]; break; }
    }
    $abo = array();
    foreach ($liste as $e) {
        if (!is_array($e)) { continue; }
        $id = '';
        foreach (array('Id', 'id', 'Topic', 'topic') as $k) {
            if (isset($e[$k]) && is_string($e[$k])) { $id = trim($e[$k]); break; }
        }
        if ($id === '') { continue; }
        $nc = false;
        foreach (array('Noncached', 'noncached') as $k) {
            if (isset($e[$k]) && is_scalar($e[$k])) {
                $nc = ($e[$k] === true || in_array(strtolower(trim((string) $e[$k])), array('1', 'true', 'yes', 'on'), true));
                break;
            }
        }
        $abo[$id] = !empty($abo[$id]) || $nc;
    }
    return $abo;
}

/**
 * Nachtwelle N3 (1.2.19): Soll gegen Ist (Rein). Soll ist die Namenstabelle aus us_mqtt_namen() (Feldtabelle
 * bin/us_vorgaben.json, Spalte v2) - dieselbe Quelle wie die Hakenliste us_v2_haken(): haken und haken_n muessen
 * angehakt sein, haken_n mit "Noncached"; n (wer es nimmt, setzt Noncached) zaehlt nur, wenn es angehakt ist und
 * "Noncached" fehlt.
 * Traegt ein Datenpunkt ohne Noncached-Bedarf "Noncached", steht er in nc_zuviel[] und ist ein Fehler
 * (Hausform wie MGiSmart 1.1.28 mg_v2_haken_abgleich(), Vereinheitlichung 29.10.2026).
 * Rueckgabe array(ok bool, fehlt[], ohne_nc[], angehakt, soll, davon Noncached, nc_zuviel[]).
 */
function us_v2_abgleich(array $namen, array $abo)
{
    $fehlt = array();
    $ohne = array();
    $zuviel = array();
    $da = 0;
    $soll = 0;
    $nc = 0;
    foreach ($namen as $z) {
        $v2 = isset($z['v2']) ? (string) $z['v2'] : '';
        if ($v2 === 'n') {
            if (array_key_exists($z['thema'], $abo) && !$abo[$z['thema']]) { $ohne[] = $z['thema']; }
            continue;
        }
        if ($v2 !== 'haken' && $v2 !== 'haken_n') { continue; }
        $soll++;
        if ($v2 === 'haken_n') { $nc++; }
        if (!array_key_exists($z['thema'], $abo)) { $fehlt[] = $z['thema']; continue; }
        $da++;
        if ($v2 === 'haken_n' && !$abo[$z['thema']]) { $ohne[] = $z['thema']; }
        elseif ($v2 === 'haken' && $abo[$z['thema']]) { $zuviel[] = $z['thema']; }
    }
    return array(!$fehlt && !$ohne && !$zuviel, $fehlt, $ohne, $da, $soll, $nc, $zuviel);
}

/**
 * Nachtwelle N3 (1.2.19): die Zeile "V2-Haken" im Reiter Test (Zustand des Dienstes, MQTT-Gateway). Liest nur
 * general.json (us_mqtt_gateway_info()) und config/system/subscriptions.json. Rueckgabe array(1|0|2, Text): 1 alle
 * Datenpunkte der Hakenliste angehakt und "Noncached", wo es noetig ist; 0 es fehlt etwas (die Themen werden genannt);
 * 2 trifft nicht zu (Gateway V1, MQTT aus) oder nicht feststellbar.
 */
function us_v2_haken_lage($cfg)
{
    $g = us_mqtt_gateway_info();
    if ($g === null || (int) $g['fassung'] === 0) { return array(2, us_t('TEST.V2_UNKLAR')); }
    if ((int) $g['fassung'] < 2) { return array(2, us_t('TEST.V2_V1')); }
    if (us_cfg($cfg, 'mqtt', '1') !== '1') { return array(2, us_t('TEST.V2_MQTT_AUS')); }
    $roh = @file_get_contents(us_paths()['home'] . '/config/system/subscriptions.json');
    $abo = ($roh === false || trim($roh) === '') ? null : us_v2_abos(json_decode($roh, true));
    if ($abo === null) { return array(2, us_t('TEST.V2_UNKLAR_DATEI')); }
    list($ok, $fehlt, $ohne, $da, $soll, $nc, $zuviel) = us_v2_abgleich(us_mqtt_namen($cfg), $abo);
    if ($soll === 0) { return array(2, us_t('TEST.V2_LEER')); }   // leere Hakenliste: kein Urteil
    if ($ok) { return array(1, sprintf(us_t('TEST.V2_OK'), $soll, $nc)); }
    return array(0, sprintf(us_t('TEST.V2_FEHLT'), $da, $soll, $fehlt ? implode(', ', $fehlt) : '-',
                            $ohne ? implode(', ', $ohne) : '-')
        . ($zuviel ? '; ' . sprintf(us_t('TEST.V2_NC_ZUVIEL'), implode(', ', $zuviel)) : ''));
}

/** Die Behaelterformen (V1, seit 1.2.18) - Schluessel wie in us_common.FORMEN. */
function us_behaelterformen()
{
    return array(
        'linear'           => us_t('EINST.FORM_LINEAR'),
        'zylinder_liegend' => us_t('EINST.FORM_ZYLINDER'),
        'kugel'            => us_t('EINST.FORM_KUGEL'),
    );
}

/** Volumenanteil aus dem Hoehenanteil - dieselbe Rechnung wie us_common.form_anteil(). */
function us_form_anteil($x, $form)
{
    $x = max(0.0, min(1.0, (float) $x));
    if ($form === 'zylinder_liegend') {
        $c = 1.0 - 2.0 * $x;
        return (acos($c) - $c * sqrt(max(0.0, 1.0 - $c * $c))) / M_PI;
    }
    if ($form === 'kugel') {
        return $x * $x * (3.0 - 2.0 * $x);
    }
    return $x;
}

/** Der Zwischenspeicher der Endpunkt-Probe (us_endpunkt_probe) - an EINER Stelle. */
function us_endpunkt_speicher()
{
    return dirname(us_paths()['status']) . '/endpunkt.json';
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
        //
        // DIE WURZEL KOMMT AUS us_paths() (seit 1.2.8). Bis 1.2.7 stand hier
        // eine eigene Suche mit einem festen Rueckfall im Heimverzeichnis
        // HINTER der Suche, und ohne Wurzel wurde "/templates/plugins/..."
        // ab "/" versucht (gemessen in WSL, Pruefung-Ultraschall-1.2.8,
        // Fall H3). Ohne Wurzel gilt nur der Entwicklungsbaum daneben.
        $home = us_paths()['home'];
        $ordner = basename(dirname(__FILE__));
        $pfad = ($home !== '') ? $home . '/templates/plugins/' . $ordner . '/lang' : '';
        if ($pfad === '' || !is_dir($pfad)) {
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
/** Die Sprachansagen aus dem Feld der Hauptkonfiguration: was fehlt, kommt aus den Vorgaben (ab Werk EIN). */
function us_ansage_block_lesen()
{
    $roh = (function () { list($w) = us_config_read(false); return isset($w['ansage_block']) ? (string) $w['ansage_block'] : ''; })();
    $b = $roh !== '' ? json_decode((string) base64_decode($roh), true) : null;
    return (is_array($b) ? $b : array()) + us_ansage_vorgaben();
}

/** Zurueck in das Feld der Hauptkonfiguration. */
function us_ansage_block_schreiben(array $cfg)
{
    $x = array();
    foreach (us_ansage_schluessel() as $k) { if (array_key_exists($k, $cfg)) { $x[$k] = $cfg[$k]; } }
    $b = base64_encode((string) json_encode($x));
    list($w) = us_config_read(false); $w['ansage_block'] = $b; return us_config_write($w);
}

/** Die Schluessel der Sprachansagen in der Hauptkonfiguration. */
function us_ansage_schluessel()
{
    return array('tts', 'ansage_regeln', 'ansage_haken', 'ansage_token_herkunft', 'ansage_aus_datei');
}

/** Die Vorgaben: nie gesetzt = ab Werk EIN. Ein gespeicherter Block bleibt, auch 'aus'. */
function us_ansage_vorgaben()
{
    return array('tts' => ansage_werk_block(), 'ansage_regeln' => ansage_regeln_vorgaben(), 'ansage_haken' => array('ausfall' => 1), 'ansage_token_herkunft' => array(), 'ansage_aus_datei' => 0);
}

/** Wertpruefung fuer die Sprachansagen: null = nicht meine Schluessel, sonst tauglich ja/nein. */
function us_ansage_wert($k, $w)
{
    if (!in_array((string) $k, us_ansage_schluessel(), true)) { return null; }
    $g = '';
    switch ((string) $k) {
        case 'tts':
            return is_array($w) && ansage_wert_pruefen($w, $g, us_ansage_modi()) !== null;
        case 'ansage_regeln':
            return is_array($w) && ansage_regeln_wert($w, $g) !== null;
        case 'ansage_haken':
            if (!is_array($w)) { return false; }
            foreach ($w as $a => $v) {
                if (!array_key_exists($a, array('ausfall' => 1)) || !in_array($v, array(0, 1, '0', '1'), true)) { return false; }
            }
            return true;
        case 'ansage_token_herkunft':
            return is_array($w);
        case 'ansage_aus_datei':
            return in_array($w, array(0, 1, '0', '1'), true);
    }
    return false;
}

/** Fuer die Sicherung: nie ein Sprechtoken, nie der Herkunftsvermerk. Ausgabeart, Ziele, Regeln und Haken reisen mit. */
function us_ansage_fuer_sicherung(array $c)
{
    unset($c['ansage_token_herkunft'], $c['ansage_aus_datei']);
    if (isset($c['tts']) && is_array($c['tts'])) { $c['tts'] = ansage_sicherung_bereinigen($c['tts']); }
    return $c;
}

/**
 * Vor dem Zurueckspielen. Eine Datei mit Sprechtoken wird abgewiesen (der Block wird unbrauchbar gemacht, die Wertpruefung nennt ihn). Die geltenden Token und der Vermerk bleiben.
 * Fehlt ein Schluessel (Sicherung einer aelteren Fassung), gilt der geltende Wert: Ein bewusstes AUS geht beim Zurueckspielen nicht verloren.
 */
function us_ansage_sicherung_vor($roh)
{
    $d = json_decode((string) $roh, true);
    if (!is_array($d)) { return $roh; }
    $inhalt = false;
    foreach (array_keys($d) as $k) { if ((string) $k !== '' && ((string) $k)[0] !== '_') { $inhalt = true; } }
    if (!$inhalt) { return $roh; }
    $j = us_ansage_cfg();
    unset($d['ansage_token_herkunft'], $d['ansage_aus_datei']);
    $jt = isset($j['tts']) && is_array($j['tts']) ? $j['tts'] : array();
    if (array_key_exists('tts', $d)) {
        $g = '';
        $p = is_array($d['tts']) ? ansage_wert_pruefen($d['tts'], $g, us_ansage_modi()) : null;
        if (ansage_sicherung_mangel($d['tts']) || $p === null) {
            $d['tts'] = 'ABGEWIESEN';
        } else {
            list($tv) = ansage_vervollstaendigen($p, 'aus');
            $d['tts'] = ansage_sicherung_tokens_behalten($tv, $jt);
        }
    } else {
        $d['tts'] = us_tts($j);
    }
    foreach (array_diff(us_ansage_schluessel(), array('tts', 'ansage_token_herkunft', 'ansage_aus_datei')) as $k) {
        if (!array_key_exists($k, $d) && array_key_exists($k, $j)) { $d[$k] = $j[$k]; }
    }
    $d['ansage_token_herkunft'] = isset($j['ansage_token_herkunft']) && is_array($j['ansage_token_herkunft']) ? $j['ansage_token_herkunft'] : array();
    $d['ansage_aus_datei'] = 1;
    return (string) json_encode($d);
}

/**
 * Die Hauptkonfiguration. Liegt eine ansage.json aus einer Probe vom 01.11., wird sie EINMAL uebernommen (Vermerk ansage_aus_datei) und bleibt liegen.
 */
function us_ansage_cfg()
{
    $c = us_ansage_block_lesen();
    if (empty($c['ansage_aus_datei'])) {
        $f = rtrim(dirname(us_paths()['config']), '/') . '/ansage.json';
        if (is_file($f)) {
            $d = json_decode((string) @file_get_contents($f), true);
            if (is_array($d)) {
                foreach (array('tts', 'ansage_regeln', 'ansage_haken', 'ansage_token_herkunft') as $k) {
                    if (array_key_exists($k, $d) && us_ansage_wert($k, $d[$k]) === true) { $c[$k] = $d[$k]; }
                }
            }
            $c['ansage_aus_datei'] = 1;
            us_ansage_cfg_schreiben($c);
        }
    }
    return $c;
}

function us_ansage_cfg_schreiben(array $cfg)
{
    return us_ansage_block_schreiben($cfg);
}

/** Die Ausgabearten dieser Linie. */
function us_ansage_modi()
{
    return array_values(array_diff(ansage_modi(), array('audioserver')));
}

/** Der Block tts: gespeichert bleibt, auch 'aus'; ungueltig gilt als 'aus'. */
function us_tts($cfg = null)
{
    if ($cfg === null) { $cfg = us_ansage_cfg(); }
    return ansage_block_oder_aus(isset($cfg['tts']) ? $cfg['tts'] : ansage_werk_block(), us_ansage_modi());
}

/** Der Kontext des Moduls. */
function us_ansage_k()
{
    $d = us_paths()['datadir'];
    return array(
        'port' => ansage_webport(ansage_lbhome() . '/config/system/general.json'),
        'home' => ansage_lbhome(),
        'kopf' => array('User-Agent: LoxBerry Ultraschall'),
        'ordner' => @is_dir($d) ? $d : '',
        't' => function ($s) { return us_t($s); },
        'werk' => ansage_werk_modus(),
    );
}

/** Die Anlaesse: Name => Beschriftung. */
function us_ansage_anlaesse()
{
    return array('ausfall' => us_t('ANSAGEPLUGIN.L_AUSFALL'));
}

/** Ist der Haken dieses Anlasses gesetzt? Fehlt er, gilt die Vorgabe der Linie. */
function us_ansage_anlass_an($a, $cfg = null)
{
    if ($cfg === null) { $cfg = us_ansage_cfg(); }
    $h = (isset($cfg['ansage_haken']) && is_array($cfg['ansage_haken'])) ? $cfg['ansage_haken'] : array();
    $v = array('ausfall' => 1);
    return !empty(array_key_exists($a, $h) ? $h[$a] : (isset($v[$a]) ? $v[$a] : 0));
}

/** Die Eingaben nach einer Beanstandung bis zur naechsten Seite aufheben, nie ein Sprechtoken. */
function us_ansage_eingaben($setzen = null)
{
    $f = us_paths()['datadir'] . '/ansage_eingaben.json';
    if (is_array($setzen)) {
        if (!is_dir(dirname($f))) { @mkdir(dirname($f), 0775, true); }
        @file_put_contents($f, (string) json_encode($setzen));
        @chmod($f, 0600);
        return $setzen;
    }
    $d = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    if (is_array($d) && $d) { @file_put_contents($f, '{}'); return $d; }
    return array();
}

/** Das Formular lesen und speichern: array(gelungen, Fehlertexte, Hinweise). */
function us_ansage_post(array $post)
{
    $cfg = us_ansage_cfg();
    $k = us_ansage_k();
    $m = array();
    $b = array();
    $h = array();
    $neu = ansage_formular_lesen($post, us_tts($cfg), $m, $b, array('modi' => us_ansage_modi(), 'ziele' => true, 'token_vor_ort' => true, 'aus_gewinnt' => true), $k, $h);
    list($r, $hk) = ansage_regeln_formular($post, us_ansage_regeln($cfg), us_ansage_anlaesse(), $m, $b, $k);
    if ($m) {
        $t = array();
        foreach ($m as $x) { $t[] = $x['text']; }
        $e = array('_bean' => array_values($b));
        foreach ($post as $n => $w) {
            if (strncmp((string) $n, 'tts_', 4) === 0 || strncmp((string) $n, 'ansage_', 7) === 0) {
                if ((string) $n !== 'tts_alexa_token' && (string) $n !== 'tts_google_token') { $e[$n] = $w; }
            }
        }
        us_ansage_eingaben($e);
        return array(false, $t, array());
    }
    $cfg['tts'] = $neu;
    // 1.2.23: ein eingetipptes oder geloeschtes Sprechtoken ist nicht mehr vom Nachbarn; bis 1.2.22 ueberschrieb es der naechste Abgleich.
    $cfg['ansage_token_herkunft'] = ansage_token_herkunft_formular($post, isset($cfg['ansage_token_herkunft']) ? $cfg['ansage_token_herkunft'] : array());
    $cfg['ansage_regeln'] = $r;
    $cfg['ansage_haken'] = $hk;
    $t = array();
    foreach ($h as $x) { $t[] = $x['text']; }
    return array(us_ansage_cfg_schreiben($cfg), array(), $t);
}

/** Der Abschnitt im Reiter Einstellungen, eigenes Formular. $felder: die versteckten Felder (Formularmerkmal, Reiter). Nach einer Beanstandung traegt er das Eingetippte. */
function us_ansage_form_html($felder)
{
    $cfg = us_ansage_cfg();
    $k = us_ansage_k();
    $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    $ein = us_ansage_eingaben();
    $bean = isset($ein['_bean']) && is_array($ein['_bean']) ? $ein['_bean'] : array();
    $h = array(
        'w' => function ($n, $g) use ($ein) { return (isset($ein[$n]) && is_string($ein[$n])) ? $ein[$n] : $g; },
        'm' => function ($n) use ($bean) { return in_array($n, $bean, true) ? ' class="sm-beanstandet"' : ''; },
        'c' => function ($n, $g) use ($ein) { return $ein ? !empty($ein[$n]) : (bool) $g; },
    );
    $o = array();
    $o[] = '<h2>' . $e(us_t('ANSAGEPLUGIN.H')) . '</h2>';
    $o[] = '<form action="index.php" method="post" autocomplete="off">' . $felder;
    $o[] = ansage_formular_html(us_tts($cfg), $h + array('modi' => us_ansage_modi(), 'ziele' => true, 'ziele_post' => isset($ein['tts_ziel_art']) ? $ein : null), $k);
    $o[] = ansage_regeln_html(us_ansage_regeln($cfg), isset($cfg['ansage_haken']) && is_array($cfg['ansage_haken']) ? $cfg['ansage_haken'] : array('ausfall' => 1), us_ansage_anlaesse(), $h + array('token_vor_ort' => true), $k);
    $o[] = '<div class="sm-knopfreihe"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="ansage_speichern" value="1">' . $e(us_t('ANSAGEPLUGIN.K_SPEICHERN')) . '</button>';
    $o[] = '<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="ansage_test" value="1">' . $e(us_t('ANSAGEPLUGIN.K_TEST')) . '</button></div>';
    $o[] = '</form>';
    $o[] = us_ansage_lage_html($cfg);
    return implode("\n", $o) . "\n";
}

/** Die Testansage (der Knopf spricht immer). Rueckgabe: array(gesendet, Grund). */
function us_ansage_test()
{
    $k = us_ansage_k();
    us_ansage_token_pflegen();
    $r = ansage_testansage(us_ansage_vor_ort(), $k);
    us_ansage_zeile('Testansage: ' . ansage_kurz($r));
    return array($r['stand'] === 1, $r['stand'] === 1 ? '' : ansage_kennung_text($r['kennung'], $k));
}

/* ---------------- Sprachansagen: Regeln aus dem Stamm (1.2.20, Welle S7, Stamm sprachausgabe.php 1.4.0) ----------------
 *
 * Nachtruhe, Sperre und Schalter "Ansagen aus" stehen im Stamm. Ab Werk EIN: ansage_werk_block() in den Vorgaben, also nur fuer einen
 * nie gespeicherten Block; ein gespeicherter bleibt, auch 'aus'. Ein Minutenlauf und ein Dienst sprechen nie selbst: Sie legen den Anlass
 * in <Daten>/ansage_warte.json, ein abgekoppelter Sender (php -r, eigene Sperre) spricht und wartet auf die Lautsprecher.
 */

/** So alt darf ein wartender Anlass werden (nach einem Update oder Hang wird nichts Aufgestautes angesagt). */
if (!defined('US_ANSAGE_HOECHSTALTER')) { define('US_ANSAGE_HOECHSTALTER', 600); }

/** Die Regeln: gespeichert und gueltig, sonst aus einer alten Einstellung dieser Linie, sonst die Vorgabe (mit Nachtruhe). */
function us_ansage_regeln($cfg = null)
{
    if ($cfg === null) { $cfg = us_ansage_cfg(); }
    $g = '';
    if (!empty($cfg['ansage_regeln'])) {
        $r = ansage_regeln_wert($cfg['ansage_regeln'], $g);
        return $r === null ? ansage_regeln_vorgaben() : $r;
    }
    $r = ansage_regeln_vorgaben();
    $p = ansage_regeln_wert($r, $g);
    return $p === null ? ansage_regeln_vorgaben() : $p;
}

/** Die Warteliste unter einer Dateisperre aendern: $fn(Liste) -> array(neue Liste, Ergebnis). */
function us_ansage_warte($fn)
{
    $d = us_paths()['datadir'];
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    $f = $d . '/ansage_warte.json';
    $h = @fopen($f, 'c+');
    if ($h === false) { return null; }
    if (!flock($h, LOCK_EX)) { fclose($h); return null; }
    $alt = json_decode((string) stream_get_contents($h), true);
    list($neu, $erg) = call_user_func($fn, is_array($alt) ? $alt : array());
    rewind($h);
    ftruncate($h, 0);
    fwrite($h, (string) json_encode($neu));
    fflush($h);
    flock($h, LOCK_UN);
    fclose($h);
    @chmod($f, 0600);
    return $erg;
}

/**
 * Anlaesse hinlegen und den Sender starten; wartet auf nichts. $l: Eintraege fuer ansage_regel_sprechen() (schluessel, text, dringend,
 * sperre_min, nur_nach). Die Schale schliesst vor dem exec jede Deskriptor-Nummer ueber 2, sonst erbte der Sender die Sperren des Rufers.
 */
function us_ansage_legen(array $l, $jetzt = null)
{
    if (!$l) { return 'nichts'; }
    $jetzt = $jetzt === null ? time() : (int) $jetzt;
    us_ansage_warte(function ($alt) use ($l, $jetzt) {
        foreach ($l as $e) { $alt[] = $e + array('zeit' => $jetzt); }
        return array(array_slice($alt, -50), null);
    });
    $php = (defined('PHP_BINARY') && PHP_BINARY !== '' && is_executable(PHP_BINARY) && stripos(basename(PHP_BINARY), 'php') === 0 && stripos(basename(PHP_BINARY), 'fpm') === false) ? PHP_BINARY : 'php';
    $befehl = 'for f in /proc/$$/fd/*; do n=${f##*/}; [ "$n" -gt 2 ] 2>/dev/null && eval "exec $n>&-"; done; '
            . 'exec ' . escapeshellarg($php) . ' -r ' . escapeshellarg('chdir(dirname($argv[1])); $_SERVER["SCRIPT_FILENAME"] = $_SERVER["PHP_SELF"] = $argv[1]; require $argv[1]; us_ansage_senden();') . ' -- ' . escapeshellarg(__FILE__);
    $aus = array();
    $rc = 1;
    @exec('bash -c ' . escapeshellarg($befehl) . ' </dev/null >/dev/null 2>&1 &', $aus, $rc);
    return $rc === 0 ? 'gestartet' : 'kein_start';
}

/** Das Sprechtoken aus Alexa-NG und Chromecast4lox uebernehmen (Stamm 1.3.1). Schreibt NUR bei einer Aenderung, nennt nie das Token. */
function us_ansage_token_pflegen($cfg = null)
{
    if ($cfg === null) { $cfg = us_ansage_cfg(); }
    list($neu, $her2, $g) = ansage_token_abgleich(us_tts($cfg), isset($cfg['ansage_token_herkunft']) ? $cfg['ansage_token_herkunft'] : array(), us_ansage_k());
    if (!$g) { return false; }
    $cfg = us_ansage_cfg();
    $cfg['tts'] = $neu;
    $cfg['ansage_token_herkunft'] = $her2;
    if (!us_ansage_cfg_schreiben($cfg)) { return false; }
    us_ansage_zeile('Ansage: Sprechtoken uebernommen (' . implode(', ', $g) . ').');
    return true;
}

/** Der Block fuer Testansage und Pruefzeile: uebernommene Token aufgeloest. Nur im Speicher. */
function us_ansage_vor_ort($cfg = null)
{
    if ($cfg === null) { $cfg = us_ansage_cfg(); }
    return ansage_token_fuer_lauf(us_tts($cfg), isset($cfg['ansage_token_herkunft']) ? $cfg['ansage_token_herkunft'] : array(), us_ansage_k());
}

/** Der Block fuer die Pruefzeile: nur die eingerichteten Ziele; ist keines eingerichtet, sagt die Zeile "aus" statt eines Kreuzes. */
function us_ansage_pruefblock($cfg = null)
{
    if ($cfg === null) { $cfg = us_ansage_cfg(); }
    $t = us_tts($cfg);
    if ($t['mode'] === 'aus') { return $t; }
    list($b) = ansage_ziele_bereit(us_ansage_vor_ort($cfg), us_ansage_k());
    if ($b === null) { $t['mode'] = 'aus'; return $t; }
    return $b;
}

/** Die Lage im Reiter Test: Ziele, Nachtruhe, Schalter. Nur im geoeffneten Reiter (der Schalter fragt den Broker). */
function us_ansage_lage_html($cfg = null)
{
    if ($cfg === null) { $cfg = us_ansage_cfg(); }
    $k = us_ansage_k();
    $o = array();
    foreach (ansage_regel_lage(us_tts($cfg), us_ansage_regeln($cfg), $k, true) as $z) {
        $o[] = '<div class="sm-hilfe">' . htmlspecialchars((string) $z[1], ENT_QUOTES, 'UTF-8') . '</div>';
    }
    return implode("\n", $o) . "\n";
}

/** Die Felder der Regeln fuer die Einmalmeldung (X-2). */
function us_ansage_regel_felder(array $anlaesse = array())
{
    return ansage_regeln_x2_felder($anlaesse);
}

/**
 * Im Vorgang sprechen, wo der Rufer das Ergebnis braucht (Antwort an Loxone, Befehl): Regeln aus dem Stamm mit "dringend, ohne Sperre" - es zaehlt nur der Schalter.
 * Schalter gesetzt: stand -1, Kennung SCHALTER. Nichts bereit oder Original-Audioserver: wie bisher ansage_sprechen(), damit die Antwort sagt, was fehlt.
 */
function us_ansage_direkt($text, array $e = array(), $tts = null)
{
    $conf = us_ansage_cfg();
    $k = us_ansage_k();
    $t = $tts === null ? us_tts($conf) : $tts;
    $a = ansage_regel_sprechen(array($e + array('schluessel' => 'direkt', 'text' => (string) $text, 'dringend' => true, 'sperre_min' => 0)), $t, us_ansage_regeln($conf), $k);
    if ($a['r'] !== null) { return $a['r']; }
    if ($a['regel'] === 'SCHALTER') {
        return array('stand' => -1, 'kennung' => 'SCHALTER', 'art' => isset($t['mode']) ? (string) $t['mode'] : '-', 'http' => 0, 'zeile' => '', 'zeichen' => 0);
    }
    return ansage_sprechen((string) $text, $t, $k);
}

/**
 * Der Sender: eigene Sperre, nimmt die Warteliste und spricht nach den Regeln. "aus" und "kein Ziel eingerichtet" hinterlassen keine Zeile;
 * ein gescheitertes Ziel genau eine je Aenderung (ab Werk EIN darf kein Protokoll vollaufen). Text und Token stehen nie im Protokoll.
 */
function us_ansage_senden($jetzt = null)
{
    $d = us_paths()['datadir'];
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    $fh = @fopen($d . '/ansage_senden.lock', 'c');
    if ($fh === false || !flock($fh, LOCK_EX | LOCK_NB)) { return 'laeuft'; }
    $erg = 'nichts';
    $l = us_ansage_warte(function ($alt) { return array(array(), $alt); });
    $jetzt = $jetzt === null ? time() : (int) $jetzt;
    $frisch = array();
    foreach (is_array($l) ? $l : array() as $e) {
        if (is_array($e) && isset($e['zeit']) && $jetzt - (int) $e['zeit'] <= US_ANSAGE_HOECHSTALTER) { $frisch[] = $e; }
    }
    if ($frisch) {
        $conf = us_ansage_cfg();
        us_ansage_token_pflegen($conf);
        $conf = us_ansage_cfg();
        $a = ansage_regel_sprechen($frisch, us_tts($conf), us_ansage_regeln($conf), us_ansage_k());
        $erg = $a['regel'] !== '' ? $a['regel'] : 'gesprochen';
        if ($a['r'] !== null) {
            $merk = $d . '/ansage_lage.txt';
            $lage = $a['r']['stand'] === 1 ? 'gesprochen' : ansage_kurz($a['r']);
            if ($a['r']['stand'] === 1 || (string) @file_get_contents($merk) !== $lage) { us_ansage_zeile('Ansage: ' . ansage_regel_kurz($a)); }
            @file_put_contents($merk, $lage);
        } elseif ($a['regel'] !== 'AUS' && $a['regel'] !== 'KEIN_ZIEL') {
            us_ansage_zeile('Ansage entfaellt: ' . ansage_regel_kurz($a));
        }
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $erg;
}

/**
 * Die Flanke eines Anlasses (1.2.20). Merker <Daten>/ansage_flanke.json, unter Dateisperre; er ueberlebt einen Neustart des Dienstes.
 * Der erste Lauf schweigt: Was schon gestoert war, als die Linie zum ersten Mal hinsah, sagt sie nicht an und meldet auch keine Rueckkehr.
 * Nur der Eintritt spricht, nach $frist Sekunden Dauer; die Rueckkehr nur, wenn der Eintritt gesprochen hat. Ein abgewaehlter Haken laeuft mit: Einschalten sagt nichts Altes an.
 * $name: der Name, den der Nutzer vergeben hat, nur im Satz, nie im Protokoll; Kennungen, Adressen und Seriennummern gehoeren nicht hierher.
 * Rueckgabe: nichts, erster_lauf, aus, oder das Ergebnis von <px>_ansage_legen().
 */
function us_ansage_flanke($anlass, $gestoert, $name = '', $frist = 0, $jetzt = null)
{
    $l = array('ausfall' => array('ausfall', 'SATZ_AUSFALL', 'SATZ_WIEDER', 0));
    if (!isset($l[$anlass])) { return 'unbekannt'; }
    $jetzt = $jetzt === null ? time() : (int) $jetzt;
    $name = trim((string) preg_replace('/[^\p{L}\p{N} .,:!?\-]/u', ' ', (string) $name));
    $sch = $anlass . ($name !== '' ? '|' . substr(md5($name), 0, 8) : '');
    $d = us_paths()['datadir'];
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    $h = @fopen($d . '/ansage_flanke.json', 'c+');
    if ($h === false) { return 'nichts'; }
    if (!flock($h, LOCK_EX)) { fclose($h); return 'nichts'; }
    $m = json_decode((string) stream_get_contents($h), true);
    $m = is_array($m) ? $m : array();
    $e = (isset($m[$sch]) && is_array($m[$sch]) && isset($m[$sch]['z'], $m[$sch]['s'], $m[$sch]['g'])) ? $m[$sch] : null;
    $neu = $gestoert ? 1 : 0;
    $tat = 'nichts';
    if ($e === null) {
        $e = array('z' => $neu, 's' => $jetzt, 'g' => $neu ? 2 : 0);
        $tat = 'erster_lauf';
    } elseif ($neu === 1) {
        if ((int) $e['z'] === 0) { $e = array('z' => 1, 's' => $jetzt, 'g' => 0); }
        if ((int) $e['g'] === 0 && $jetzt - (int) $e['s'] >= (int) $frist) {
            if (us_ansage_anlass_an($l[$anlass][0])) { $e['g'] = 1; $tat = 'stoerung'; } else { $e['g'] = 2; $tat = 'aus'; }
        }
    } elseif ((int) $e['z'] === 1) {
        if ((int) $e['g'] === 1) { $tat = $l[$anlass][2] !== '' ? 'wieder' : 'nichts'; }
        $e = array('z' => 0, 's' => $jetzt, 'g' => 0);
    }
    if (!isset($m[$sch]) || $m[$sch] !== $e) {     // nur bei einer Aenderung schreiben: Dienste rufen das im Takt
        $m[$sch] = $e;
        rewind($h);
        ftruncate($h, 0);
        fwrite($h, (string) json_encode($m));
        fflush($h);
    }
    flock($h, LOCK_UN);
    fclose($h);
    @chmod($d . '/ansage_flanke.json', 0600);
    if ($tat !== 'stoerung' && $tat !== 'wieder') { return $tat; }
    $satz = us_t('ANSAGEPLUGIN.' . ($tat === 'stoerung' ? $l[$anlass][1] : $l[$anlass][2]));
    if (strpos($satz, '%s') !== false) { $satz = sprintf($satz, $name); }
    $ein = array('schluessel' => $tat === 'stoerung' ? $sch : 'wieder|' . $sch, 'text' => $satz);
    if ($tat === 'wieder') { $ein['nur_nach'] = $sch; }
    if ($tat === 'stoerung' && $l[$anlass][3]) { $ein['dringend'] = true; }
    us_ansage_token_pflegen();
    return us_ansage_legen(array($ein));
}

/** Fuer die Sicherung: die Hauptkonfiguration ohne das Feld, dazu die Felder der Sprachansagen ohne Sprechtoken und Vermerk. */
function us_ansage_sicherung_felder(array $c)
{
    unset($c['ansage_block']);
    return us_ansage_fuer_sicherung(us_ansage_cfg()) + $c;
}

/** Eine Protokollzeile der Sprachansagen. */
function us_ansage_zeile($t)
{
    $f = us_log_file();
    if ($f !== '' && is_dir(dirname($f))) { @file_put_contents($f, date('Y-m-d H:i:s') . ' Oberflaeche: ' . $t . "\n", FILE_APPEND | LOCK_EX); }
}

function us_sicherung_lesen($roh, $bestand = null)
{
    $roh = us_ansage_sicherung_vor($roh);     // Token und Vermerk bleiben die geltenden
    $us_d = json_decode((string) $roh, true);
    if (is_array($us_d)) { $us_d['ansage_block'] = ''; $roh = (string) json_encode($us_d); }
    $us_ab = array();
    $mangel = array();
    $hinweise = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, array(us_t('TEXT.SICH_KEIN_JSON')), 0, array());
    }
    $bekannt = array_keys(us_defaults());
    $anzahl = 0;
    $gelesen = array();
    $token_leer = false;
    foreach ($daten as $k => $w) {
        /* DER LESBARE KOPF GEHOERT ZUR DATEI (Durchgang 02.10.2026, O5).
         * _hinweis, _stand und _warnung schreibt "Einstellungen sichern"
         * selbst hinein (CLAUDE.md 9). Bis 1.2.10 wurde eine Datei mit
         * solchem Kopf als fremd abgewiesen (gemessen, Oberflaechen-
         * Pruefer 8). */
        if (is_string($k) && $k !== '' && $k[0] === '_') {
            continue;
        }
        /* EIN LEERES ODER null-TOKEN LOESCHT KEINES (O6). Bis 1.2.10 wurde
         * es angenommen: jede Adresse im Miniserver antwortete danach 403,
         * und die Oberflaeche liess nur noch "Neues Token" zu (gemessen,
         * Code-Pruefer C6, Oberflaechen-Pruefer 7). Es gilt wie ein
         * fehlender Schluessel: das geltende bleibt, mit Hinweis. */
        if ($k === 'aktionstoken' && ($w === null || $w === '')) {
            $token_leer = true;
            continue;
        }
        if ((string) $k === 'ansage_block') { continue; }     // das Feld mit den Sprechtoken kommt nie aus einer Datei
        $us_aw = us_ansage_wert($k, $w);
        if ($us_aw !== null) {
            if ($us_aw) { $us_ab[$k] = $w; } else { $mangel[] = us_t('ANSAGEPLUGIN.M_FEHL'); }
            continue;
        }
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
        $us_hat = is_array($bestand) && isset($bestand['aktionstoken'])
            && (string) $bestand['aktionstoken'] !== '';
        if ($us_hat) {
            $gelesen['aktionstoken'] = (string) $bestand['aktionstoken'];
        }
        if ($token_leer) {
            $hinweise[] = us_t($us_hat ? 'TEXT.SICH_TOKEN_LEER' : 'TEXT.SICH_TOKEN_LEER_KEINS');
        }
    }

    /* DIE NEUEN SCHLUESSEL VON 1.2.18 (N2): eine Sicherung aus 1.2.17 kennt
     * endpunkt_ein, behaelter_form und temperatur_c nicht. us_pruefen() beginnt bei den
     * Vorgaben - beim Zurueckspielen schaltete das den Endpunkt einer Bestandsanlage
     * still AUS und setzte Form und Temperatur auf Werk. Fehlt einer, bleibt der
     * geltende Stand, und der Hinweis nennt ihn. */
    $us_behalten = array();
    foreach (array('endpunkt_ein', 'behaelter_form', 'temperatur_c') as $us_k) {
        if (!array_key_exists($us_k, $gelesen)) {
            if ($bestand === null) {
                list($bestand) = us_config_read(false);
            }
            if (is_array($bestand) && array_key_exists($us_k, $bestand)) {
                $gelesen[$us_k] = (string) $bestand[$us_k];
                $us_behalten[] = $us_k . '=' . $gelesen[$us_k];
            }
        }
    }
    if ($us_behalten) {
        $hinweise[] = sprintf(us_t('TEXT.SICH_NEU_BEHALTEN'), us_e(implode(', ', $us_behalten)));
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
        if ($us_ab) { $geprueft['ansage_block'] = base64_encode((string) json_encode($us_ab)); }     // die Felder der Sprachansagen zurueck in das eine Feld
            return array($geprueft, array(), $anzahl, $hinweise);
        }
        $mangel = $wertmaengel;
    }
    return array(null, $mangel, $anzahl, $hinweise);
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
    $speicher = us_endpunkt_speicher();
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
        if (PHP_VERSION_ID < 80000) { curl_close($ch); }
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
        /* PHP 8.5 kuendigt die vordefinierte lokale Variable
         * http_response_header ab, schon beim Uebersetzen ihres Namens; mit
         * PHP 9 faellt sie weg (Durchgang 02.10.2026, C8). Ab 8.4 gibt es die
         * Funktion; darunter wird die Variable ueber ihren Namen gelesen, so
         * dass 8.5 beim Uebersetzen nichts zu melden hat. */
        $us_kopfname = 'http_response_header';
        $us_kopfzeilen = function_exists('http_get_last_response_headers')
            ? http_get_last_response_headers()
            : (isset($$us_kopfname) ? $$us_kopfname : null);
        if (is_array($us_kopfzeilen)) {
            foreach ($us_kopfzeilen as $z) {
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

    /* Beurteilt wird der HERZSCHLAG, nicht das Alter der letzten Messung
     * (Durchgang 02.10.2026, C1): ein Dienst, der laeuft, den Sensor aber
     * nicht oeffnen kann, arbeitet - er misst nur nicht. Das sagt VALID. Die
     * Grenze ist dieselbe wie fuer OK (C5). */
    $alter = us_herzschlag_alter();
    $grenze = us_ok_grenze($cfg);
    if ($pid <= 0) {
        // Ueber einen Dienst, der gar nicht laeuft, wird kein Herzschlag
        // beurteilt.
        $o(us_t('PRUEF.ARBEITET'), us_t('PRUEF.ARBEITET_OFFEN'));
    } elseif ($alter === null) {
        $o(us_t('PRUEF.ARBEITET'), us_t('PRUEF.ARBEITET_KEINE_DATEI'));
    } else {
        $j(us_t('PRUEF.ARBEITET'), $alter >= -5 && $alter <= $grenze,
           sprintf(us_t('PRUEF.ARBEITET_TEXT'), max(0, $alter), $grenze));
    }

    // --- Token und Endpunkt ------------------------------------------------
    $token = isset($cfg['aktionstoken']) ? (string) $cfg['aktionstoken'] : '';
    /* Ist der Endpunkt aus (seit 1.2.18, E6), sind beide Zeilen grau: ausgeschaltet
     * ist eine Entscheidung, kein Fehler - und aufgerufen wird nichts. */
    if (us_cfg($cfg, 'endpunkt_ein', '0') !== '1') {
        $o(us_t('PRUEF.TOKEN'), us_t('PRUEF.EP_AUS'));
        $o(us_t('PRUEF.ENDPUNKT'), us_t('PRUEF.EP_AUS'));
    } else {
    $j(us_t('PRUEF.TOKEN'), $token !== '',
       $token !== '' ? sprintf(us_t('PRUEF.TOKEN_JA'), strlen($token))
                     : us_t('PRUEF.TOKEN_NEIN'));

    if ($netz) {
        $ep = us_endpunkt_probe($token);
        $z[] = array(us_t('PRUEF.ENDPUNKT'), $ep['zustand'], $ep['text']);
    } else {
        $o(us_t('PRUEF.ENDPUNKT'), us_t('PRUEF.ENDPUNKT_OFFEN'));
    }
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
            /* U17 (seit 1.2.18): die LEISTE ist die dritte Stelle (Regeln/04). */
            preg_match_all('/data-pane="tab-([a-z]+)"/', $q, $m5);
            $leiste = array_values(array_unique($m5[1]));
            sort($liste);
            sort($flaechen);
            sort($leiste);
            $fehlt1 = array_diff($flaechen, $liste);   // Flaeche ohne Eintrag
            $fehlt2 = array_diff($liste, $flaechen);   // Eintrag ohne Flaeche
            $fehlt3 = array_merge(array_diff($leiste, $liste), array_diff($liste, $leiste));
            $j(us_t('PRUEF.REITER'), $liste && !$fehlt1 && !$fehlt2 && !$fehlt3,
               sprintf(us_t('PRUEF.REITER_TEXT'), count($liste), count($flaechen), count($leiste),
                       ($fehlt1 || $fehlt2 || $fehlt3)
                           ? implode(', ', array_unique(array_merge($fehlt1, $fehlt2, $fehlt3))) : '-'));

            // U17: auch ein <form mit Zeilenumbruch dahinter zaehlt.
            $formulare = preg_match_all('/<form\b/', $q);
            $merkmale = substr_count($q, 'us_fmt($us_cfg)');
            /* DAS MERKMAL MUSS GEFUELLT SEIN (Durchgang 02.10.2026, O8). Bis
             * 1.2.10 zaehlte diese Zeile nur die Zeichenfolge im Quelltext und
             * zeigte bei leerem Token einen Haken, waehrend alle 19 Formulare
             * ein LEERES Merkmal trugen (gemessen, Oberflaechen-Pruefer 10).
             * Gemessen wird das gerenderte Feld, wie es jedes Formular
             * ausgibt. */
            $us_feld = us_fmt($cfg);
            $us_gefuellt = preg_match('/name="formtoken" value="[0-9a-f]{64}"/', $us_feld) === 1;
            $j(us_t('PRUEF.FORMULARE'), $formulare > 0 && $merkmale >= $formulare && $us_gefuellt,
               $us_gefuellt
                   ? sprintf(us_t('PRUEF.FORMULARE_TEXT'), $merkmale, $formulare)
                   : sprintf(us_t('PRUEF.FORMULARE_LEER'), $merkmale, $formulare));
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
            // N3 (seit 1.2.18): Themennamen mit "/" (status/zaehler, status/ok, status/ts).
            preg_match_all('/_senden\(\s*"([a-z_\/]+)"/', $q, $m4);
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
    // Seit 1.2.18 nur noch die UDP-Vorlage (die MQTT-Vorlage ist entfallen, E1).
    foreach (array('udp_in') as $art) {
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

    // --- Namenstabelle (seit 1.2.18) -----------------------------------------------
    /* Jeder Name entsteht aus einem gesendeten Thema (us_gateway_name), keiner
     * doppelt, keiner mit "/"; Haken fuer Gateway V2 und Noncached gezaehlt. */
    $us_nt = us_mqtt_namen($cfg);
    $us_nn = array();
    $us_nh = 0;
    $us_nnc = 0;
    foreach ($us_nt as $us_x) {
        $us_nn[] = $us_x['name'];
        if ($us_x['v2'] === 'haken' || $us_x['v2'] === 'haken_n') { $us_nh++; }
        if ($us_x['v2'] === 'haken_n') { $us_nnc++; }
    }
    $us_nok = $us_nt && count(array_unique($us_nn)) === count($us_nn)
        && !preg_grep('#[/%]#', $us_nn) && $us_nh > 0;
    $j(us_t('PRUEF.NAMEN'), $us_nok, sprintf(us_t('PRUEF.NAMEN_TEXT'), count($us_nn), $us_nh, $us_nnc));

    // --- Fuellstandsrechnung (V1, seit 1.2.18) -------------------------------------
    $us_form = us_cfg($cfg, 'behaelter_form', 'linear');
    $us_formen = us_behaelterformen();
    $us_lc = trim(us_roh($cfg, 'leer_cm'));
    $us_vc = trim(us_roh($cfg, 'voll_cm'));
    if (!isset($us_formen[$us_form])) {
        $j(us_t('PRUEF.FUELL'), false, sprintf(us_t('PRUEF.FUELL_FORM'), $us_form));
    } elseif ($us_lc === '' || $us_vc === '') {
        $o(us_t('PRUEF.FUELL'), sprintf(us_t('PRUEF.FUELL_OFFEN'), $us_formen[$us_form]));
    } elseif (us_fuellstand_hinweis($cfg) !== '') {
        $j(us_t('PRUEF.FUELL'), false, us_fuellstand_hinweis($cfg));
    } else {
        $us_vol = trim(us_roh($cfg, 'volumen_liter'));
        $j(us_t('PRUEF.FUELL'), true, sprintf(us_t('PRUEF.FUELL_JA'), $us_formen[$us_form], $us_vc, $us_lc,
            $us_vol !== '' ? $us_vol : '-'));
    }

    // --- MQTT dieses Plugins (Durchgang 02.10.2026, O9) -------------------------
    /* Bis 1.2.10 gab es keine Zeile zum MQTT-Schalter des Plugins, und die
     * Gateway-Zeile blieb gruen, auch wenn dieses Plugin gar nichts sendet
     * (gemessen, Oberflaechen-Pruefer 11; Regeln/04, BatterieBMS 0.9.17).
     * Ist MQTT aus, sind die Folgezeilen grau: ueber einen Weg, der nicht
     * benutzt wird, ist nichts zu sagen. Der Verbindungszustand kommt aus
     * der Zustandsdatei (MQTT-Pruefer M2): eine abgewiesene Anmeldung stand
     * bis 1.2.10 nur im Protokoll. */
    $us_mqtt_an = us_cfg($cfg, 'mqtt', '1') === '1';
    if ($us_mqtt_an) {
        $j(us_t('PRUEF.MQTT_EIN'), true, us_t('PRUEF.MQTT_EIN_JA'));
    } else {
        $o(us_t('PRUEF.MQTT_EIN'), us_t('PRUEF.MQTT_EIN_AUS'));
    }
    $us_st = us_status();
    if (!$us_mqtt_an) {
        $o(us_t('PRUEF.MQTT_VERB'), us_t('PRUEF.MQTT_AUS_GRAU'));
    } elseif ($pid <= 0 || !is_array($us_st) || !isset($us_st['mqtt'])) {
        $o(us_t('PRUEF.MQTT_VERB'), us_t('PRUEF.MQTT_VERB_OFFEN'));
    } elseif ($us_st['mqtt'] === 'verbunden') {
        $j(us_t('PRUEF.MQTT_VERB'), true, us_t('PRUEF.MQTT_VERB_JA'));
    } elseif ($us_st['mqtt'] === 'abgewiesen') {
        $j(us_t('PRUEF.MQTT_VERB'), false, sprintf(us_t('PRUEF.MQTT_VERB_ABGEWIESEN'),
            isset($us_st['mqtt_grund']) ? (string) $us_st['mqtt_grund'] : ''));
    } else {
        $j(us_t('PRUEF.MQTT_VERB'), false, us_t('PRUEF.MQTT_VERB_NEIN'));
    }

    // --- MQTT-Gateway ----------------------------------------------------------
    $g = us_mqtt_gateway_info();
    if (!$us_mqtt_an) {
        $o(us_t('PRUEF.GATEWAY'), us_t('PRUEF.MQTT_AUS_GRAU'));
    } elseif ($g === null) {
        $o(us_t('PRUEF.GATEWAY'), us_t('PRUEF.GATEWAY_OFFEN'));
    } else {
        $j(us_t('PRUEF.GATEWAY'), $g['autostart'],
           sprintf(us_t('PRUEF.GATEWAY_TEXT'),
                   $g['autostart'] ? us_t('PRUEF.JA') : us_t('PRUEF.NEIN'),
                   $g['fassung'] > 0 ? 'V' . $g['fassung'] : us_t('PRUEF.UNBEKANNT')));
    }

    return $z;
}
