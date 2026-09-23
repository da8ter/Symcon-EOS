<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../EOSBattery/module.php';
require_once __DIR__ . '/../EOSVehicle/module.php';
require_once __DIR__ . '/../EOSMeter/module.php';

$eos = $GLOBALS['eos'];
$now = time();

// ---------------------------------------------------------------- bindings: type conversion and normalisation
echo "== Typumwandlung\n";
$b = new EOSBattery(1000); $b->Create();
$probe = new class($b) {
    public function __construct(private EOSBattery $m) {}
    public function coerce(mixed $v, int $t): mixed { return (fn () => $this->coerceToVariableType($v, $t))->call($this->m); }
    public function norm(mixed $v): string { return (fn () => $this->normalizeValue($v))->call($this->m); }
    public function action(string $j): string { return (fn () => $this->normalizeActionJson($j))->call($this->m); }
};
check($probe->coerce('true', 0) === true && $probe->coerce(1.0, 0) === true && $probe->coerce('0', 0) === false && $probe->coerce('aus', 0) === false, 'bool conversion accepts common spellings');
try { $probe->coerce('maybe', 0); check(false, 'ambiguous bool rejected'); } catch (InvalidArgumentException $e) { check(true, 'ambiguous bool rejected'); }
check($probe->coerce(2750.4, 1) === 2750 && $probe->coerce('12', 1) === 12 && $probe->coerce(true, 1) === 1, 'int conversion rounds');
try { $probe->coerce('pv', 1); check(false, 'text to int rejected'); } catch (InvalidArgumentException $e) { check(true, 'text to int rejected'); }
check($probe->coerce(2750.0, 3) === '2750' && $probe->coerce(false, 3) === 'false' && $probe->coerce('pv', 3) === 'pv', 'string conversion keeps enums, drops .0');
check($probe->norm(5000.0) === '5000' && $probe->norm(0.0) === '0' && $probe->norm(15.949) === '15.949' && $probe->norm(true) === '1', 'normalised values for change detection');
check($probe->action('') === '' && $probe->action('{}') === '' && $probe->action('{"actionID":"","parameters":{}}') === '' && $probe->action('{"actionID":"{X}"}') === '{"actionID":"{X}"}', 'action JSON without actionID means no action');

// ---------------------------------------------------------------- config sync: compare tolerantly, never send null (finding 5)
echo "== Konfigurationsabgleich\n";
worldVar(30, 1, 40, false);
$eos->config = ['devices' => ['electric_vehicles' => ['ev1' => [
    'device_id' => 'ev1', 'capacity_wh' => 60000, 'max_charge_power_w' => 11000, 'min_soc_percentage' => 80, 'max_soc_percentage' => 100,
    'charging_efficiency' => 0.9, 'charge_rates' => [0, 0.25, 0.5, 0.75, 1], 'min_soc_deadline_datetime' => '2026-09-23T07:00:00.000000+02:00',
]]]];
$v = new EOSVehicle(1001); $v->Create(); connectToServer($v);
$v->properties['SoCSourceVariable'] = 30;
$eos->calls = [];
$v->ApplyChanges();
$merges = array_filter($eos->calls, static fn ($c) => $c['Command'] === 'MergeConfig');
check($merges === [], 'equal configuration (charge rates as list, deadline null in Symcon) is not written: ' . json_encode(array_column($eos->calls, 'Command')));
$v->properties['CapacityWh'] = 62000; $eos->calls = []; $v->ApplyChanges();
$merges = array_values(array_filter($eos->calls, static fn ($c) => $c['Command'] === 'MergeConfig'));
check(count($merges) === 1, 'changed capacity is written once');
$sent = $merges[0]['Value']['devices']['electric_vehicles']['ev1'];
check(!array_key_exists('min_soc_deadline_datetime', $sent) && $sent['capacity_wh'] === 62000, 'null is not part of the payload, the EOS deadline survives');
check($eos->config['devices']['electric_vehicles']['ev1']['min_soc_deadline_datetime'] === '2026-09-23T07:00:00.000000+02:00', 'deadline kept in EOS');
check(count(array_filter($v->logs, static fn ($l) => str_contains($l[1], 'capacity_wh'))) === 1, 'write logged with the differing field');
// timestamp notation tolerance
$v->variables['Departure']['value'] = $now + 7200;
$eos->config['devices']['electric_vehicles']['ev1']['min_soc_deadline_datetime'] = date('Y-m-d\TH:i:s.000000P', $now + 7200);
$eos->calls = []; $v->ApplyChanges();
check(array_filter($eos->calls, static fn ($c) => $c['Command'] === 'MergeConfig') === [], 'same timestamp in a different notation counts as equal');
// notation tolerance of the comparison (three-way sync)
$probeSync = new class($v) {
    public function __construct(private EOSVehicle $m) {}
    public function eq(string $key, mixed $eos, mixed $ours): bool { return (fn () => $this->valuesEqual($key, $eos, $ours))->call($this->m); }
};
check($probeSync->eq('time_windows', ['windows' => [['start_time' => '08:00:00.000000', 'duration' => '6 hours', 'day_of_week' => null]]], ['windows' => [['start_time' => '08:00:00', 'duration' => '6 hours']]]), 'EOS default fields and .000000 do not count as difference');
check(!$probeSync->eq('capacity_wh', null, 1) && $probeSync->eq('capacity_wh', 1, 1.0000001) && !$probeSync->eq('capacity_wh', 1, 2) && $probeSync->eq('max_charge_power_w', 3680.5, 3680), 'equality: missing value, float tolerance, real change, below 1 W for powers (K14)');

// ---------------------------------------------------------------- meter: key registration and late parent (findings 4 and 6)
echo "== Zähler\n";
worldVar(50, 2, 1234.5, false);
$eos->config = ['measurement' => ['load_emr_keys' => ['load0_emr'], 'grid_import_emr_keys' => ['grid_import_emr']]];
$mt = new EOSMeter(1003); $mt->Create(); connectToServer($mt);
$mt->properties['Meters'] = json_encode([['variable' => 50, 'key' => 'house_emr', 'category' => 'load', 'unit' => 0]]);
$eos->configReadFails = true; $eos->calls = [];
check($mt->WriteKeysToEOS() === false && array_filter($eos->calls, static fn ($c) => $c['Command'] === 'MergeConfig') === [], 'read failure aborts key registration without writing');
check(str_contains((string) $mt->value('LastError'), 'keys'), 'read failure reported in LastError');
$eos->configReadFails = false; $eos->calls = [];
check($mt->WriteKeysToEOS() === true && $eos->config['measurement']['load_emr_keys'] === ['load0_emr', 'house_emr'] && $eos->config['measurement']['grid_import_emr_keys'] === ['grid_import_emr'], 'successful read extends only the matching list');
// late parent: status 104, then a broadcast arrives
$GLOBALS['instances'][2000]['InstanceStatus'] = 201;
$mt->ApplyChanges();
check($mt->status === IS_INACTIVE && $mt->timers['MeterPush']['ms'] === 0, 'unreachable server: status 104, push timer off');
$GLOBALS['instances'][2000]['InstanceStatus'] = IS_ACTIVE;
$mt->ReceiveData(json_encode(['DataID' => '{EAB78E68-BFF7-4608-BA75-9ECDB5165208}', 'Event' => 'PlanUpdated', 'Plan' => [], 'Instructions' => []]));
check(count($mt->onceTimers) === 1 && $mt->onceTimers[0]['name'] === 'ApplyLater', 'broadcast with usable server schedules ApplyChanges');
$mt->fireOnce();
check($mt->status === IS_ACTIVE && $mt->timers['MeterPush']['ms'] === 300000, 'deferred ApplyChanges restores status and push timer');

// ---------------------------------------------------------------- SoC push (K2, K18, K19, K52c)
echo "== SoC-Push\n";
setClock($now);
$pushes = static fn (): array => array_values(array_filter($GLOBALS['eos']->calls, static fn (array $c): bool => ($c['Command'] ?? '') === 'PutMeasurement'));
$logged = static fn (IPSModuleStrict $m, string $text): int => count(array_filter($m->logs, static fn (array $l): bool => str_contains($l[1], $text)));
worldVar(90, 2, 100.5, false);
$eos->config['devices']['batteries']['battery1'] = ['device_id' => 'battery1'];
$sb = new EOSBattery(1010); $sb->Create(); connectToServer($sb); $sb->properties['SoCSourceVariable'] = 90;
$eos->calls = []; $sb->ApplyChanges();
$sent = $pushes();
check((float) (end($sent)['Value'] ?? -1) === 1.0, 'K2: 100.5 % is sent as 1.0, not divided twice');
$GLOBALS['world'][90]['value'] = 150.0; $eos->calls = []; $sb->PushSoC(); $sb->PushSoC();
check($pushes() === [] && $logged($sb, 'out of range') === 1, 'K2: 150 % is not sent and warned about once');
$sb->properties['SoCUnit'] = 1; $GLOBALS['world'][90]['value'] = 1.005; $eos->calls = []; $sb->PushSoC();
$GLOBALS['world'][90]['value'] = 55.0; $sb->PushSoC();
check(array_map('floatval', array_column($pushes(), 'Value')) === [1.0, 0.55], 'K2: factor 1.005 becomes 1.0, 55 in factor mode is read as percent');
$sb->properties['SoCUnit'] = 0; $GLOBALS['world'][90]['value'] = 40.0;
$eos->putFails = true; $eos->calls = [];
$sb->PushSoC();
for ($i = 1; $i <= 5; $i++) {
    setClock($now + $i * 2);
    $sb->MessageSink(0, 90, VM_UPDATE, [40.0 + $i, true, 40.0]);
}
check(count($pushes()) === 1 && $logged($sb, 'SoC push failed') === 1, 'K18: after a rejected push, source updates in the backoff send nothing and warn nothing more');
$eos->putFails = false; setClock($now + 200); $eos->calls = [];
$sb->MessageSink(0, 90, VM_UPDATE, [45.0, false, 45.0]);
check($pushes() === [], 'K18: an update without a change sends nothing (the push timer keeps EOS fresh)');
$sb->MessageSink(0, 90, VM_UPDATE, [46.0, true, 45.0]);
check(count($pushes()) === 1 && $logged($sb, 'SoC push works again') === 1, 'a changed value is sent; recovery is logged');
$sb->properties['SoCMaxAgeMinutes'] = 10; $GLOBALS['world'][90]['VariableUpdated'] = $now + 200 - 3600; $eos->calls = [];
$sb->PushSoC(); $sb->PushSoC();
check($pushes() === [] && $logged($sb, 'not updated for more than 10 minutes') === 1, 'K52c: a frozen source (1 h) is not sent, warned about once');
unset($GLOBALS['objects'][1010]);
worldVar(91, 0, true, false);
$sv = new EOSVehicle(1011); $sv->Create(); connectToServer($sv);
$sv->properties['SoCSourceVariable'] = 30; $sv->properties['PluggedSourceVariable'] = 91; $sv->ApplyChanges();
$sets = $sv->variables['NextChange']['setCount']; $eos->calls = [];
$sv->MessageSink(0, 91, VM_UPDATE, [true, false, true]);
check($sv->variables['NextChange']['setCount'] === $sets && $pushes() === [], 'K19: a plug update without a change neither re-evaluates the plan nor pushes');
unset($GLOBALS['objects'][1011]);
setClock(null);

// ---------------------------------------------------------------- tile texts (K27)
echo "== Kachel-Texte\n";
$de = json_decode((string) file_get_contents(__DIR__ . '/../EOSBattery/locale.json'), true)['translations']['de'];
$tile = new class(1020) extends EOSBattery {
    public array $de = [];
    protected function Translate(string $text): string { return $this->de[$text] ?? $text; }
};
$tile->de = $de; $tile->Create();
$html = $tile->GetVisualizationTile();
preg_match('/const I18N = (\{.*?\});/', $html, $m);
$i18n = json_decode($m[1] ?? 'null', true);
check(is_array($i18n) && ($i18n['Locked'] ?? '') === 'Gesperrt' && ($i18n['from %s: %s'] ?? '') === 'ab %s: %s' && !str_contains($html, 'const I18N = {};'), 'K27: the tile receives its texts translated');
$source = (string) file_get_contents(__DIR__ . '/../EOSBattery/module.html');
preg_match_all("/\\btf?\\('((?:[^'\\\\]|\\\\.)*)'/", $source, $keys);
check(count($keys[1]) > 20 && array_diff($keys[1], array_keys($de)) === [] && !preg_match('/Gesperrt|Steuerung|jetzt|Preis|de-DE/', $source), 'K27: every tile text has a German translation and none is hard-coded');

echo "\nAlle {$GLOBALS['checks']} Prüfungen bestanden.\n";
