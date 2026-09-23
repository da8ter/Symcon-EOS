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

// ---------------------------------------------------------------- R6-6: mode-row retries only while the row is pending
echo "== Modus-Aktion: Wiederholung nur solange offen\n";
$rowPlan = static function (IPSModuleStrict $m, string $mode): void { planFor('battery1', $mode); $m->RefreshPlan(); $m->fireOnce(); };
setClock($now);
$r = bat(1020, ['ModeAction_NON_EXPORT' => json_encode(['actionID' => '{OK}', 'parameters' => []]), 'ModeAction_SELF_CONSUMPTION' => json_encode(['actionID' => '{FAIL}', 'parameters' => []])]);
planFor('battery1', 'NON_EXPORT');
$r->ApplyChanges(); $r->fireOnce();
setClock($now + 10); $rowPlan($r, 'SELF_CONSUMPTION'); // its row action fails
setClock($now + 20); $rowPlan($r, 'NON_EXPORT');       // back to the mode that fired last
check($r->timers['Retry']['ms'] === 0, 'R6-6: a failed row action of a mode that is no longer pending arms no retry (live: 500 ms loop): ' . $r->timers['Retry']['ms']);
unset($GLOBALS['objects'][1020]);

setClock($now);
$r2 = bat(1021, ['ModeAction_NON_EXPORT' => json_encode(['actionID' => '{OK}', 'parameters' => []])]);
planFor('battery1', 'NON_EXPORT');
$r2->ApplyChanges(); $r2->fireOnce();
$r2->properties['ModeAction_NON_EXPORT'] = json_encode(['actionID' => '{FAIL}', 'parameters' => []]);
$r2->ApplyControl(true);
$failedRow = ls($r2)['row'];
check($failedRow['modeRaw'] === null && $failedRow['fail'] === 1 && $r2->timers['Retry']['ms'] === 60500, 'R6-6: a forced row action that fails stays open and is retried after the backoff: ' . json_encode($failedRow) . ' retry ' . $r2->timers['Retry']['ms']);
$r2->properties['ModeAction_NON_EXPORT'] = json_encode(['actionID' => '{OK}', 'parameters' => []]); resetWorld();
setClock($now + 61); $r2->fireTimer('Retry');
check(count($GLOBALS['runActions']) === 1 && ls($r2)['row']['modeRaw'] === 'NON_EXPORT' && ls($r2)['row']['fail'] === 0, 'R6-6: ... and the retry that succeeds marks the row done');
unset($GLOBALS['objects'][1021]);

// ---------------------------------------------------------------- R6-3, R6-4, R6-5: appliance start lifecycle
echo "== Haushaltsgerät: Lauf, Wiederholung, manueller Start\n";
$eos->config['devices']['home_appliances'] = ['dishwasher1' => ['device_id' => 'dishwasher1']];
$start = ['ModeAction_RUN' => json_encode(['actionID' => '{START}', 'parameters' => []])];
setClock($now);
worldVar(40, 0, false, true);
$hb = appliance(1030, ['AllowStop' => true]);
planFor('dishwasher1', 'RUN', 1.0, 60);
resetWorld(); $hb->ApplyChanges(); $hb->fireOnce();
for ($t = 60; $t <= 1860; $t += 60) { setClock($now + $t); $hb->Watchdog(); $hb->fireOnce(); }
check(writesTo(40) === [true] && $GLOBALS['world'][40]['value'] === true, 'R6-3: with AllowStop the running appliance is not switched off when the grace period of its RUN ends: ' . json_encode(writesTo(40)));
resetWorld(); setClock($now + 2700); $hb->ApplyChanges(); $hb->fireOnce();
check(writesTo(40) === [true], 'R6-3: ... also not by the resync of an Apply 45 min into the run: ' . json_encode(writesTo(40)));
unset($GLOBALS['objects'][1030]);

setClock($now);
worldVar(40, 0, false, true); worldVar(41, 0, false, false);
$hw = appliance(1031, $start + ['RunningSourceVariable' => 41]);
planFor('dishwasher1', 'RUN', 1.0, 60);
resetWorld(); $hw->ApplyChanges(); $hw->fireOnce();
$first = count($GLOBALS['runActions']);
for ($t = 60; $t <= 900; $t += 60) { setClock($now + $t); $hw->Watchdog(); $hw->fireOnce(); } // "running" never turns true
$released = count(array_filter($hw->logs, static fn (array $l): bool => str_contains($l[1], 'the start may be retried')));
$gaveUp = count(array_filter($hw->logs, static fn (array $l): bool => str_contains($l[1], 'not starting again')));
check($first === 1 && $released === 1 && $gaveUp === 1 && count($GLOBALS['runActions']) === 2, 'R6-4: an unconfirmed start is retried once within the grace period, without an Apply, then given up: starts ' . count($GLOBALS['runActions']) . ', releases ' . $released . ', given up ' . $gaveUp);
unset($GLOBALS['objects'][1031]);

setClock($now);
worldVar(40, 0, false, true); worldVar(41, 0, true, false); // already running when "run" is chosen
$hm = appliance(1032, $start + ['RunningSourceVariable' => 41]);
planFor('dishwasher1', 'OFF', 1.0, 60);
$hm->ApplyChanges(); $hm->fireOnce(); resetWorld();
$hm->RequestAction('ManualMode', 1); $hm->fireOnce();
setClock($now + 1800); $GLOBALS['world'][41]['value'] = false;
for ($t = 1860; $t <= 2400; $t += 60) { setClock($now + $t); $hm->Watchdog(); $hm->fireOnce(); }
setClock($now + 6 * 3600); $eos->freshPlan($now + 6 * 3600 - 30); $hm->ApplyChanges(); $hm->fireOnce();
check($GLOBALS['runActions'] === [] && $hm->attributes['ManualStartArmed'] === false, 'R6-5: manual "run" chosen while running starts nothing, neither when it stops nor on an Apply hours later');
unset($GLOBALS['objects'][1032]);

setClock($now);
worldVar(40, 0, false, true); worldVar(41, 0, false, false);
$hr = appliance(1033, $start);
planFor('dishwasher1', 'OFF', 1.0, 60);
$hr->ApplyChanges(); $hr->fireOnce(); resetWorld();
$hr->RequestAction('ManualMode', 1); $hr->fireOnce();
setClock($now + 600); $hr->RequestAction('ManualMode', 1); $hr->fireOnce(); // the same mode again, no edge
check(count($GLOBALS['runActions']) === 1, 'R6-5: choosing manual "run" again while in "run" does not start a second time: ' . count($GLOBALS['runActions']));
unset($GLOBALS['objects'][1033]);

// ---------------------------------------------------------------- R6-9: an expired manual hold is never written again
echo "== Abgelaufene manuelle Haltezeit\n";
setClock($now);
$mm = bat(1040, ['ManualReturnMinutes' => 30]);
planFor('battery1', 'NON_EXPORT');
$mm->ApplyChanges(); $mm->fireOnce();
$mm->RequestAction('ManualMode', 5); $mm->fireOnce();     // manual FORCED_CHARGE for 30 min
$mm->properties['ControlMode'] = 0; $mm->ApplyChanges();  // display only for a day
setClock($now + 86400); resetWorld(); planFor('battery1', 'NON_EXPORT');
$mm->properties['ControlMode'] = 2; $mm->ApplyChanges(); $mm->fireOnce();
check(writesTo(20) === ['pvonly'] && writesTo(21) === [0] && $mm->value('ManualMode') === 100, 'R6-9: back to "active" a day later writes the plan, not the expired manual FORCED_CHARGE: mode ' . json_encode(writesTo(20)) . ' power ' . json_encode(writesTo(21)));
unset($GLOBALS['objects'][1040]);

setClock($now);
worldVar(33, 0, false, true); worldVar(32, 2, 0.0, true);
$ev = new EOSVehicle(1041); $ev->Create(); connectToServer($ev);
$ev->properties = array_merge($ev->properties, ['SoCSourceVariable' => 30, 'ControlMode' => 2, 'FallbackMode' => 0, 'ManualReturnMinutes' => 30, 'TargetChargeAllowedVariable' => 33, 'TargetCurrentVariable' => 32]);
planFor('ev1', 'IDLE', 0.0);
$ev->ApplyChanges(); $ev->fireOnce();
$ev->RequestAction('ManualMode', 5); $ev->fireOnce();
$ev->properties['ControlMode'] = 0; $ev->ApplyChanges();
setClock($now + 86400); resetWorld(); planFor('ev1', 'IDLE', 0.0);
$ev->properties['ControlMode'] = 2; $ev->ApplyChanges(); $ev->fireOnce();
check(!in_array(true, writesTo(33), true), 'R6-9: vehicle: an expired manual charge is not switched on again (live risk: 11 kW for the 300 s dwell): ' . json_encode(writesTo(33)));
unset($GLOBALS['objects'][1041]);

// ---------------------------------------------------------------- R6-7, R6-10: blocked instances never write; R6-8: id ownership
echo "== Gesperrte Instanzen, Geräte-ID-Hoheit\n";
$GLOBALS['registry'] = true;
setClock($now);
$owner = bat(1050); planFor('battery1', 'NON_EXPORT'); $owner->ApplyChanges(); $owner->fireOnce();
$dup = bat(1049); $dup->ApplyChanges(); $dup->fireOnce(); // lower InstanceID, added later, default id
check($owner->status === IS_ACTIVE && $dup->status === 203, 'R6-8: a newly added instance with the default id is blocked, not the configured one with the higher InstanceID: owner ' . $owner->status . ', new ' . $dup->status);
$owner->ApplyChanges(); $dup->ApplyChanges();
check($owner->status === IS_ACTIVE && $dup->status === 203, 'R6-8: ... and it stays that way after a restart (the server keeps the owner)');
$calls = count($eos->calls);
check($dup->WriteConfigToEOS() === false && array_filter(array_slice($eos->calls, $calls), static fn (array $c): bool => in_array($c['Command'], ['MergeConfig', 'SetConfig', 'SaveConfig'], true)) === [],
    'R6-7: "Overwrite EOS" does nothing in status 203 (the entry belongs to the other instance)');
$bad = bat(1051, ['DeviceID' => '']); $bad->ApplyChanges(); $calls = count($eos->calls);
check($bad->status === 201 && $bad->WriteConfigToEOS() === false && array_filter(array_slice($eos->calls, $calls), static fn (array $c): bool => in_array($c['Command'], ['MergeConfig', 'SetConfig', 'SaveConfig'], true)) === [],
    'R6-7: ... nor in status 201 (an empty id would write a battery "" or the whole map)');
unset($GLOBALS['objects'][1049], $GLOBALS['objects'][1051]);
$owner->properties['DeviceID'] = 'speicher1'; $owner->ApplyChanges(); // renamed: EOS still has battery1, the second battery is refused
check($owner->status === 205, 'setup: renamed battery -> status 205 (EOS already has battery1)');
resetWorld(); $owner->properties['ControlMode'] = 0; $owner->ApplyChanges();
check($GLOBALS['actions'] === [] && $GLOBALS['runActions'] === [], 'R6-10: leaving "active" in status 205 writes no fallback to the hardware: ' . json_encode($GLOBALS['actions']));
unset($GLOBALS['objects'][1050]);

// Without a usable answer from the server the local rule decides, within the same server only.
$eos->claimFails = true;
$x = bat(1052); $x->ApplyChanges();
$y = bat(1053); $GLOBALS['instances'][1053]['ConnectionID'] = 3000; $GLOBALS['instances'][3000] = ['ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE]; $y->ApplyChanges();
check($x->status === IS_ACTIVE && $y->status !== 203, 'R6-8: the same id on another EOS Server is no duplicate: ' . $y->status);
$z = bat(1054); $z->ApplyChanges();
check($z->status === 203, 'R6-8: without the server the lowest InstanceID on the same server keeps the id');
$eos->claimFails = false;
unset($GLOBALS['objects'][1052], $GLOBALS['objects'][1053], $GLOBALS['objects'][1054]);
$GLOBALS['registry'] = false;

// ---------------------------------------------------------------- R6-12: log throttling
echo "== Log-Drosselung\n";
$warnings = static fn (IPSModuleStrict $m, string $needle): int => count(array_filter($m->logs, static fn (array $l): bool => str_contains($l[1], $needle)));
setClock($now);
worldVar(21, 1, 0, true);
$sl = bat(1060, ['HeartbeatSeconds' => 30]);
$GLOBALS['world'][21]['slowS'] = 6;
planFor('battery1', 'FORCED_CHARGE');
$sl->ApplyChanges(); $sl->fireOnce();
for ($t = 1; $t <= 4; $t++) { setClock($now + $t * 60); $sl->fireTimer('Retry'); }
check($warnings($sl, 'Control writes took') === 1, 'R6-12: a slow device warns once, not on every heartbeat: ' . $warnings($sl, 'Control writes took'));
$GLOBALS['world'][21]['slowS'] = 0;
unset($GLOBALS['objects'][1060]);

setClock($now);
worldVar(21, 1, 0, true);
$fl = bat(1061);
planFor('battery1', 'FORCED_CHARGE', 1.0);
$fl->ApplyChanges(); $fl->fireOnce();
for ($i = 1; $i <= 6; $i++) { // the target flaps: fails, works, fails ... within an hour
    $GLOBALS['world'][21]['fail'] = $i % 2 === 1;
    setClock($now + $i * 300); planFor('battery1', 'FORCED_CHARGE', $i % 2 === 1 ? 0.5 : 1.0); $fl->RefreshPlan(); $fl->fireOnce();
}
check($warnings($fl, 'Control write failed') === 1 && $warnings($fl, 'Control writes OK again') === 1, 'R6-12: a flapping target logs one warning and one "OK again" per hour: ' . $warnings($fl, 'Control write failed') . '/' . $warnings($fl, 'Control writes OK again'));
$GLOBALS['world'][21]['fail'] = false;
unset($GLOBALS['objects'][1061]);

setClock($now);
worldVar(40, 0, false, true);
$rk = appliance(1062, ['ModeAction_RUN' => json_encode(['actionID' => '{FAIL}', 'parameters' => []])]);
planFor('dishwasher1', 'RUN', 1.0, 30);
$rk->ApplyChanges(); $rk->fireOnce();
for ($i = 1; $i <= 3; $i++) { setClock($now + $i * 120); planFor('dishwasher1', 'RUN', 1.0, 30); $rk->RefreshPlan(); $rk->fireOnce(); } // re-plans move the start
check($warnings($rk, 'Control write failed') === 1, 'R6-12: a failing RUN action warns once across re-plans (RUN@<start> keys): ' . $warnings($rk, 'Control write failed'));
unset($GLOBALS['objects'][1062]);

// ---------------------------------------------------------------- regression check (after the final review): option 3 failure of an old state
echo "== Regression: Option 3 nach Fehlschlag eines alten Stands\n";
setClock($now);
$cl = bat(1080, ['TargetModeVariable' => 0, 'TargetChargePowerVariable' => 0, 'ChangeAction' => json_encode(['actionID' => '{OK}', 'parameters' => []]), 'HeartbeatSeconds' => 30]);
planFor('battery1', 'FORCED_CHARGE', 0.5);
$cl->ApplyChanges(); $cl->fireOnce();                                        // state A runs
setClock($now + 10); $cl->properties['ChangeAction'] = json_encode(['actionID' => '{FAIL}', 'parameters' => []]);
planFor('battery1', 'FORCED_CHARGE', 1.0); $cl->RefreshPlan(); $cl->fireOnce();  // state B fails
setClock($now + 20); $cl->properties['ChangeAction'] = json_encode(['actionID' => '{OK}', 'parameters' => []]);
planFor('battery1', 'FORCED_CHARGE', 0.5); $cl->RefreshPlan(); $cl->fireOnce();  // back to A
resetWorld(); $wakeups = 0; $t = 20.0;
while ($t < 200) { $t += max(0.5, $cl->timers['Retry']['ms'] / 1000); setClock($now + $t); $cl->fireTimer('Retry'); $wakeups++; }
check($wakeups < 20 && count($GLOBALS['runActions']) >= 4, 'regression: after a failed option-3 run of a state that is gone, no 500 ms loop, and the heartbeat keeps running option 3: wakeups ' . $wakeups . ', runs ' . count($GLOBALS['runActions']));
$cl->RequestAction('ControlActive', false); $cl->fireTimer('Retry');
check($cl->timers['Retry']['ms'] === 0, 'regression: after the release the Retry timer is switched off instead of waking as a no-op: ' . $cl->timers['Retry']['ms']);
unset($GLOBALS['objects'][1080]);

// ---------------------------------------------------------------- regression check: a heartbeat never repeats a start
echo "== Regression: Heartbeat wiederholt keinen Start\n";
setClock($now);
$hs = appliance(1081, ['TargetEnableVariable' => 0, 'ControlScript' => 900, 'HeartbeatSeconds' => 30]);
planFor('dishwasher1', 'RUN', 1.0, 60);
resetWorld(); $hs->ApplyChanges(); $hs->fireOnce();
$pulse = $hs->attributes['StartPulseTs'];
setClock($now + 31); $hs->fireTimer('Retry');
$starts = array_map(static fn (array $r): bool => (bool) ($r[1]['Start'] ?? false), $GLOBALS['runScripts']);
check($starts === [true, false] && $hs->attributes['StartPulseTs'] === $pulse, 'regression: a script-only appliance gets Start=true once; the heartbeat sends Start=false and keeps the pulse time: ' . json_encode($starts));
$hs->RequestAction('ManualMode', 1); $hs->fireOnce();
setClock($now + 62); $hs->fireTimer('Retry');
$manual = array_map(static fn (array $r): bool => (bool) ($r[1]['Start'] ?? false), array_slice($GLOBALS['runScripts'], 2));
check(count(array_filter($manual)) <= 1 && end($manual) === false, 'regression: ... also for manual "run": the heartbeat does not send the start again: ' . json_encode($manual));
unset($GLOBALS['objects'][1081]);

// ---------------------------------------------------------------- regression check: the id owner survives an EOS outage
echo "== Regression: Geräte-ID-Hoheit bei EOS-Ausfall\n";
$GLOBALS['registry'] = true; $eos->owners = [];
setClock($now);
$ow = bat(1091); planFor('battery1', 'NON_EXPORT'); $ow->ApplyChanges();  // configured owner
$nw = bat(1089); $nw->ApplyChanges();                                       // added later, lower InstanceID
$GLOBALS['instances'][2000]['InstanceStatus'] = 201;                        // EOS unreachable, the EOS Server instance still answers
$ow->ApplyChanges(); $nw->ApplyChanges();
check($ow->status !== 203 && $nw->status === 203, 'regression: while EOS is unreachable the server still decides the id owner; the configured one is not blocked: owner ' . $ow->status . ', new ' . $nw->status);
$GLOBALS['instances'][2000]['InstanceStatus'] = IS_ACTIVE;
$eos->claimFails = true; $ow->ApplyChanges(); $eos->claimFails = false;
check(($ow->timers['ClaimRetry']['ms'] ?? 0) === 30000, 'regression: a server that cannot answer (e.g. mid-reload) is asked again in 30 s: ' . json_encode($ow->timers['ClaimRetry'] ?? null));
$ow->fireTimer('ClaimRetry');
check($ow->status === IS_ACTIVE && $ow->timers['ClaimRetry']['ms'] === 0, 'regression: ... the retry settles it and stops');
unset($GLOBALS['objects'][1089], $GLOBALS['objects'][1091]);
$GLOBALS['registry'] = false;

// ---------------------------------------------------------------- regression check: the start retry uses the binding that carries the pulse
echo "== Regression: Startwiederholung je Impulsbindung\n";
setClock($now);
worldVar(40, 0, false, true); worldVar(41, 0, false, false);
$re = appliance(1082, ['RunningSourceVariable' => 41]);        // pulse through the release target only
planFor('dishwasher1', 'RUN', 1.0, 60);
resetWorld(); $re->ApplyChanges(); $re->fireOnce();
for ($t = 60; $t <= 900; $t += 60) { setClock($now + $t); $re->Watchdog(); $re->fireOnce(); }
$warned = count(array_filter($re->logs, static fn (array $l): bool => str_contains($l[1], 'cannot be repeated')));
check(writesTo(40) === [true] && $warned === 1 && $re->attributes['StartPulseTs'] > 0, 'regression: with only the release target no fake second start ("on" over "on"); one warning, the lock stays: ' . json_encode(writesTo(40)) . ', warnings ' . $warned);
unset($GLOBALS['objects'][1082]);

setClock($now);
worldVar(41, 0, false, false);
$rs = appliance(1083, ['TargetEnableVariable' => 0, 'ControlScript' => 900, 'RunningSourceVariable' => 41]); // pulse through option 3
planFor('dishwasher1', 'RUN', 1.0, 60);
resetWorld(); $rs->ApplyChanges(); $rs->fireOnce();
for ($t = 60; $t <= 900; $t += 60) { setClock($now + $t); $rs->Watchdog(); $rs->fireOnce(); }
$starts = count(array_filter($GLOBALS['runScripts'], static fn (array $r): bool => !empty($r[1]['Start'])));
$gaveUp = count(array_filter($rs->logs, static fn (array $l): bool => str_contains($l[1], 'not starting again')));
check($starts === 2 && $gaveUp === 1, 'regression: with option 3 as the pulse the retry runs the script again with Start=true, then gives up: starts ' . $starts . ', given up ' . $gaveUp);
unset($GLOBALS['objects'][1083]);

setClock(null);
echo "\nAlle {$GLOBALS['checks']} Prüfungen bestanden.\n";
