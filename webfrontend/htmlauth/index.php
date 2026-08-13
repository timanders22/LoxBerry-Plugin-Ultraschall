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
// Der Reiter kommt aus einem abgesendeten Formular (activetab) oder aus der
// Adresse (?tab=...). Letzteres brauchen die Reiter, seit sie echte Verweise
// sind - siehe die Reiterleiste weiter unten.
$us_wunsch = isset($_POST['activetab']) ? (string) $_POST['activetab']
    : (isset($_GET['tab']) ? 'tab-' . (string) $_GET['tab'] : '');
/* EINE Quelle fuer Reihenfolge, Positivliste und Beschriftung. Die Namen
 * standen bis 1.1.1 an drei Stellen: in diesem Muster, im Feld $us_reiter
 * und in den Flaechen-ids. Wer einen Reiter ergaenzt und eine davon
 * vergisst, bekommt keinen Fehler, sondern eine Seite, die nach jedem
 * Absenden auf Einstellungen zurueckspringt. */
$us_reiter_ids = array('settings', 'loxone', 'test', 'log');
$us_tab = preg_match('/^tab-(' . implode('|', $us_reiter_ids) . ')$/', $us_wunsch)
    ? $us_wunsch : 'tab-' . $us_reiter_ids[0];

list($us_cfg, $us_altformat) = us_config_read();

/* ============ Loxone-Vorlage herunterladen ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download'])) {
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
        $us_error = us_t('FEHLER.GPIO_GLEICH');
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
        $us_error = us_t('FEHLER.UDP_OHNE_PORT');
    }

    if (us_config_write($neu)) {
        $us_saved = true;
        require_once __DIR__ . '/us_test.php';
        us_dienst('restart');
        $us_hinweis = us_dienst_pid()
            ? us_t('MELD.DIENST_NEUSTART')
            : us_t('MELD.DIENST_LAEUFT_NICHT');
        list($us_cfg, $us_altformat) = us_config_read();
    } else {
        $us_error = sprintf(us_t('FEHLER.CONFIG_SCHREIBEN'), us_e($us_p['config']));
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
</style>
<div class="sm-wrap">

<?php if ($us_saved) { ?>
<div class="sm-alert sm-ok"><b><?php echo us_t('TEXT.GESPEICHERT'); ?></b> <?= $us_hinweis ?></div>
<?php } ?>
<?php if ($us_error !== '') { ?><div class="sm-alert sm-err"><b><?php echo us_t('TEXT.HINWEIS'); ?></b> <?= $us_error ?></div><?php } ?>
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
$us_beschriftung = array(
    'settings' => 'REITER.EINSTELLUNGEN', 'loxone' => 'REITER.LOXONE',
    'test'     => 'REITER.TEST',          'log'    => 'REITER.LOG',
);
$us_reiter = array();
foreach ($us_reiter_ids as $us_i) {
    $us_reiter['tab-' . $us_i] = isset($us_beschriftung[$us_i])
        ? us_t($us_beschriftung[$us_i]) : $us_i;
}
?>
<div class="sm-tabs">
<?php foreach ($us_reiter as $us_id => $us_bez) { ?>
    <a class="sm-tab<?php echo $us_tab === $us_id ? ' sm-active' : ''; ?>"
       data-pane="<?php echo us_e($us_id); ?>"
       href="index.php?tab=<?php echo us_e(substr($us_id, 4)); ?>"><?php echo $us_bez; ?></a>
<?php } ?>
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
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

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
<input data-role="none" type="text" name="volumen<?php echo us_t('TEXT.LITER_2'); ?>" value="<?= us_e(us_roh($us_cfg, 'volumen_liter')) ?>" placeholder="optional">
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
<label class="sm-check"><input data-role="none" type="checkbox" name="mqtt" value="1"<?= us_cfg($us_cfg, 'mqtt', '1') === '1' ? ' checked' : '' ?>> <b>MQTT</b> <?php echo us_t('TEXT.EMPFOHLEN'); ?></label>
<div class="sm-small"><?php echo us_t('TEXT.WERTE_GEHEN_RETAINED_AN_DEN_BROKER'); ?></div>

<label class="sm-check" style="margin-top:10px;"><input data-role="none" type="checkbox" name="udp" value="1"<?= us_cfg($us_cfg, 'udp', '0') === '1' ? ' checked' : '' ?><?php echo us_t('TEXT.ZUSTZLICH_PER_UDP_SENDEN'); ?></label>
<div class="sm-small"><?php echo us_t('TEXT.DER_WEG_DER_ORIGINALFASSUNG_DIE_EN'); ?></div>

<div class="sm-row" style="margin-top:12px;">
<div>
<label><?php echo us_t('TEXT.MQTT_THEMENPRFIX'); ?></label>
<input data-role="none" type="text" name="themenpraefix" value="<?= us_e($us_praefix) ?>">
</div>
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
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-settings"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="kalibrieren" value="leer"><?php echo us_t('TEXT.JETZT_MESSEN_LEER'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-settings"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="kalibrieren" value="voll"><?php echo us_t('TEXT.JETZT_MESSEN_VOLL'); ?></button></form>
</div>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-pane<?php echo $us_tab === 'tab-loxone' ? ' sm-active' : ''; ?>" id="tab-loxone">

<h2><?php echo us_t('TEXT.EINBINDUNG_IN_LOXONE_SCHRITT_FR_SC'); ?></h2>
<div class="sm-small"><?php echo us_t('TEXT.DER_SENSOR_MISST_DEN_ABSTAND_ZUR_O'); ?></div>
<div class="sm-step"><b><?php echo us_t('TEXT.SCHRITT_1_SENSOR_EINRICHTEN'); ?></b><br><br>
<?php echo us_t('TEXT.IM_REITER'); ?> <i><?php echo us_t('REITER.EINSTELLUNGEN'); ?></i><?php echo us_t('TEXT.DANN_IM_REITER'); ?> <i><?php echo us_t('REITER.TEST'); ?></i> mit <i><?php echo us_t('TEXT.JETZT_MESSEN'); ?></i> <?php echo us_t('TEXT.PRFEN_OB_EIN_PLAUSIBLER_WERT_HERAU'); ?></div>
<div class="sm-step"><b><?php echo us_t('TEXT.SCHRITT_2_ABO_IM_MQTT_GATEWAY_EINT'); ?></b><br><br>
<?php if (!function_exists('us_hs_autostart')) { function us_hs_autostart() { $h = getenv('LBHOMEDIR') ?: '/opt/loxberry'; $g = $h . '/config/system/general.json'; if (!is_file($g)) { return null; } $j = json_decode((string) @file_get_contents($g), true); if (!is_array($j) || !isset($j['Mqtt'])) { return null; } return !empty($j['Mqtt']['Gatewayautostart']); } } if (us_hs_autostart() === false) { ?><div class="sm-alert sm-warn"><b>MQTT:</b> <?php echo us_t('TEXT.W_AUTOSTART'); ?></div><?php } ?>
<b><?php echo us_t('TEXT.OHNE_DIESEN_EINTRAG_KOMMT_AM_MINIS'); ?></b> <?php echo us_t('TEXT.EINZUTRAGEN_UNTER'); ?>
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

<h2><?php echo us_t('TEXT.VORLAGEN'); ?></h2>
<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-loxone">
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

<h3 class="sm-h3"><?php echo us_t('TEXT.ANSEHEN'); ?></h3>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="status"><?php echo us_t('TEXT.ZUSTAND_DES_DIENSTES'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="messwert"><?php echo us_t('TEXT.LETZTER_MESSWERT'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="mqttinfo"><?php echo us_t('TEXT.MQTT_GATEWAY'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="udpinfo"><?php echo us_t('TEXT.UDP_AN_DEN_MINISERVER'); ?></button></form>
</div>

<h3 class="sm-h3"><?php echo us_t('TEXT.TECHNISCHE_AUSKUNFT'); ?></h3>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="sensor"><?php echo us_t('TEXT.SENSOR_PRUEFEN'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="konfig"><?php echo us_t('TEXT.KONFIGURATION_ANZEIGEN'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="umgebung"><?php echo us_t('TEXT.UMGEBUNG_UND_MODULE'); ?></button></form>
</div>

<h3 class="sm-h3"><?php echo us_t('TEXT.LST_ETWAS_AUS'); ?></h3>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="messen"><?php echo us_t('TEXT.JETZT_MESSEN'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="udptest"><?php echo us_t('TEXT.UDP_TESTPAKET_SENDEN'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="restart"><?php echo us_t('TEXT.DIENST_NEU_STARTEN'); ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="stop"><?php echo us_t('TEXT.DIENST_ANHALTEN'); ?></button></form>
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
