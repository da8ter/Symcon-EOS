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

function eosLoad(array $devices): void
{
    $GLOBALS['eosBackend']->load(['devices' => $devices + ['batteries' => [], 'electric_vehicles' => [], 'inverters' => [], 'home_appliances' => []], 'measurement' => []]);
    $GLOBALS['eosBackend']->calls = [];
}
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
unset($GLOBALS['objects'][1100], $GLOBALS['instances'][1100]);
$c = batteryAt(1200, 'speicher1'); $c->ApplyChanges();
check(!in_array($c->status, [203], true), 'R6-8: an owner that was deleted frees its id: ' . $c->status);
unset($GLOBALS['objects'][1050], $GLOBALS['instances'][1050], $GLOBALS['objects'][1200], $GLOBALS['instances'][1200]);
$GLOBALS['registry'] = false;

setClock(null);
echo "\nAlle {$GLOBALS['checks']} Prüfungen bestanden.\n";
