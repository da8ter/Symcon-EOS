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

setClock(null);
echo "\nAlle {$GLOBALS['checks']} Prüfungen bestanden.\n";
