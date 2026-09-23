<?php

declare(strict_types=1);

/*
 * EOS Server on top of FakeEOSClient: device -> real EOSServer::ForwardData -> fake
 * EOS. Covers the splitter itself (health, plan distribution, measurement bundling,
 * configuration pass-through) and the EOS semantics the fake models.
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fake_eos_client.php';
require_once __DIR__ . '/../EOSServer/module.php';
require_once __DIR__ . '/../EOSBattery/module.php';
require_once __DIR__ . '/../EOSMeter/module.php';

$be = $GLOBALS['eosBackend'];
$now = time();
setClock($now);
$be->load(['devices' => [
    'max_batteries' => 1, 'max_electric_vehicles' => 1, 'max_inverters' => 1, 'max_home_appliances' => 0,
    'batteries' => ['battery1' => ['device_id' => 'battery1', 'capacity_wh' => 10000, 'max_charge_power_w' => 5000, 'min_soc_percentage' => 10, 'max_soc_percentage' => 95]],
    'electric_vehicles' => ['ev1' => ['device_id' => 'ev1', 'capacity_wh' => 60000, 'min_soc_percentage' => 80, 'max_soc_percentage' => 100]],
    'inverters' => ['inv1' => ['device_id' => 'inv1', 'max_power_w' => 10000, 'battery_id' => 'battery1']],
    'home_appliances' => [],
], 'measurement' => ['load_emr_keys' => ['load0_emr']]]);

function forward(EOSServer $s, array $payload): array
{
    $payload['DataID'] = '{8C2B1A5E-2D35-4723-8EEA-6B71A7B2428F}';
    return json_decode($s->ForwardData(json_encode($payload)), true);
}

// ---------------------------------------------------------------- fake EOS semantics (guards the model itself)
echo "== EOS-Nachbau\n";
check($be->putConfig(['devices' => ['electric_vehicles' => ['ev1' => ['device_id' => 'ev1', 'min_soc_percentage' => 100]]]])['status'] === 422, 'partial body min 100 with default max 100 is rejected (probe P2)');
$be->putConfig(['devices' => ['electric_vehicles' => ['ev1' => ['device_id' => 'ev1', 'min_soc_deadline_datetime' => '2026-09-24T07:00:00+02:00']]]]);
$be->putConfig(['devices' => ['electric_vehicles' => ['ev1' => ['device_id' => 'ev1', 'min_soc_deadline_datetime' => null]]]]);
check($be->getConfigPath('devices/electric_vehicles/ev1/min_soc_deadline_datetime')['data'] === '2026-09-24T07:00:00+02:00', 'null in a merge does not clear (probe P2)');
$be->putConfigPath('devices/electric_vehicles/ev1/min_soc_deadline_datetime', null);
$be->putConfig(['general' => ['latitude' => 51.2]]);
check($be->getConfigPath('devices/electric_vehicles/ev1/min_soc_deadline_datetime')['data'] === null, 'path PUT null clears and survives later merges (probe P2)');
$be->putConfig(['devices' => ['electric_vehicles' => ['probe_ev' => ['device_id' => 'probe_ev']]]]);
$be->putConfigPath('devices/electric_vehicles', ['ev1' => $be->live['devices']['electric_vehicles']['ev1']]);
$be->putConfig(['general' => ['latitude' => 51.2]]);
check(array_keys($be->live['devices']['electric_vehicles']) === ['ev1', 'probe_ev'], 'a replaced device map comes back on the next merge (probe P2)');
$be->putConfigPath('devices/electric_vehicles', ['ev1' => $be->live['devices']['electric_vehicles']['ev1']]);
$be->saveConfigFile(); $be->resetConfig(); $be->putConfig(['general' => ['latitude' => 51.2]]);
check(array_keys($be->live['devices']['electric_vehicles']) === ['ev1'], 'map PUT + save + reset removes the key for good (probe P2)');
check($be->getConfigPath('devices/batteries/nope')['status'] === 404, 'missing device path answers 404');

// ---------------------------------------------------------------- health, plan distribution
echo "== Server\n";
$server = realServer();
check($server->status === 203 && $server->value('Connected') === true && $server->value('Version') === '0.4.0rc1', 'health poll: connected, version; no plan in EOS yet -> status 203');
worldVar(10, 2, 55.0, false);
$b = new EOSBattery(1000); $b->Create(); connectToRealServer($b);
$b->properties['SoCSourceVariable'] = 10;
$b->ApplyChanges();
check($b->status === IS_ACTIVE, 'battery behind the real server becomes active');
check(($be->records[$now]['battery1-soc-factor'] ?? null) === 0.55, 'SoC push reaches EOS through ForwardData');
$be->plan = ['id' => 'p1', 'generated_at' => date(DATE_ATOM, $now - 30), 'valid_from' => null, 'valid_until' => null,
    'instructions' => [['id' => 'battery1@x', 'resource_id' => 'battery1', 'execution_time' => date(DATE_ATOM, $now - 60), 'operation_mode_id' => 'SELF_CONSUMPTION', 'operation_mode_factor' => 1.0]]];
check($server->FetchPlan() === true && $server->status === IS_ACTIVE, 'plan fetched, status 102');
check($b->value('ModeRaw') === 'SELF_CONSUMPTION', 'plan broadcast reaches the child');

// ---------------------------------------------------------------- measurement bundling
echo "== Messwerte\n";
setClock($now + 30);
$res = forward($server, ['Command' => 'PutMeasurement', 'Key' => 'ev1-soc-factor', 'Value' => 0.4, 'DateTime' => date(DATE_ATOM, $now + 30)]);
check($res['ok'] === true && ($be->records[$now + 30]['ev1-soc-factor'] ?? null) === 0.4 && ($be->records[$now + 30]['battery1-soc-factor'] ?? null) === 0.55, 'a second SoC key carries the known battery SoC in the same record');
check($be->runCheck($now + 30) === [], 'complete configuration with fresh SoC values: EOS run can start');
$res = forward($server, ['Command' => 'GetConfig', 'Path' => 'devices/batteries/battery1']);
check($res['ok'] === true && $res['data']['capacity_wh'] === 10000, 'GetConfig passes the device entry through');

// ---------------------------------------------------------------- K23, K49: reachability and status
echo "== Erreichbarkeit und Status (K23, K49)\n";
setClock($now);
$be->plan = ['id' => 'p1', 'generated_at' => date(DATE_ATOM, $now - 30), 'valid_from' => null, 'valid_until' => null,
    'instructions' => [['id' => 'battery1@x', 'resource_id' => 'battery1', 'execution_time' => date(DATE_ATOM, $now - 60), 'operation_mode_id' => 'SELF_CONSUMPTION', 'operation_mode_factor' => 1.0]]];
$server->FetchPlan();
check($server->status === IS_ACTIVE, 'server active with plan');
$be->down = 'timeout';
forward($server, ['Command' => 'PutMeasurement', 'Key' => 'battery1-soc-factor', 'Value' => 0.5, 'DateTime' => date(DATE_ATOM, $now)]);
check($server->status === IS_ACTIVE, 'K23: one timeout (e.g. during a GENETIC run) is not an outage');
forward($server, ['Command' => 'PutMeasurement', 'Key' => 'battery1-soc-factor', 'Value' => 0.5, 'DateTime' => date(DATE_ATOM, $now)]);
check($server->status === 201 && $server->value('Connected') === false, 'K23: two timeouts in a row mark EOS unreachable');
check(count(array_filter($server->onceTimers, static fn (array $t): bool => $t['name'] === 'StatusBroadcast')) === 1, 'K23: the status broadcast is deferred (not sent from inside the child request)');
$be->down = '';
forward($server, ['Command' => 'PutMeasurement', 'Key' => 'battery1-soc-factor', 'Value' => 0.5, 'DateTime' => date(DATE_ATOM, $now)]);
check($server->status === IS_ACTIVE, 'K23: the next successful request makes EOS reachable again');
$server->fireOnce();
$be->down = 'connect';
forward($server, ['Command' => 'GetConfig', 'Path' => 'devices']);
check($server->status === 201, 'K23: a connection error marks EOS unreachable at once');
$server->fireOnce();
// child goes to 104 while EOS is down; EOS comes back with an unexpected version
$b->ApplyChanges();
check($b->status === IS_INACTIVE, 'battery waits for the server (104)');
$be->down = ''; $be->health['version'] = '0.5.0';
$server->PollHealth(); $b->fireOnce();
check($server->status === 202 && $b->status === IS_ACTIVE, 'K49: a reconnect with a version mismatch still wakes the waiting children: server ' . $server->status . ', battery ' . $b->status);
$be->plan['generated_at'] = date(DATE_ATOM, $now - 20); $server->FetchPlan();
check($server->status === 202 && str_contains((string) $server->value('LastError'), 'version'), 'K49: a plan fetch does not hide the version mismatch');
$be->health['version'] = '0.4.0rc1'; $server->PollHealth();
check($server->status === IS_ACTIVE, 'mismatch resolved -> active');

// ---------------------------------------------------------------- K22: unchanged plan
echo "== Unveränderter Plan (K22)\n";
$be->calls = []; $plans = 0;
$b->variables['ModeRaw']['setCount'] = 0;
$server->FetchPlan();
$gets = array_filter($be->calls, static fn (array $c): bool => $c[1] === '/v1/energy-management/optimization/solution');
check($gets === [] && $b->variables['ModeRaw']['setCount'] === 0, 'K22: an unchanged plan is neither fetched again with its solution nor broadcast');

// ---------------------------------------------------------------- K6: optimize does not block the server
echo "== Optimierung (K6)\n";
$be->optimizeSlow = true; $be->calls = [];
$server->RunOptimizeNow();
$call = array_values(array_filter($be->calls, static fn (array $c): bool => $c[1] === '/v1/optimize'))[0] ?? [];
check(($call[2] ?? 0) === 3 && $server->attributes['OptimizeRequestedTs'] === $now && str_contains((string) $server->value('LastError'), 'optimize') === false, 'K6: "Optimize now" only kicks the run off (3 s), a timeout counts as started');
setClock($now + 1000); $server->PollHealth();
check(count(array_filter($server->logs, static fn (array $l): bool => str_contains($l[1], 'no new run'))) === 1 && $server->attributes['OptimizeRequestedTs'] === 0, 'K6: no new EOS run 15 min later is reported once');
setClock($now); $be->optimizeSlow = false;

// ---------------------------------------------------------------- K7, K52d: sticky values
echo "== Sticky-Werte (K7, K52d)\n";
$be->records = [];
forward($server, ['Command' => 'PutMeasurement', 'Key' => 'battery1-soc-factor', 'Value' => 0.6, 'DateTime' => date(DATE_ATOM, $now - 30)]);
forward($server, ['Command' => 'PutSamples', 'Samples' => [
    ['date_time' => date(DATE_ATOM, $now), 'key' => 'load0_emr', 'value' => 12.0],
    ['date_time' => date(DATE_ATOM, $now - 3600), 'key' => 'load0_emr', 'value' => 11.0],
]]);
check(($be->records[$now]['battery1-soc-factor'] ?? null) === 0.6 && !isset($be->records[$now - 3600]['battery1-soc-factor']), 'K7: the sticky values are stamped with the newest sample (history comes newest first)');
$server->attributes['SoCCache'] = json_encode(['dishwasher1.cycles_completed' => ['value' => 1.0, 'ts' => $now - 86400]]);
$bundle = (fn (): array => $this->stickyBundle(''))->call($server);
check(($bundle['dishwasher1.cycles_completed'] ?? null) === 0.0, 'K52d: yesterday\'s completed cycles are sent as 0 after midnight');
$server->attributes['SoCCache'] = '{}';

// ---------------------------------------------------------------- K50, K53, K55a, S2
echo "== Konfiguration (K50, K53, K55a, S2)\n";
$be->live['devices']['home_appliances'] = [];
$be->calls = [];
$server->SetConfig('devices/home_appliances', '{}');
$last = end($be->calls);
check(($last[2] ?? null) instanceof stdClass, 'K50: "{}" reaches EOS as an object, not as a list');
check($server->WriteRawMerge('{"devices":{"electric_vehicles":{}}}') === true, 'K50: a raw merge with an empty device map is accepted');
check($server->GetConfig('devices/home_appliances') === '{}', 'K50: an empty device map reads back as "{}"');
locationControl(51.245, 6.855); $server->formUpdates = [];
$server->UseSymconLocation();
check(in_array(['GeneralLatitude', 'value', 51.245], $server->formUpdates, true) && in_array(['GeneralLongitude', 'value', 6.855], $server->formUpdates, true), 'K53: the location comes from the Location Control');
$json = (fn (): string => $this->reply(['ok' => false, 'status' => 502, 'error' => "Bad Gateway \xc3"]))->call($server);
check(is_array(json_decode($json, true)), 'K55a: a reply with a cut multibyte character is still valid JSON');
$GLOBALS['registry'] = true;
$be->live['devices']['inverters'] = [];
$server->properties['InverterMaxPowerW'] = 10000; $server->properties['InverterID'] = 'inv1';
check($server->WriteConfigToEOS() === true && ($be->live['devices']['inverters']['inv1']['battery_id'] ?? null) === 'battery1', 'S2: the inverter is written and linked to the battery on this server');
$be->live['devices']['inverters'] = ['inverter9' => ['device_id' => 'inverter9']]; $be->calls = [];
check($server->WriteConfigToEOS() === false && array_filter($be->calls, static fn (array $c): bool => $c[0] === 'PUT' && $c[1] === '/v1/config') === [], 'S2: a second inverter under another id is refused');
$problems = (fn (): array => $this->configProblems(['devices' => ['batteries' => ['battery1' => ['min_soc_percentage' => 10, 'max_soc_percentage' => 95]], 'inverters' => []]]))->call($server);
check(count($problems) === 1 && str_contains($problems[0], 'no inverter'), 'S2: the plausibility check names the missing inverter');
$GLOBALS['registry'] = false;

// ---------------------------------------------------------------- K7: history import beyond the archive limit
echo "== Historien-Import (K7)\n";
plainInstance(30000, ARCHIVE_CONTROL_GUID);
worldVar(80, 2, 5000.0, false);
$GLOBALS['logged'][80] = [];
for ($t = $now - 48 * 3600; $t <= $now; $t += 10) {
    $GLOBALS['logged'][80][] = ['TimeStamp' => $t, 'Value' => 1000.0 + ($t - $now) / 1000];
}
$be->live['measurement']['load_emr_keys'] = ['load0_emr', 'house_emr'];
$mt = new EOSMeter(1003); $mt->Create(); connectToRealServer($mt);
$mt->properties['Meters'] = json_encode([['variable' => 80, 'key' => 'house_emr', 'category' => 'load', 'unit' => 0]]);
$mt->formUpdates = [];
check($mt->ImportHistory(48) === true && in_array(['ActionResult', 'caption', '17281 history values imported.'], $mt->formUpdates, true), 'K7: the import pages past the 10000-row archive limit and reports the real count');

echo "\nAlle {$GLOBALS['checks']} Prüfungen bestanden.\n";
