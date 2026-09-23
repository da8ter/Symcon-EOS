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
const EV = ['device_id' => 'ev1', 'capacity_wh' => 60000, 'max_charge_power_w' => 11000, 'min_soc_percentage' => 80, 'max_soc_percentage' => 100, 'charging_efficiency' => 0.9, 'charge_rates' => [0.0, 0.25, 0.5, 0.75, 1.0]];
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
function formElement(array $nodes, string $name): ?array
{
    foreach ($nodes as $n) {
        if (!is_array($n)) {
            continue;
        }
        if (($n['name'] ?? '') === $name) {
            return $n;
        }
        foreach (['items', 'elements', 'actions'] as $k) {
            if (isset($n[$k]) && is_array($n[$k]) && ($found = formElement($n[$k], $name)) !== null) {
                return $found;
            }
        }
    }
    return null;
}
function eosMerges(): array
{
    return array_values(array_filter($GLOBALS['eosBackend']->calls, static fn (array $c): bool => $c[0] === 'PUT' && str_starts_with($c[1], '/v1/config') && $c[1] !== '/v1/config/file'));
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
$be->down = 'connect'; $b->ApplyChanges();                    // the sync meets an unreachable EOS
$down = realServer()->status; $b->ApplyChanges();              // the next claim is answered by the server itself
check($down === 201 && realServer()->status === 201, 'regression: a claim does not count as "EOS reachable" (server stays 201): ' . $down . ' -> ' . realServer()->status);
$be->down = ''; realServer()->PollHealth();
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

// ---------------------------------------------------------------- R8-2, R8-9: time windows compared in full, durations in any notation
echo "== Zeitfenster vollständig, Dauern normalisiert\n";

$win = static fn (string $start, string $duration, array $extra = []): array => ['windows' => [['start_time' => $start, 'duration' => $duration] + $extra]];
eosLoad(['home_appliances' => ['dishwasher1' => HA + ['time_windows' => $win('08:00', '2 hours', ['day_of_week' => 1])]], 'max_home_appliances' => 1]);
$w = applianceAt(1310, ['TimeWindows' => json_encode([['start_time' => '08:00', 'duration' => '2 hours']])]);
$w->ApplyChanges(); // first sync after the update: EOSdash restricted the window to Mondays
$w->properties['TimeWindows'] = json_encode([['start_time' => '08:00', 'duration' => '3 hours']]); $w->ApplyChanges();
check((eosField('devices/home_appliances/dishwasher1/time_windows')['windows'][0]['day_of_week'] ?? null) === 1,
    'R8-2: a window EOSdash restricted to a weekday is not replaced by a Symcon edit that lacks it (conflict, nothing written): ' . json_encode(eosField('devices/home_appliances/dishwasher1/time_windows')));
drop(1310);

eosLoad(['home_appliances' => ['dishwasher1' => HA], 'max_home_appliances' => 1]);
$w = applianceAt(1311, ['TimeWindows' => json_encode([['start_time' => '08:00', 'duration' => '90 minutes']])]);
$w->ApplyChanges();
$stored = eosField('devices/home_appliances/dishwasher1/time_windows')['windows'][0]['duration'] ?? null;
$w->properties['TimeWindows'] = json_encode([['start_time' => '09:00', 'duration' => '90 minutes']]); $be->calls = []; $w->ApplyChanges();
check($stored === '1 hour 30 minutes' && (eosField('devices/home_appliances/dishwasher1/time_windows')['windows'][0]['start_time'] ?? null) === '09:00:00.000000',
    'R8-9: "90 minutes" and EOS\'s "1 hour 30 minutes" are the same duration: a later window edit is written, not a conflict: ' . json_encode(eosField('devices/home_appliances/dishwasher1/time_windows')));
drop(1311);


// ---------------------------------------------------------------- R8-3: the SoC pair keeps the limit EOS owns
echo "== SoC-Paar\n";
eosLoad(['batteries' => [], 'inverters' => INV, 'max_batteries' => 1]);
$p = batteryAt(1320); $p->ApplyChanges(); // creates battery1 with min 10 / max 95
$be->putConfigPath('devices/batteries/battery1/min_soc_percentage', 20); // EOSdash
$p->properties['MaxSoC'] = 90; $p->ApplyChanges();
check((int) eosField('devices/batteries/battery1/min_soc_percentage') === 20 && (int) eosField('devices/batteries/battery1/max_soc_percentage') === 90,
    'R8-3: writing the max SoC sends the min SoC EOS holds (EOSdash 20), not the Symcon 10: min ' . json_encode(eosField('devices/batteries/battery1/min_soc_percentage')) . ' max ' . json_encode(eosField('devices/batteries/battery1/max_soc_percentage')));
drop(1320);

// ---------------------------------------------------------------- R8-4, R8-5: without a known base nothing is overwritten; conflicts stay open
echo "== Basis ohne Abgleich, Auswahl nur im Formular\n";
eosLoad(['electric_vehicles' => ['ev1' => EV], 'max_electric_vehicles' => 1]);
worldVar(30, 1, 40, false);
$ev = new EOSVehicle(1330); $ev->Create(); connectToRealServer($ev);
$ev->properties = array_merge($ev->properties, ['SoCSourceVariable' => 30, 'DeviceID' => 'ev1', 'CapacityWh' => 60000, 'MaxChargePowerW' => 11000, 'TargetSoC' => 90, 'MaxSoC' => 100]);
$ev->ApplyChanges(); // the automatic Apply after the update: no SyncedConfig yet
check((int) eosField('devices/electric_vehicles/ev1/min_soc_percentage') === 80, 'R8-5: the first sync after the update does not overwrite the EOSdash target 80 with Symcon 90: ' . json_encode(eosField('devices/electric_vehicles/ev1/min_soc_percentage')));
drop(1330);

eosLoad(['home_appliances' => ['dishwasher1' => HA, 'dryer1' => ['device_id' => 'dryer1', 'consumption_wh' => 4000, 'duration_h' => 2, 'num_cycles' => 1, 'min_cycle_gap_h' => 0, 'schedule_mode' => 'DAILY', 'deadline_policy' => 'BEST_EFFORT']], 'max_home_appliances' => 2]);
$pk = applianceAt(1331); $pk->ApplyChanges();
$pk->RequestAction('PickDeviceId', 'dryer1');   // just looking
$pk->GetConfigurationForm();                   // form closed without Apply, opened again later
$pk->properties['DeviceID'] = 'dryer1'; $pk->ApplyChanges(); // id typed and applied, fields still those of dishwasher1
check((int) eosField('devices/home_appliances/dryer1/consumption_wh') === 4000 && eosField('devices/home_appliances/dryer1/schedule_mode') === 'DAILY',
    'R8-4: an old look at a device does not turn the instance values into "Symcon changes" later: ' . json_encode(eosField('devices/home_appliances/dryer1/consumption_wh')));
drop(1331);

eosLoad(['home_appliances' => ['dishwasher1' => HA], 'max_home_appliances' => 1]);
$cf = applianceAt(1332); $cf->ApplyChanges();
$be->putConfigPath('devices/home_appliances/dishwasher1/consumption_wh', 2500); // EOSdash
$be->putConfigPath('devices/home_appliances/dishwasher1/duration_h', 4);          // EOSdash only
$cf->properties['ConsumptionWh'] = 3000; $cf->ApplyChanges();                     // Symcon too: conflict
$cf->formUpdates = [];
$cf->RequestAction('FillFormFromEOS', '');
$filled = array_column(array_filter($cf->formUpdates, static fn (array $u): bool => $u[1] === 'value'), 2, 0);
check(!array_key_exists('ConsumptionWh', $filled) && ($filled['DurationH'] ?? null) === 4, 'R8 (README, conflict row): FormFill loads what only EOS changed, a conflict keeps the Symcon value in the field: ' . json_encode($filled));
drop(1332);

// ---------------------------------------------------------------- R8-6: the control math follows the power EOS holds
echo "== Leistung aus EOS aktuell\n";
eosLoad(['batteries' => ['battery1' => BAT], 'inverters' => INV, 'max_batteries' => 1]);
$ev6 = batteryAt(1340); $ev6->ApplyChanges();
$ev6->properties['MaxChargePowerW'] = 3000; $ev6->ApplyChanges(); // written to EOS
$power = (fn (): array => $this->batteryState('FORCED_CHARGE', 1.0, false))->call($ev6)['chargeW'];
check($power === 3000.0, 'R8-6: after writing max_charge_power_w the control computes with the new 3000 W at once, not the old 5000 W: ' . $power);
$be->putConfigPath('devices/batteries/battery1/max_charge_power_w', 2500); // EOSdash
setClock($now + 1000); $ev6->PushSoC();
$power = (fn (): array => $this->batteryState('FORCED_CHARGE', 1.0, false))->call($ev6)['chargeW'];
check($power === 2500.0, 'R8-6: an EOSdash change of max_charge_power_w reaches the control with the next SoC push after 15 min: ' . $power);
setClock($now);
drop(1340);

// ---------------------------------------------------------------- R8-8: the old entry after a rename stays known until it is removed
echo "== Alter Eintrag nach Umbenennen\n";
eosLoad(['home_appliances' => ['dishwasher1' => HA], 'max_home_appliances' => 1]);
$rn = applianceAt(1350); $rn->ApplyChanges();
$rn->properties['DeviceID'] = 'dryer1'; $rn->ApplyChanges(); // renamed: dryer1 created, dishwasher1 still in EOS
$rn->ApplyChanges();                                          // any later Apply must not forget the old id
$form = json_decode($rn->GetConfigurationForm(), true);
$button = formElement(array_merge($form['elements'] ?? [], $form['actions'] ?? []), 'RemoveOldEntry');
check(($button['visible'] ?? false) === true && isset($be->live['devices']['home_appliances']['dishwasher1']), 'R8-8: after renaming an appliance "Remove old EOS entry" is offered while the old entry exists: ' . json_encode($button['visible'] ?? null));
$rn->RequestAction('RemoveOldEOSEntry', '');
$form = json_decode($rn->GetConfigurationForm(), true);
$button = formElement(array_merge($form['elements'] ?? [], $form['actions'] ?? []), 'RemoveOldEntry');
check(!isset($be->live['devices']['home_appliances']['dishwasher1']) && isset($be->live['devices']['home_appliances']['dryer1']) && ($button['visible'] ?? true) === false,
    'R8-8: ... the button removes it, the new entry stays, and the button is gone');
drop(1350);

// ---------------------------------------------------------------- R8-10: error paths never create, relink or forget
echo "== Fehlerpfade\n";
eosLoad(['batteries' => ['battery1' => BAT], 'inverters' => INV, 'max_batteries' => 1]);
$be->failPaths = ['devices/batteries'];
$e1 = batteryAt(1360, 'speicher1'); $e1->ApplyChanges();
$be->failPaths = [];
check(array_keys($be->live['devices']['batteries']) === ['battery1'] && ($be->live['devices']['inverters']['inv1']['battery_id'] ?? null) === 'battery1',
    'R8-10: a failed read of devices/batteries creates no second battery and does not relink the inverter: ' . json_encode(array_keys($be->live['devices']['batteries'])));
drop(1360);

eosLoad(['batteries' => ['battery1' => BAT], 'inverters' => INV, 'max_batteries' => 1]);
$e2 = batteryAt(1361); $e2->ApplyChanges();
$e2->properties['DeviceID'] = 'speicher1'; $e2->ApplyChanges(); // 205: EOS still has battery1
$be->saveFails = true; $e2->RequestAction('RemoveOldEOSEntry', ''); $be->saveFails = false;
$e2->ApplyChanges();
check(array_keys($be->live['devices']['batteries']) === ['battery1'] && $e2->status === 205,
    'R8-10: a removal that fails after its map PUT is rolled back; the next Apply does not end with two batteries: ' . json_encode(array_keys($be->live['devices']['batteries'])) . ' status ' . $e2->status);
drop(1361);

eosLoad(['batteries' => ['battery1' => BAT], 'inverters' => INV, 'max_batteries' => 1]);
$e3 = batteryAt(1362); $e3->ApplyChanges();
$e3->properties['CapacityWh'] = 12000; $be->saveFails = true; $e3->ApplyChanges(); $be->saveFails = false; // written, not saved
$be->live = $be->file; $be->runtime = [];                                                               // EOS restart
$e3->ApplyChanges();
check((int) eosField('devices/batteries/battery1/capacity_wh') === 12000, 'R8-10: a write whose save failed is written again after an EOS restart: ' . json_encode(eosField('devices/batteries/battery1/capacity_wh')));
drop(1362);

// ---------------------------------------------------------------- R8-11: the buttons act on what the form shows
echo "== Knöpfe nach dem Formular\n";
eosLoad(['home_appliances' => ['dishwasher1' => HA, 'dryer1' => ['device_id' => 'dryer1', 'consumption_wh' => 4000, 'duration_h' => 2, 'num_cycles' => 1, 'min_cycle_gap_h' => 0, 'schedule_mode' => 'DAILY', 'deadline_policy' => 'BEST_EFFORT']], 'max_home_appliances' => 2]);
$bt = applianceAt(1370); $bt->ApplyChanges();
$bt->formUpdates = [];
$bt->RequestAction('LoadFromEOS', 'dryer1'); // the form shows dryer1 (picked or typed), not yet applied
check(in_array(['ConsumptionWh', 'value', 4000], $bt->formUpdates, true), 'R8-11: "Load values from EOS" loads the device the form shows (dryer1), not the saved one: ' . json_encode(array_slice($bt->formUpdates, 0, 3)));
$be->calls = [];
$bt->RequestAction('OverwriteEOS', 'dryer1');
check(eosMerges() === [] && str_contains((string) json_encode($bt->formUpdates), 'Apply'), 'R8-11: "Overwrite EOS" refuses while the form shows another device id than the saved one');
drop(1370);

// ---------------------------------------------------------------- regression check: an unsaved write never becomes the base
echo "== Regression: ungespeicherter Schreibvorgang\n";
eosLoad(['batteries' => ['battery1' => BAT], 'inverters' => INV, 'max_batteries' => 1]);
$us = batteryAt(1380); $us->ApplyChanges();
$us->properties['CapacityWh'] = 12000; $be->saveFails = true; $us->ApplyChanges(); // written, not saved
$us->ApplyChanges();                                                               // e.g. a kernel start, save still failing
$be->saveFails = false; $be->live = $be->file; $be->runtime = [];                   // EOS restart: 10000 again
$us->ApplyChanges();
check((int) eosField('devices/batteries/battery1/capacity_wh') === 12000, 'regression: an unsaved value stays pending through later Applies and is written again after an EOS restart: ' . json_encode(eosField('devices/batteries/battery1/capacity_wh')));
drop(1380);
eosLoad(['batteries' => ['battery1' => BAT], 'inverters' => INV, 'max_batteries' => 1]);
$us = batteryAt(1381); $us->ApplyChanges();
$us->properties['CapacityWh'] = 12000; $be->saveFails = true; $us->ApplyChanges(); $be->saveFails = false;
$us->ApplyChanges();                                                               // the save is retried and works
check((int) ($be->file['devices']['batteries']['battery1']['capacity_wh'] ?? 0) === 12000, 'regression: ... and the next Apply retries the save: ' . json_encode($be->file['devices']['batteries']['battery1']['capacity_wh'] ?? null));
drop(1381);

// ---------------------------------------------------------------- regression check: "Remove old EOS entry" never deletes another instance's device
echo "== Regression: alter Eintrag gehört inzwischen einer anderen Instanz\n";
$GLOBALS['registry'] = true;
eosLoad(['home_appliances' => ['dishwasher1' => HA], 'max_home_appliances' => 1]);
$x = applianceAt(1400); $x->ApplyChanges();
$x->properties['DeviceID'] = 'dryer1'; $x->ApplyChanges();   // X renamed, dishwasher1 still in EOS
$y = applianceAt(1401); $y->ApplyChanges();                  // Y with the default id adopts dishwasher1
$x->RequestAction('RemoveOldEOSEntry', '');
$form = json_decode($x->GetConfigurationForm(), true);
$button = formElement(array_merge($form['elements'] ?? [], $form['actions'] ?? []), 'RemoveOldEntry');
check($y->status === IS_ACTIVE && isset($be->live['devices']['home_appliances']['dishwasher1']) && ($button['visible'] ?? true) === false,
    'regression: the old entry now owned by another instance is neither removed nor offered for removal: ' . json_encode(array_keys($be->live['devices']['home_appliances'])));
drop(1400, 1401);
$GLOBALS['registry'] = false;

setClock(null);
echo "\nAlle {$GLOBALS['checks']} Prüfungen bestanden.\n";
