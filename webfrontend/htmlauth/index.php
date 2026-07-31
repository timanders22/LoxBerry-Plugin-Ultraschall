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

require_once __DIR__ . '/us_lib.php';

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
$us_tab = preg_match('/^tab-(settings|loxone|test|log)$/', (string) (isset($_POST['activetab']) ? $_POST['activetab'] : ''))
    ? $_POST['activetab'] : 'tab-settings';

list($us_cfg, $us_altformat) = us_config_read();

/* ============ Loxone-Vorlage herunterladen ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download'])) {
    $art = (string) $_POST['download'];
    if ($art === 'udp_in' && trim(us_roh($us_cfg, 'udp_port')) === '') {
        $us_error = 'F&uuml;r die UDP-Vorlage muss im Reiter Einstellungen ein Port eingetragen sein.';
        $us_tab = 'tab-loxone';
    } else {
        list($name, $inhalt) = us_vorlage($us_cfg, $art);
        header('Content-Type: application/x-download');
        header('Content-Disposition: attachment; filename=' . $name);
        header('Content-Length: ' . strlen($inhalt));
        echo $inhalt;
        exit;
    }
}

/* ============ Test-Aktionen ============ */
$us_test_titel = '';
$us_test_text = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test'])) {
    require_once __DIR__ . '/us_test.php';
    list($us_test_titel, $us_test_text) = us_test_ausfuehren((string) $_POST['test']);
    $us_tab = 'tab-test';
}

/* ============ Kalibrierpunkt uebernehmen ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kalibrieren'])) {
    require_once __DIR__ . '/us_test.php';
    $mess = us_einmal_messen();
    if (!isset($mess['entfernung']) || $mess['entfernung'] === null) {
        $us_error = 'Die Messung lieferte keinen brauchbaren Wert'
            . (!empty($mess['fehler']) ? ': ' . us_e($mess['fehler']) : '.');
    } else {
        $feld = $_POST['kalibrieren'] === 'voll' ? 'voll_cm' : 'leer_cm';
        $us_cfg[$feld] = (string) $mess['entfernung'];
        if (us_config_write($us_cfg)) {
            $us_hinweis = 'Gemessen: <b>' . us_e($mess['entfernung']) . ' cm</b> &mdash; &uuml;bernommen als '
                . ($feld === 'voll_cm' ? '&bdquo;voll&ldquo;' : '&bdquo;leer&ldquo;') . '.';
            $us_saved = true;
            list($us_cfg, $us_altformat) = us_config_read();
        } else {
            $us_error = 'Die Konfigurationsdatei konnte nicht geschrieben werden: ' . us_e($us_p['config']);
        }
    }
    $us_tab = 'tab-settings';
}

/* ============ Speichern ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $neu = $us_cfg;

    // Eingaben nie hart filtern - nur Steuerzeichen und Anfuehrungszeichen raus.
    $saeubern = function ($s) {
        $s = preg_replace('/[\x00-\x1F\x7F"\']+/u', '', (string) $s);
        return trim($s);
    };
    $ganz = function ($wert, $vorgabe, $min, $max) {
        if (!is_numeric($wert)) {
            return (string) $vorgabe;
        }
        $n = (int) $wert;
        return ($n >= $min && $n <= $max) ? (string) $n : (string) $vorgabe;
    };
    // Komma statt Punkt kommt bei deutscher Tastatur staendig vor.
    $komma = function ($wert, $vorgabe, $min, $max) {
        $wert = str_replace(',', '.', trim((string) $wert));
        if (!is_numeric($wert)) {
            return (string) $vorgabe;
        }
        $n = (float) $wert;
        return ($n >= $min && $n <= $max) ? rtrim(rtrim(sprintf('%.2f', $n), '0'), '.') : (string) $vorgabe;
    };
    // Darf leer bleiben - dann wird der Fuellstand nicht berechnet.
    $leerbar = function ($wert, $min, $max) {
        $wert = str_replace(',', '.', trim((string) $wert));
        if ($wert === '' || !is_numeric($wert)) {
            return '';
        }
        $n = (float) $wert;
        return ($n >= $min && $n <= $max) ? rtrim(rtrim(sprintf('%.2f', $n), '0'), '.') : '';
    };

    $sensor = (string) ($_POST['sensor'] ?? 'srf02');
    $neu['sensor'] = array_key_exists($sensor, us_sensoren()) ? $sensor : 'srf02';
    $neu['enabled'] = isset($_POST['enabled']) ? '1' : '0';
    $neu['mqtt']    = isset($_POST['mqtt']) ? '1' : '0';
    $neu['udp']     = isset($_POST['udp']) ? '1' : '0';

    $praefix = preg_replace('/[^A-Za-z0-9_-]+/', '', $saeubern($_POST['themenpraefix'] ?? ''));
    $neu['themenpraefix'] = $praefix !== '' ? $praefix : 'ultraschall';

    $neu['i2c_bus'] = $ganz($_POST['i2c_bus'] ?? '', 1, 0, 20);
    $adr = strtolower($saeubern($_POST['i2c_adresse'] ?? ''));
    $neu['i2c_adresse'] = preg_match('/^0x[0-9a-f]{1,2}$/', $adr) ? $adr : '0x70';
    $neu['gpio_trigger'] = $ganz($_POST['gpio_trigger'] ?? '', 23, 0, 27);
    $neu['gpio_echo']    = $ganz($_POST['gpio_echo'] ?? '', 24, 0, 27);
    if ($neu['gpio_trigger'] === $neu['gpio_echo']) {
        // Ein Pin kann nicht beides sein - sonst laeuft der Treiber ins Leere.
        $us_error = 'Trigger und Echo m&uuml;ssen verschiedene GPIO-Pins sein &mdash; die Vorgaben 23 und 24 wurden gesetzt.';
        $neu['gpio_trigger'] = '23';
        $neu['gpio_echo'] = '24';
    }

    $neu['messungen']   = $ganz($_POST['messungen'] ?? '', 5, 1, 25);
    $neu['messabstand'] = $komma($_POST['messabstand'] ?? '', '0.2', 0.05, 5);
    $neu['min_cm']      = $komma($_POST['min_cm'] ?? '', '3', 0, 1000);
    $neu['max_cm']      = $komma($_POST['max_cm'] ?? '', '400', 1, 2000);
    if ((float) $neu['min_cm'] >= (float) $neu['max_cm']) {
        // Vertauscht oder gleich - so waere jede Messung unplausibel.
        $tausch = $neu['min_cm'];
        $neu['min_cm'] = $neu['max_cm'];
        $neu['max_cm'] = $tausch;
        if ((float) $neu['min_cm'] >= (float) $neu['max_cm']) {
            $neu['min_cm'] = '3';
            $neu['max_cm'] = '400';
        }
    }
    $neu['offset_cm']     = $komma($_POST['offset_cm'] ?? '', '0', -500, 500);
    $neu['leer_cm']       = $leerbar($_POST['leer_cm'] ?? '', 0, 2000);
    $neu['voll_cm']       = $leerbar($_POST['voll_cm'] ?? '', 0, 2000);
    $neu['volumen_liter'] = $leerbar($_POST['volumen_liter'] ?? '', 0, 1000000);

    $neu['intervall']      = $ganz($_POST['intervall'] ?? '', 60, 5, 86400);
    $neu['aktualisierung'] = $ganz($_POST['aktualisierung'] ?? '', 300, 5, 86400);
    $neu['udp_miniserver'] = $ganz($_POST['udp_miniserver'] ?? '', 1, 1, 20);
    $port = $saeubern($_POST['udp_port'] ?? '');
    $neu['udp_port'] = ($port !== '' && ctype_digit($port) && (int) $port >= 1 && (int) $port <= 65535)
        ? $port : '';
    if ($neu['udp'] === '1' && $neu['udp_port'] === '') {
        $us_error = 'UDP ist eingeschaltet, aber es steht kein g&uuml;ltiger Port da (1&ndash;65535). '
            . 'Ohne Port wird nichts gesendet.';
    }

    if (us_config_write($neu)) {
        $us_saved = true;
        require_once __DIR__ . '/us_test.php';
        us_dienst('restart');
        $us_hinweis = us_dienst_pid()
            ? 'Der Dienst wurde neu gestartet.'
            : 'Der Dienst l&auml;uft nicht &mdash; siehe Reiter Logdateien.';
        list($us_cfg, $us_altformat) = us_config_read();
    } else {
        $us_error = 'Die Konfigurationsdatei konnte nicht geschrieben werden: ' . us_e($us_p['config']);
    }
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

// WICHTIG: LBWeb::lbheader() setzt SDK-Globale - deshalb ueberall us_-Praefix.
$us_frame = class_exists('LBWeb', false);
if ($us_frame) {
    LBWeb::lbheader('Ultraschall Entfernung', 'https://wiki.loxberry.de/plugins/ultraschall_entfernung/start', 'help.html');
}
?>
<style>
.us-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.us-wrap, .us-wrap * { text-shadow: none !important; }
.us-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.us-wrap label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.us-wrap input[type=text], .us-wrap input[type=number], .us-wrap select {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.us-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0 6px 0 0; vertical-align: middle; }
.us-check { font-weight: 400 !important; font-size: 0.95em !important; color: #333 !important; }
.us-row { display: flex; gap: 12px; flex-wrap: wrap; }
.us-row > div { flex: 1; min-width: 180px; }
.us-btn { background: #6dac20; color: #fff !important; border: 0; border-radius: 6px; padding: 10px 22px; font-size: 1em; cursor: pointer; margin-top: 18px; font-weight: 600; }
.us-wrap .us-btn, .us-wrap a.us-btn, .us-wrap button { box-shadow: none !important; }
.us-wrap a.us-btn, .us-wrap a.us-btn:visited, .us-wrap a.us-btn:hover { color: #fff !important; text-decoration: none; }
.us-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.us-ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
.us-err { background: #ffebee; border: 1px solid #ef9a9a; }
.us-info { background: #e3f2fd; border: 1px solid #90caf9; font-size: 0.9em; }
.us-mono { font-family: ui-monospace, monospace; background: #f5f5f5; padding: 2px 6px; border-radius: 4px; }
.us-small { font-size: 0.82em; color: #666; margin-top: 3px; }
.us-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.us-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 9px 18px; cursor: pointer; font-size: 0.95em; color: #444 !important; }
.us-tab.us-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.us-pane { display: none; padding-top: 4px; }
.us-pane.us-active { display: block; }
.us-log { background: #1e1e1e; color: #d4d4d4; font-family: ui-monospace, monospace; font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.us-step { margin: 10px 0; padding: 10px 14px; background: #fafafa; border-left: 4px solid #6dac20; border-radius: 0 8px 8px 0; }
.us-tbl { border-collapse: collapse; margin: 8px 0; width: 100%; }
.us-tbl th, .us-tbl td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; font-size: 0.9em; vertical-align: middle; }
.us-tbl th { background: #f0f0f0; }
.us-gross { font-size: 1.6em; font-weight: 700; color: #4f7d17; }
.us-tank { height: 16px; background: #eceff1; border-radius: 4px; overflow: hidden; margin: 6px 0 2px; }
.us-tank i { display: block; height: 100%; background: #6dac20; }

/* --- Einheitliches Kachel-Raster im Reiter Test (Hausstandard) --- */
.us-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.us-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.us-knopfreihe form { margin: 0; display: flex; }
.us-knopfreihe .us-btn { flex: 0 0 auto; min-width: 250px; text-align: center;
    display: inline-flex; align-items: center; justify-content: center; line-height: 1.25; margin-top: 0; }
.us-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.us-legende span { display: inline-flex; align-items: center; gap: 6px; }
.us-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.us-btn.us-b-lesen   { background: #6dac20; }
.us-btn.us-b-technik { background: #546e7a; }
.us-btn.us-b-aktion  { background: #e0620d; }
.us-punkt.us-b-lesen   { background: #6dac20; }
.us-punkt.us-b-technik { background: #546e7a; }
.us-punkt.us-b-aktion  { background: #e0620d; }
</style>
<div class="us-wrap">

<?php if ($us_saved) { ?>
<div class="us-alert us-ok"><b>Gespeichert.</b> <?= $us_hinweis ?></div>
<?php } ?>
<?php if ($us_error !== '') { ?><div class="us-alert us-err"><b>Hinweis:</b> <?= $us_error ?></div><?php } ?>
<?php if ($us_altformat) { ?>
<div class="us-alert us-info">Die Konfiguration stammt noch aus der Originalfassung. Sie wird gelesen wie sie ist &mdash;
beim n&auml;chsten Speichern schreibt das Plugin sie ins neue Format um. Miniserver und UDP-Port bleiben dabei erhalten.</div>
<?php } ?>

<div class="us-alert us-info">
Dienst: <b><?= $us_pid ? 'l&auml;uft' : 'l&auml;uft nicht' ?></b><?= $us_pid ? ' (PID ' . $us_pid . ')' : '' ?>
&middot; Plugin: <b><?= us_cfg($us_cfg, 'enabled', '0') === '1' ? 'eingeschaltet' : 'ausgeschaltet' ?></b>
&middot; Sensor: <span class="us-mono"><?= $us_sensor === 'hcsr04' ? 'HC-SR04' : 'SRF02' ?></span>
<?php if ($us_status && $us_status['entfernung'] !== null) { ?>
&middot; zuletzt: <b><?= us_e(number_format((float) $us_status['entfernung'], 1, ',', '')) ?>&nbsp;cm</b>
<?php if (isset($us_status['prozent']) && $us_status['prozent'] !== null) { ?>
(<?= us_e(number_format((float) $us_status['prozent'], 1, ',', '')) ?>&nbsp;%)
<?php } ?>
<?php } ?>
&middot; MQTT: <b><?= us_cfg($us_cfg, 'mqtt', '1') === '1' ? 'ein' : 'aus' ?></b>
<?php if ($us_alter >= 0) { ?>&middot; Stand vor <?= $us_alter ?> s<?php } ?>
</div>

<div class="us-tabs">
    <div class="us-tab" data-pane="tab-settings">Einstellungen</div>
    <div class="us-tab" data-pane="tab-loxone">Einbindung in Loxone</div>
    <div class="us-tab" data-pane="tab-test">Test</div>
    <div class="us-tab" data-pane="tab-log">Logdateien</div>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="us-pane" id="tab-settings">

<?php if ($us_status && $us_status['entfernung'] !== null) { ?>
<h2>Aktueller Messwert</h2>
<div class="us-gross"><?= us_e(number_format((float) $us_status['entfernung'], 1, ',', '')) ?> cm</div>
<?php if (isset($us_status['prozent']) && $us_status['prozent'] !== null) { ?>
<div class="us-tank"><i style="width: <?= (float) $us_status['prozent'] ?>%;"></i></div>
<div class="us-small">F&uuml;llstand <?= us_e(number_format((float) $us_status['prozent'], 1, ',', '')) ?>&nbsp;%<?php
if (isset($us_status['liter']) && $us_status['liter'] !== null) {
    echo ' &middot; rund ' . us_e(number_format((float) $us_status['liter'], 1, ',', '.')) . ' Liter';
} ?> &middot; Stand vor <?= $us_alter ?> Sekunden</div>
<?php } else { ?>
<div class="us-small">Stand vor <?= $us_alter ?> Sekunden. Ein F&uuml;llstand wird erst berechnet,
wenn unten &bdquo;leer&ldquo; und &bdquo;voll&ldquo; eingetragen sind.</div>
<?php } ?>
<?php } ?>

<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2>Betrieb</h2>
<label class="us-check"><input data-role="none" type="checkbox" name="enabled" value="1"<?= us_cfg($us_cfg, 'enabled', '0') === '1' ? ' checked' : '' ?>> <b>Plugin eingeschaltet</b></label>
<div class="us-small">Solange das nicht angehakt ist, l&auml;uft der Dienst zwar, misst aber nicht.</div>

<h2>Sensor</h2>
<label>Bauart</label>
<select data-role="none" name="sensor" id="us-sensorwahl">
<?php foreach (us_sensoren() as $k => $bez) { ?>
<option value="<?= us_e($k) ?>"<?= $us_sensor === $k ? ' selected' : '' ?>><?= us_e($bez) ?></option>
<?php } ?>
</select>

<div id="us-srf02" class="us-row" style="margin-top:8px;">
<div>
<label>I2C-Bus</label>
<input data-role="none" type="number" name="i2c_bus" min="0" max="20" value="<?= us_e(us_cfg($us_cfg, 'i2c_bus', '1')) ?>">
<div class="us-small">Auf dem Raspberry Pi fast immer <span class="us-mono">1</span>.</div>
</div>
<div>
<label>I2C-Adresse</label>
<input data-role="none" type="text" name="i2c_adresse" value="<?= us_e(us_cfg($us_cfg, 'i2c_adresse', '0x70')) ?>">
<div class="us-small">Ab Werk <span class="us-mono">0x70</span>. Welche Adressen belegt sind, zeigt
der Reiter Test unter <i>Sensor pr&uuml;fen</i>.</div>
</div>
</div>

<div id="us-hcsr04" class="us-row" style="margin-top:8px;">
<div>
<label>GPIO Trigger</label>
<input data-role="none" type="number" name="gpio_trigger" min="0" max="27" value="<?= us_e(us_cfg($us_cfg, 'gpio_trigger', '23')) ?>">
</div>
<div>
<label>GPIO Echo</label>
<input data-role="none" type="number" name="gpio_echo" min="0" max="27" value="<?= us_e(us_cfg($us_cfg, 'gpio_echo', '24')) ?>">
<div class="us-small">BCM-Nummern, nicht Steckerplatznummern.</div>
</div>
</div>
<div id="us-hcsr04-warn" class="us-alert us-info" style="margin-top:6px;">
<b>Spannung beachten:</b> der HC-SR04 arbeitet mit 5&nbsp;V. Der Echo-Pin muss &uuml;ber einen
Spannungsteiler auf 3,3&nbsp;V gebracht werden &mdash; sonst nimmt der Raspberry Pi mit der Zeit Schaden.
Der SRF02 dagegen h&auml;ngt am I2C-Bus und ist ohne Zusatzbeschaltung anschlie&szlig;bar.
</div>

<h2>Messung</h2>
<div class="us-row">
<div>
<label>Werte je Durchgang</label>
<input data-role="none" type="number" name="messungen" min="1" max="25" value="<?= us_e(us_cfg($us_cfg, 'messungen', '5')) ?>">
<div class="us-small">Aus diesen Werten wird der <b>Median</b> genommen, nicht der Mittelwert:
ein einzelner Ausrei&szlig;er zieht das Ergebnis so nicht mit.</div>
</div>
<div>
<label>Abstand zwischen den Werten (s)</label>
<input data-role="none" type="text" name="messabstand" value="<?= us_e(us_cfg($us_cfg, 'messabstand', '0.2')) ?>">
<div class="us-small">Zu kurz gew&auml;hlt h&ouml;rt der Sensor noch sein eigenes Echo.</div>
</div>
</div>
<div class="us-row">
<div>
<label>Kleinster plausibler Wert (cm)</label>
<input data-role="none" type="text" name="min_cm" value="<?= us_e(us_cfg($us_cfg, 'min_cm', '3')) ?>">
</div>
<div>
<label>Gr&ouml;&szlig;ter plausibler Wert (cm)</label>
<input data-role="none" type="text" name="max_cm" value="<?= us_e(us_cfg($us_cfg, 'max_cm', '400')) ?>">
<div class="us-small">Werte au&szlig;erhalb dieses Bereichs werden verworfen.
Der SRF02 misst ab etwa 16&nbsp;cm, der HC-SR04 ab etwa 2&nbsp;cm.</div>
</div>
<div>
<label>Korrektur (cm)</label>
<input data-role="none" type="text" name="offset_cm" value="<?= us_e(us_cfg($us_cfg, 'offset_cm', '0')) ?>">
<div class="us-small">Wird auf jeden Messwert addiert &mdash; etwa f&uuml;r den Abstand
zwischen Sensorgeh&auml;use und Deckel.</div>
</div>
</div>

<h2>F&uuml;llstand</h2>
<div class="us-small" style="margin-bottom:6px;">
Optional. Sind beide Felder gef&uuml;llt, rechnet das Plugin die Entfernung in Prozent um.
Am einfachsten misst man beide Punkte direkt &mdash; die Kn&ouml;pfe darunter &uuml;bernehmen
den gerade gemessenen Wert.
</div>
<div class="us-row">
<div>
<label>Abstand bei <b>leer</b> (cm)</label>
<input data-role="none" type="text" name="leer_cm" value="<?= us_e(us_roh($us_cfg, 'leer_cm')) ?>" placeholder="leer lassen = keine Umrechnung">
</div>
<div>
<label>Abstand bei <b>voll</b> (cm)</label>
<input data-role="none" type="text" name="voll_cm" value="<?= us_e(us_roh($us_cfg, 'voll_cm')) ?>" placeholder="leer lassen = keine Umrechnung">
</div>
<div>
<label>Gesamtvolumen (Liter)</label>
<input data-role="none" type="text" name="volumen_liter" value="<?= us_e(us_roh($us_cfg, 'volumen_liter')) ?>" placeholder="optional">
<div class="us-small">Nur bei senkrechten W&auml;nden verl&auml;sslich. Bei einem liegenden
Zylinder oder einer Kugel ist der Zusammenhang zwischen H&ouml;he und Inhalt nicht linear.</div>
</div>
</div>

<h2>Zeiten</h2>
<div class="us-row">
<div>
<label>Messen alle &hellip; Sekunden</label>
<input data-role="none" type="number" name="intervall" min="5" max="86400" value="<?= us_e(us_cfg($us_cfg, 'intervall', '60')) ?>">
</div>
<div>
<label>Alles neu melden alle &hellip; Sekunden</label>
<input data-role="none" type="number" name="aktualisierung" min="5" max="86400" value="<?= us_e(us_cfg($us_cfg, 'aktualisierung', '300')) ?>">
<div class="us-small">Sonst geht nur hinaus, was sich ge&auml;ndert hat.</div>
</div>
</div>

<h2>Weg zum Miniserver</h2>
<label class="us-check"><input data-role="none" type="checkbox" name="mqtt" value="1"<?= us_cfg($us_cfg, 'mqtt', '1') === '1' ? ' checked' : '' ?>> <b>MQTT</b> &mdash; empfohlen</label>
<div class="us-small">Werte gehen retained an den Broker. Nach einem Neustart des Miniservers
steht die Entfernung sofort wieder da, ohne auf die n&auml;chste Messung zu warten.</div>

<label class="us-check" style="margin-top:10px;"><input data-role="none" type="checkbox" name="udp" value="1"<?= us_cfg($us_cfg, 'udp', '0') === '1' ? ' checked' : '' ?>> Zus&auml;tzlich per UDP senden</label>
<div class="us-small">Der Weg der Originalfassung: die Entfernung geht als blanke Zahl in cm
an einen Port des Miniservers. Nur einschalten, wenn eine bestehende Loxone-Konfiguration
daran h&auml;ngt.</div>

<div class="us-row" style="margin-top:12px;">
<div>
<label>MQTT-Themenpr&auml;fix</label>
<input data-role="none" type="text" name="themenpraefix" value="<?= us_e($us_praefix) ?>">
</div>
<div>
<label>Miniserver f&uuml;r UDP</label>
<select data-role="none" name="udp_miniserver">
<?php if (!$us_ms) { ?>
<option value="1">Miniserver 1</option>
<?php } foreach ($us_ms as $nr => $m) { ?>
<option value="<?= us_e($nr) ?>"<?= us_cfg($us_cfg, 'udp_miniserver', '1') === (string) $nr ? ' selected' : '' ?>><?= us_e($nr . ' - ' . $m['name'] . ' (' . $m['ip'] . ')') ?></option>
<?php } ?>
</select>
</div>
<div>
<label>UDP-Port</label>
<input data-role="none" type="text" name="udp_port" value="<?= us_e(us_roh($us_cfg, 'udp_port')) ?>" placeholder="z.&nbsp;B. 12345">
</div>
</div>

<button data-role="none" class="us-btn" type="submit" name="save" value="1">Speichern</button>
<div class="us-small">Beim Speichern wird der Dienst neu gestartet.</div>
</form>

<h2>Kalibrierpunkt messen</h2>
<div class="us-small" style="margin-bottom:4px;">
Misst sofort und tr&auml;gt das Ergebnis in das jeweilige Feld ein. Vorher speichern &mdash;
sonst werden die anderen Eingaben verworfen.
</div>
<div class="us-legende"><span><i class="us-punkt us-b-aktion"></i> L&ouml;st etwas aus &mdash; misst und speichert</span></div>
<div class="us-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-settings"><button data-role="none" class="us-btn us-b-aktion" type="submit" name="kalibrieren" value="leer">Jetzt messen &rarr; &bdquo;leer&ldquo;</button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-settings"><button data-role="none" class="us-btn us-b-aktion" type="submit" name="kalibrieren" value="voll">Jetzt messen &rarr; &bdquo;voll&ldquo;</button></form>
</div>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="us-pane" id="tab-loxone">

<h2>Einbindung in Loxone &mdash; Schritt f&uuml;r Schritt</h2>
<div class="us-small">Der Sensor misst den Abstand zur Oberfl&auml;che. Daraus rechnet das Plugin
F&uuml;llstand und Inhalt aus und meldet beides per MQTT (Schritt&nbsp;1 bis&nbsp;3). Im Miniserver
wird daraus eine Kachel und, wenn man will, eine Warnung bei niedrigem Stand.</div>
<div class="us-step"><b>Schritt 1: Sensor einrichten</b><br><br>
Im Reiter <i>Einstellungen</i>, dann im Reiter <i>Test</i> mit <i>Jetzt messen</i> pr&uuml;fen, ob
ein plausibler Wert herauskommt. Ohne brauchbare Messung hat der Rest keinen Sinn.</div>
<div class="us-step"><b>Schritt 2: Abo im MQTT-Gateway eintragen</b><br><br>
<b>Ohne diesen Eintrag kommt am Miniserver nichts an</b> &mdash; einzutragen unter
<i>System-Einstellungen &rarr; MQTT Gateway &rarr; Abonnements</i>:
<div class="us-mono" style="background:#f4f4f4;border:1px solid #ccc;padding:8px;margin-top:6px;"><?= us_e($us_praefix) ?>/#</div></div>
<div class="us-step"><b>Schritt 3: Vorlage einlesen</b><br><br>
Vorlage herunterladen (unten) und in Loxone Config einlesen: Rechtsklick auf den Miniserver &rarr;
<i>Vorlage einf&uuml;gen</i>. Sie legt die virtuellen Eing&auml;nge mit den richtigen Namen an; die
Werte liefert das Gateway. Unter <i>Incoming overview</i> erscheinen die Themen, sobald der Dienst
das erste Mal gemessen hat. <b>Wer lieber von Hand anlegt</b>, findet die Namen weiter unten in
Schritt&nbsp;5 &mdash; das Gateway ersetzt dabei jeden Schr&auml;gstrich durch einen Unterstrich,
aus <span class="us-mono"><?= us_e($us_praefix) ?>/distance</span> wird also
<span class="us-mono"><?= us_e($us_praefix) ?>_distance</span>.</div>
<div class="us-step"><b>Schritt 4: Kachel in der App</b><br><br>
Einen <i>Status</i>-Baustein anlegen, <span class="us-mono">v1</span> mit
<span class="us-mono">level</span> und <span class="us-mono">v2</span> mit
<span class="us-mono">liter</span> verbinden. Statustext zum Beispiel:
<span class="us-mono">&lt;v1.0&gt;&nbsp;% &middot; &lt;v2.0&gt;&nbsp;Liter</span>. H&auml;kchen
<i>Visualisierung</i> setzen &mdash; fertig.</div>

<div class="us-small" style="margin-top:10px;">
Broker: <span class="us-mono"><?= $us_broker !== '' ? us_e($us_broker) : 'MQTT-Gateway nicht gefunden' ?></span>
&middot; Themenpr&auml;fix: <span class="us-mono"><?= us_e($us_praefix) ?></span>
</div>

<?php if (us_cfg($us_cfg, 'mqtt', '1') !== '1') { ?>
<div class="us-alert us-err">MQTT ist im Reiter Einstellungen ausgeschaltet &mdash; die Vorlage liefert dann keine Werte.</div>
<?php } ?>

<h2>Vorlagen</h2>
<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-loxone">
<div class="us-legende"><span><i class="us-punkt us-b-aktion"></i> L&ouml;st etwas aus &mdash; erzeugt eine Datei</span></div>
<div class="us-knopfreihe">
<button data-role="none" class="us-btn us-b-aktion" type="submit" name="download" value="mqtt_in">Vorlage: Eing&auml;nge (MQTT)</button>
<button data-role="none" class="us-btn us-b-aktion" type="submit" name="download" value="udp_in">Vorlage: Eingang (UDP)</button>
</div>
</form>
<div class="us-small">Die MQTT-Vorlage legt <?= count(us_status_themen()) ?> virtuelle Eing&auml;nge an.
Die UDP-Vorlage nur einen &mdash; mehr geht auf diesem Weg nicht, weil die Originalfassung
den Wert ohne Namen davor sendet.</div>

<h2>Was ver&ouml;ffentlicht wird</h2>
<table class="us-tbl">
<tr><th style="width:26%;">Thema</th><th style="width:14%;">Art</th><th>Bedeutung</th></tr>
<?php foreach (us_status_themen() as $k => $info) { ?>
<tr><td><span class="us-mono"><?= us_e($us_praefix . '/' . $k) ?></span></td><td><?= us_e($info[1]) ?></td><td><?= $info[0] ?></td></tr>
<?php } ?>
</table>
<div class="us-small">Alle Themen sind <b>retained</b>: der Broker merkt sich den letzten Wert.</div>

<?php if (!$us_hat_kalibrierung) { ?>
<div class="us-alert us-info"><b>Hinweis:</b> ohne eingetragene Kalibrierung
(&bdquo;leer&ldquo; und &bdquo;voll&ldquo; im Reiter Einstellungen) bleiben
<span class="us-mono">level</span> und <span class="us-mono">liter</span> leer.
Die Vorlage legt sie trotzdem an &mdash; dann tr&auml;gt man die Kalibrierung sp&auml;ter
nach, ohne die Vorlage erneut einlesen zu m&uuml;ssen.</div>
<?php } ?>

<h2>Schritt 5: Komplette Baustein-Liste zum 1:1-Nachbauen</h2>
<div class="us-small">So sieht die vollst&auml;ndige Logik auf der Programmierseite aus (jede Zeile =
ein Baustein). Alle Bausteine findet man in Loxone Config &uuml;ber die Baustein-Suche (F5):</div>
<table class="us-tbl">
<tr><th>#</th><th>Baustein (Typ)</th><th>Name (Vorschlag)</th><th>Parameter</th><th>Eing&auml;nge verbinden mit</th></tr>
<tr><td>1</td><td>Virtueller Eingang</td><td class="us-mono"><?= us_e($us_praefix) ?>_distance</td><td>Einheit cm</td><td>&mdash; (kommt &uuml;ber das Gateway)</td></tr>
<tr><td>2</td><td>Virtueller Eingang</td><td class="us-mono"><?= us_e($us_praefix) ?>_level</td><td>Einheit %</td><td>&mdash;</td></tr>
<tr><td>3</td><td>Virtueller Eingang</td><td class="us-mono"><?= us_e($us_praefix) ?>_liter</td><td>Einheit l</td><td>&mdash;</td></tr>
<tr><td>4</td><td>Virtueller Eingang</td><td class="us-mono"><?= us_e($us_praefix) ?>_valid</td><td>digital, 1 = Messung brauchbar</td><td>&mdash;</td></tr>
<tr><td>5</td><td>Virtueller Eingang</td><td class="us-mono"><?= us_e($us_praefix) ?>_online</td><td>digital, 1 = Dienst l&auml;uft</td><td>&mdash;</td></tr>
<tr><td>6</td><td>Schwellwertschalter</td><td>F&uuml;llstand niedrig</td><td>Ein <b>18</b> / Aus <b>25</b> (Ein &lt; Aus = schaltet beim <b>Unter</b>schreiten ein)</td><td>Eingang = #2</td></tr>
<tr><td>7</td><td>UND</td><td>Warnung erlaubt</td><td>&mdash;</td><td>I1 = #6, I2 = #4</td></tr>
<tr><td>8</td><td>Einschaltverz&ouml;gerung</td><td>Niedrig, und zwar l&auml;nger</td><td>600&nbsp;s</td><td>Eingang = #7</td></tr>
<tr><td>9</td><td>Benachrichtigung</td><td>F&uuml;llstand niedrig</td><td>Text z.&nbsp;B. &bdquo;Der Beh&auml;lter ist unter 18&nbsp;% gefallen.&ldquo;</td><td>&larr; #8</td></tr>
<tr><td>10</td><td>NICHT</td><td>Dienst antwortet nicht</td><td>&mdash;</td><td>Eingang = #5</td></tr>
<tr><td>11</td><td>Einschaltverz&ouml;gerung</td><td>Ausfall best&auml;tigt</td><td>1800&nbsp;s</td><td>Eingang = #10 &rarr; Benachrichtigung</td></tr>
<tr><td>12</td><td>Status</td><td>Beh&auml;lter</td><td>Statustext siehe Schritt&nbsp;4, Visualisierung EIN</td><td>v1 = #2, v2 = #3</td></tr>
<tr><td>13</td><td>Merker (optional)</td><td>Stand bei Tagesbeginn (l)</td><td>Speichern durch einen Impuls um 0:00&nbsp;Uhr</td><td>&larr; #3</td></tr>
<tr><td>14</td><td>Formel (optional)</td><td>Verbrauch heute (l)</td><td>Formel: <span class="us-mono">I2-I1</span></td><td>I1 = #3, I2 = #13</td></tr>
</table>
<div class="us-alert us-info">
<b>Zu #7:</b> ohne die Verkn&uuml;pfung mit <span class="us-mono">valid</span> l&ouml;st ein einzelner
Fehlschuss des Sensors eine Warnung aus. Der Baustein liefert genau daf&uuml;r ein Kennzeichen.<br>
<b>Zu #8 und #11:</b> die Verz&ouml;gerungen sind kein Schmuck. Ultraschall streut; ein einzelner
Ausrei&szlig;er darf niemanden aus dem Bett holen.<br>
<b>Zu #9:</b> ein Benachrichtigungs-Baustein sendet nur bei einem Wechsel von Aus auf Ein. Niemals
mehrere Quellen direkt an seinen Eingang legen &mdash; erst &uuml;ber einen ODER-Baustein
zusammenf&uuml;hren.<br>
<b>Zu #6:</b> die Ein-Schwelle liegt <i>unter</i> der Aus-Schwelle. Ohne diesen Abstand meldet der
Baustein an der Grenze im Wechsel ein und aus.
</div>

<h2>Worauf man sich nicht verlassen kann</h2>
<div class="us-small">
Ultraschall misst die Laufzeit eines Schallechos. Das geht gut gegen ebene, harte, waagrechte
Fl&auml;chen &mdash; Wasser, Beton, Blech. Es geht schlecht gegen Schaum, Sch&uuml;ttgut mit
schr&auml;ger Oberfl&auml;che, Textilien und alles, was den Schall streut statt ihn
zur&uuml;ckzuwerfen. Sitzt der Sensor in einem engen Rohr, kommen Echos von der Rohrwand mit.
<br><br>
Die Schallgeschwindigkeit h&auml;ngt von der Temperatur ab: rund 0,17&nbsp;% je Grad.
Zwischen einer kalten Winternacht und einem hei&szlig;en Sommertag sind das leicht 5&nbsp;%
Unterschied &mdash; bei 200&nbsp;cm also rund 10&nbsp;cm. Weder der SRF02 noch der HC-SR04
gleichen das aus. Wer es genau braucht, kalibriert bei der Temperatur, die im Betrieb
&uuml;blich ist.
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="us-pane" id="tab-test">

<div class="us-legende">
<span><i class="us-punkt us-b-lesen"></i> Ansehen &mdash; fragt nur ab, ver&auml;ndert nichts</span>
<span><i class="us-punkt us-b-technik"></i> Technische Auskunft &mdash; f&uuml;r die Fehlersuche</span>
<span><i class="us-punkt us-b-aktion"></i> L&ouml;st etwas aus &mdash; sendet oder ver&auml;ndert</span>
</div>

<h3 class="us-h3">Ansehen</h3>
<div class="us-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="us-btn us-b-lesen" type="submit" name="test" value="status">Zustand des Dienstes</button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="us-btn us-b-lesen" type="submit" name="test" value="messwert">Letzter Messwert</button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="us-btn us-b-lesen" type="submit" name="test" value="mqttinfo">MQTT-Gateway</button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="us-btn us-b-lesen" type="submit" name="test" value="udpinfo">UDP an den Miniserver</button></form>
</div>

<h3 class="us-h3">Technische Auskunft</h3>
<div class="us-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="us-btn us-b-technik" type="submit" name="test" value="sensor">Sensor pr&uuml;fen</button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="us-btn us-b-technik" type="submit" name="test" value="konfig">Konfiguration anzeigen</button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="us-btn us-b-technik" type="submit" name="test" value="umgebung">Umgebung und Module</button></form>
</div>

<h3 class="us-h3">L&ouml;st etwas aus</h3>
<div class="us-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="us-btn us-b-aktion" type="submit" name="test" value="messen">Jetzt messen</button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="us-btn us-b-aktion" type="submit" name="test" value="udptest">UDP-Testpaket senden</button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="us-btn us-b-aktion" type="submit" name="test" value="restart">Dienst neu starten</button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="us-btn us-b-aktion" type="submit" name="test" value="stop">Dienst anhalten</button></form>
</div>

<?php if ($us_test_titel !== '') { ?>
<h2><?= us_e($us_test_titel) ?></h2>
<div class="us-log"><?= us_e($us_test_text) ?></div>
<?php } else { ?>
<div class="us-alert us-info" style="margin-top:18px;">Noch nichts abgefragt. Die Ausgabe erscheint hier.</div>
<?php } ?>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="us-pane" id="tab-log">
<h2>Protokoll</h2>
<div class="us-small">
<?php if ($us_log !== '') { ?>
Datei: <span class="us-mono"><?= us_e($us_log) ?></span> &middot; neueste Zeile zuerst
<?php } else { ?>
Noch keine Protokolldatei vorhanden. Sie entsteht, sobald der Dienst das erste Mal l&auml;uft.
<?php } ?>
</div>
<?php if ($us_zeilen) { ?>
<div class="us-log"><?php foreach ($us_zeilen as $z) { echo us_e($z) . "\n"; } ?></div>
<?php } ?>
</div>

</div>
<script>
(function () {
    var tabs = document.querySelectorAll('.us-tab');
    var start = <?= json_encode($us_tab) ?>;
    function zeige(id) {
        var i;
        for (i = 0; i < tabs.length; i++) {
            tabs[i].classList.toggle('us-active', tabs[i].getAttribute('data-pane') === id);
        }
        var panes = document.querySelectorAll('.us-pane');
        for (i = 0; i < panes.length; i++) {
            panes[i].classList.toggle('us-active', panes[i].id === id);
        }
    }
    for (var i = 0; i < tabs.length; i++) {
        (function (t) {
            t.addEventListener('click', function () { zeige(t.getAttribute('data-pane')); });
        })(tabs[i]);
    }
    zeige(start);

    // Nur die Felder zeigen, die zur gewaehlten Bauart gehoeren.
    var wahl = document.getElementById('us-sensorwahl');
    function sensorfelder() {
        var ist = wahl ? wahl.value : 'srf02';
        var an = { 'us-srf02': ist === 'srf02',
                   'us-hcsr04': ist === 'hcsr04',
                   'us-hcsr04-warn': ist === 'hcsr04' };
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
