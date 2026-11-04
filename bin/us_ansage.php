<?php
/**
 * Ultraschall - Bruecke vom Dienst zur gemeinsamen Sprachausgabe (seit 1.2.20).
 * Aufruf: php us_ansage.php <anlass> <0|1> <pluginordner> [name] [frist]. Anlaesse: 'ausfall'. Legt nur hin und kehrt sofort zurueck; es spricht ein eigener Vorgang.
 * Ausgabe: eine ASCII-Zeile, nie ein Text oder Token. Rueckgabe 0 gelegt oder nichts zu tun, 1 nicht ausfuehrbar.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur Kommandozeile.\n");
}
$us_a = isset($argv[1]) ? (string) $argv[1] : '';
$us_g = isset($argv[2]) ? (string) $argv[2] : '';
$us_o = isset($argv[3]) ? (string) $argv[3] : '';
$us_n = isset($argv[4]) ? (string) $argv[4] : '';
$us_f = isset($argv[5]) ? (int) $argv[5] : 0;
if (!in_array($us_a, array('ausfall'), true) || ($us_g !== '0' && $us_g !== '1') || preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $us_o) !== 1) {
    echo "ANSAGE;STAND=0;KENNUNG=AUFRUF\n";
    exit(1);
}
$us_h = getenv('LBHOMEDIR');
if (!$us_h || !is_dir($us_h . '/config/plugins')) {
    $us_h = dirname(dirname(dirname(__DIR__)));
}
$us_lib = $us_h . '/webfrontend/html/plugins/' . $us_o . '/us_lib.php';
if (!is_file($us_lib)) {
    echo "ANSAGE;STAND=0;KENNUNG=MODUL_FEHLT\n";
    exit(1);
}
putenv('LBHOMEDIR=' . $us_h);
putenv('LBPPLUGINDIR=' . $us_o);
require_once $us_lib;
echo 'ANSAGE;STAND=1;KENNUNG=' . strtoupper((string) us_ansage_flanke($us_a, $us_g === '1', $us_n, $us_f)) . "\n";
exit(0);
