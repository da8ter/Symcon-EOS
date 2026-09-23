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

echo "\nAlle {$GLOBALS['checks']} Prüfungen bestanden.\n";
