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

echo "\nAlle {$GLOBALS['checks']} Prüfungen bestanden.\n";
