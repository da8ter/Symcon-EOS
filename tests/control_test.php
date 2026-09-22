<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../EOSBattery/module.php';
require_once __DIR__ . '/../EOSVehicle/module.php';
require_once __DIR__ . '/../EOSAppliance/module.php';

// World: SoC source 10, targets 20 (string mode), 21 (int charge W), 22 (float discharge W), 23 (bool discharge allowed), 24 (bool grid).
worldVar(10, 2, 55.0, false);
worldVar(20, 3, '', true);
worldVar(21, 1, 0, true);
worldVar(22, 2, 0.0, true);
worldVar(23, 0, false, true);
worldVar(24, 0, false, true);
$GLOBALS['scripts'] = [900];
$eos = $GLOBALS['eos'];
$eos->config = ['devices' => ['batteries' => ['battery1' => ['device_id' => 'battery1', 'capacity_wh' => 10000, 'max_charge_power_w' => 5000, 'min_soc_percentage' => 10, 'max_soc_percentage' => 95, 'charging_efficiency' => 0.95, 'discharging_efficiency' => 0.95, 'levelized_cost_of_storage_amt_kwh' => 0.0]]]];

function battery(int $controlMode): EOSBattery
{
    $m = new EOSBattery(1000);
    $m->Create();
    connectToServer($m);
    $m->properties['SoCSourceVariable'] = 10;
    $m->properties['ControlMode'] = $controlMode;
    $m->properties['TargetModeVariable'] = 20;
    $m->properties['TargetChargePowerVariable'] = 21;
    $m->properties['TargetDischargePowerVariable'] = 22;
    $m->properties['TargetDischargeAllowedVariable'] = 23;
    $m->properties['ModeMap'] = json_encode([['mode' => 'IDLE', 'value' => 'off'], ['mode' => 'SELF_CONSUMPTION', 'value' => 'pv'], ['mode' => 'NON_EXPORT', 'value' => 'pvonly'], ['mode' => 'GRID_SUPPORT_IMPORT', 'value' => 'now'], ['mode' => 'FORCED_CHARGE', 'value' => 'now']]);
    $m->properties['ControlScript'] = 900;
    return $m;
}
function lastSent(IPSModuleStrict $m): array { return json_decode($m->attributes['LastSent'], true) ?: []; }

$now = time();

// ---------------------------------------------------------------- T1 display only: never writes, also not on master switch off
echo "== Nur anzeigen\n";
$eos->instructions = []; $eos->instruction('battery1', $now - 600, 'NON_EXPORT'); $eos->freshPlan($now - 60);
$m = battery(0); $m->ApplyChanges(); $m->fireOnce();
check($GLOBALS['actions'] === [], 'display mode writes nothing on apply');
check($m->value('ControlActive') === true && $m->value('ManualMode') === 100, 'control active and automatic by default');
$m->RequestAction('ControlActive', false); $m->fireOnce();
check($GLOBALS['actions'] === [] && $GLOBALS['runScripts'] === [], 'master switch off in display mode writes no fallback (finding 1)');
check($m->timers['Watchdog']['ms'] === 0, 'no watchdog in display mode');

// ---------------------------------------------------------------- T2 simulation
echo "== Simulation\n";
resetWorld();
$m = battery(1); $m->ApplyChanges(); check($m->fireOnce() === 1, 'plan schedules exactly one dispatch');
check($GLOBALS['actions'] === [] && $GLOBALS['runScripts'] === [], 'simulation executes nothing');
check(str_starts_with((string) $m->value('LastControlResult'), 'SIM [plan]'), 'simulation result text: ' . $m->value('LastControlResult'));
check(($m->value('ModeRaw')) === 'NON_EXPORT' && $m->value('DischargeAllowed') === false, 'display variables follow the plan');

// ---------------------------------------------------------------- T3 active: one write per target, dedupe on re-broadcast
echo "== Aktiv\n";
resetWorld();
$m = battery(2); $m->ApplyChanges(); $m->fireOnce();
check(writesTo(20) === ['pvonly'] && writesTo(21) === [0] && writesTo(22) === [0.0] && writesTo(23) === [false], 'NON_EXPORT written once per bound target: ' . json_encode($GLOBALS['actions']));
check(count($GLOBALS['runScripts']) === 1 && $GLOBALS['runScripts'][0][1]['ModeRaw'] === 'NON_EXPORT' && $GLOBALS['runScripts'][0][1]['Reason'] === 'plan', 'script runs once with context');
$before = count($GLOBALS['actions']);
$m->ReceiveData(json_encode(['DataID' => '{EAB78E68-BFF7-4608-BA75-9ECDB5165208}', 'Event' => 'PlanUpdated', 'Plan' => $eos->plan, 'Instructions' => $eos->instructions, 'Force' => true]));
$m->fireOnce(); $m->ProcessPlan(); $m->fireOnce(); $m->Watchdog(); $m->fireOnce();
check(count($GLOBALS['actions']) === $before && count($GLOBALS['runScripts']) === 1, 'unchanged plan re-broadcast, ProcessPlan and watchdog write nothing');
check($m->value('FallbackActive') === false, 'fresh plan: no fallback');

// slot change: GRID_SUPPORT_IMPORT 0.5 -> now/2500/0/false
$eos->instruction('battery1', $now - 10, 'GRID_SUPPORT_IMPORT', 0.5); $m->RefreshPlan(); $m->fireOnce();
check(writesTo(20) === ['pvonly', 'now'] && writesTo(21) === [0, 2500], 'slot change writes only changed targets (mode, charge power)');
check($m->value('GridChargeActive') === true, 'grid charging displayed');

// ---------------------------------------------------------------- T4 failed write: backoff, new value at once (finding 3)
echo "== Fehlgeschlagener Schreibvorgang\n";
resetWorld();
$GLOBALS['world'][21]['fail'] = true;
$eos->instructions = []; $eos->instruction('battery1', $now - 5, 'FORCED_CHARGE', 1.0); $m->RefreshPlan(); $m->fireOnce();
$ls = lastSent($m);
check(($ls['targets']['ChargePowerW']['fail'] ?? 0) === 1 && $ls['targets']['ChargePowerW']['fv'] === '5000', 'failed value remembered with fail=1: ' . json_encode($ls['targets']['ChargePowerW']));
check(str_contains((string) $m->value('LastControlResult'), 'failed'), 'failure visible in result text');
$logsBefore = count($m->logs);
$m->Dispatch(); $m->Dispatch(); $m->ApplyControl(false);
check((lastSent($m)['targets']['ChargePowerW']['fail'] ?? 0) === 1 && count($m->logs) === $logsBefore, 'same failed value is not retried before the backoff and not re-logged');
$eos->instructions = []; $eos->instruction('battery1', $now - 5, 'GRID_SUPPORT_IMPORT', 0.2); $m->RefreshPlan(); $m->fireOnce();
check((lastSent($m)['targets']['ChargePowerW']['fail'] ?? 0) === 2 && lastSent($m)['targets']['ChargePowerW']['fv'] === '1000', 'a different value is attempted at once and counted');
$GLOBALS['world'][21]['fail'] = false;
$eos->instructions = []; $eos->instruction('battery1', $now - 5, 'GRID_SUPPORT_IMPORT', 0.4); $m->RefreshPlan(); $m->fireOnce();
check(lastSent($m)['targets']['ChargePowerW']['fail'] === 0 && end($GLOBALS['actions'])[1] === 2000, 'success clears the failure state');

// ---------------------------------------------------------------- T5 mode action failure stays pending (finding 2)
echo "== Modus-Aktion\n";
resetWorld();
$m->properties['ModeAction_SELF_CONSUMPTION'] = json_encode(['actionID' => '{FAIL}', 'parameters' => ['TARGET' => 20]]);
$m->properties['ModeAction_IDLE'] = json_encode(['actionID' => '{OK}', 'parameters' => ['TARGET' => 20, 'VALUE' => 'x']]);
$m->ApplyChanges(); $m->fireOnce(); resetWorld();
$eos->instructions = []; $eos->instruction('battery1', $now - 5, 'SELF_CONSUMPTION'); $m->RefreshPlan(); $m->fireOnce();
$ls = lastSent($m);
check($ls['modeRaw'] === 'SELF_CONSUMPTION' && ($ls['row']['modeRaw'] ?? null) !== 'SELF_CONSUMPTION' && $ls['row']['fail'] === 1, 'failed mode action is not marked as done: ' . json_encode($ls['row']));
$m->Dispatch();
check(lastSent($m)['row']['fail'] === 1, 'failed mode action waits for the backoff');
$eos->instructions = []; $eos->instruction('battery1', $now - 5, 'IDLE'); $m->RefreshPlan(); $m->fireOnce();
check(lastSent($m)['row'] === ['modeRaw' => 'IDLE', 'fail' => 0, 'failTs' => 0] && $GLOBALS['runActions'][0][0] === '{OK}' && $GLOBALS['runActions'][0][1]['VALUE'] === 'x' && $GLOBALS['runActions'][0][1]['ModeRaw'] === 'IDLE', 'successful mode action marked, user parameters win over context');
$c = count($GLOBALS['runActions']); $m->Dispatch(); $m->Watchdog(); $m->fireOnce();
check(count($GLOBALS['runActions']) === $c, 'mode action fires once per mode');
$m->properties['ModeAction_SELF_CONSUMPTION'] = ''; $m->properties['ModeAction_IDLE'] = '';

// ---------------------------------------------------------------- T6 fallback: stale plan in, fresh plan out; gap tolerance
echo "== Fallback\n";
$m->ApplyChanges(); $m->fireOnce(); resetWorld();
$eos->freshPlan($now - 4 * 3600); $m->RefreshPlan(); $m->fireOnce();
check($m->value('FallbackActive') === true && writesTo(20) === ['pv'] && writesTo(22) === [5000.0] && writesTo(23) === [true], 'stale plan -> fallback SELF_CONSUMPTION written once (mode, discharge power, discharge allowed)');
check(count(array_filter($m->logs, static fn ($l) => str_contains($l[1], 'Fallback activated'))) === 1, 'fallback logged once');
$m->Watchdog(); $m->fireOnce();
check(count($GLOBALS['actions']) === 3, 'watchdog in fallback writes nothing more');
$eos->freshPlan($now - 30); $m->RefreshPlan(); $m->fireOnce();
check($m->value('FallbackActive') === false && writesTo(20) === ['pv', 'off'], 'fresh plan ends the fallback and writes the plan state');
resetWorld();
$eos->instructions = []; $eos->instruction('battery1', $now + 300, 'NON_EXPORT'); $eos->freshPlan($now - 30); $m->RefreshPlan(); $m->fireOnce(); $m->Watchdog(); $m->fireOnce();
check($m->value('FallbackActive') === false && $GLOBALS['actions'] === [], 'gap tolerance: first instruction in 5 min keeps the state, no fallback');
$eos->instructions = []; $eos->instruction('battery1', $now + 3600, 'NON_EXPORT'); $m->RefreshPlan(); $m->fireOnce();
check($m->value('FallbackActive') === true, 'first instruction in 1 h: no instruction -> fallback');

// ---------------------------------------------------------------- T7 manual mode and return
echo "== Manuell\n";
$eos->instructions = []; $eos->instruction('battery1', $now - 5, 'NON_EXPORT'); $eos->freshPlan($now - 30); $m->RefreshPlan(); $m->fireOnce(); resetWorld();
$m->properties['ManualReturnMinutes'] = 30;
$m->RequestAction('ManualMode', 5); $m->fireOnce();
check(writesTo(20) === ['now'] && writesTo(21) === [5000] && $m->value('ManualMode') === 5 && $m->attributes['ManualUntil'] > $now, 'manual FORCED_CHARGE written, return time armed');
$m->RefreshPlan(); $m->fireOnce();
check(count($GLOBALS['actions']) === 4, 'plan changes do not override manual mode');
$m->attributes['ManualUntil'] = $now - 1; $m->Watchdog(); $m->fireOnce();
check($m->value('ManualMode') === 100 && end($GLOBALS['actions'])[0] === 23, 'watchdog returns to automatic and rewrites the plan state');

// ---------------------------------------------------------------- T8 master switch off/on, mode 2 -> 0 release
echo "== Hauptschalter und Freigabe\n";
resetWorld();
$m->RequestAction('ControlActive', false);
check(writesTo(20) === ['pv'] && $m->value('ControlActive') === false && lastSent($m) === [], 'switch off writes the fallback once');
$m->RefreshPlan(); $m->fireOnce();
check(writesTo(20) === ['pv'], 'nothing written while switched off');
$m->RequestAction('ControlActive', true); $m->fireOnce();
check(writesTo(20) === ['pv', 'pvonly'], 'switch on rewrites the plan state once');
resetWorld();
$m->properties['ControlMode'] = 0; $m->ApplyChanges(); $m->fireOnce();
check(writesTo(20) === ['pv'] && $m->timers['Watchdog']['ms'] === 0, 'mode 2 -> 0 releases once and stops the watchdog');
$m->ProcessPlan(); $m->fireOnce();
check(count($GLOBALS['actions']) === 4, 'display mode afterwards writes nothing');

// ---------------------------------------------------------------- T9 battery policy degradations (check the device state, not the write count)
echo "== Batterie-Politik\n";
$m = battery(2); $m->properties['AllowGridCharge'] = false; $m->ApplyChanges(); $m->fireOnce();
$eos->instructions = []; $eos->instruction('battery1', $now - 5, 'FORCED_CHARGE', 1.0); $m->RefreshPlan(); $m->fireOnce();
check($GLOBALS['world'][20]['value'] === 'pvonly' && $GLOBALS['world'][21]['value'] === 0 && str_contains((string) $m->value('LastControlResult'), 'FORCED_CHARGE→NON_EXPORT'), 'grid charging forbidden -> device in NON_EXPORT, 0 W, degradation shown: ' . json_encode([$GLOBALS['world'][20]['value'], $GLOBALS['world'][21]['value'], $m->value('LastControlResult'), $m->attributes['ControlProblem'] ?? '']));
$m->properties['AllowGridCharge'] = true; $m->ApplyChanges(); $m->fireOnce();
check($GLOBALS['world'][20]['value'] === 'now' && $GLOBALS['world'][21]['value'] === 5000, 'grid charging allowed again -> FORCED_CHARGE with 5000 W');
$eos->instructions = []; $eos->instruction('battery1', $now - 5, 'GRID_SUPPORT_IMPORT', 0.0); $m->RefreshPlan(); $m->fireOnce();
check($GLOBALS['world'][20]['value'] === 'pvonly' && $GLOBALS['world'][21]['value'] === 0, 'grid charging with 0 W -> NON_EXPORT');
$eos->instructions = []; $eos->instruction('battery1', $now - 5, 'GRID_SUPPORT_EXPORT', 1.0); $m->RefreshPlan(); $m->fireOnce();
check($GLOBALS['world'][20]['value'] === 'pv' && $GLOBALS['world'][23]['value'] === true && $m->value('ModeRaw') === 'GRID_SUPPORT_EXPORT', 'export forbidden -> SELF_CONSUMPTION on the device, display keeps the raw EOS mode');
$eos->instructions = []; $eos->instruction('battery1', $now - 5, 'SOMETHING_NEW', 1.0); $m->RefreshPlan(); $m->fireOnce();
check($m->value('FallbackActive') === true, 'unknown mode -> fallback');

// ---------------------------------------------------------------- T10 vehicle: min current, dwell, unplugged
echo "== E-Auto\n";
worldVar(30, 1, 40, false); worldVar(31, 0, true, false); worldVar(32, 2, 0.0, true); worldVar(33, 0, false, true);
$eos->config['devices']['electric_vehicles']['ev1'] = ['device_id' => 'ev1'];
$v = new EOSVehicle(1001); $v->Create(); connectToServer($v);
$v->properties['SoCSourceVariable'] = 30; $v->properties['PluggedSourceVariable'] = 31; $v->properties['ControlMode'] = 2;
$v->properties['TargetCurrentVariable'] = 32; $v->properties['TargetChargeAllowedVariable'] = 33; $v->properties['FallbackMode'] = 0;
$eos->instructions = []; $eos->instruction('ev1', $now - 5, 'GRID_SUPPORT_IMPORT', 0.25); $eos->freshPlan($now - 30);
$v->ApplyChanges(); $v->fireOnce(); resetWorld(); $v->ApplyChanges(); $v->fireOnce();
check(writesTo(32) === [6.0] && writesTo(33) === [true], 'factor 0.25 on 11 kW -> raised to the 6 A minimum, charging allowed: ' . json_encode($GLOBALS['actions']));
resetWorld();
$eos->instructions = []; $eos->instruction('ev1', $now - 5, 'IDLE', 0.0); $v->RefreshPlan(); $v->fireOnce();
check($GLOBALS['actions'] === [] && str_contains((string) $v->value('LastControlResult'), 'held back'), 'on->off within the dwell time is held back');
$v->attributes['LastChargeSwitchTs'] = $now - 3600; $v->RefreshPlan(); $v->fireOnce();
check(writesTo(33) === [false] && writesTo(32) === [0.0], 'after the dwell time the switch-off is written');
resetWorld();
$eos->instructions = []; $eos->instruction('ev1', $now - 5, 'FORCED_CHARGE', 1.0); $GLOBALS['world'][31]['value'] = false; $v->attributes['LastChargeSwitchTs'] = $now - 3600; $v->RefreshPlan(); $v->fireOnce();
check($GLOBALS['actions'] === [] && str_contains((string) $v->value('LastControlResult'), 'unplugged'), 'unplugged: charging stays off');

// ---------------------------------------------------------------- T11 appliance: start once, grace, failed start not recorded
echo "== Haushaltsgerät\n";
worldVar(40, 0, false, true);
$eos->config['devices']['home_appliances']['dishwasher1'] = ['device_id' => 'dishwasher1'];
$eos->config['devices']['max_home_appliances'] = 1;
$a = new EOSAppliance(1002); $a->Create(); connectToServer($a);
$a->properties['ControlMode'] = 2; $a->properties['TargetEnableVariable'] = 40;
$a->properties['ModeAction_RUN'] = json_encode(['actionID' => '{START}', 'parameters' => ['TARGET' => 40]]);
$eos->instructions = []; $eos->instruction('dishwasher1', $now - 300, 'RUN'); $eos->freshPlan($now - 30);
$a->ApplyChanges(); $a->fireOnce();
check(writesTo(40) === [true] && count($GLOBALS['runActions']) === 1 && $GLOBALS['runActions'][0][0] === '{START}', 'RUN within grace: enable + one start action');
check(json_decode($a->attributes['StartedInstructionIds'], true) === ['dishwasher1@' . ($now - 300)], 'start recorded by instruction id');
$c = count($GLOBALS['runActions']); $a->RefreshPlan(); $a->fireOnce(); $a->Watchdog(); $a->fireOnce();
check(count($GLOBALS['runActions']) === $c && writesTo(40) === [true], 'no second start for the same instruction');
resetWorld();
$eos->instructions = []; $eos->instruction('dishwasher1', $now - 5400, 'RUN'); $a->RefreshPlan(); $a->fireOnce();
check($GLOBALS['runActions'] === [] && str_contains((string) $a->value('LastControlResult'), 'missed'), 'start 90 min late is skipped (grace 30 min)');
// failed start: enable write fails -> not recorded (finding 2)
resetWorld(); $GLOBALS['world'][40]['fail'] = true;
$eos->instructions = []; $eos->instruction('dishwasher1', $now - 60, 'RUN'); $a->RefreshPlan(); $a->fireOnce();
check(!in_array('dishwasher1@' . ($now - 60), json_decode($a->attributes['StartedInstructionIds'], true), true), 'failed enable write: instruction not recorded as started');
$GLOBALS['world'][40]['fail'] = false; $a->attributes['LastSent'] = '{}'; $a->RefreshPlan(); $a->fireOnce();
check(in_array('dishwasher1@' . ($now - 60), json_decode($a->attributes['StartedInstructionIds'], true), true), 'successful retry records the start');
resetWorld();
$eos->instructions = []; $eos->instruction('dishwasher1', $now - 5, 'OFF'); $a->RefreshPlan(); $a->fireOnce();
check($GLOBALS['actions'] === [], 'OFF without AllowStop writes nothing');
$a->properties['AllowStop'] = true; $a->ApplyChanges(); $a->fireOnce();
check(writesTo(40) === [false], 'OFF with AllowStop writes enable=false');

// ---------------------------------------------------------------- T12 heartbeat respects the backoff (review round 2)
echo "== Heartbeat und Backoff\n";
$m = battery(2); $m->properties['HeartbeatSeconds'] = 30; $m->ApplyChanges(); $m->fireOnce(); resetWorld();
$GLOBALS['world'][21]['fail'] = true;
$eos->instructions = []; $eos->instruction('battery1', $now - 5, 'FORCED_CHARGE', 1.0); $eos->freshPlan($now - 30); $m->RefreshPlan(); $m->fireOnce();
check((lastSent($m)['targets']['ChargePowerW']['fail'] ?? 0) === 1, 'charge power write failed once');
$ls = lastSent($m); $ls['ts'] = $now - 3600; $m->attributes['LastSent'] = json_encode($ls); resetWorld();
$m->Dispatch();
check(writesTo(20) === ['now'] && writesTo(23) === [false] && writesTo(21) === [] && lastSent($m)['targets']['ChargePowerW']['fail'] === 1, 'heartbeat re-sends the healthy targets but not the value in backoff: ' . json_encode($GLOBALS['actions']));
$ls = lastSent($m); $ls['ts'] = $now - 3600; $ls['targets']['ChargePowerW']['failTs'] = $now - 120; $m->attributes['LastSent'] = json_encode($ls); resetWorld();
$m->Dispatch();
check(lastSent($m)['targets']['ChargePowerW']['fail'] === 2, 'after the backoff the heartbeat retries the failed value');
$GLOBALS['world'][21]['fail'] = false;

// ---------------------------------------------------------------- T13 appliance start only counts when nothing is still failing
echo "== Gerätestart mit offenem Schreibfehler\n";
worldVar(41, 0, false, true, true);
$a2 = new EOSAppliance(1004); $a2->Create(); connectToServer($a2);
$a2->properties['ControlMode'] = 2; $a2->properties['TargetEnableVariable'] = 41;
$a2->properties['ModeAction_RUN'] = json_encode(['actionID' => '{FAIL}', 'parameters' => ['TARGET' => 41]]);
$eos->instructions = []; $eos->instruction('dishwasher1', $now - 60, 'RUN'); $eos->freshPlan($now - 30);
$a2->ApplyChanges(); $a2->fireOnce();
check(json_decode($a2->attributes['StartedInstructionIds'], true) === [] && lastSent($a2)['row']['fail'] === 1 && lastSent($a2)['targets']['Enable']['fail'] === 1, 'enable write and start action failed: nothing recorded');
// start action now works, enable write still failing and inside its backoff
$a2->properties['ModeAction_RUN'] = json_encode(['actionID' => '{START}', 'parameters' => ['TARGET' => 41]]);
$ls = lastSent($a2); $ls['row']['failTs'] = $now - 120; $a2->attributes['LastSent'] = json_encode($ls); resetWorld();
$a2->Dispatch();
check(count($GLOBALS['runActions']) === 1 && lastSent($a2)['row']['modeRaw'] === 'RUN', 'start action retried and succeeded');
check(json_decode($a2->attributes['StartedInstructionIds'], true) === [], 'start not recorded while the enable write is still failing (review round 2)');
$GLOBALS['world'][41]['fail'] = false;
$ls = lastSent($a2); $ls['targets']['Enable']['failTs'] = $now - 120; $a2->attributes['LastSent'] = json_encode($ls);
$a2->Dispatch();
check(json_decode($a2->attributes['StartedInstructionIds'], true) === ['dishwasher1@' . ($now - 60)] && $GLOBALS['world'][41]['value'] === true, 'once the enable write succeeds the start is recorded');

echo "\nAlle {$GLOBALS['checks']} Prüfungen bestanden.\n";
