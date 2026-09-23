<?php

declare(strict_types=1);

/*
 * Device ownership and configuration data, findings of the final review of 23.09.2026
 * (R6-8 for the id owner kept by the EOS Server, R8-1 … R8-12 in the check labels), over
 * the real EOSServer and the EOS model. Complements config_test.php.
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fake_eos_client.php';
require_once __DIR__ . '/../EOSServer/module.php';
require_once __DIR__ . '/../EOSBattery/module.php';
require_once __DIR__ . '/../EOSVehicle/module.php';
require_once __DIR__ . '/../EOSAppliance/module.php';

$be = $GLOBALS['eosBackend'];
$now = 1_800_000_000;
setClock($now);
worldVar(10, 2, 55.0, false);
const BAT = ['device_id' => 'battery1', 'capacity_wh' => 10000, 'max_charge_power_w' => 5000, 'min_soc_percentage' => 10, 'max_soc_percentage' => 95, 'charging_efficiency' => 0.95, 'discharging_efficiency' => 0.95, 'levelized_cost_of_storage_amt_kwh' => 0.0];
const INV = ['inv1' => ['device_id' => 'inv1', 'max_power_w' => 10000, 'battery_id' => 'battery1']];
const HA = ['device_id' => 'dishwasher1', 'consumption_wh' => 2000, 'duration_h' => 3, 'num_cycles' => 1, 'min_cycle_gap_h' => 0, 'schedule_mode' => 'ONCE', 'deadline_policy' => 'BEST_EFFORT'];

function eosLoad(array $devices): void
{
    $GLOBALS['eosBackend']->load(['devices' => $devices + ['batteries' => [], 'electric_vehicles' => [], 'inverters' => [], 'home_appliances' => []], 'measurement' => []]);
    $GLOBALS['eosBackend']->calls = [];
}
function applianceAt(int $iid, array $props = []): EOSAppliance
{
    $a = new EOSAppliance($iid); $a->Create(); connectToRealServer($a);
    $a->properties = array_merge($a->properties, ['DeviceID' => 'dishwasher1', 'ConsumptionWh' => 2000, 'DurationH' => 3], $props);
    return $a;
}
/** Delete instances the way Symcon does: object and instance entry gone. */
function drop(int ...$ids): void
{
    foreach ($ids as $id) {
        unset($GLOBALS['objects'][$id], $GLOBALS['instances'][$id]);
    }
}
function eosField(string $path): mixed { return $GLOBALS['eosBackend']->getConfigPath($path)['data'] ?? null; }
function batteryAt(int $iid, string $id = 'battery1'): EOSBattery
{
    $b = new EOSBattery($iid); $b->Create(); connectToRealServer($b);
    $b->properties = array_merge($b->properties, ['SoCSourceVariable' => 10, 'DeviceID' => $id, 'CapacityWh' => 10000, 'MaxChargePowerW' => 5000, 'MinSoC' => 10, 'MaxSoC' => 95]);
    return $b;
}

// ---------------------------------------------------------------- R6-8: the EOS Server keeps one owner per device id
echo "== Geräte-ID-Hoheit im EOS Server\n";
$GLOBALS['registry'] = true;
eosLoad(['batteries' => ['battery1' => BAT], 'inverters' => INV, 'max_batteries' => 1]);
$a = batteryAt(1100); $a->ApplyChanges();
$b = batteryAt(1050); $b->ApplyChanges(); // added later, lower InstanceID, default id
check($a->status === IS_ACTIVE && $b->status === 203, 'R6-8: the server gives the id to the first claimant: first ' . $a->status . ', later ' . $b->status);
$a->ApplyChanges(); $b->ApplyChanges();
check($a->status === IS_ACTIVE && $b->status === 203, 'R6-8: ... across restarts (stored in DeviceOwners)');
$a->properties['DeviceID'] = 'speicher1'; $a->ApplyChanges(); $b->ApplyChanges();
check($b->status === IS_ACTIVE && (json_decode(realServer()->attributes['DeviceOwners'], true)['battery1'] ?? 0) === 1050, 'R6-8: an owner that changed its id gives the old one up: ' . realServer()->attributes['DeviceOwners']);
drop(1100);
$c = batteryAt(1200, 'speicher1'); $c->ApplyChanges();
check(!in_array($c->status, [203], true), 'R6-8: an owner that was deleted frees its id: ' . $c->status);
drop(1050, 1200);
$GLOBALS['registry'] = false;

// ---------------------------------------------------------------- R8-1, R8-12: deadline and earliest start are owned one by one
echo "== Frist und frühester Start einzeln\n";
$iso = static fn (int $ts): string => date(DATE_ATOM, $ts);
eosLoad(['home_appliances' => ['dishwasher1' => HA + ['earliest_start_datetime' => $iso($now + 3600)]], 'max_home_appliances' => 1]);
worldVar(50, 1, $now + 7200, false);
$h = applianceAt(1300, ['DeadlineSourceVariable' => 50]); $h->ApplyChanges();
check(eosField('devices/home_appliances/dishwasher1/earliest_start_datetime') !== null && strtotime((string) eosField('devices/home_appliances/dishwasher1/deadline_datetime')) === $now + 7200,
    'R8-1: a deadline source owns the deadline only; the earliest start from EOSdash stays: ' . json_encode(eosField('devices/home_appliances/dishwasher1/earliest_start_datetime')));
drop(1300);

eosLoad(['home_appliances' => ['dishwasher1' => HA + ['earliest_start_datetime' => $iso($now + 3600)]], 'max_home_appliances' => 1]);
$h = applianceAt(1301); $h->ApplyChanges(); $h->SetDeadline($now + 9000);
check(eosField('devices/home_appliances/dishwasher1/earliest_start_datetime') !== null && strtotime((string) eosField('devices/home_appliances/dishwasher1/deadline_datetime')) === $now + 9000,
    'R8-1: EOSHA_SetDeadline owns the deadline only; the earliest start from EOSdash stays');
drop(1301);

eosLoad(['home_appliances' => ['dishwasher1' => HA + ['deadline_datetime' => $iso($now + 7200)]], 'max_home_appliances' => 1]);
worldVar(51, 1, $now + 1800, false);
$h = applianceAt(1302, ['EarliestStartSourceVariable' => 51]); $h->ApplyChanges();
check(strtotime((string) eosField('devices/home_appliances/dishwasher1/deadline_datetime')) === $now + 7200 && strtotime((string) eosField('devices/home_appliances/dishwasher1/earliest_start_datetime')) === $now + 1800,
    'R8-1: an earliest-start source owns the earliest start only; the deadline from EOSdash stays');
drop(1302);

eosLoad(['home_appliances' => ['dishwasher1' => HA + ['earliest_start_datetime' => $iso($now + 1800), 'deadline_datetime' => $iso($now + 7200)]], 'max_home_appliances' => 1]);
worldVar(50, 1, $now + 18000, false); worldVar(51, 1, $now + 10800, false); // the window moves past the current deadline
$h = applianceAt(1303, ['DeadlineSourceVariable' => 50, 'EarliestStartSourceVariable' => 51]); $h->ApplyChanges();
check(strtotime((string) eosField('devices/home_appliances/dishwasher1/earliest_start_datetime')) === $now + 10800 && strtotime((string) eosField('devices/home_appliances/dishwasher1/deadline_datetime')) === $now + 18000
    && array_filter($h->logs, static fn (array $l): bool => str_contains($l[1], 'update failed')) === [], 'R8-12: moving the window past the old deadline writes the deadline first (EOS checks deadline > earliest)');
drop(1303);

setClock(null);
echo "\nAlle {$GLOBALS['checks']} Prüfungen bestanden.\n";
