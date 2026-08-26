<?php
/**
 * Ultraschall Entfernung - Admin-Oberflaeche (v1.0.0)
 * Reiter: Einstellungen | Einbindung in Loxone | Test | Logdateien
 *
 * Loest die alte Perl-CGI-Oberflaeche ab (webfrontend/cgi/index.cgi mit
 * HTML::Template und je einer Sprachdatei fuer Deutsch und Englisch).
 * Alles auf Deutsch.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '1');

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

$us_p = us_paths();
if ($us_p['home']) {
    $us_sdk = $us_p['home'] . '/libs/phplib/loxberry_system.php';
    if (file_exists($us_sdk)) {
        require_once $us_sdk;
        require_once $us_p['home'] . '/libs/phplib/loxberry_web.php';
    }
}

$us_saved = false;
$us_error = '';
$us_hinweis = '';

/* Die Konfiguration wird beim Aufruf der OBERFLAECHE vervollstaendigt - und
 * nur hier. Der Endpunkt im unangemeldeten Bereich ruft us_config_read(false)
 * und legt nichts an; wer sich nicht ausweisen kann, hinterlaesst auch nichts
 * Harmloses. Beim ersten Mal entsteht dabei das Aktionstoken. */
list($us_cfg, $us_altformat, $us_lage) = us_config_read(true);

/* ============ WACHPOSTEN gegen fremde Absender ============
 *
 * htmlauth schuetzt gegen den unangemeldeten Aufruf - NICHT dagegen, dass der
 * Browser eines angemeldeten Bedieners ein Formular abschickt, das auf einer
 * fremden Seite steht. Der Browser schickt die hinterlegten Zugangsdaten bei
 * einer Anfrage von aussen mit. Ausloesbar waeren sonst: Dienst anhalten,
 * Dienst neu starten, einen Kalibrierpunkt schreiben, eine untergeschobene
 * Sicherung einspielen und das Aktionstoken neu wuerfeln - Letzteres macht
 * JEDE Adresse im Miniserver ungueltig.
 *
 * Geprueft wird an EINER Stelle vor allen Handlern, und bei Fehlschlag wird
 * $_POST bis auf den Reiter GELEERT. Das ist mit Absicht gruendlicher als
 * eine Abfrage vor jedem Handler: der naechste Handler, den jemand ergaenzt,
 * ist damit von selbst mitgeschuetzt. Ein Schutz, den man beim Erweitern
 * vergessen kann, ist keiner.
 *
 * Fail closed: ohne hinterlegtes Aktionstoken gibt es nichts zu vergleichen,
 * und hash_equals('', '') waere wahr. */
$us_ist_post = ($_SERVER['REQUEST_METHOD'] === 'POST');
if ($us_ist_post) {
    $us_fmt_soll = us_formtoken($us_cfg);
    $us_fmt_ist = (isset($_POST['formtoken']) && is_string($_POST['formtoken']))
        ? $_POST['formtoken'] : '';
    if ($us_fmt_soll === '' || !hash_equals($us_fmt_soll, $us_fmt_ist)) {
        $us_behalten = (isset($_POST['activetab']) && is_string($_POST['activetab']))
            ? $_POST['activetab'] : null;
        $_POST = array();
        if ($us_behalten !== null) {
            $_POST['activetab'] = $us_behalten;
        }
        $us_ist_post = false;
        // Ein Formular, das wortlos nichts tut, schickt den Anwender auf die
        // Suche nach einem Fehler, den es nicht gibt.
        $us_error = us_t('FEHLER.FORMULAR_FREMD');
    }
}

/* Die Reiterwahl steht NACH dem Wachposten. Sonst uebernimmt sie das
 * activetab eines abgewiesenen POST, und ein fremdes Formular koennte
 * wenigstens noch den Reiter umschalten. */
$us_wunsch = isset($_POST['activetab']) ? (string) $_POST['activetab']
    : (isset($_GET['tab']) ? 'tab-' . (string) $_GET['tab'] : '');
/* Die Positivliste steht AUSGESCHRIEBEN da, nicht gerechnet.
 *
 * hausstandard_pruefen.py sucht sie als Literal; eine mit implode()
 * zusammengesetzte Fassung steht im Quelltext in keiner der beiden Formen,
 * die es kennt, und die Spalte meldet dann "nicht gemessen". Ein Strich ist
 * ausdruecklich kein Haken.
 *
 * Dass die drei Stellen - diese Liste, die Leiste weiter unten und die id der
 * Flaechen - trotzdem nicht auseinanderlaufen koennen, misst der Reiter Test
 * nach. Ausgeschrieben UND nachgemessen, nicht ausgeschrieben und gehofft.
 *
 * Fehlt ein Name hier, ist der Reiter sichtbar und anklickbar - aber nach
 * jedem Absenden eines Formulars springt die Seite auf Einstellungen zurueck. */
$us_reiter_liste = array('tab-settings', 'tab-mqtt', 'tab-loxone', 'tab-test', 'tab-log');
$us_tab = in_array($us_wunsch, $us_reiter_liste, true) ? $us_wunsch : $us_reiter_liste[0];

/* ============ Loxone-Vorlage herunterladen ============ */
if ($us_ist_post && isset($_POST['download'])) {
    $art = (string) $_POST['download'];
    if ($art === 'udp_in' && trim(us_roh($us_cfg, 'udp_port')) === '') {
        $us_error = us_t('FEHLER.UDP_VORLAGE_PORT');
        $us_tab = 'tab-loxone';
    } else {
        list($name, $inhalt) = us_vorlage($us_cfg, $art);
        header('Content-Type: application/x-download');
        // Dateiname in Anfuehrungszeichen, wie es RFC 6266 vorsieht. Die
        // Namen sind hier fest vergeben und enthalten kein Leerzeichen - der
        // naechste Name muss es aber nicht auch nicht enthalten.
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . strlen($inhalt));
        echo $inhalt;
        exit;
    }
}

/* ============ Test-Aktionen ============ */
$us_test_titel = '';
$us_test_text = '';
if ($us_ist_post && isset($_POST['test'])) {
    require_once __DIR__ . '/us_test.php';
    list($us_test_titel, $us_test_text) = us_test_ausfuehren((string) $_POST['test']);
    $us_tab = 'tab-test';
}

/* ============ Kalibrierpunkt uebernehmen ============ */
if ($us_ist_post && isset($_POST['kalibrieren'])) {
    require_once __DIR__ . '/us_test.php';
    $mess = us_einmal_messen();
    if (!isset($mess['entfernung']) || $mess['entfernung'] === null) {
        $us_error = us_t('FEHLER.MESSUNG_UNBRAUCHBAR')
            . (!empty($mess['fehler']) ? ': ' . us_e($mess['fehler']) : '.');
    } else {
        $feld = $_POST['kalibrieren'] === 'voll' ? 'voll_cm' : 'leer_cm';
        $us_cfg[$feld] = (string) $mess['entfernung'];
        if (us_config_write($us_cfg)) {
            $us_hinweis = sprintf(us_t('MELD.GEMESSEN'), us_e($mess['entfernung']),
                $feld === 'voll_cm' ? us_t('TEXT.VOLL_Q') : us_t('TEXT.LEER_Q'));
            $us_saved = true;
            list($us_cfg, $us_altformat) = us_config_read();
        } else {
            $us_error = sprintf(us_t('FEHLER.CONFIG_SCHREIBEN'), us_e($us_p['config']));
        }
    }
    $us_tab = 'tab-settings';
}



/* ============ Speichern ============
 *
 * JEDES Formular nennt sich selbst, und jeder Zweig fasst NUR seine eigenen
 * Schluessel an. Ohne das setzt ein Speichern der Einstellungen die
 * MQTT-Werte mit - ein nicht angehakter Haken steht ueberhaupt nicht im POST,
 * und aus "MQTT ein" wuerde beim Speichern der Einstellungen still "aus".
 * Genau diese Bauart hat in diesem Haus schon Konfigurationen geleert.
 *
 * Fehlt die Angabe oder ist sie unbekannt, wird ABGEWIESEN statt geraten. */
function us_formularfelder($name)
{
    $f = array(
        'einstellungen' => array(
            'haken' => array('enabled', 'udp'),
            'werte' => array('sensor', 'i2c_bus', 'i2c_adresse', 'gpio_trigger',
                             'gpio_echo', 'messungen', 'messabstand', 'min_cm',
                             'max_cm', 'offset_cm', 'leer_cm', 'voll_cm',
                             'volumen_liter', 'intervall', 'aktualisierung',
                             'udp_miniserver', 'udp_port'),
        ),
        'mqtt' => array(
            'haken' => array('mqtt'),
            'werte' => array('themenpraefix'),
        ),
    );
    return isset($f[$name]) ? $f[$name] : null;
}

if ($us_ist_post && isset($_POST['save'])) {
    $us_form = (isset($_POST['formular']) && is_string($_POST['formular']))
        ? $_POST['formular'] : '';
    $us_feldsatz = us_formularfelder($us_form);
    if ($us_feldsatz === null) {
        $us_error = us_t('FEHLER.FORMULAR_UNBEKANNT');
    } else {
        // Grundlage ist der GESPEICHERTE Stand; darueber kommen ausschliesslich
        // die Felder DIESES Formulars.
        $us_roh = $us_cfg;
        foreach ($us_feldsatz['haken'] as $us_h) {
            $us_roh[$us_h] = isset($_POST[$us_h]) ? '1' : '0';
        }
        foreach ($us_feldsatz['werte'] as $us_w) {
            if (isset($_POST[$us_w]) && !is_array($_POST[$us_w])) {
                $us_roh[$us_w] = (string) $_POST[$us_w];
            }
        }
        list($us_neuwerte, $us_maengel) = us_pruefen($us_roh);

        /* Beanstandungen melden, nicht das ganze Speichern verhindern. */
        if ($us_maengel) {
            $us_error = implode(' ', $us_maengel);
        }
        if (us_config_write($us_neuwerte)) {
            $us_saved = true;
            us_dienst('restart');
            $us_hinweis = us_dienst_pid()
                ? us_t('MELD.DIENST_NEUSTART')
                : us_t('MELD.DIENST_LAEUFT_NICHT');
            list($us_cfg, $us_altformat) = us_config_read();
        } else {
            $us_error = sprintf(us_t('FEHLER.CONFIG_SCHREIBEN'), us_e($us_p['config']));
        }
    }
    $us_tab = ($us_form === 'mqtt') ? 'tab-mqtt' : 'tab-settings';
}

/* ============ Neues Aktionstoken ============
 *
 * Oranger Knopf mit Rueckfrage: er liest nicht, er macht JEDE Adresse
 * ungueltig, die im Miniserver steht. Der Warnhinweis steht DANEBEN, nicht
 * erst in der Antwort nach dem Klick. */
if ($us_ist_post && isset($_POST['token_neu'])) {
    $us_cfg['aktionstoken'] = us_token_neu();
    if (us_config_write($us_cfg)) {
        $us_saved = true;
        $us_hinweis = us_t('TEXT.TOKEN_NEU_OK');
        list($us_cfg, $us_altformat) = us_config_read();
    } else {
        $us_error = sprintf(us_t('FEHLER.CONFIG_SCHREIBEN'), us_e($us_p['config']));
    }
    $us_tab = 'tab-loxone';
}

$us_praefix = us_cfg($us_cfg, 'themenpraefix', 'ultraschall');
$us_sensor  = us_cfg($us_cfg, 'sensor', 'srf02');
$us_pid     = us_dienst_pid();
$us_status  = us_status();
$us_alter   = us_status_alter();
$us_broker  = us_mqtt_broker();
$us_ms      = us_miniservers();
$us_log     = us_log_file();
$us_zeilen  = us_log_tail($us_log);
$us_hat_kalibrierung = trim(us_roh($us_cfg, 'leer_cm')) !== '' && trim(us_roh($us_cfg, 'voll_cm')) !== '';

/* ---------------- Einstellungen sichern ----------------
 *
 * Ausgegeben wird die VOLLE Konfiguration - alle 21 Schluessel aus
 * us_defaults(), nicht nur die abweichenden. Ein Schluessel, der in der
 * Sicherung fehlt, kaeme beim Zurueckspielen aus der Vorgabe, und das ist
 * genau dann falsch, wenn jemand ihn bewusst auf den Vorgabewert gesetzt
 * hat und die Vorgabe sich spaeter aendert.
 *
 * ZWEIERLEI STAND BIS 1.1.11 FALSCH. Beides ist am 26.08.2026 am
 * ausgelieferten Tag-Archiv und an einem echten Webserver gemessen worden:
 *
 *   1. Die Lesefunktion fuer EINEN Konfigurationswert wurde hier ohne ihre
 *      beiden Pflichtargumente gerufen. Unter 7.4.33 wie unter 8.4.24 endete
 *      das mit einem ArgumentCountError, Rueckgabewert 255. Der Anwender bekam
 *      keine Datei, sondern eine halb aufgebaute Seite mit einer PHP-Meldung.
 *   2. Der ganze Block stand HINTER LBWeb::lbheader(). Dort ist
 *      headers_sent() bereits JA - die drei header()-Aufrufe unten greifen
 *      dann nicht ("Cannot modify header information"), der Inhaltstyp bleibt
 *      text/html, und dem JSON steht der Seitenkopf voran. Auch mit
 *      berichtigtem Aufruf war das Ergebnis kein gueltiges JSON; erst beide
 *      Korrekturen zusammen ergaben eine Datei.
 *
 * Deshalb steht der Block jetzt VOR jeder Ausgabe. Wer ihn wieder nach unten
 * schiebt, nimmt Punkt 2 zurueck.
 *
 * KEIN AKTIONSTOKEN: dieses Plugin hat keines, weil es keinen Endpunkt im
 * unangemeldeten Bereich gibt - den Ordner webfrontend/html/ gibt es nicht.
 * Der Warntext am Knopf sagt deshalb NICHT, die Datei enthalte Zugangsdaten;
 * sie enthaelt keine. Kommt der Endpunkt, kommt der Satz mit ihm. */
if ($us_ist_post && isset($_POST['us_sichern'])) {
    $us_js = json_encode($us_cfg,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($us_js !== false) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="ultraschall_einstellungen_'
               . date('Ymd_His') . '.json"');
        echo $us_js;
        exit;
    }
    $us_error = us_t('TEXT.SICH_SCHREIBFEHLER');
}

/* ---------------- Einstellungen zurueckspielen ----------------
 *
 * is_uploaded_file() ZUERST: ohne diese Pruefung liesse sich jede Datei des
 * Servers unterschieben. Dann die Groessengrenze - eine Sicherung dieses
 * Plugins ist wenige Kilobyte gross; alles darueber wird gar nicht gelesen. */
if ($us_ist_post && isset($_POST['us_zurueck'])) {
    // VOR dem Schreiben feststellen, ob der Dienst lief - danach ist es
    // nicht mehr zu unterscheiden.
    $us_lief_vorher = us_dienst_pid() > 0;
    if (!isset($_FILES['us_sicherung']) || !is_array($_FILES['us_sicherung'])
        || !isset($_FILES['us_sicherung']['tmp_name'])
        || !@is_uploaded_file($_FILES['us_sicherung']['tmp_name'])) {
        $us_error = us_t('TEXT.SICH_KEINE_DATEI');
    } elseif ((int) $_FILES['us_sicherung']['size'] > 262144) {
        $us_error = us_t('TEXT.SICH_ZU_GROSS');
    } else {
        list($us_neu, $us_mangel, $us_n) = us_sicherung_lesen(
            (string) @file_get_contents($_FILES['us_sicherung']['tmp_name']));
        if ($us_neu === null) {
            /* ALLE Beanstandungen, nicht nur die erste - und geaendert wird
             * nichts. */
            $us_error = us_t('TEXT.SICH_ABGELEHNT') . ' '
                            . implode(' ', $us_mangel);
        } elseif (us_config_write($us_neu)) {
            $us_saved = true;
            list($us_cfg, $us_altformat) = us_config_read();
            /* Punkt 7 der Hausregel: den Dienst nachziehen UND sagen, was mit
             * ihm geschehen ist.
             *
             * Der Dauerlaeufer liest die Konfiguration zwar im Betrieb neu
             * ein, uebernimmt dabei aber weder das Themenpraefix noch den
             * MQTT-Zustand - beide setzt er nur beim Start. Gemessen mit
             * Attrappe: Praefix in der Datei auf einen anderen Wert geaendert,
             * Dienst liest neu ein, misst weiter - und veroeffentlicht ueber
             * den ganzen Lauf ausschliesslich unter dem ALTEN. Ohne diesen
             * Neustart traegt eine zurueckgespielte Sicherung ihr Praefix
             * nicht, und in Loxone kommt nichts mehr an.
             *
             * Ein bewusst angehaltener Dienst bleibt angehalten: ein Plugin,
             * das gegen den Willen des Anwenders startet, ist schlimmer als
             * eines, das stehen bleibt. */
            $us_hinweis = sprintf(us_t('TEXT.SICH_UEBERNOMMEN'), $us_n) . ' ';
            if ($us_lief_vorher) {
                us_dienst('restart');
                $us_hinweis .= us_t(us_dienst_pid()
                    ? 'TEXT.SICH_DIENST_NEU' : 'TEXT.SICH_DIENST_FEHL');
            } else {
                $us_hinweis .= us_t('TEXT.SICH_DIENST_AUS');
            }
        } else {
            $us_error = us_t('TEXT.SICH_SCHREIBFEHLER');
        }
    }
}

// WICHTIG: LBWeb::lbheader() setzt SDK-Globale - deshalb ueberall us_-Praefix.
$us_frame = class_exists('LBWeb', false);
if ($us_frame) {
    LBWeb::lbheader(us_t('TEXT.TITEL'), 'https://wiki.loxberry.de/plugins/ultraschall_entfernung/start', 'help.html');
}


?>
<style>
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.sm-wrap input[type=text], .sm-wrap input[type=number], .sm-wrap select {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.sm-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0 6px 0 0; vertical-align: middle; }
.sm-check { font-weight: 400 !important; font-size: 0.95em !important; color: #333 !important; }
.sm-row { display: flex; gap: 12px; flex-wrap: wrap; }
.sm-row > div { flex: 1; min-width: 180px; }
.sm-btn { background: #6dac20; color: #fff !important; border: 0; border-radius: 6px; padding: 10px 22px; font-size: 1em; cursor: pointer; margin-top: 18px; font-weight: 600; }
.sm-wrap .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button { box-shadow: none !important; }
.sm-wrap a.sm-btn, .sm-wrap a.sm-btn:visited, .sm-wrap a.sm-btn:hover { color: #fff !important; text-decoration: none; }
.sm-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.sm-ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
.sm-err { background: #ffebee; border: 1px solid #ef9a9a; }
.sm-info { background: #e3f2fd; border: 1px solid #90caf9; font-size: 0.9em; }
.sm-mono { font-family: ui-monospace, monospace; background: #f5f5f5; padding: 2px 6px; border-radius: 4px; }
.sm-small { font-size: 0.82em; color: #666; margin-top: 3px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 9px 18px; cursor: pointer; font-size: 0.95em; color: #444 !important;
  text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-pane { display: none; padding-top: 4px; }
.sm-pane.sm-active { display: block; }
.sm-log { background: #1e1e1e; color: #d4d4d4; font-family: ui-monospace, monospace; font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.sm-step { margin: 10px 0; padding: 10px 14px; background: #fafafa; border-left: 4px solid #6dac20; border-radius: 0 8px 8px 0; }
.sm-tbl { border-collapse: collapse; margin: 8px 0; width: 100%; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; font-size: 0.9em; vertical-align: middle; }
.sm-tbl th { background: #f0f0f0; }
.sm-gross { font-size: 1.6em; font-weight: 700; color: #4f7d17; }
.sm-tank { height: 16px; background: #eceff1; border-radius: 4px; overflow: hidden; margin: 6px 0 2px; }
.sm-tank i { display: block; height: 100%; background: #6dac20; }

/* --- Einheitliches Kachel-Raster im Reiter <?php echo us_t('TEXT.TEST'); ?> (Hausstandard) --- */
.sm-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-knopfreihe .sm-btn { flex: 0 0 auto; min-width: 250px; text-align: center;
    display: inline-flex; align-items: center; justify-content: center; line-height: 1.25; margin-top: 0; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-btn.sm-b-lesen   { background: #6dac20; }
.sm-btn.sm-b-technik { background: #546e7a; }
.sm-btn.sm-b-aktion  { background: #e0620d; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }

/* Nachgetragene Definitionen (CSS-Luecken-Durchgang 13.08.2026):
   benutzt, aber nie definiert - wortgleich aus der Hausstandard-Vorlage
   bzw. der Referenzimplementierung uebernommen. */
.sm-warn { background: #fdf3e3; border: 1px solid #e0620d; }
/* Benutzt vom Sicherungsblock, bis 1.1.11 nirgends definiert -
   hausstandard_pruefen.py meldete beide als "benutzt, aber nirgends
   definiert". Der Warnhinweis zur Sicherungsdatei stand dadurch als
   grauer Fliesstext da. Wortgleich aus VORLAGE_hausstandard.css.html. */
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
              padding: 8px 12px; margin: 8px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
              padding: 8px 12px; margin: 8px 0; font-size: 0.9em; }
</style>
<div class="sm-wrap">

<?php if ($us_saved) { ?>
<div class="sm-alert sm-ok"><b><?php echo us_t('TEXT.GESPEICHERT'); ?></b> <?= $us_hinweis ?></div>
<?php } ?>
<?php if ($us_error !== '') { ?><div class="sm-alert sm-err"><b><?php echo us_t('TEXT.HINWEIS'); ?></b> <?= $us_error ?></div><?php } ?>
<?php if ($us_lage['quelle'] !== 'ok') { ?>
<div class="sm-alert sm-err"><b><?php echo us_t('TEXT.HINWEIS'); ?></b> <?php echo us_t('TEXT.KEINE_DATENQUELLE'); ?></div>
<?php } ?>
<?php if (!empty($us_lage['ergaenzt'])) { ?>
<div class="sm-alert sm-info"><?php printf(us_t('TEXT.LAGE_ERGAENZT'), us_e(implode(', ', $us_lage['ergaenzt']))); ?></div>
<?php } ?>
<?php if (!empty($us_lage['fremd'])) { ?>
<div class="sm-alert sm-info"><?php printf(us_t('TEXT.LAGE_FREMD'), us_e(implode(', ', $us_lage['fremd']))); ?></div>
<?php } ?>
<?php if ($us_altformat) { ?>
<div class="sm-alert sm-info"><?php echo us_t('TEXT.DIE_KONFIGURATION_STAMMT_NOCH_AUS_'); ?></div>
<?php } ?>

<div class="sm-alert sm-info">
<?php echo us_t('TEXT.DIENST'); ?> <b><?= $us_pid ? 'l&auml;uft' : 'l&auml;uft nicht' ?></b><?= $us_pid ? ' (PID ' . $us_pid . ')' : '' ?>
<?php echo us_t('TEXT.PLUGIN'); ?> <b><?= us_cfg($us_cfg, 'enabled', '0') === '1' ? 'eingeschaltet' : 'ausgeschaltet' ?></b>
<?php echo us_t('TEXT.SENSOR'); ?> <span class="sm-mono"><?= $us_sensor === 'hcsr04' ? 'HC-SR04' : 'SRF02' ?></span>
<?php if ($us_status && $us_status['entfernung'] !== null) { ?>
<?php echo us_t('TEXT.ZULETZT'); ?> <b><?= us_e(number_format((float) $us_status['entfernung'], 1, ',', '')) ?><?php echo us_t('TEXT.CM'); ?></b>
<?php if (isset($us_status['prozent']) && $us_status['prozent'] !== null) { ?>
(<?= us_e(number_format((float) $us_status['prozent'], 1, ',', '')) ?>&nbsp;%)
<?php } ?>
<?php } ?>
&middot; <?php echo us_t('TEXT.MQTT_2'); ?>: <b><?= us_cfg($us_cfg, 'mqtt', '1') === '1' ? 'ein' : 'aus' ?></b>
<?php if ($us_alter >= 0) { ?><?php echo us_t('TEXT.STAND_VOR'); ?> <?= $us_alter ?> s<?php } ?>
</div>

<?php
/*
 * Die Reiter sind echte Verweise, keine <div>. Vorher stand hier
 * <div class="sm-tab" data-pane="..."> - und weil alle Flaechen bis zum Lauf
 * des JavaScripts auf display:none stehen, war die Seite ohne JavaScript
 * vollstaendig leer. Jetzt setzt der Server die Klasse sm-active an Reiter
 * UND Flaeche; das JavaScript spart nur noch den Seitenaufbau.
 */
?>
<!-- Die Reiterleiste steht AUSGESCHRIEBEN. Eine foreach-Schleife waere
     sauberer Code und macht hausstandard_pruefen.py blind - es findet die
     Reiter dann nicht und meldet die Spalte als nicht messbar.
     Gegengeprueft wird die Uebereinstimmung mit der Positivliste oben und
     mit den ids der Flaechen im Reiter Test. -->
<div class="sm-tabs">
<a class="sm-tab<?php echo $us_tab === 'tab-settings' ? ' sm-active' : ''; ?>" data-pane="tab-settings" href="index.php?tab=settings"><?php echo us_t('REITER.EINSTELLUNGEN'); ?></a>
<a class="sm-tab<?php echo $us_tab === 'tab-mqtt' ? ' sm-active' : ''; ?>" data-pane="tab-mqtt" href="index.php?tab=mqtt"><?php echo us_t('REITER.MQTT'); ?></a>
<a class="sm-tab<?php echo $us_tab === 'tab-loxone' ? ' sm-active' : ''; ?>" data-pane="tab-loxone" href="index.php?tab=loxone"><?php echo us_t('REITER.LOXONE'); ?></a>
<a class="sm-tab<?php echo $us_tab === 'tab-test' ? ' sm-active' : ''; ?>" data-pane="tab-test" href="index.php?tab=test"><?php echo us_t('REITER.TEST'); ?></a>
<a class="sm-tab<?php echo $us_tab === 'tab-log' ? ' sm-active' : ''; ?>" data-pane="tab-log" href="index.php?tab=log"><?php echo us_t('REITER.LOG'); ?></a>
</div>

<!-- ================= Reiter: <?php echo us_t('TEXT.EINSTELLUNGEN'); ?> ================= -->
<div class="sm-pane<?php echo $us_tab === 'tab-settings' ? ' sm-active' : ''; ?>" id="tab-settings">

<?php if ($us_status && $us_status['entfernung'] !== null) { ?>
<h2><?php echo us_t('TEXT.AKTUELLER_MESSWERT'); ?></h2>
<div class="sm-gross"><?= us_e(number_format((float) $us_status['entfernung'], 1, ',', '')) ?> cm</div>
<?php if (isset($us_status['prozent']) && $us_status['prozent'] !== null) { ?>
<div class="sm-tank"><i style="width: <?= (float) $us_status['prozent'] ?>%;"></i></div>
<div class="sm-small"><?php echo us_t('TEXT.FLLSTAND'); ?> <?= us_e(number_format((float) $us_status['prozent'], 1, ',', '')) ?>&nbsp;%<?php
if (isset($us_status['liter']) && $us_status['liter'] !== null) {
    echo ' &middot; ' . sprintf(us_t('TEXT.RUND_LITER'),
        us_e(number_format((float) $us_status['liter'], 1, ',', '.')));
} ?> &middot; <?php echo us_t('TEXT.STAND_VOR_3'); ?> <?= $us_alter ?> <?php echo us_t('TEXT.SEKUNDEN'); ?></div>
<?php } else { ?>
<div class="sm-small"><?php echo us_t('TEXT.STAND_VOR_3'); ?> <?= $us_alter ?> <?php echo us_t('TEXT.SEKUNDEN_EIN_FLLSTAND_WIRD_ERST_BE'); ?></div>
<?php } ?>
<?php } ?>

<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-settings"><?php echo us_fmt($us_cfg); ?>
<!-- Jedes Formular nennt sich selbst. Der Speicher-Zweig fasst dann NUR
     die Schluessel DIESES Formulars an - sonst setzt ein Speichern der
     Einstellungen die MQTT-Werte mit, weil ein nicht angehakter Haken
     ueberhaupt nicht im POST steht. -->
<input data-role="none" type="hidden" name="formular" value="einstellungen">

<h2><?php echo us_t('TEXT.BETRIEB'); ?></h2>
<label class="sm-check"><input data-role="none" type="checkbox" name="enabled" value="1"<?= us_cfg($us_cfg, 'enabled', '0') === '1' ? ' checked' : '' ?>> <b><?php echo us_t('TEXT.PLUGIN_EINGESCHALTET'); ?></b></label>
<div class="sm-small"><?php echo us_t('TEXT.SOLANGE_DAS_NICHT_ANGEHAKT_IST_LUF'); ?></div>

<h2><?php echo us_t('TEXT.SENSOR_2'); ?></h2>
<label><?php echo us_t('TEXT.BAUART'); ?></label>
<select data-role="none" name="sensor" id="sm-sensorwahl">
<?php foreach (us_sensoren() as $k => $bez) { ?>
<option value="<?= us_e($k) ?>"<?= $us_sensor === $k ? ' selected' : '' ?>><?= us_e($bez) ?></option>
<?php } ?>
</select>

<div id="sm-srf02" class="sm-row" style="margin-top:8px;">
<div>
<label><?php echo us_t('TEXT.I2C_BUS'); ?></label>
<input data-role="none" type="number" name="i2c_bus" min="0" max="20" value="<?= us_e(us_cfg($us_cfg, 'i2c_bus', '1')) ?>">
<div class="sm-small"><?php echo us_t('TEXT.AUF_DEM_RASPBERRY_PI_FAST_IMMER'); ?> <span class="sm-mono">1</span>.</div>
</div>
<div>
<label><?php echo us_t('TEXT.I2C_ADRESSE'); ?></label>
<input data-role="none" type="text" name="i2c_adresse" value="<?= us_e(us_cfg($us_cfg, 'i2c_adresse', '0x70')) ?>">
<div class="sm-small"><?php echo us_t('TEXT.AB_WERK'); ?> <span class="sm-mono">0x70</span><?php echo us_t('TEXT.WELCHE_ADRESSEN_BELEGT_SIND_ZEIGT_'); ?> <i><?php echo us_t('TEXT.SENSOR_PRFEN'); ?></i>.</div>
</div>
</div>

<div id="sm-hcsr04" class="sm-row" style="margin-top:8px;">
<div>
<label><?php echo us_t('TEXT.GPIO_TRIGGER'); ?></label>
<input data-role="none" type="number" name="gpio_trigger" min="0" max="27" value="<?= us_e(us_cfg($us_cfg, 'gpio_trigger', '23')) ?>">
</div>
<div>
<label><?php echo us_t('TEXT.GPIO_ECHO'); ?></label>
<input data-role="none" type="number" name="gpio_echo" min="0" max="27" value="<?= us_e(us_cfg($us_cfg, 'gpio_echo', '24')) ?>">
<div class="sm-small"><?php echo us_t('TEXT.BCM_NUMMERN_NICHT_STECKERPLATZNUMM'); ?></div>
</div>
</div>
<div id="sm-hcsr04-warn" class="sm-alert sm-info" style="margin-top:6px;">
<b><?php echo us_t('TEXT.SPANNUNG_BEACHTEN'); ?></b> <?php echo us_t('TEXT.DER_HC_SR04_ARBEITET_MIT_5V_DER_EC'); ?>
</div>

<h2><?php echo us_t('TEXT.MESSUNG'); ?></h2>
<div class="sm-row">
<div>
<label><?php echo us_t('TEXT.WERTE_JE_DURCHGANG'); ?></label>
<input data-role="none" type="number" name="messungen" min="1" max="25" value="<?= us_e(us_cfg($us_cfg, 'messungen', '5')) ?>">
<div class="sm-small"><?php echo us_t('TEXT.AUS_DIESEN_WERTEN_WIRD_DER'); ?> <b><?php echo us_t('TEXT.MEDIAN'); ?></b> <?php echo us_t('TEXT.GENOMMEN_NICHT_DER_MITTELWERT_EIN_'); ?></div>
</div>
<div>
<label><?php echo us_t('TEXT.ABSTAND_ZWISCHEN_DEN_WERTEN_S'); ?></label>
<input data-role="none" type="text" name="messabstand" value="<?= us_e(us_cfg($us_cfg, 'messabstand', '0.2')) ?>">
<div class="sm-small"><?php echo us_t('TEXT.ZU_KURZ_GEWHLT_HRT_DER_SENSOR_NOCH'); ?></div>
</div>
</div>
<div class="sm-row">
<div>
<label><?php echo us_t('TEXT.KLEINSTER_PLAUSIBLER_WERT_CM'); ?></label>
<input data-role="none" type="text" name="min_cm" value="<?= us_e(us_cfg($us_cfg, 'min_cm', '3')) ?>">
</div>
<div>
<label><?php echo us_t('TEXT.GRTER_PLAUSIBLER_WERT_CM'); ?></label>
<input data-role="none" type="text" name="max_cm" value="<?= us_e(us_cfg($us_cfg, 'max_cm', '400')) ?>">
<div class="sm-small"><?php echo us_t('TEXT.WERTE_AUERHALB_DIESES_BEREICHS_WER'); ?></div>
</div>
<div>
<label><?php echo us_t('TEXT.KORREKTUR_CM'); ?></label>
<input data-role="none" type="text" name="offset_cm" value="<?= us_e(us_cfg($us_cfg, 'offset_cm', '0')) ?>">
<div class="sm-small"><?php echo us_t('TEXT.WIRD_AUF_JEDEN_MESSWERT_ADDIERT_ET'); ?></div>
</div>
</div>

<h2>F&uuml;llstand</h2>
<div class="sm-small" style="margin-bottom:6px;">
<?php echo us_t('TEXT.OPTIONAL_SIND_BEIDE_FELDER_GEFLLT_'); ?>
</div>
<div class="sm-row">
<div>
<label><?php echo us_t('TEXT.ABSTAND_BEI'); ?> <b><?php echo us_t('TEXT.LEER'); ?></b> (cm)</label>
<input data-role="none" type="text" name="leer_cm" value="<?= us_e(us_roh($us_cfg, 'leer_cm')) ?>" placeholder="leer lassen = keine Umrechnung">
</div>
<div>
<label><?php echo us_t('TEXT.ABSTAND_BEI'); ?> <b><?php echo us_t('TEXT.VOLL'); ?></b> (cm)</label>
<input data-role="none" type="text" name="voll_cm" value="<?= us_e(us_roh($us_cfg, 'voll_cm')) ?>" placeholder="leer lassen = keine Umrechnung">
</div>
<div>
<label><?php echo us_t('TEXT.GESAMTVOLUMEN_LITER'); ?></label>
<!-- Der Feldname wird AUSGESCHRIEBEN. Bis 1.1.11 wurde er zur Haelfte
     aus einem Sprachschluessel gebaut. Heute steht in beiden Dateien
     derselbe Wert, es ging also gut; wer uebersetzt und dort etwas anderes
     eintraegt, bekommt ein Feld unter anderem Namen - und das Gesamtvolumen
     waere bei jedem Speichern weg, ohne dass irgendwo etwas stuende. -->
<input data-role="none" type="text" name="volumen_liter" value="<?= us_e(us_roh($us_cfg, 'volumen_liter')) ?>" placeholder="optional">
<div class="sm-small"><?php echo us_t('TEXT.NUR_BEI_SENKRECHTEN_WNDEN_VERLSSLI'); ?></div>
</div>
</div>

<h2><?php echo us_t('TEXT.ZEITEN'); ?></h2>
<div class="sm-row">
<div>
<label><?php echo us_t('TEXT.MESSEN_ALLE_SEKUNDEN'); ?></label>
<input data-role="none" type="number" name="intervall" min="5" max="86400" value="<?= us_e(us_cfg($us_cfg, 'intervall', '60')) ?>">
</div>
<div>
<label><?php echo us_t('TEXT.ALLES_NEU_MELDEN_ALLE_SEKUNDEN'); ?></label>
<input data-role="none" type="number" name="aktualisierung" min="5" max="86400" value="<?= us_e(us_cfg($us_cfg, 'aktualisierung', '300')) ?>">
<div class="sm-small"><?php echo us_t('TEXT.SONST_GEHT_NUR_HINAUS_WAS_SICH_GEN'); ?></div>
</div>
</div>

<h2><?php echo us_t('TEXT.WEG_ZUM_MINISERVER'); ?></h2>
<div class="sm-hinweis"><?php echo us_t('TEXT.MQTT_WOHNT_IM_REITER'); ?></div>
<label class="sm-check" style="margin-top:10px;"><input data-role="none" type="checkbox" name="udp" value="1"<?= us_cfg($us_cfg, 'udp', '0') === '1' ? ' checked' : '' ?>> <?php echo us_t('TEXT.ZUSTZLICH_PER_UDP_SENDEN'); ?></label>
<div class="sm-small"><?php echo us_t('TEXT.DER_WEG_DER_ORIGINALFASSUNG_DIE_EN'); ?></div>

<div class="sm-row" style="margin-top:12px;">
<div>
<label><?php echo us_t('TEXT.MINISERVER_FR_UDP'); ?></label>
<select data-role="none" name="udp_miniserver">
<?php if (!$us_ms) { ?>
<option value="1"><?php echo us_t('TEXT.MINISERVER_1'); ?></option>
<?php } foreach ($us_ms as $nr => $m) { ?>
<option value="<?= us_e($nr) ?>"<?= us_cfg($us_cfg, 'udp_miniserver', '1') === (string) $nr ? ' selected' : '' ?>><?= us_e($nr . ' - ' . $m['name'] . ' (' . $m['ip'] . ')') ?></option>
<?php } ?>
</select>
</div>
<div>
<label><?php echo us_t('TEXT.UDP_PORT'); ?></label>
<input data-role="none" type="text" name="udp_port" value="<?= us_e(us_roh($us_cfg, 'udp_port')) ?>" placeholder="z.&nbsp;B. 12345">
</div>
</div>

<button data-role="none" class="sm-btn" type="submit" name="save" value="1"><?php echo us_t('TEXT.SPEICHERN'); ?></button>
<div class="sm-small"><?php echo us_t('TEXT.BEIM_SPEICHERN_WIRD_DER_DIENST_NEU'); ?></div>
</form>

<h2><?php echo us_t('TEXT.KALIBRIERPUNKT_MESSEN'); ?></h2>
<div class="sm-small" style="margin-bottom:4px;">
<?php echo us_t('TEXT.MISST_SOFORT_UND_TRGT_DAS_ERGEBNIS'); ?>
</div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?php echo us_t('LEGENDE.AKTION_MESSEN'); ?></span></div>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-settings"><?php echo us_fmt($us_cfg); ?><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="kalibrieren" value="leer"><?php echo us_t('TEXT.JETZT_MESSEN_LEER'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-settings"><?php echo us_fmt($us_cfg); ?><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="kalibrieren" value="voll"><?php echo us_t('TEXT.JETZT_MESSEN_VOLL'); ?></button></form>
</div>

<!-- Der Sicherungsblock stand bis 1.1.11 AUSSERHALB aller Reiter und
     erschien deshalb unter jedem, auch unter Logdateien und Test. Er
     gehoert in die Einstellungen - das versteckte activetab sagt das
     ohnehin. -->
<h2><?= us_t('TEXT.H_SICHERUNG') ?></h2>
<div class="sm-hinweis"><?= us_t('TEXT.SICH_ERKLAERUNG') ?></div>
<div class="sm-warnung"><?= us_t('TEXT.SICH_WARNUNG') ?></div>
<!-- Keine Knopfreihe ohne erklaerende Legende ueber sich - und eine neue
     Knopffarbe braucht ihren Eintrag darin. Der Sicherungsblock bringt einen
     gruenen Knopf in einen Reiter, der bisher nur Orange kannte;
     hausstandard_pruefen.py hat die Spalte leg dafuer sofort klein gemeldet. -->
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo us_t('LEGENDE.LESEN_SICHERN'); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo us_t('LEGENDE.AKTION_ZURUECK'); ?></span>
</div>
<div class="sm-knopfreihe">
  <!-- ZWEI GETRENNTE Formulare. Das Sichern schickt einen Download und ruft
       exit auf; das Zurueckspielen braucht enctype="multipart/form-data".
       Wer beides in ein Formular legt, bekommt entweder keinen Upload oder
       einen Download, der das Speichern verschluckt. -->
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings"><?php echo us_fmt($us_cfg); ?>
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="us_sichern" value="1"><?= us_t('TEXT.K_SICHERN') ?></button>
  </form>
  <form action="index.php" method="post" enctype="multipart/form-data">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings"><?php echo us_fmt($us_cfg); ?>
    <input data-role="none" type="file" name="us_sicherung" accept=".json">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="us_zurueck" value="1"><?= us_t('TEXT.K_ZURUECK') ?></button>
  </form>
</div>
</div>


<!-- ================= Reiter: MQTT =================
     MQTT wohnt VOLLSTAENDIG hier - Haken, Themenpraefix, Zustand des
     Gateways, das einzutragende Abo und die Tabelle der Themen. Mit EIGENEM
     Formular und EIGENEM Speicher-Handler: ein Sammel-Handler setzt Haken
     per isset() und wuerde beim Absenden des anderen Formulars die Werte
     dieses stillschweigend nullen. -->
<div class="sm-pane<?php echo $us_tab === 'tab-mqtt' ? ' sm-active' : ''; ?>" id="tab-mqtt">

<h2><?php echo us_t('MQTT.H_WEG'); ?></h2>
<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt"><?php echo us_fmt($us_cfg); ?>
<input data-role="none" type="hidden" name="formular" value="mqtt">
<label class="sm-check"><input data-role="none" type="checkbox" name="mqtt" value="1"<?= us_cfg($us_cfg, 'mqtt', '1') === '1' ? ' checked' : '' ?>> <b>MQTT</b> <?php echo us_t('TEXT.EMPFOHLEN'); ?></label>
<div class="sm-small"><?php echo us_t('TEXT.WERTE_GEHEN_RETAINED_AN_DEN_BROKER'); ?></div>
<label style="margin-top:10px;"><?php echo us_t('TEXT.MQTT_THEMENPRFIX'); ?></label>
<input data-role="none" type="text" name="themenpraefix" value="<?= us_e($us_praefix) ?>">
<div class="sm-small"><?php echo us_t('MQTT.PRAEFIX_HINWEIS'); ?></div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?php echo us_t('LEGENDE.AKTION'); ?></span></div>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="save" value="1"><?php echo us_t('TEXT.SPEICHERN'); ?></button>
</form>

<h2><?php echo us_t('MQTT.H_ZUSTAND'); ?></h2>
<?php $us_gw = us_mqtt_gateway_info(); ?>
<?php if ($us_gw === null) { ?>
<div class="sm-warnung"><?php echo us_t('MQTT.KEIN_GATEWAY'); ?></div>
<?php } else { ?>
<?php if (!$us_gw['autostart']) { ?>
<div class="sm-warnung"><b>MQTT:</b> <?php echo us_t('TEXT.W_AUTOSTART'); ?></div>
<?php } ?>
<table class="sm-tbl">
<tr><td><?php echo us_t('MQTT.BROKER'); ?></td><td class="sm-mono"><?= $us_broker !== '' ? us_e($us_broker) : us_e(us_t('MQTT.NICHT_GEFUNDEN')) ?></td></tr>
<tr><td><?php echo us_t('MQTT.AUTOSTART'); ?></td><td><?= $us_gw['autostart'] ? us_e(us_t('PRUEF.JA')) : us_e(us_t('PRUEF.NEIN')) ?></td></tr>
<tr><td><?php echo us_t('MQTT.FASSUNG'); ?></td><td><?= $us_gw['fassung'] > 0 ? 'V' . (int) $us_gw['fassung'] : us_e(us_t('PRUEF.UNBEKANNT')) ?></td></tr>
<tr><td><?php echo us_t('TEXT.THEMENPRFIX'); ?></td><td class="sm-mono"><?= us_e($us_praefix) ?></td></tr>
</table>
<?php } ?>

<h2><?php echo us_t('MQTT.H_ABO'); ?></h2>
<div class="sm-step"><b><?php echo us_abo_text(); ?></b>
<div class="sm-mono" style="background:#f4f4f4;border:1px solid #ccc;padding:8px;margin-top:6px;"><?= us_e($us_praefix) ?>/#</div></div>

<h2><?php echo us_t('TEXT.WAS_VERFFENTLICHT_WIRD'); ?></h2>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:24%;"><?php echo us_t('TEXT.THEMA'); ?></th><th style="width:10%;"><?php echo us_t('TEXT.ART'); ?></th><th style="width:10%;"><?php echo us_t('MQTT.EINHEIT'); ?></th><th><?php echo us_t('TEXT.BEDEUTUNG'); ?></th></tr>
<?php foreach (us_felder() as $us_n => $us_f) { ?>
<tr><td><span class="sm-mono"><?= us_e($us_praefix . '/' . $us_n) ?></span></td>
    <td><?= us_e($us_f['art']) ?></td>
    <td><?= us_e($us_f['einheit']) ?></td>
    <td><?php echo us_thema_lang($us_n); ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-small"><?php echo us_t('TEXT.ALLE_THEMEN_SIND'); ?> <b><?php echo us_t('TEXT.RETAINED'); ?></b><?php echo us_t('TEXT.DER_BROKER_MERKT_SICH_DEN_LETZTEN_'); ?></div>
<div class="sm-hinweis"><?php echo us_t('MQTT.HERZSCHLAG'); ?></div>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-pane<?php echo $us_tab === 'tab-loxone' ? ' sm-active' : ''; ?>" id="tab-loxone">

<h2><?php echo us_t('TEXT.EINBINDUNG_IN_LOXONE_SCHRITT_FR_SC'); ?></h2>
<div class="sm-small"><?php echo us_t('TEXT.DER_SENSOR_MISST_DEN_ABSTAND_ZUR_O'); ?></div>
<div class="sm-step"><b><?php echo us_t('TEXT.SCHRITT_1_SENSOR_EINRICHTEN'); ?></b><br><br>
<?php echo us_t('TEXT.IM_REITER'); ?> <i><?php echo us_t('REITER.EINSTELLUNGEN'); ?></i><?php echo us_t('TEXT.DANN_IM_REITER'); ?> <i><?php echo us_t('REITER.TEST'); ?></i> mit <i><?php echo us_t('TEXT.JETZT_MESSEN'); ?></i> <?php echo us_t('TEXT.PRFEN_OB_EIN_PLAUSIBLER_WERT_HERAU'); ?></div>
<div class="sm-step"><b><?php echo us_t('TEXT.SCHRITT_2_ABO_IM_MQTT_GATEWAY_EINT'); ?></b><br><br>

<?php $us_gw2 = us_mqtt_gateway_info(); if ($us_gw2 !== null && !$us_gw2['autostart']) { ?><div class="sm-alert sm-warn"><b>MQTT:</b> <?php echo us_t('TEXT.W_AUTOSTART'); ?></div><?php } ?>
<b><?php echo us_abo_text(); ?></b> <?php echo us_t('TEXT.EINZUTRAGEN_UNTER'); ?>
<i><?php echo us_t('TEXT.SYSTEM_EINSTELLUNGEN_MQTT_GATEWAY_'); ?></i>:
<div class="sm-mono" style="background:#f4f4f4;border:1px solid #ccc;padding:8px;margin-top:6px;"><?= us_e($us_praefix) ?>/#</div></div>
<div class="sm-step"><b><?php echo us_t('TEXT.SCHRITT_3_VORLAGE_EINLESEN'); ?></b><br><br>
<?php echo us_t('TEXT.VORLAGE_HERUNTERLADEN_UNTEN_UND_IN'); ?>
<i><?php echo us_t('TEXT.VORLAGE_EINFGEN'); ?></i><?php echo us_t('TEXT.SIE_LEGT_DIE_VIRTUELLEN_EINGNGE_MI'); ?> <i><?php echo us_t('TEXT.INCOMING_OVERVIEW'); ?></i> <?php echo us_t('TEXT.ERSCHEINEN_DIE_THEMEN_SOBALD_DER_D'); ?> <b><?php echo us_t('TEXT.WER_LIEBER_VON_HAND_ANLEGT'); ?></b><?php echo us_t('TEXT.FINDET_DIE_NAMEN_WEITER_UNTEN_IN_S'); ?> <span class="sm-mono"><?= us_e($us_praefix) ?><?php echo us_t('TEXT.DISTANCE'); ?></span> <?php echo us_t('TEXT.WIRD_ALSO'); ?>
<span class="sm-mono"><?= us_e($us_praefix) ?><?php echo us_t('TEXT.DISTANCE_2'); ?></span>.</div>
<div class="sm-step"><b><?php echo us_t('TEXT.SCHRITT_4_KACHEL_IN_DER_APP'); ?></b><br><br>
<?php echo us_t('TEXT.EINEN'); ?> <i><?php echo us_t('TEXT.STATUS'); ?></i><?php echo us_t('TEXT.BAUSTEIN_ANLEGEN'); ?> <span class="sm-mono">v1</span> mit
<span class="sm-mono"><?php echo us_t('TEXT.LEVEL'); ?></span> und <span class="sm-mono">v2</span> mit
<span class="sm-mono"><?php echo us_t('TEXT.LITER'); ?></span> <?php echo us_t('TEXT.VERBINDEN_STATUSTEXT_ZUM_BEISPIEL'); ?>
<span class="sm-mono"><?php echo us_t('TEXT.V1_0_V2_0LITER'); ?></span><?php echo us_t('TEXT.HKCHEN'); ?>
<i><?php echo us_t('TEXT.VISUALISIERUNG'); ?></i> <?php echo us_t('TEXT.SETZEN_FERTIG'); ?></div>

<div class="sm-small" style="margin-top:10px;">
<?php echo us_t('TEXT.BROKER'); ?> <span class="sm-mono"><?= $us_broker !== '' ? us_e($us_broker) : 'MQTT-Gateway nicht gefunden' ?></span>
<?php echo us_t('TEXT.THEMENPRFIX'); ?> <span class="sm-mono"><?= us_e($us_praefix) ?></span>
</div>

<?php if (us_cfg($us_cfg, 'mqtt', '1') !== '1') { ?>
<div class="sm-alert sm-err"><?php echo us_t('TEXT.MQTT_IST_IM_REITER_EINSTELLUNGEN_A'); ?></div>
<?php } ?>


<h2><?php echo us_t('LOX.H_ENDPUNKT'); ?></h2>
<div class="sm-small"><?php echo us_t('LOX.ENDPUNKT_ERKLAERT'); ?></div>
<?php
/* Eine angezeigte Adresse traegt JEDEN Parameter, den der eigene Endpunkt
 * verlangt - sonst weist das Plugin die eigene Anleitung ab. Und sie wird
 * aus DEMSELBEN Bauteil gebildet wie die Adresse, die das Plugin selbst
 * benutzt: zwei Stellen, die dasselbe zusammensetzen, laufen auseinander. */
$us_tok = us_roh($us_cfg, 'aktionstoken');
$us_host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : 'loxberry';
?>
<?php if ($us_tok === '') { ?>
<div class="sm-warnung"><?php echo us_t('LOX.KEIN_TOKEN'); ?></div>
<?php } else { ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:22%;"><?php echo us_t('LOX.WOFUER'); ?></th><th><?php echo us_t('LOX.ADRESSE'); ?></th></tr>
<tr><td><?php echo us_t('LOX.STATUSZEILE'); ?></td>
    <td><span class="sm-mono">http://<?= us_e($us_host) . us_e(us_endpunkt_pfad('status', $us_tok)) ?></span></td></tr>
<tr><td><?php echo us_t('LOX.SELBSTTEST'); ?></td>
    <td><span class="sm-mono">http://<?= us_e($us_host) ?>/plugins/<?= us_e($us_p['plugin']) ?>/index.php?selftest=1&amp;token=<?= us_e($us_tok) ?></span></td></tr>
</table>
</div>
<div class="sm-small"><?php echo us_t('LOX.ZEILE_BEISPIEL'); ?>
<span class="sm-mono"><?= us_e(us_zeile(array('distance' => '123.4', 'level' => '42.1', 'liter' => '2105', 'valid' => '1', 'online' => '1', 'ts' => '1787000000', 'zaehler' => '418'))) ?></span></div>

<h2><?php echo us_t('LOX.H_BEFEHLE'); ?></h2>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?php echo us_t('TEXT.THEMA'); ?></th><th><?php echo us_t('MQTT.EINHEIT'); ?></th><th><?php echo us_t('LOX.SUCHTEXT'); ?></th><th><?php echo us_t('LOX.GRENZEN'); ?></th></tr>
<?php foreach (us_felder_zeile() as $us_n => $us_f) { ?>
<tr><td class="sm-mono"><?= us_e(strtoupper($us_n)) ?></td>
    <td><?= us_e($us_f['einheit']) ?></td>
    <td class="sm-mono"><?= us_e(us_check($us_n)) ?></td>
    <td><?= us_e($us_f['min'] . ' bis ' . $us_f['max']) ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-small"><?php echo us_t('LOX.SUCHTEXT_ERKLAERT'); ?></div>

<h2><?php echo us_t('LOX.H_TOKEN_NEU'); ?></h2>
<div class="sm-warnung"><?php echo us_t('LOX.TOKEN_NEU_WARNUNG'); ?></div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?php echo us_t('LEGENDE.AKTION'); ?></span></div>
<div class="sm-knopfreihe">
<form method="post" action="index.php" onsubmit="return confirm(<?= us_e(json_encode(us_t('LOX.TOKEN_NEU_FRAGE'))) ?>);">
<input data-role="none" type="hidden" name="activetab" value="tab-loxone"><?php echo us_fmt($us_cfg); ?>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?php echo us_t('LOX.K_TOKEN_NEU'); ?></button>
</form>
</div>
<?php } ?>

<h2><?php echo us_t('TEXT.VORLAGEN'); ?></h2>
<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-loxone"><?php echo us_fmt($us_cfg); ?>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?php echo us_t('LEGENDE.AKTION_DATEI'); ?></span></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="download" value="mqtt_in"><?php echo us_t('TEXT.VORLAGE_EINGNGE_MQTT'); ?></button>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="download" value="udp_in"><?php echo us_t('TEXT.VORLAGE_EINGANG_UDP'); ?></button>
</div>
</form>
<div class="sm-small"><?php echo us_t('TEXT.DIE_MQTT_VORLAGE_LEGT'); ?> <?= count(us_status_themen()) ?> <?php echo us_t('TEXT.VIRTUELLE_EINGNGE_AN_DIE_UDP_VORLA'); ?></div>

<h2><?php echo us_t('TEXT.WAS_VERFFENTLICHT_WIRD'); ?></h2>
<table class="sm-tbl">
<tr><th style="width:26%;"><?php echo us_t('TEXT.THEMA'); ?></th><th style="width:14%;"><?php echo us_t('TEXT.ART'); ?></th><th><?php echo us_t('TEXT.BEDEUTUNG'); ?></th></tr>
<?php foreach (us_status_themen() as $k => $info) { ?>
<tr><td><span class="sm-mono"><?= us_e($us_praefix . '/' . $k) ?></span></td><td><?= us_e($info[1]) ?></td><td><?= $info[0] ?></td></tr>
<?php } ?>
</table>
<div class="sm-small"><?php echo us_t('TEXT.ALLE_THEMEN_SIND'); ?> <b><?php echo us_t('TEXT.RETAINED'); ?></b><?php echo us_t('TEXT.DER_BROKER_MERKT_SICH_DEN_LETZTEN_'); ?></div>

<?php if (!$us_hat_kalibrierung) { ?>
<div class="sm-alert sm-info"><b><?php echo us_t('TEXT.HINWEIS'); ?></b> <?php echo us_t('TEXT.OHNE_EINGETRAGENE_KALIBRIERUNG_LEE'); ?>
<span class="sm-mono">level</span> und <span class="sm-mono">liter</span> <?php echo us_t('TEXT.LEER_DIE_VORLAGE_LEGT_SIE_TROTZDEM'); ?></div>
<?php } ?>

<h2><?php echo us_t('TEXT.SCHRITT_5_KOMPLETTE_BAUSTEIN_LISTE'); ?></h2>
<div class="sm-small"><?php echo us_t('TEXT.SO_SIEHT_DIE_VOLLSTNDIGE_LOGIK_AUF'); ?></div>
<table class="sm-tbl">
<tr><th>#</th><th><?php echo us_t('TEXT.BAUSTEIN_TYP'); ?></th><th><?php echo us_t('TEXT.NAME_VORSCHLAG'); ?></th><th><?php echo us_t('TEXT.PARAMETER'); ?></th><th><?php echo us_t('TEXT.EINGNGE_VERBINDEN_MIT'); ?></th></tr>
<tr><td>1</td><td><?php echo us_t('TEXT.VIRTUELLER_EINGANG'); ?></td><td class="sm-mono"><?= us_e($us_praefix) ?>_distance</td><td><?php echo us_t('TEXT.EINHEIT_CM'); ?></td><td><?php echo us_t('TEXT.KOMMT_BER_DAS_GATEWAY'); ?></td></tr>
<tr><td>2</td><td><?php echo us_t('TEXT.VIRTUELLER_EINGANG'); ?></td><td class="sm-mono"><?= us_e($us_praefix) ?><?php echo us_t('TEXT.LEVEL_2'); ?></td><td><?php echo us_t('TEXT.EINHEIT'); ?></td><td><?php echo us_t('TEXT.TEXT'); ?></td></tr>
<tr><td>3</td><td><?php echo us_t('TEXT.VIRTUELLER_EINGANG'); ?></td><td class="sm-mono"><?= us_e($us_praefix) ?>_liter</td><td><?php echo us_t('TEXT.EINHEIT_L'); ?></td><td>&mdash;</td></tr>
<tr><td>4</td><td><?php echo us_t('TEXT.VIRTUELLER_EINGANG'); ?></td><td class="sm-mono"><?= us_e($us_praefix) ?><?php echo us_t('TEXT.VALID'); ?></td><td><?php echo us_t('TEXT.DIGITAL_1_MESSUNG_BRAUCHBAR'); ?></td><td>&mdash;</td></tr>
<tr><td>5</td><td><?php echo us_t('TEXT.VIRTUELLER_EINGANG'); ?></td><td class="sm-mono"><?= us_e($us_praefix) ?><?php echo us_t('TEXT.ONLINE'); ?></td><td><?php echo us_t('TEXT.DIGITAL_1_DIENST_LUFT'); ?></td><td>&mdash;</td></tr>
<tr><td>6</td><td><?php echo us_t('TEXT.SCHWELLWERTSCHALTER'); ?></td><td><?php echo us_t('TEXT.FLLSTAND_NIEDRIG'); ?></td><td><?php echo us_t('TEXT.EIN'); ?> <b>18</b> <?php echo us_t('TEXT.AUS'); ?> <b>25</b> <?php echo us_t('TEXT.EIN_AUS_SCHALTET_BEIM'); ?> <b><?php echo us_t('TEXT.UNTER'); ?></b><?php echo us_t('TEXT.SCHREITEN_EIN'); ?></td><td><?php echo us_t('TEXT.EINGANG_2'); ?></td></tr>
<tr><td>7</td><td><?php echo us_t('TEXT.UND'); ?></td><td><?php echo us_t('TEXT.WARNUNG_ERLAUBT'); ?></td><td>&mdash;</td><td>I1 = #6, I2 = #4</td></tr>
<tr><td>8</td><td><?php echo us_t('TEXT.EINSCHALTVERZGERUNG'); ?></td><td><?php echo us_t('TEXT.NIEDRIG_UND_ZWAR_LNGER'); ?></td><td><?php echo us_t('TEXT.600S'); ?></td><td><?php echo us_t('TEXT.EINGANG_7'); ?></td></tr>
<tr><td>9</td><td><?php echo us_t('TEXT.BENACHRICHTIGUNG'); ?></td><td><?php echo us_t('TEXT.FLLSTAND_NIEDRIG'); ?></td><td><?php echo us_t('TEXT.TEXT_Z_B_DER_BEHLTER_IST_UNTER_18_'); ?></td><td><?php echo us_t('TEXT.8'); ?></td></tr>
<tr><td>10</td><td><?php echo us_t('TEXT.NICHT'); ?></td><td><?php echo us_t('TEXT.DIENST_ANTWORTET_NICHT'); ?></td><td>&mdash;</td><td><?php echo us_t('TEXT.EINGANG_5'); ?></td></tr>
<tr><td>11</td><td><?php echo us_t('TEXT.EINSCHALTVERZGERUNG'); ?></td><td><?php echo us_t('TEXT.AUSFALL_BESTTIGT'); ?></td><td><?php echo us_t('TEXT.1800S'); ?></td><td><?php echo us_t('TEXT.EINGANG_10_BENACHRICHTIGUNG'); ?></td></tr>
<tr><td>12</td><td><?php echo us_t('TEXT.STATUS'); ?></td><td><?php echo us_t('TEXT.BEHLTER'); ?></td><td><?php echo us_t('TEXT.STATUSTEXT_SIEHE_SCHRITT4_VISUALIS'); ?></td><td>v1 = #2, v2 = #3</td></tr>
<tr><td>13</td><td><?php echo us_t('TEXT.MERKER_OPTIONAL'); ?></td><td><?php echo us_t('TEXT.STAND_BEI_TAGESBEGINN_L'); ?></td><td><?php echo us_t('TEXT.SPEICHERN_DURCH_EINEN_IMPULS_UM_0_'); ?></td><td><?php echo us_t('TEXT.3'); ?></td></tr>
<tr><td>14</td><td><?php echo us_t('TEXT.FORMEL_OPTIONAL'); ?></td><td><?php echo us_t('TEXT.VERBRAUCH_HEUTE_L'); ?></td><td><?php echo us_t('TEXT.FORMEL'); ?> <span class="sm-mono">I2-I1</span></td><td>I1 = #3, I2 = #13</td></tr>
</table>
<div class="sm-alert sm-info">
<b>Zu #7:</b> <?php echo us_t('TEXT.OHNE_DIE_VERKNPFUNG_MIT'); ?> <span class="sm-mono"><?php echo us_t('TEXT.VALID_2'); ?></span> <?php echo us_t('TEXT.LST_EIN_EINZELNER_FEHLSCHUSS_DES_S'); ?><br>
<b><?php echo us_t('TEXT.ZU_8_UND_11'); ?></b> <?php echo us_t('TEXT.DIE_VERZGERUNGEN_SIND_KEIN_SCHMUCK'); ?><br>
<b>Zu #9:</b> <?php echo us_t('TEXT.EIN_BENACHRICHTIGUNGS_BAUSTEIN_SEN'); ?><br>
<b>Zu #6:</b> <?php echo us_t('TEXT.DIE_EIN_SCHWELLE_LIEGT'); ?> <i><?php echo us_t('TEXT.UNTER_2'); ?></i> <?php echo us_t('TEXT.DER_AUS_SCHWELLE_OHNE_DIESEN_ABSTA'); ?>
</div>

<h2><?php echo us_t('TEXT.WORAUF_MAN_SICH_NICHT_VERLASSEN_KA'); ?></h2>
<div class="sm-small">
<?php echo us_t('TEXT.ULTRASCHALL_MISST_DIE_LAUFZEIT_EIN'); ?>
<br><br>
<?php echo us_t('TEXT.DIE_SCHALLGESCHWINDIGKEIT_HNGT_VON'); ?>
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-pane<?php echo $us_tab === 'tab-test' ? ' sm-active' : ''; ?>" id="tab-test">

<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo us_t('LEGENDE.LESEN'); ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?php echo us_t('LEGENDE.TECHNIK'); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo us_t('LEGENDE.AKTION'); ?></span>
</div>


<h2><?php echo us_t('PRUEF.H'); ?></h2>
<div class="sm-small"><?php echo us_t('PRUEF.ERKLAERT'); ?></div>
<?php
/* Die teuren Zeilen laufen NUR, wenn der Reiter Test serverseitig der offene
 * ist. Alle Reiter werden mitgerendert; sonst riefe sich der Webserver bei
 * jedem Klick selbst auf, und die Zeitschranke laege bei jedem Speichern im
 * Weg. */
$us_zeilen_pruef = us_pruefzeilen($us_cfg, $us_lage, $us_tab === 'tab-test');
$us_offen = 0;
?>
<div class="sm-breit">
<table class="sm-tbl">
<?php foreach ($us_zeilen_pruef as $us_pz) {
    $us_z = $us_pz[1];
    if ($us_z === 'offen') { $us_offen++; }
    $us_sym = ($us_z === 'ja') ? '&#10004;' : (($us_z === 'nein') ? '&#10008;' : '&ndash;');
    $us_far = ($us_z === 'ja') ? '#4f7d17' : (($us_z === 'nein') ? '#c62828' : '#888');
?>
<tr><td style="width:26px;color:<?= $us_far ?>;font-weight:700;"><?= $us_sym ?></td>
    <td style="width:34%;"><?= us_e($us_pz[0]) ?></td>
    <td><?= us_e($us_pz[2]) ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-small"><?php printf(us_t('PRUEF.BILANZ'), count($us_zeilen_pruef), $us_offen); ?></div>

<h3 class="sm-h3"><?php echo us_t('TEXT.ANSEHEN'); ?></h3>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><?php echo us_fmt($us_cfg); ?><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="status"><?php echo us_t('TEXT.ZUSTAND_DES_DIENSTES'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><?php echo us_fmt($us_cfg); ?><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="messwert"><?php echo us_t('TEXT.LETZTER_MESSWERT'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><?php echo us_fmt($us_cfg); ?><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="mqttinfo"><?php echo us_t('TEXT.MQTT_GATEWAY'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><?php echo us_fmt($us_cfg); ?><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="udpinfo"><?php echo us_t('TEXT.UDP_AN_DEN_MINISERVER'); ?></button></form>
</div>

<h3 class="sm-h3"><?php echo us_t('TEXT.TECHNISCHE_AUSKUNFT'); ?></h3>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><?php echo us_fmt($us_cfg); ?><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="sensor"><?php echo us_t('TEXT.SENSOR_PRUEFEN'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><?php echo us_fmt($us_cfg); ?><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="konfig"><?php echo us_t('TEXT.KONFIGURATION_ANZEIGEN'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><?php echo us_fmt($us_cfg); ?><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="umgebung"><?php echo us_t('TEXT.UMGEBUNG_UND_MODULE'); ?></button></form>
</div>

<h3 class="sm-h3"><?php echo us_t('TEXT.LST_ETWAS_AUS'); ?></h3>
<div class="sm-small"><?php echo us_t('TEXT.WAECHTER_HINWEIS'); ?></div>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><?php echo us_fmt($us_cfg); ?><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="messen"><?php echo us_t('TEXT.JETZT_MESSEN'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><?php echo us_fmt($us_cfg); ?><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="udptest"><?php echo us_t('TEXT.UDP_TESTPAKET_SENDEN'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><?php echo us_fmt($us_cfg); ?><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="restart"><?php echo us_t('TEXT.DIENST_NEU_STARTEN'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><?php echo us_fmt($us_cfg); ?><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="stop"><?php echo us_t('TEXT.DIENST_ANHALTEN'); ?></button></form>
</div>

<?php if ($us_test_titel !== '') { ?>
<h2><?= us_e($us_test_titel) ?></h2>
<div class="sm-log"><?= us_e($us_test_text) ?></div>
<?php } else { ?>
<div class="sm-alert sm-info" style="margin-top:18px;"><?php echo us_t('TEXT.NOCH_NICHTS_ABGEFRAGT_DIE_AUSGABE_'); ?></div>
<?php } ?>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-pane<?php echo $us_tab === 'tab-log' ? ' sm-active' : ''; ?>" id="tab-log">
<h2><?php echo us_t('TEXT.PROTOKOLL'); ?></h2>
<div class="sm-small">
<?php if ($us_log !== '') { ?>
<?php echo us_t('TEXT.DATEI'); ?> <span class="sm-mono"><?= us_e($us_log) ?></span> &middot; <?php echo us_t('TEXT.NEUESTE_ZEILE'); ?>
<?php } else { ?>
<?php echo us_t('TEXT.KEIN_PROTOKOLL'); ?>
<?php } ?>
</div>
<?php if ($us_zeilen) { ?>
<div class="sm-log"><?php foreach ($us_zeilen as $z) { echo us_e($z) . "\n"; } ?></div>
<?php } ?>
</div>


</div>
<script>
(function () {
    var tabs = document.querySelectorAll('.sm-tab');
    var start = <?= json_encode($us_tab) ?>;
    function zeige(id) {
        var i;
        for (i = 0; i < tabs.length; i++) {
            tabs[i].classList.toggle('sm-active', tabs[i].getAttribute('data-pane') === id);
        }
        var panes = document.querySelectorAll('.sm-pane');
        for (i = 0; i < panes.length; i++) {
            panes[i].classList.toggle('sm-active', panes[i].id === id);
        }
    }
    for (var i = 0; i < tabs.length; i++) {
        (function (t) {
            t.addEventListener('click', function () { zeige(t.getAttribute('data-pane')); });
        })(tabs[i]);
    }
    zeige(start);

    // Nur die Felder zeigen, die zur gewaehlten Bauart gehoeren.
    var wahl = document.getElementById('sm-sensorwahl');
    function sensorfelder() {
        var ist = wahl ? wahl.value : 'srf02';
        var an = { 'sm-srf02': ist === 'srf02',
                   'sm-hcsr04': ist === 'hcsr04',
                   'sm-hcsr04-warn': ist === 'hcsr04' };
        for (var id in an) {
            var el = document.getElementById(id);
            if (el) { el.style.display = an[id] ? '' : 'none'; }
        }
    }
    if (wahl) { wahl.addEventListener('change', sensorfelder); }
    sensorfelder();
})();
</script>
<?php
if ($us_frame) {
    LBWeb::lbfooter();
}
