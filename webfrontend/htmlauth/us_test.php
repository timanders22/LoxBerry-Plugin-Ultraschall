<?php
/**
 * Ultraschall Entfernung - Aktionen des Reiters Test
 *
 * Jede Funktion liefert array(Ueberschrift, Text). Der Text wird von der
 * Oberflaeche maskiert ausgegeben, hier also bewusst als Klartext erzeugt.
 */

require_once __DIR__ . '/us_lib.php';

function us_sh($cmd)
{
    $out = array();
    @exec($cmd . ' 2>&1', $out);
    return implode("\n", $out);
}

/** Einen Messdurchgang ueber bin/us_messen.py anstossen. */
function us_einmal_messen()
{
    $p = us_paths();
    $skript = $p['bindir'] . '/us_messen.py';
    if (!is_file($skript)) {
        return array('fehler' => 'us_messen.py nicht gefunden: ' . $skript);
    }
    $out = array();
    @exec('timeout 40 python3 ' . escapeshellarg($skript) . ' 2>&1', $out);
    $roh = trim(implode("\n", $out));
    $j = @json_decode($roh, true);
    if (!is_array($j)) {
        return array('fehler' => "Der Messlauf lieferte keine verwertbare Antwort:\n\n"
            . mb_substr($roh, 0, 800));
    }
    return $j;
}

function us_test_ausfuehren($was)
{
    $p = us_paths();
    list($cfg, $alt) = us_config_read();
    $sensoren = us_sensoren();
    $sensor = us_cfg($cfg, 'sensor', 'srf02');

    switch ($was) {

        case 'status':
            $pid = us_dienst_pid();
            $alter = us_status_alter();
            $t  = "Dienst:          " . ($pid ? "laeuft (PID $pid)" : 'laeuft nicht') . "\n";
            $t .= "Eingeschaltet:   " . (us_cfg($cfg, 'enabled', '0') === '1' ? 'ja' : 'nein') . "\n";
            $t .= "Zustandsdatei:   " . ($alter < 0 ? 'nicht vorhanden' : $alter . ' Sekunden alt') . "\n";
            $t .= "Sensor:          " . (isset($sensoren[$sensor]) ? $sensoren[$sensor] : $sensor) . "\n";
            if ($sensor === 'hcsr04') {
                $t .= "GPIO:            Trigger " . us_cfg($cfg, 'gpio_trigger', '23')
                    . ", Echo " . us_cfg($cfg, 'gpio_echo', '24') . "\n";
            } else {
                $t .= "I2C:             Bus " . us_cfg($cfg, 'i2c_bus', '1')
                    . ", Adresse " . us_cfg($cfg, 'i2c_adresse', '0x70') . "\n";
            }
            $t .= "Messung:         " . us_cfg($cfg, 'messungen', '5') . " Werte, Median, "
                . "gueltig von " . us_cfg($cfg, 'min_cm', '3') . " bis "
                . us_cfg($cfg, 'max_cm', '400') . " cm\n";
            $t .= "Takt:            alle " . us_cfg($cfg, 'intervall', '60') . " Sekunden\n";
            $t .= "MQTT:            " . (us_cfg($cfg, 'mqtt', '1') === '1' ? 'ein' : 'aus') . "\n";
            $t .= "UDP:             " . (us_cfg($cfg, 'udp', '0') === '1' ? 'ein' : 'aus') . "\n\n";
            if ($alt) {
                $t .= "Die Konfiguration liegt noch im Format der Originalfassung.\n"
                    . "Sie wird gelesen und beim naechsten Speichern umgeschrieben.\n\n";
            }
            if (us_cfg($cfg, 'enabled', '0') !== '1') {
                $t .= "Das Plugin ist ausgeschaltet - der Dienst misst nicht. Im Reiter\n"
                    . "Einstellungen einschalten und speichern.\n\n";
            } elseif (!$pid) {
                $t .= "Der Dienst laeuft nicht. Die Ursache steht meistens im Protokoll\n"
                    . "(Reiter Logdateien). Mit \"Dienst neu starten\" erneut versuchen.\n\n";
            } elseif ($alter > 3 * (int) us_cfg($cfg, 'intervall', '60')) {
                $t .= "Der Dienst laeuft, hat aber seit $alter Sekunden nichts mehr\n"
                    . "geschrieben - laenger als drei Messtakte. \"Sensor pruefen\"\n"
                    . "gibt Aufschluss.\n\n";
            }
            $t .= us_sh('ps -o pid,etime,rss,args -C python3 2>/dev/null | grep -iE "ultraschall|PID"');
            return array('Zustand des Dienstes', trim($t) !== '' ? $t : 'Keine Angaben.');

        case 'messwert':
            $s = us_status();
            if (!$s) {
                return array('Letzter Messwert',
                    "Es gibt noch keine Zustandsdatei.\n\n"
                    . "Sie entsteht, sobald der Dienst den ersten Durchgang beendet hat.\n"
                    . "Laeuft der Dienst? Siehe \"Zustand des Dienstes\".");
            }
            $t = "Stand: vor " . us_status_alter() . " Sekunden\n\n";
            if ($s['entfernung'] === null) {
                $t .= "Entfernung:   keine brauchbare Messung\n";
            } else {
                $t .= sprintf("Entfernung:   %.1f cm\n", $s['entfernung']);
            }
            if (isset($s['prozent']) && $s['prozent'] !== null) {
                $t .= sprintf("Fuellstand:   %.1f %%\n", $s['prozent']);
            } else {
                $t .= "Fuellstand:   nicht berechnet (Kalibrierung fehlt)\n";
            }
            if (isset($s['liter']) && $s['liter'] !== null) {
                $t .= sprintf("Inhalt:       %.1f l\n", $s['liter']);
            } else {
                $t .= "Inhalt:       nicht berechnet (Gesamtvolumen fehlt)\n";
            }
            $t .= "\nEinzelwerte:  " . (empty($s['roh']) ? '-' : implode('  ', $s['roh'])) . "\n";
            if (!empty($s['verworfen'])) {
                $liste = array();
                foreach ($s['verworfen'] as $v) {
                    $liste[] = $v === null ? 'kein Echo' : $v;
                }
                $t .= "Verworfen:    " . implode('  ', $liste) . "\n";
            }
            if (!empty($s['fehler'])) {
                $t .= "\nFehler:       " . $s['fehler'] . "\n";
            }
            return array('Letzter Messwert', $t);

        case 'messen':
            $j = us_einmal_messen();
            if (isset($j['fehler']) && !isset($j['entfernung'])) {
                return array('Jetzt messen', $j['fehler']
                    . (isset($j['hinweis']) ? "\n\n" . $j['hinweis'] : ''));
            }
            $t = "Sensor: " . (isset($sensoren[$sensor]) ? $sensoren[$sensor] : $sensor) . "\n\n";
            $t .= "Einzelwerte:  " . (empty($j['roh']) ? '-' : implode('  ', $j['roh'])) . "\n";
            if (!empty($j['verworfen'])) {
                $liste = array();
                foreach ($j['verworfen'] as $v) {
                    $liste[] = $v === null ? 'kein Echo' : $v;
                }
                $t .= "Verworfen:    " . implode('  ', $liste) . "\n";
            }
            $t .= "\n";
            if ($j['entfernung'] === null) {
                $t .= "Ergebnis:     keine brauchbare Messung\n";
                if (!empty($j['fehler'])) {
                    $t .= "\n" . $j['fehler'] . "\n";
                }
                $t .= "\nWas man pruefen kann:\n"
                    . "- Zeigt der Sensor tatsaechlich auf eine Flaeche?\n"
                    . "  Ultraschall braucht eine ebene, moeglichst harte Oberflaeche.\n"
                    . "  Schaum, Textilien und schraege Waende schlucken das Echo.\n"
                    . "- Liegt der Abstand im Plausibilitaetsbereich? Der SRF02 misst\n"
                    . "  ab etwa 16 cm, der HC-SR04 ab etwa 2 cm.\n"
                    . "- Stimmt die Verkabelung? \"Sensor pruefen\" sagt, ob der Bus\n"
                    . "  ueberhaupt antwortet.\n";
            } else {
                $t .= sprintf("Ergebnis:     %.1f cm  (Median von %d Werten)\n",
                    $j['entfernung'], count($j['roh']));
                list($proz, $liter) = us_fuellstand($cfg, $j['entfernung']);
                if ($proz !== null) {
                    $t .= sprintf("Fuellstand:   %.1f %%\n", $proz);
                    if ($liter !== null) {
                        $t .= sprintf("Inhalt:       %.1f l\n", $liter);
                    }
                } else {
                    $t .= "\nFuellstand:   nicht berechnet. Dafuer muessen im Reiter\n"
                        . "              Einstellungen \"leer\" und \"voll\" eingetragen sein.\n"
                        . "              Diesen Wert kann man dafuer verwenden.\n";
                }
            }
            return array('Jetzt messen', $t);

        case 'sensor':
            $t = '';
            if ($sensor === 'hcsr04') {
                $t .= "Sensor: HC-SR04 an GPIO " . us_cfg($cfg, 'gpio_trigger', '23')
                    . " (Trigger) und " . us_cfg($cfg, 'gpio_echo', '24') . " (Echo)\n\n";
                $t .= "gpiozero:  " . (trim(us_sh('python3 -c "import gpiozero; print(gpiozero.__version__)"')) ?: 'nicht vorhanden') . "\n";
                $t .= "lgpio:     " . (trim(us_sh('python3 -c "import lgpio; print(\"vorhanden\")"')) ?: 'nicht vorhanden') . "\n\n";
                $t .= "Gruppen des Benutzers loxberry:\n" . us_sh('id loxberry') . "\n\n";
                $t .= "GPIO-Geraete:\n" . (us_sh('ls -l /dev/gpiochip* 2>&1') ?: 'keine') . "\n\n";
                $t .= "Achtung Spannung: der HC-SR04 arbeitet mit 5 V. Der Echo-Pin muss\n"
                    . "ueber einen Spannungsteiler auf 3,3 V gebracht werden, sonst nimmt\n"
                    . "der Raspberry Pi mit der Zeit Schaden.\n";
            } else {
                $bus = us_cfg($cfg, 'i2c_bus', '1');
                $t .= "Sensor: SRF02 am I2C-Bus $bus, Adresse " . us_cfg($cfg, 'i2c_adresse', '0x70') . "\n\n";
                $t .= "Geraetedatei /dev/i2c-$bus: "
                    . (file_exists("/dev/i2c-$bus") ? 'vorhanden' : 'FEHLT - ist I2C eingeschaltet?') . "\n\n";
                $t .= "Gruppen des Benutzers loxberry:\n" . us_sh('id loxberry') . "\n\n";
                $t .= "Belegte Adressen am Bus (i2cdetect):\n";
                $scan = us_sh('i2cdetect -y ' . (int) $bus);
                $t .= ($scan !== '' ? $scan : 'i2cdetect nicht vorhanden oder kein Zugriff') . "\n\n";
                $t .= "Der SRF02 meldet sich ab Werk auf 0x70. Steht dort nichts, ist\n"
                    . "entweder die Verkabelung falsch oder die Adresse wurde geaendert.\n"
                    . "Erscheint statt der Adresse \"UU\", benutzt sie schon ein Treiber.\n";
            }
            return array('Sensor pruefen', $t);

        case 'konfig':
            $t = "Datei: " . $p['config'] . "\n\n";
            if (is_file($p['config'])) {
                $t .= (string) @file_get_contents($p['config']);
            } else {
                $t .= "Die Datei gibt es noch nicht. Sie entsteht beim ersten Speichern.\n\n"
                    . "Bis dahin gelten die Voreinstellungen:\n\n";
                foreach (us_defaults() as $k => $v) {
                    $t .= sprintf("  %-16s %s\n", $k, $v === '' ? '(leer)' : $v);
                }
            }
            return array('Konfiguration anzeigen', $t);

        case 'umgebung':
            $t  = "Python:      " . trim(us_sh('python3 --version')) . "\n";
            $t .= "System:      " . trim(us_sh('. /etc/os-release 2>/dev/null && echo "$PRETTY_NAME"')) . "\n";
            $t .= "Modell:      " . trim((string) @file_get_contents('/proc/device-tree/model')) . "\n";
            $t .= "LoxBerry:    " . ($p['home'] !== '' ? $p['home'] : 'nicht gefunden') . "\n";
            $t .= "Plugin:      " . $p['plugin'] . "\n";
            $t .= "Programme:   " . $p['bindir'] . "\n";
            $t .= "Protokolle:  " . $p['logdir'] . "\n\n";
            $t .= "Python-Module:\n";
            foreach (array('smbus', 'smbus2', 'gpiozero', 'lgpio', 'paho.mqtt.client') as $m) {
                $da = trim(us_sh('python3 -c "import ' . $m . '" >/dev/null 2>&1 && echo ja || echo nein'));
                $t .= sprintf("  %-18s %s\n", $m, $da);
            }
            $t .= "\nHilfsprogramme:\n";
            foreach (array('i2cdetect', 'pgrep', 'pkill') as $c) {
                $t .= sprintf("  %-18s %s\n", $c, trim(us_sh('command -v ' . $c)) ?: 'fehlt');
            }
            $t .= "\nGeladene I2C-Module:\n" . (us_sh('lsmod | grep -i i2c') ?: 'keine');
            return array('Umgebung und Module', $t);

        case 'mqttinfo':
            $broker = us_mqtt_broker();
            $t = "Broker: " . ($broker !== '' ? $broker : 'kein MQTT-Gateway in general.json gefunden') . "\n";
            $t .= "Themenpraefix: " . us_cfg($cfg, 'themenpraefix', 'ultraschall') . "\n\n";
            if ($broker === '') {
                $t .= "Ohne MQTT-Gateway kann das Plugin nichts veroeffentlichen.\n"
                    . "Das Gateway ist ein eigenes Plugin und muss eingerichtet sein.\n\n";
            }
            $t .= "Themen, die der Dienst setzt (alle retained):\n\n";
            $praefix = us_cfg($cfg, 'themenpraefix', 'ultraschall');
            foreach (us_status_themen() as $k => $info) {
                $t .= sprintf("  %-28s %s\n", $praefix . '/' . $k,
                    strip_tags(html_entity_decode($info[0], ENT_QUOTES, 'UTF-8')));
            }
            $t .= "\nRetained heisst: der Broker merkt sich den letzten Wert. Nach einem\n"
                . "Neustart des Miniservers steht die Entfernung sofort wieder da,\n"
                . "ohne auf die naechste Messung zu warten.\n";
            return array('MQTT-Gateway', $t);

        case 'udpinfo':
            $ms = us_miniservers();
            $nr = us_cfg($cfg, 'udp_miniserver', '1');
            $t = "UDP-Versand: " . (us_cfg($cfg, 'udp', '0') === '1' ? 'ein' : 'aus') . "\n";
            $t .= "Ziel:        Miniserver " . $nr;
            if (isset($ms[$nr])) {
                $t .= " (" . $ms[$nr]['name'] . ", " . $ms[$nr]['ip'] . ")";
            } else {
                $t .= " - nicht in general.json gefunden";
            }
            $t .= "\nPort:        " . (us_roh($cfg, 'udp_port') !== '' ? us_roh($cfg, 'udp_port') : 'nicht eingetragen') . "\n\n";
            $t .= "Bekannte Miniserver:\n";
            foreach ($ms as $k => $m) {
                $t .= sprintf("  %-3s %-24s %s\n", $k, $m['name'], $m['ip']);
            }
            if (!$ms) {
                $t .= "  keine\n";
            }
            $t .= "\nGesendet wird die Entfernung als blanke Zahl in cm, ohne Namen davor -\n"
                . "so wie es die Originalfassung tat. In Loxone Config braucht man dafuer\n"
                . "einen virtuellen UDP-Eingang mit Befehlserkennung \\v.\n\n"
                . "MQTT ist der bessere Weg: dort ueberlebt der Wert einen Neustart des\n"
                . "Miniservers, und Fuellstand und Liter kommen gleich mit.\n";
            return array('UDP an den Miniserver', $t);

        case 'udptest':
            if (us_cfg($cfg, 'udp', '0') !== '1') {
                return array('UDP-Testpaket senden',
                    "Der UDP-Versand ist ausgeschaltet. Zum Ausprobieren im Reiter\n"
                    . "Einstellungen einschalten, Port eintragen und speichern.");
            }
            $s = us_status();
            $wert = ($s && $s['entfernung'] !== null) ? (int) round($s['entfernung']) : 42;
            $ms = us_miniservers();
            $nr = us_cfg($cfg, 'udp_miniserver', '1');
            $port = us_roh($cfg, 'udp_port');
            if (!isset($ms[$nr]) || $ms[$nr]['ip'] === '') {
                return array('UDP-Testpaket senden',
                    "Miniserver $nr steht nicht in general.json.");
            }
            if (trim($port) === '' || !ctype_digit(trim($port))) {
                return array('UDP-Testpaket senden', "Es ist kein gueltiger Port eingetragen.");
            }
            $sock = @fsockopen('udp://' . $ms[$nr]['ip'], (int) $port, $errno, $errstr, 3);
            if (!$sock) {
                return array('UDP-Testpaket senden',
                    "Verbindung nicht moeglich: $errstr ($errno)");
            }
            @fwrite($sock, (string) $wert);
            @fclose($sock);
            return array('UDP-Testpaket senden',
                "Gesendet: $wert\n"
                . "An:       " . $ms[$nr]['ip'] . ":" . $port . "\n\n"
                . "UDP bestaetigt nichts. Ob der Miniserver das Paket bekommen hat,\n"
                . "sieht man nur dort - in Loxone Config unter Monitor / UDP-Monitor.\n"
                . ($s && $s['entfernung'] !== null
                    ? "Gesendet wurde der letzte gemessene Wert.\n"
                    : "Es gab noch keine Messung, deshalb der Platzhalter 42.\n"));

        case 'restart':
            $aus = us_dienst('restart');
            $pid = us_dienst_pid();
            return array('Dienst neu starten',
                ($pid ? "Der Dienst laeuft wieder (PID $pid)." : "Der Dienst laeuft nicht.\nDie Ursache steht im Protokoll, Reiter Logdateien.")
                . ($aus !== '' ? "\n\n" . $aus : ''));

        case 'stop':
            $aus = us_dienst('stop');
            $pid = us_dienst_pid();
            return array('Dienst anhalten',
                ($pid ? "Der Dienst laeuft noch (PID $pid)." : 'Der Dienst wurde angehalten.')
                . ($aus !== '' ? "\n\n" . $aus : ''));
    }

    return array('Unbekannt', 'Diese Aktion gibt es nicht: ' . $was);
}
