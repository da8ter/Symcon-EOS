<?php

declare(strict_types=1);

/*
 * Control state machine, findings of the final review of 23.09.2026 (R6-1 … R6-12 in
 * the check labels). Complements control_test.php.
 */

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../EOSBattery/module.php';
require_once __DIR__ . '/../EOSVehicle/module.php';
require_once __DIR__ . '/../EOSAppliance/module.php';

// World: SoC 10, mode 20 (string), charge W 21 (int), discharge W 22 (float), discharge allowed 23, enable 40, running 41.
worldVar(10, 2, 55.0, false);
worldVar(20, 3, '', true);
worldVar(21, 1, 0, true);
worldVar(22, 2, 0.0, true);
worldVar(23, 0, false, true);
worldVar(40, 0, false, true);
worldVar(41, 0, false, false);
$GLOBALS['scripts'] = [900];
$eos = $GLOBALS['eos'];
$eos->config = ['devices' => [
    'batteries'       => ['battery1' => ['device_id' => 'battery1', 'capacity_wh' => 10000, 'max_charge_power_w' => 5000, 'min_soc_percentage' => 10, 'max_soc_percentage' => 95, 'charging_efficiency' => 0.95, 'discharging_efficiency' => 0.95, 'levelized_cost_of_storage_amt_kwh' => 0.0]],
    'home_appliances' => ['dishwasher1' => ['device_id' => 'dishwasher1']],
    'max_home_appliances' => 1,
]];
$now = 1_800_000_000;
setClock($now);

function bat(int $id, array $props = []): EOSBattery
{
    $m = new EOSBattery($id);
    $m->Create();
    connectToServer($m);
    $m->properties = array_merge($m->properties, ['SoCSourceVariable' => 10, 'ControlMode' => 2, 'TargetModeVariable' => 20, 'TargetChargePowerVariable' => 21,
        'ModeMap' => json_encode([['mode' => 'IDLE', 'value' => 'off'], ['mode' => 'SELF_CONSUMPTION', 'value' => 'pv'], ['mode' => 'NON_EXPORT', 'value' => 'pvonly'], ['mode' => 'GRID_SUPPORT_IMPORT', 'value' => 'now'], ['mode' => 'FORCED_CHARGE', 'value' => 'now']])], $props);
    return $m;
}
function appliance(int $id, array $props = []): EOSAppliance
{
    $a = new EOSAppliance($id);
    $a->Create();
    connectToServer($a);
    $a->properties = array_merge($a->properties, ['ControlMode' => 2, 'TargetEnableVariable' => 40], $props);
    return $a;
}
function ls(IPSModuleStrict $m): array { return json_decode($m->attributes['LastSent'], true) ?: []; }
/** One instruction for $device starting $startAgo s ago, plan generated $age s ago. */
function planFor(string $device, string $mode, float $factor = 1.0, int $startAgo = 5, int $age = 30): void
{
    $GLOBALS['eos']->instructions = [];
    $GLOBALS['eos']->instruction($device, nowTs() - $startAgo, $mode, $factor);
    $GLOBALS['eos']->freshPlan(nowTs() - $age);
}

// ---------------------------------------------------------------- R6-1, R6-11: nothing to drive, nothing replayed, idle ticks write nothing
echo "== Nichts zu tun\n";
$b = bat(1000, ['FallbackMode' => -1, 'HeartbeatSeconds' => 30, 'StaleAfterMinutes' => 30]);
planFor('battery1', 'FORCED_CHARGE');
$b->ApplyChanges(); $b->fireOnce();
check($GLOBALS['world'][21]['value'] === 5000 && $b->timers['Retry']['ms'] > 0, 'plan FORCED_CHARGE written, heartbeat armed');
setClock($now + 31 * 60); resetWorld();
$b->Watchdog(); $b->fireOnce(); $b->fireTimer('Retry'); $b->fireTimer('Retry');
check($b->value('FallbackActive') === true && writesTo(21) === [] && writesTo(20) === [] && $b->timers['Retry']['ms'] === 0 && $b->attributes['Desired'] === '{}',
    'R6-1: stale plan with fallback "no intervention" stops the heartbeat; the old FORCED_CHARGE is not re-sent: ' . json_encode($GLOBALS['actions']));
$resultSets = $b->variables['LastControlResult']['setCount']; $writes = $b->attrWrites;
for ($i = 2; $i <= 4; $i++) { setClock($now + (30 + $i) * 60); $b->Watchdog(); $b->fireOnce(); }
check($b->variables['LastControlResult']['setCount'] === $resultSets && ($b->attrWrites['Desired'] ?? 0) === ($writes['Desired'] ?? 0) && ($b->attrWrites['LastSent'] ?? 0) === ($writes['LastSent'] ?? 0),
    'R6-11: idle ticks under "no intervention" write neither the result nor Desired/LastSent');
unset($GLOBALS['objects'][1000]);

setClock($now);
$s = bat(1001);
planFor('battery1', 'NON_EXPORT');
$s->ApplyChanges(); $s->fireOnce();
$lpi = $s->attrWrites['LastPlanInstruction'] ?? 0;
for ($i = 1; $i <= 3; $i++) { setClock($now + $i * 60); $s->Watchdog(); }
check(($s->attrWrites['LastPlanInstruction'] ?? 0) === $lpi, 'R6-11: an unchanged plan instruction is not rewritten on every watchdog tick');
$s->RequestAction('ControlActive', false);
$armed = 0; $resultSets = $s->variables['LastControlResult']['setCount'];
for ($i = 4; $i <= 8; $i++) { setClock($now + $i * 60); $s->Watchdog(); $armed += $s->fireOnce(); }
check($armed === 0 && $s->variables['LastControlResult']['setCount'] === $resultSets, 'R6-11: with the master switch off the watchdog arms no dispatch and rewrites no result');
unset($GLOBALS['objects'][1001]);

// Appliance (fallback default "no intervention"): the RUN action fails, the plan turns stale inside the grace period.
setClock($now);
$a = appliance(1002, ['ModeAction_RUN' => json_encode(['actionID' => '{FAIL}', 'parameters' => []])]);
planFor('dishwasher1', 'RUN', 1.0, 60, 3 * 3600 - 120);
$a->ApplyChanges(); $a->fireOnce();
setClock($now + 180); $a->Watchdog(); $a->fireOnce();
$a->properties['ModeAction_RUN'] = json_encode(['actionID' => '{START}', 'parameters' => []]); resetWorld();
for ($t = 240; $t <= 6 * 3600 + 600; $t += 300) { setClock($now + $t); $a->Watchdog(); $a->fireOnce(); $a->fireTimer('Retry'); }
check($a->value('FallbackActive') === true && $GLOBALS['runActions'] === [], 'R6-1: a failed start is not retried from the stored state after the plan went stale (grace period long over)');
unset($GLOBALS['objects'][1002]);

// ---------------------------------------------------------------- R6-2: option 3 and the heartbeat follow the whole desired state
echo "== Option 3 und Heartbeat ohne Option-1-Ziele\n";
$eos->config['devices']['electric_vehicles'] = ['ev1' => ['device_id' => 'ev1']];
worldVar(30, 1, 40, false);
$scriptPower = static fn (): array => array_map(static fn (array $r): mixed => $r[1]['PowerW'] ?? null, $GLOBALS['runScripts']);
setClock($now);
$o = bat(1010, ['TargetModeVariable' => 0, 'TargetChargePowerVariable' => 0, 'ControlScript' => 900]);
planFor('battery1', 'FORCED_CHARGE', 0.5);
resetWorld(); $o->ApplyChanges(); $o->fireOnce();
setClock($now + 900); planFor('battery1', 'FORCED_CHARGE', 1.0); $o->RefreshPlan(); $o->fireOnce();
check($scriptPower() === [2500, 5000], 'R6-2: script-only battery: 2.5 kW -> 5 kW inside FORCED_CHARGE reaches the script: ' . json_encode($scriptPower()));
unset($GLOBALS['objects'][1010]);

setClock($now);
$o2 = bat(1011, ['TargetChargePowerVariable' => 0, 'ControlScript' => 900]);
planFor('battery1', 'GRID_SUPPORT_IMPORT', 0.3);
resetWorld(); $o2->ApplyChanges(); $o2->fireOnce();
setClock($now + 900); planFor('battery1', 'GRID_SUPPORT_IMPORT', 0.9); $o2->RefreshPlan(); $o2->fireOnce();
check($scriptPower() === [1500, 4500] && writesTo(20) === ['now'], 'R6-2: mode target plus script: 1.5 kW -> 4.5 kW reaches the script, the unchanged mode is not rewritten: ' . json_encode($scriptPower()));
unset($GLOBALS['objects'][1011]);

setClock($now);
$v = new EOSVehicle(1012); $v->Create(); connectToServer($v);
$v->properties = array_merge($v->properties, ['SoCSourceVariable' => 30, 'ControlMode' => 2, 'ControlScript' => 900, 'FallbackMode' => 0]);
planFor('ev1', 'GRID_SUPPORT_IMPORT', 0.5);
resetWorld(); $v->ApplyChanges(); $v->fireOnce();
setClock($now + 900); planFor('ev1', 'GRID_SUPPORT_IMPORT', 1.0); $v->RefreshPlan(); $v->fireOnce();
$currents = array_map(static fn (array $r): mixed => $r[1]['CurrentA'], $GLOBALS['runScripts']);
check(count($currents) === 2 && $currents[1] > $currents[0], 'R6-2: script-only vehicle (go-e example): a higher current reaches the script: ' . json_encode($currents));
unset($GLOBALS['objects'][1012]);

setClock($now);
$h = bat(1013, ['TargetModeVariable' => 0, 'TargetChargePowerVariable' => 0, 'ControlScript' => 900, 'HeartbeatSeconds' => 30]);
planFor('battery1', 'NON_EXPORT');
resetWorld(); $h->ApplyChanges(); $h->fireOnce();
check(count($GLOBALS['runScripts']) === 1 && $h->timers['Retry']['ms'] === 30500, 'R6-2: script-only binding with heartbeat arms the Retry timer: ' . $h->timers['Retry']['ms']);
setClock($now + 30); $h->fireTimer('Retry');
check(count($GLOBALS['runScripts']) === 2 && ($GLOBALS['runScripts'][1][1]['Heartbeat'] ?? null) === true, 'R6-2: ... and the heartbeat runs the script again');
unset($GLOBALS['objects'][1013]);

// Heartbeats stay aligned after a partial change: option 3 once per period, all healthy targets together.
setClock($now);
$al = bat(1014, ['ControlScript' => 900, 'HeartbeatSeconds' => 30]);
planFor('battery1', 'FORCED_CHARGE', 0.5);
resetWorld(); $al->ApplyChanges(); $al->fireOnce();
setClock($now + 10); planFor('battery1', 'FORCED_CHARGE', 1.0); $al->RefreshPlan(); $al->fireOnce(); // only ChargePowerW changes
resetWorld();
for ($t = 11; $t <= 100; $t++) { setClock($now + $t); $al->Dispatch(); }
$runs = count($GLOBALS['runScripts']);
check($runs === 3 && count(writesTo(20)) === 3 && count(writesTo(21)) === 3, 'R6-2: after a partial change the heartbeats realign: 3 periods in 90 s, option 3 and every target once per period: script ' . $runs . ', mode ' . count(writesTo(20)) . ', power ' . count(writesTo(21)));
unset($GLOBALS['objects'][1014]);

setClock(null);
echo "\nAlle {$GLOBALS['checks']} Prüfungen bestanden.\n";
