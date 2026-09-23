<?php

declare(strict_types=1);

/*
 * Device configuration in EOS and the server's configuration writes, over the real
 * EOSServer and the EOS model (tests/fake_eos_client.php). Finding ids of the review
 * of 23.09.2026 are part of each check label.
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fake_eos_client.php';
require_once __DIR__ . '/../EOSServer/module.php';
require_once __DIR__ . '/../EOSBattery/module.php';
require_once __DIR__ . '/../EOSVehicle/module.php';
require_once __DIR__ . '/../EOSAppliance/module.php';
require_once __DIR__ . '/../EOSMeter/module.php';

$be = $GLOBALS['eosBackend'];
$now = time();
setClock($now);
const BAT = ['device_id' => 'battery1', 'capacity_wh' => 10000, 'max_charge_power_w' => 5000, 'min_soc_percentage' => 10, 'max_soc_percentage' => 95, 'charging_efficiency' => 0.95, 'discharging_efficiency' => 0.95, 'levelized_cost_of_storage_amt_kwh' => 0.0];
const EV = ['device_id' => 'ev1', 'capacity_wh' => 60000, 'max_charge_power_w' => 11000, 'min_soc_percentage' => 80, 'max_soc_percentage' => 100, 'charging_efficiency' => 0.9, 'charge_rates' => [0.0, 0.25, 0.5, 0.75, 1.0]];
const HA = ['device_id' => 'dishwasher1', 'consumption_wh' => 2000, 'duration_h' => 3, 'num_cycles' => 1, 'min_cycle_gap_h' => 0, 'schedule_mode' => 'ONCE', 'deadline_policy' => 'BEST_EFFORT'];
const INV = ['inv1' => ['device_id' => 'inv1', 'max_power_w' => 10000, 'battery_id' => 'battery1']];

function eosLoad(array $devices, array $measurement = []): void
{
    $GLOBALS['eosBackend']->load(['devices' => $devices + ['batteries' => [], 'electric_vehicles' => [], 'inverters' => [], 'home_appliances' => []], 'measurement' => $measurement]);
    $GLOBALS['eosBackend']->calls = [];
    $GLOBALS['eosBackend']->getConfigStatus = 0;
}
function eosMerges(): array
{
    return array_values(array_filter($GLOBALS['eosBackend']->calls, static fn (array $c): bool => $c[0] === 'PUT' && $c[1] === '/v1/config'));
}
function element(array $nodes, string $name): ?array
{
    foreach ($nodes as $n) {
        if (!is_array($n)) {
            continue;
        }
        if (($n['name'] ?? '') === $name) {
            return $n;
        }
        if (isset($n['items']) && ($found = element($n['items'], $name)) !== null) {
            return $found;
        }
    }
    return null;
}
function battery(string $id = 'battery1'): EOSBattery
{
    $b = new EOSBattery(1000); $b->Create(); connectToRealServer($b);
    $b->properties['SoCSourceVariable'] = 10; $b->properties['DeviceID'] = $id;
    $b->properties['CapacityWh'] = 10000; $b->properties['MaxChargePowerW'] = 5000; $b->properties['MinSoC'] = 10; $b->properties['MaxSoC'] = 95;
    $b->properties['ChargingEfficiency'] = 0.95; $b->properties['DischargingEfficiency'] = 0.95; $b->properties['LcosAmtKwh'] = 0.0;
    return $b;
}
function vehicle(): EOSVehicle
{
    $v = new EOSVehicle(1001); $v->Create(); connectToRealServer($v);
    $v->properties['SoCSourceVariable'] = 30; $v->properties['CapacityWh'] = 60000; $v->properties['MaxChargePowerW'] = 11000;
    $v->properties['TargetSoC'] = 80; $v->properties['MaxSoC'] = 100; $v->properties['ChargingEfficiency'] = 0.9; $v->properties['ChargeRates'] = '0, 0.25, 0.5, 0.75, 1';
    return $v;
}
worldVar(10, 2, 55.0, false);
worldVar(30, 2, 40.0, false);
worldVar(31, 0, true, false);
$server = realServer();

// ---------------------------------------------------------------- K43: EOS error bodies are not configuration data
echo "== Lesefehler und fehlende Einträge (K43)\n";
eosLoad(['inverters' => INV]);
$v = vehicle();
$form = json_decode($v->GetConfigurationForm(), true);
check(str_contains((string) (element($form['elements'], 'ConfigInfo')['caption'] ?? ''), 'not in EOS yet'), 'K43: a device EOS answers with 404 is "not in EOS yet", not a difference: ' . (element($form['elements'], 'ConfigInfo')['caption'] ?? ''));
eosLoad(['batteries' => ['battery1' => BAT], 'inverters' => INV]);
$be->getConfigStatus = 500;
$b = battery(); $b->ApplyChanges();
check(eosMerges() === [], 'K43: an EOS error while reading the entry never leads to a write');
$be->getConfigStatus = 0;

// ---------------------------------------------------------------- K13: device maxima are repaired independently of the entry
echo "== Gerätehöchstzahlen (K13)\n";
eosLoad(['max_home_appliances' => 0, 'home_appliances' => ['dishwasher1' => HA], 'batteries' => ['battery1' => BAT], 'inverters' => INV]);
$a = new EOSAppliance(1002); $a->Create(); connectToRealServer($a); $a->ApplyChanges();
check((int) $be->live['devices']['max_home_appliances'] === 1 && !in_array('devices.home_appliances exceeds configured maximum 0.', $be->runCheck(), true), 'K13: max_home_appliances 0 with a matching entry is raised to 1 on Apply');
eosLoad(['max_electric_vehicles' => 0, 'electric_vehicles' => ['ev1' => EV], 'batteries' => ['battery1' => BAT], 'inverters' => INV]);
$v = vehicle(); $v->ApplyChanges();
check((int) $be->live['devices']['max_electric_vehicles'] === 1, 'K13: max_electric_vehicles 0 with a matching entry is raised to 1 on Apply');

// ---------------------------------------------------------------- S1: never a second battery / vehicle
echo "== Zweites Gerät (S1)\n";
eosLoad(['max_batteries' => 1, 'batteries' => ['speicher' => ['device_id' => 'speicher'] + BAT], 'inverters' => ['inv1' => ['device_id' => 'inv1', 'battery_id' => 'speicher']]]);
$be->live['devices']['batteries']['speicher']['device_id'] = 'speicher';
$b = battery(); $b->ApplyChanges();
check(array_keys($be->live['devices']['batteries']) === ['speicher'] && $b->status === 205, 'S1: EOS already holds battery "speicher": battery1 is not created, status 205 (status ' . $b->status . ')');

// ---------------------------------------------------------------- S3: EOS requires min SoC < max SoC
echo "== SoC-Grenzen (S3)\n";
eosLoad(['max_electric_vehicles' => 1, 'batteries' => ['battery1' => BAT], 'inverters' => INV]);
$v = vehicle(); $v->properties['TargetSoC'] = 100; $v->ApplyChanges();
check(($be->live['devices']['electric_vehicles']['ev1']['min_soc_percentage'] ?? null) === 99 && ($be->live['devices']['electric_vehicles']['ev1']['max_soc_percentage'] ?? null) === 100, 'S3: TargetSoC 100 with MaxSoC 100 is sent as 99 and the vehicle reaches EOS');
eosLoad(['batteries' => ['battery1' => BAT], 'inverters' => INV]);
$b = battery(); $b->properties['MinSoC'] = 95; $b->properties['MaxSoC'] = 95; $b->ApplyChanges();
check($b->status === 206 && eosMerges() === [], 'S3: battery MinSoC >= MaxSoC is a configuration error (status 206), nothing is written');

// ---------------------------------------------------------------- K51b, K44: device ids
echo "== Geräte-IDs (K51b, K44)\n";
$b = battery('0'); $b->ApplyChanges();
check($b->status === 201, 'K51b: DeviceID "0" is invalid (would become a JSON list)');
eosLoad(['batteries' => ['battery1' => BAT], 'inverters' => INV]);
$b = battery(''); $form = json_decode($b->GetConfigurationForm(), true);
$values = array_column(element($form['elements'], 'EOSDevicePick')['options'] ?? [], 'value');
check($values === ['', 'battery1'], 'K44: with an empty DeviceID the picker still lists the batteries of EOS: ' . json_encode($values));

// ---------------------------------------------------------------- K5: EOS_PutMeasurement keeps the SoC in the newest record
echo "== EOS_PutMeasurement (K5)\n";
eosLoad(['batteries' => ['battery1' => BAT], 'inverters' => INV], ['load_emr_keys' => ['load0_emr']]);
$b = battery(); $b->ApplyChanges();
setClock($now + 60);
$server->PutMeasurement('load0_emr', 12345.6, '');
check(!in_array('Fresh SoC missing for battery1', $be->runCheck($now + 60), true), 'K5: a script measurement via EOS_PutMeasurement carries the known battery SoC: ' . json_encode($be->runCheck($now + 60)));
setClock($now);

// ---------------------------------------------------------------- K41: vehicle away with "push only while plugged"
echo "== SoC abgesteckt (K41)\n";
eosLoad(['max_electric_vehicles' => 1, 'electric_vehicles' => ['ev1' => EV], 'batteries' => ['battery1' => BAT], 'inverters' => INV]);
$v = vehicle(); $v->properties['PushOnlyWhenPlugged'] = true; $v->properties['PluggedSourceVariable'] = 31; $v->ApplyChanges();
$GLOBALS['world'][31]['value'] = false; $GLOBALS['world'][30]['value'] = 0.0;
setClock($now + 400); $v->PushSoC();
check(($be->records[$now + 400]['ev1-soc-factor'] ?? null) === 0.4, 'K41: unplugged, the last plugged SoC keeps the vehicle key fresh: ' . json_encode($be->records[$now + 400] ?? null));
setClock($now); $GLOBALS['world'][31]['value'] = true; $GLOBALS['world'][30]['value'] = 40.0;

// ---------------------------------------------------------------- K8: server write keeps the meter keys
echo "== Zählerschlüssel (K8)\n";
eosLoad(['batteries' => ['battery1' => BAT], 'inverters' => INV], ['load_emr_keys' => ['load0_emr', 'house_emr']]);
$server->properties['MeasLoadEmrKeys'] = json_encode([['key' => 'load0_emr']]);
$server->WriteConfigToEOS();
check($be->live['measurement']['load_emr_keys'] === ['load0_emr', 'house_emr'], 'K8: "Write to EOS" keeps the keys the meter registered: ' . json_encode($be->live['measurement']['load_emr_keys']));
eosLoad(['batteries' => ['battery1' => BAT], 'inverters' => INV], ['load_emr_keys' => ['load0_emr']]);
worldVar(50, 2, 1234.5, false);
$mt = new EOSMeter(1003); $mt->Create(); connectToRealServer($mt);
$mt->properties['Meters'] = json_encode([['variable' => 50, 'key' => 'house_emr', 'category' => 'load', 'unit' => 0]]);
$mt->Push();
check(in_array('house_emr', $be->live['measurement']['load_emr_keys'], true) && ($be->records[$now]['house_emr'] ?? null) === 1234.5, 'K8: a 422 "No energy channel" makes the meter register its key and send again');

// ---------------------------------------------------------------- K54, S5: values EOS would reject for the whole write
echo "== Serverkonfiguration (K54, S5)\n";
eosLoad(['batteries' => ['battery1' => BAT], 'inverters' => INV]);
$server->properties['PvPlanes'] = json_encode([['peakpower' => 5.0, 'surface_azimuth' => 180, 'surface_tilt' => 30, 'userhorizon' => '10,,20']]);
$server->properties['EmsStartupDelay'] = 0;
$server->WriteConfigToEOS();
$body = eosMerges()[0][2] ?? [];
check(($body['pvforecast']['planes'][0]['userhorizon'] ?? null) === [10.0, 20.0], 'K54: an empty horizon item is skipped and the horizon stays a list: ' . json_encode($body['pvforecast']['planes'][0]['userhorizon'] ?? null));
check(($body['ems']['startup_delay'] ?? null) === 1, 'S5: startup delay 0 is sent as the EOS minimum 1');
eosLoad(['batteries' => ['battery1' => BAT], 'inverters' => INV]);
$server->properties['PvPlanes'] = json_encode([['peakpower' => 5.0, 'surface_azimuth' => 180, 'surface_tilt' => 30, 'userhorizon' => '10;20']]);
check($server->WriteConfigToEOS() === false && eosMerges() === [], 'K54: a horizon with a non-number is refused before anything is sent');

// ---------------------------------------------------------------- N1, K10, K52a: departure and deadlines
echo "== Fristen (N1, K10, K52a)\n";
worldVar(32, 1, $now + 3600, false);
eosLoad(['max_electric_vehicles' => 1, 'electric_vehicles' => ['ev1' => EV], 'batteries' => ['battery1' => BAT], 'inverters' => INV]);
$v = vehicle(); $v->properties['DepartureSourceVariable'] = 32; $v->ApplyChanges();
$deadline = fn (): ?string => $be->live['devices']['electric_vehicles']['ev1']['min_soc_deadline_datetime'] ?? null;
check($deadline() !== null && (new DateTimeImmutable($deadline()))->getTimestamp() === $now + 3600, 'N1: the departure from the source variable reaches EOS');
check(in_array(['PUT', '/v1/config/file'], $be->calls, true), 'K52a: writing the departure saves the EOS configuration');
check($v->timers['DeadlineExpiry']['ms'] === 3601000, 'N1: expiry timer armed one second after the departure');
setClock($now + 3601); $v->fireTimer('DeadlineExpiry');
check($deadline() === null, 'N1: the passed departure is cleared in EOS (EOS would charge at once for a past deadline)');
setClock($now + 7200); $GLOBALS['world'][32]['value'] = $now + 10800; $v->PushSoC();
check((new DateTimeImmutable((string) $deadline()))->getTimestamp() === $now + 10800, 'N1: a SoC push brings a new departure to EOS');
setClock($now);
eosLoad(['max_electric_vehicles' => 1, 'electric_vehicles' => ['ev1' => ['min_soc_deadline_datetime' => date(DATE_ATOM, $now + 5000)] + EV], 'batteries' => ['battery1' => BAT], 'inverters' => INV]);
$v = vehicle(); $v->properties['DepartureSourceVariable'] = 32; $v->properties['SyncDepartureToEOS'] = false; $GLOBALS['world'][32]['value'] = $now + 3600; $v->ApplyChanges();
check((new DateTimeImmutable((string) $deadline()))->getTimestamp() === $now + 5000, 'K10: with syncing switched off the EOSdash deadline stays (no ApplyChanges write)');
eosLoad(['max_electric_vehicles' => 1, 'electric_vehicles' => ['ev1' => ['min_soc_deadline_datetime' => date(DATE_ATOM, $now - 60)] + EV], 'batteries' => ['battery1' => BAT], 'inverters' => INV]);
$v = vehicle(); $v->ApplyChanges();
check($deadline() !== null && count(array_filter($v->logs, static fn (array $l): bool => str_contains($l[1], 'departure time in the past'))) === 1, 'N1: a past EOSdash deadline without a Symcon source is not touched, but warned about once');
$v->ApplyChanges();
check(count(array_filter($v->logs, static fn (array $l): bool => str_contains($l[1], 'departure time in the past'))) === 1, 'N1: the warning is not repeated');
worldVar(33, 1, $now + 7200, false);
eosLoad(['max_home_appliances' => 1, 'home_appliances' => ['dishwasher1' => HA], 'batteries' => ['battery1' => BAT], 'inverters' => INV]);
$a = new EOSAppliance(1002); $a->Create(); connectToRealServer($a); $a->properties['DeadlineSourceVariable'] = 33; $a->ApplyChanges();
$haDeadline = fn (): ?string => $be->live['devices']['home_appliances']['dishwasher1']['deadline_datetime'] ?? null;
check($haDeadline() !== null && (new DateTimeImmutable($haDeadline()))->getTimestamp() === $now + 7200, 'N1: the appliance deadline from the source reaches EOS');
setClock($now + 7201); $a->fireTimer('TimesExpiry');
check($haDeadline() === null, 'N1: the passed appliance deadline is cleared in EOS');
setClock($now);

// ---------------------------------------------------------------- K11, K9, K14, K12, S4, S1: three-way sync
echo "== Drei-Wege-Abgleich (K9, K11, K12, K14, S4, S1)\n";
$caption = static function (IPSModuleStrict $m): string {
    $last = '';
    foreach ($m->formUpdates as [$field, $param, $value]) {
        if ($field === 'ConfigInfo' && $param === 'caption') {
            $last = (string) $value;
        }
    }
    return $last;
};
eosLoad(['batteries' => ['battery1' => BAT], 'inverters' => INV]);
$b = battery(); $b->ApplyChanges();
$be->putConfig(['devices' => ['batteries' => ['battery1' => ['device_id' => 'battery1', 'max_charge_power_w' => 3000]]]]);
$be->calls = []; $b->ApplyChanges();
check(eosMerges() === [] && (int) $be->live['devices']['batteries']['battery1']['max_charge_power_w'] === 3000, 'K11: an EOSdash edit survives kernel start, reload and ApplyLater (no write)');
$form = json_decode($b->GetConfigurationForm(), true);
check(str_contains((string) element($form['elements'], 'ConfigInfo')['caption'], 'max_charge_power_w') && $b->timers['FormFill']['ms'] === 1500, 'K11: the form offers the value changed in EOS');
$b->formUpdates = []; $b->fireTimer('FormFill');
check(in_array(['MaxChargePowerW', 'value', 3000], $b->formUpdates, true) && !in_array('CapacityWh', array_column($b->formUpdates, 0), true), 'K11: FormFill loads only the field changed in EOS');
$b->properties['MaxChargePowerW'] = 3000; $be->calls = []; $b->ApplyChanges();
check(eosMerges() === [], 'taking over the EOS value writes nothing');
$be->putConfig(['devices' => ['batteries' => ['battery1' => ['device_id' => 'battery1', 'min_soc_percentage' => 20]]]]);
$b->properties['CapacityWh'] = 12000; $be->calls = []; $b->ApplyChanges();
$sent = eosMerges()[0][2]['devices']['batteries']['battery1'] ?? [];
check(array_keys($sent) === ['device_id', 'capacity_wh'] && (int) $be->live['devices']['batteries']['battery1']['min_soc_percentage'] === 20, 'K11: only the field changed in Symcon is written, the EOSdash edit of another field stays: ' . json_encode($sent));
$be->putConfig(['devices' => ['batteries' => ['battery1' => ['device_id' => 'battery1', 'capacity_wh' => 15000]]]]);
$b->properties['CapacityWh'] = 11000; $be->calls = []; $b->ApplyChanges();
check(eosMerges() === [] && (int) $be->live['devices']['batteries']['battery1']['capacity_wh'] === 15000 && str_contains($caption($b), 'conflict capacity_wh'), 'conflict (both changed): nothing written, both values shown');
$b->WriteConfigToEOS();
check((int) $be->live['devices']['batteries']['battery1']['capacity_wh'] === 11000 && (int) $be->live['devices']['batteries']['battery1']['min_soc_percentage'] === 10, '"Overwrite EOS with these values" writes every differing field');

const SPEICHER = ['device_id' => 'speicher', 'capacity_wh' => 13500, 'max_charge_power_w' => 3000, 'min_soc_percentage' => 20, 'max_soc_percentage' => 90, 'charging_efficiency' => 0.97, 'discharging_efficiency' => 0.97, 'levelized_cost_of_storage_amt_kwh' => 0.05];
eosLoad(['batteries' => ['speicher' => SPEICHER], 'inverters' => ['inv1' => ['device_id' => 'inv1', 'battery_id' => 'speicher']]]);
$b = battery();
$b->RequestAction('PickDeviceId', 'speicher');
check(in_array(['CapacityWh', 'value', 13500], $b->formUpdates, true) && in_array(['DeviceID', 'value', 'speicher'], $b->formUpdates, true), 'K9: picking an EOS device loads its id and values into the form');
$b->properties = array_merge($b->properties, ['DeviceID' => 'speicher', 'CapacityWh' => 13500, 'MaxChargePowerW' => 3000, 'MinSoC' => 20, 'MaxSoC' => 90, 'ChargingEfficiency' => 0.97, 'DischargingEfficiency' => 0.97, 'LcosAmtKwh' => 0.05]);
$be->calls = []; $b->ApplyChanges();
check(eosMerges() === [] && $b->status === IS_ACTIVE, 'K9: Apply after the pick writes nothing');
$b2 = battery('speicher'); $be->calls = []; $b2->ApplyChanges();
check(eosMerges() === [] && (int) $be->live['devices']['batteries']['speicher']['capacity_wh'] === 13500, 'K9: a typed id with untouched defaults takes the EOS values instead of overwriting them');
$state = (fn (): array => $this->batteryState('GRID_SUPPORT_EXPORT', 0.5, false))->call($b2);
check($state['dischargeW'] === 1500.0, 'S4: the export setpoint follows the rated power EOS plans with (0.5 x 3000 W), not the Symcon limit: ' . $state['dischargeW']);

eosLoad(['max_home_appliances' => 1, 'home_appliances' => ['dishwasher1' => HA], 'batteries' => ['battery1' => BAT], 'inverters' => INV]);
$a = new EOSAppliance(1002); $a->Create(); connectToRealServer($a);
$a->properties['TimeWindows'] = json_encode([['start_time' => '08:00', 'duration' => '6 hours']]); $a->ApplyChanges();
check(($be->live['devices']['home_appliances']['dishwasher1']['time_windows']['windows'][0]['start_time'] ?? '') === '08:00', 'a new time window is written');
$a->properties['TimeWindows'] = '[]'; $a->ApplyChanges();
check(array_key_exists('time_windows', $be->live['devices']['home_appliances']['dishwasher1']) && $be->live['devices']['home_appliances']['dishwasher1']['time_windows'] === null, 'K12: emptied time windows are cleared in EOS (path PUT null)');
eosLoad(['max_home_appliances' => 1, 'home_appliances' => ['dishwasher1' => HA + ['time_windows' => ['windows' => [['start_time' => '08:00:00.000000', 'duration' => '6 hours', 'day_of_week' => 'mon']]]]], 'batteries' => ['battery1' => BAT], 'inverters' => INV]);
$a = new EOSAppliance(1002); $a->Create(); connectToRealServer($a);
$a->RequestAction('PickDeviceId', 'dishwasher1');
$rows = null;
foreach ($a->formUpdates as [$field, $param, $value]) {
    if ($field === 'TimeWindows' && $param === 'values') {
        $rows = json_decode((string) $value, true);
    }
}
check(($rows[0]['day_of_week'] ?? null) === 'mon', 'K14: loading keeps the EOSdash weekday of a time window');
$a->properties['TimeWindows'] = json_encode($rows); $be->calls = []; $a->ApplyChanges();
check(eosMerges() === [] && ($be->live['devices']['home_appliances']['dishwasher1']['time_windows']['windows'][0]['day_of_week'] ?? null) === 'mon', 'K14: the weekday survives Apply (no write, not dropped)');

eosLoad(['max_batteries' => 1, 'batteries' => ['battery1' => BAT], 'inverters' => INV]);
$b = battery(); $b->ApplyChanges();
$b->properties['DeviceID'] = 'speicher'; $b->formUpdates = []; $b->ApplyChanges();
check($b->status === 205 && str_contains($caption($b), 'old device battery1'), 'S1: after a rename the old entry blocks a second battery and the form points to the remove button');
$form = json_decode($b->GetConfigurationForm(), true);
check((element($form['elements'], 'RemoveOldEntry')['visible'] ?? false) === true, 'S1: the button "Remove old EOS entry" is shown');
$b->RequestAction('RemoveOldEOSEntry', '');
$be->putConfig(['general' => ['latitude' => 51.0]]);
check(!isset($be->live['devices']['batteries']['battery1']), 'S1: the old entry is gone for good (map PUT, save, reset), also after a later merge');
$b->ApplyChanges();
check($b->status === IS_ACTIVE && isset($be->live['devices']['batteries']['speicher']) && ($be->live['devices']['inverters']['inv1']['battery_id'] ?? null) === 'speicher' && $be->runCheck() === [] , 'S1: Apply creates the renamed battery and the inverter points at it: ' . json_encode($be->runCheck()));

echo "\nAlle {$GLOBALS['checks']} Prüfungen bestanden.\n";
