<?php
/**
 * Ultraschall Entfernung - Endpunkt fuer den Miniserver (seit 1.2.0)
 *
 * Diese Datei liegt im UNANGEMELDETEN Bereich, damit Loxone sie ohne
 * Zugangsdaten erreicht, und ist durch ein Token geschuetzt:
 *
 *     /plugins/<Ordner>/index.php?token=<TOKEN>&aktion=status
 *     -> ULTRA;OK=1;DISTANCE=123.4;LEVEL=42.1;LITER=2105;VALID=1;ONLINE=1;TS=…;ZAEHLER=418
 *
 *     /plugins/<Ordner>/index.php?selftest=1&token=<TOKEN>
 *     -> SELFTEST;OK=1;TOKEN=OK
 *
 * WARUM ES DEN SELBSTTEST GIBT: ein Token muss sich pruefen lassen, ohne dass
 * etwas passiert. Ohne ihn gibt es nur zwei schlechte Moeglichkeiten - man
 * loest wirklich etwas aus, oder man erfaehrt nie, ob die Adresse im
 * Miniserver noch stimmt.
 *
 * DIESER ENDPUNKT LIEST NUR. Er schaltet nichts, er schreibt nichts, und er
 * legt nichts an: us_config_read(false). Wer sich nicht ausweisen kann, darf
 * auch nichts Harmloses hinterlassen - in diesem Haus hat ein einziger Aufruf
 * OHNE Token schon einmal eine Konfigurationsdatei samt frisch erzeugtem
 * Token zurueckgelassen.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

/* Die Bibliothek liegt NEBEN dieser Datei - installiert wie im Archiv.
 * Genau dafuer ist sie mit 1.2.0 aus htmlauth/ hierher gezogen: installiert
 * liegen die beiden Baeume getrennt, und ein '../htmlauth/us_lib.php' zeigt
 * dann auf html/plugins/htmlauth/, das es nicht gibt. Der Endpunkt endet in
 * diesem Fall mit HTTP 500 und LEEREM Rumpf, und in Loxone sieht das aus wie
 * "kein Wert" - die teuerste Fehlerklasse dieses Hauses. */
$us_lib = __DIR__ . '/us_lib.php';
if (!is_file($us_lib)) {
    // Die durchsuchten Pfade gehoeren ins Fehlerprotokoll des Webservers,
    // NICHT in die Antwort: an dieser Stelle hat sich der Aufrufer noch nicht
    // ausgewiesen.
    error_log('ultraschall: us_lib.php nicht gefunden, gesucht in ' . $us_lib);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "ULTRA;OK=0;ERR=BIBLIOTHEK_FEHLT\n";
    exit;
}
require_once $us_lib;

/**
 * Antwort abschicken und Schluss.
 *
 * Der Inhaltstyp ist text/plain: eine Befehlserkennung in Loxone liest
 * Zeichen, keine Auszeichnung.
 */
function us_ende($code, $zeile)
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $zeile . "\n";
    exit;
}

/* Parameter EINMAL zentral einsammeln.
 *
 * ?token[]=x macht aus dem Parameter ein Feld; ein trim() darauf ist unter
 * PHP 8 ein TypeError, und die Anfrage endet mit HTTP 500 und leerem Rumpf -
 * der Miniserver bekaeme statt einer Fehlermeldung gar nichts zu lesen.
 * Gelesen wird aus $_GET, nicht aus $_REQUEST: was dort steht, haengt von
 * request_order ab und schliesst ab Werk COOKIES ein. */
function us_par($name, $laenge = 64)
{
    if (!isset($_GET[$name]) || !is_string($_GET[$name])) {
        return '';
    }
    $w = trim($_GET[$name]);
    return (strlen($w) > $laenge) ? '' : $w;
}

$us_token_soll = '';
list($us_cfg, $us_alt, $us_lage) = us_config_read(false);
if (isset($us_cfg['aktionstoken'])) {
    $us_token_soll = (string) $us_cfg['aktionstoken'];
}
$us_token_ist = us_par('token');

/* Der Selbsttest steht VOR jeder Wirkung, aber HINTER derselben Tokenpruefung
 * wie alles andere - er darf keine Abkuerzung an der Sicherheit vorbei sein.
 *
 * Ein leeres Soll darf nicht auf ein leeres Ist passen: hash_equals('', '')
 * ist true, und dann stuende der Endpunkt genau auf der Anlage offen, auf der
 * nie jemand ein Token gesetzt hat. */
$us_selbsttest = (us_par('selftest') === '1');

if ($us_token_soll === '') {
    us_ende(403, ($us_selbsttest ? 'SELFTEST' : 'ULTRA')
                 . ';OK=0;ERR=KEIN_TOKEN_EINGERICHTET');
}
if ($us_token_ist === '' || !hash_equals($us_token_soll, $us_token_ist)) {
    us_ende(403, ($us_selbsttest ? 'SELFTEST' : 'ULTRA') . ';OK=0;ERR=TOKEN');
}
if ($us_selbsttest) {
    us_ende(200, 'SELFTEST;OK=1;TOKEN=OK');
}

/* Weissliste. Was nicht daraufsteht, wird abgewiesen und nicht geraten. */
$us_aktion = us_par('aktion', 16);
if ($us_aktion === '') {
    $us_aktion = 'status';
}
if (!in_array($us_aktion, array('status', 'json'), true)) {
    us_ende(400, 'ULTRA;OK=0;ERR=AKTION_UNBEKANNT');
}

/* Die Werte kommen aus der Zustandsdatei des Dienstes - dieser Endpunkt misst
 * NICHT selbst. Zwei Prozesse am selben Sensor vertragen sich nicht: bei I2C
 * serialisiert der Kern zwar einzelne Uebertragungen, aber nicht die Folge aus
 * Schreiben, Warten und Lesen; heraus kaeme ein Wert, der zu keiner der beiden
 * Anfragen gehoert. Still und falsch. */
$us_st = us_status();
$us_alter = us_status_alter();

/* Das Alter wird zur LESEZEIT gerechnet, nicht beim Schreiben eingefroren -
 * sonst kann ein toter Dienst nicht von einer frischen Messung unterschieden
 * werden. Und ONLINE beantwortet die Frage, die der Anwender stellt ("ist
 * dieser Wert aktuell?"), nicht die, die der Dienst beim Schreiben beantworten
 * konnte. Die Grenze liegt deutlich ueber dem Takt, damit ein einzelner
 * langsamer Durchlauf nichts ausloest. */
$us_takt = (int) us_cfg($us_cfg, 'intervall', '60');
$us_grenze = max(180, 3 * $us_takt);
$us_frisch = ($us_alter >= 0 && $us_alter <= $us_grenze);

$us_werte = array(
    'distance' => ($us_st && isset($us_st['entfernung'])) ? $us_st['entfernung'] : null,
    'level'    => ($us_st && isset($us_st['prozent'])) ? $us_st['prozent'] : null,
    'liter'    => ($us_st && isset($us_st['liter'])) ? $us_st['liter'] : null,
    'valid'    => ($us_st && isset($us_st['entfernung']) && $us_st['entfernung'] !== null) ? 1 : 0,
    'online'   => $us_frisch ? 1 : 0,
    'ts'       => ($us_st && isset($us_st['zeit'])) ? (int) $us_st['zeit'] : 0,
    // -1 heisst "noch nie gelaufen". 0 waere ein gueltiger Stand des
    // umlaufenden Zaehlers und damit nicht zu unterscheiden.
    'zaehler'  => ($us_st && isset($us_st['zaehler'])) ? (int) $us_st['zaehler'] : -1,
);

if ($us_aktion === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $us_js = json_encode(array(
        'ok'      => $us_frisch ? 1 : 0,
        'alter'   => $us_alter,
        'grenze'  => $us_grenze,
        'werte'   => $us_werte,
        'fehler'  => ($us_st && isset($us_st['fehler'])) ? $us_st['fehler'] : '',
        'sensor'  => ($us_st && isset($us_st['sensor'])) ? $us_st['sensor'] : '',
        'version' => ($us_st && isset($us_st['version'])) ? $us_st['version'] : '',
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // Erst kodieren, dann den Rueckgabewert ansehen, dann schreiben: bei
    // ungueltigem UTF-8 liefert json_encode false, und daraus wuerde sonst
    // eine leere Antwort mit HTTP 200.
    if ($us_js === false) {
        us_ende(500, 'ULTRA;OK=0;ERR=JSON');
    }
    echo $us_js . "\n";
    exit;
}

/* Die Statuszeile. OK sagt, ob der Wert aktuell ist - nicht, ob irgendwann
 * einmal eine Messung gelungen ist. */
$us_zeile = 'ULTRA;OK=' . ($us_frisch ? '1' : '0')
          . ';' . substr(us_zeile($us_werte), strlen('ULTRA;'))
          . ';ALTER=' . ($us_alter < 0 ? -1 : $us_alter);
us_ende(200, $us_zeile);
