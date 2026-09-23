<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/EOSCommon.php';
require_once __DIR__ . '/../libs/EOSPlanDevice.php';
require_once __DIR__ . '/../libs/EOSSoCPush.php';
require_once __DIR__ . '/../libs/EOSControlBindings.php';
require_once __DIR__ . '/../libs/EOSFormHelpers.php';
require_once __DIR__ . '/../libs/EOSControl.php';
require_once __DIR__ . '/../libs/EOSControlDispatch.php';
require_once __DIR__ . '/../libs/EOSDeviceConfigSync.php';
require_once __DIR__ . '/../libs/EOSDeviceConfigForm.php';
require_once __DIR__ . '/../libs/EOSBatteryConfig.php';

/**
 * EOS Battery: represents one stationary battery known to EOS.
 *
 * Pushes the state of charge to EOS through the EOS Server splitter, shows the
 * currently active planner instruction (operation mode, factor, target power)
 * plus the next scheduled change and - when a control mode is enabled - drives
 * the user's inverter through vendor-neutral bindings (EOSControl).
 */
class EOSBattery extends IPSModuleStrict
{
    use EOSCommon;
    use EOSPlanDevice;
    use EOSSoCPush;
    use EOSControlBindings;
    use EOSFormHelpers;
    use EOSControl;
    use EOSControlDispatch;
    use EOSDeviceConfigForm;
    use EOSDeviceConfigSync, EOSBatteryConfig {
        EOSBatteryConfig::onDeviceSynced insteadof EOSDeviceConfigSync; // the battery links the inverter
    }

    private const MODULE_GUID = '{F4B30383-1210-4169-93DA-5C9664447B42}';
    /** Device map in the EOS configuration; GENETIC supports only one battery and one vehicle. */
    private const DEVICE_COLLECTION = 'devices/batteries';
    private const SINGLE_DEVICE = true;
    /** Configuration properties sent to EOS, with their defaults (also the base of a first sync). */
    private const CONFIG_DEFAULTS = ['CapacityWh' => 10000, 'MaxChargePowerW' => 5000, 'MinSoC' => 10, 'MaxSoC' => 95, 'ChargingEfficiency' => 0.95, 'DischargingEfficiency' => 0.95, 'LcosAmtKwh' => 0.0];
    /** Stopped and released when the device id is invalid or not ours (blockDevice()). */
    private const BLOCK_TIMERS = ['SoCPush', 'SlotTimer', 'Watchdog', 'Retry'];
    private const SOURCE_ATTRIBUTES = ['RegisteredSoCVar'];
    private const CONTROL_PREFIX = 'EOSBAT';

    public function Create(): void
    {
        parent::Create();
        $this->SetVisualizationType(1);

        $this->RegisterPropertyString('DeviceID', 'battery1');
        $this->registerSoCProperties();
        $this->RegisterPropertyInteger('StaleAfterMinutes', 180);
        $this->registerConfigProperties();
        $this->RegisterPropertyInteger('MaxDischargePowerW', 5000);
        $this->RegisterPropertyBoolean('AllowGridCharge', true);
        $this->RegisterPropertyBoolean('AllowGridExport', false);
        $this->registerControlProperties(self::BATTERY_MODES['SELF_CONSUMPTION']['value']);

        $this->registerPlanAttributes();
        $this->RegisterAttributeString('SolutionSubset', '{}');
        $this->RegisterAttributeString('PlausibilityWarned', '');

        $this->RegisterVariableInteger('Mode', $this->Translate('Operation mode'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS'      => json_encode($this->eosBatteryModeOptions(), JSON_UNESCAPED_UNICODE),
        ], 10);
        $this->RegisterVariableString('ModeRaw', $this->Translate('Operation mode (raw)'), $this->eosValuePresentation('Information'), 20);
        $this->RegisterVariableFloat('Factor', $this->Translate('Factor'), $this->eosValuePresentation('Gauge', '', 2), 30);
        $this->RegisterVariableFloat('TargetChargePowerW', $this->Translate('Target charge power'), $this->eosValuePresentation('Electricity', ' W', 0), 40);
        $this->RegisterVariableBoolean('DischargeAllowed', $this->Translate('Discharge allowed'), $this->eosBoolPresentation('No', 'Yes', 0x808080, 0x00A000, 'HollowArrowDown'), 50);
        $this->RegisterVariableBoolean('GridChargeActive', $this->Translate('Grid charging active'), $this->eosBoolPresentation('No', 'Yes', 0x808080, 0xC000C0, 'Plug'), 60);
        $this->registerPlanVariables(70);
        $this->registerSoCVariables(110);
        $this->registerControlVariables(200);

        $this->RegisterTimer('SoCPush', 0, 'EOSBAT_PushSoC($_IPS[\'TARGET\']);');
        $this->RegisterTimer('SlotTimer', 0, 'EOSBAT_ProcessPlan($_IPS[\'TARGET\']);');
        $this->registerControlTimers();
        $this->registerFormFillTimer();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        $deviceId = $this->ReadPropertyString('DeviceID');
        // An id that is invalid or used by another instance blocks everything: no pushes,
        // no plans, no hardware writes under a device id this instance does not own.
        if (!$this->validDeviceId($deviceId)) {
            $this->blockDevice(self::STATUS_BAD_DEVICE_ID);
            return;
        }
        if ($this->isDuplicateDeviceId($deviceId)) {
            $this->blockDevice(self::STATUS_DUPLICATE_ID);
            return;
        }
        $this->SetStatus(IS_ACTIVE); // leaves a blocking status before control and sources start again
        $this->setupControl();
        $hasSource = $this->setupSoCSource();
        $this->SetTimerInterval('SoCPush', 0);

        if (!$hasSource) {
            $this->SetStatus(self::STATUS_NO_SOURCE);
            return;
        }
        if (!$this->parentUsable()) {
            $this->SetStatus(self::STATUS_NO_PARENT);
            return;
        }

        $this->SetStatus(IS_ACTIVE);
        if ($this->ReadPropertyInteger('MinSoC') >= $this->ReadPropertyInteger('MaxSoC')) {
            // EOS rejects such a battery; keep pushing and controlling with the entry EOS has.
            $this->SetStatus(self::STATUS_BAD_LIMITS);
        } else {
            [$path, $device, $merge] = $this->deviceConfig();
            $this->syncDeviceConfig($path, $device, $merge, false);
            if ($this->GetStatus() === self::STATUS_OTHER_DEVICE) {
                $this->blockDevice(self::STATUS_OTHER_DEVICE);
                return;
            }
        }
        $this->SetTimerInterval('SoCPush', $this->ReadPropertyInteger('PushInterval') * 1000);
        $this->PushSoC();
        $this->RefreshPlan();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }
        $this->handleSoCMessage($SenderID, $Message);
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if (!$this->handleControlAction($Ident, $Value)) {
            throw new Exception('Invalid ident: ' . $Ident);
        }
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        $this->fillModeMap($form);
        [$path, $device] = $this->deviceConfig();
        $this->setFormAttribute($form['elements'], 'ConfigInfo', 'caption', $this->eosConfigSummary($path, $device));
        $this->armFormFillIfDiffers($path, $device);
        $this->fillDevicePicker($form);
        return json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ------------------------------------------------------------------ public API (prefix EOSBAT_)

    /** Timer: write the stored desired state. */
    public function Dispatch(): void
    {
        $this->runDispatch();
    }

    /** Timer: stale plan, manual auto-return, heartbeat, retries. */
    public function Watchdog(): void
    {
        $this->runWatchdog();
    }

    /** Re-evaluate and write now; $Force rewrites every bound target. */
    public function ApplyControl(bool $Force): bool
    {
        return $this->applyControlNow($Force);
    }

    /** Manual override: an operation mode value (0-6) or 100 = automatic. */
    public function SetManualMode(int $Mode): void
    {
        $this->changeManualMode($Mode);
    }

    public function GetControlState(): string
    {
        return json_encode($this->controlState(), JSON_UNESCAPED_UNICODE);
    }

    // ------------------------------------------------------------------ visualization

    public function GetVisualizationTile(): string
    {
        $html = (string) file_get_contents(__DIR__ . '/module.html');
        return $html . '<script>handleMessage(' . json_encode(json_encode($this->tileState(), JSON_UNESCAPED_UNICODE)) . ');</script>';
    }

    /** Tile payload as JSON (debugging and external visualizations). */
    public function GetTileState(): string
    {
        return json_encode($this->tileState(), JSON_UNESCAPED_UNICODE);
    }

    // ------------------------------------------------------------------ plan hooks (display)

    protected function showInstruction(array $instruction): void
    {
        $modeId = (string) ($instruction['operation_mode_id'] ?? '');
        $state = $this->batteryState($modeId, (float) ($instruction['operation_mode_factor'] ?? 0.0), false);
        if (!$state['mode']['known'] && $modeId !== (string) $this->GetValue('ModeRaw')) {
            $this->LogMessage(sprintf($this->Translate('Unknown operation mode %s'), $modeId), KL_WARNING);
        }
        $this->SetValue('Mode', $state['mode']['value']);
        $this->SetValue('ModeRaw', $modeId);
        $this->SetValue('Factor', $state['factor']);
        $this->SetValue('DischargeAllowed', $state['mode']['discharge']);
        $this->SetValue('GridChargeActive', $state['mode']['grid']);
        $this->SetValue('TargetChargePowerW', $state['chargeW']);
    }

    protected function showNoInstruction(): void
    {
        $this->SetValue('Mode', self::EOS_MODE_UNKNOWN);
        $this->SetValue('ModeRaw', '');
        $this->SetValue('Factor', 0.0);
        $this->SetValue('DischargeAllowed', false);
        $this->SetValue('GridChargeActive', false);
        $this->SetValue('TargetChargePowerW', 0.0);
    }

    protected function onPlanStored(): void
    {
        $this->fetchSolutionSubset();
    }

    protected function onPlanProcessed(?array $active): void
    {
        $this->scheduleControl($active, 'plan');
        $this->UpdateVisualizationValue(json_encode($this->tileState(), JSON_UNESCAPED_UNICODE));
    }

    // ------------------------------------------------------------------ control hooks

    protected function controlTargets(): array
    {
        return [
            'Mode'             => ['property' => 'TargetModeVariable'],
            'ChargePowerW'     => ['property' => 'TargetChargePowerVariable'],
            'DischargePowerW'  => ['property' => 'TargetDischargePowerVariable'],
            'DischargeAllowed' => ['property' => 'TargetDischargeAllowedVariable'],
            'GridCharge'       => ['property' => 'TargetGridChargeVariable'],
        ];
    }

    protected function modeMapRows(): array
    {
        $rows = [];
        foreach (self::BATTERY_MODE_CAPTIONS as $mode => $caption) {
            $rows[] = ['mode' => $mode, 'caption' => $caption];
        }
        return $rows;
    }

    protected function manualModeOptions(): array
    {
        return array_values(array_filter($this->eosBatteryModeOptions(), static fn (array $o): bool => $o['Value'] !== self::EOS_MODE_UNKNOWN));
    }

    protected function desiredFromInstruction(array $instruction): ?array
    {
        $modeId = (string) ($instruction['operation_mode_id'] ?? '');
        if (!$this->eosBatteryMode($modeId)['known']) {
            return null;
        }
        $state = $this->batteryState($modeId, (float) ($instruction['operation_mode_factor'] ?? 0.0), true);
        $this->plausibilityCheck($instruction, $state);
        return $this->desiredFromState($state, (string) ($instruction['execution_time'] ?? ''));
    }

    protected function desiredFallback(): ?array
    {
        $fallback = $this->ReadPropertyInteger('FallbackMode');
        if ($fallback === self::FALLBACK_NONE) {
            return null;
        }
        return $this->desiredFromState($this->batteryState($this->eosBatteryModeId($fallback) ?? 'SELF_CONSUMPTION', 1.0, true), '');
    }

    protected function desiredManual(int $manualMode): array
    {
        return $this->desiredFromState($this->batteryState($this->eosBatteryModeId($manualMode) ?? 'SELF_CONSUMPTION', 1.0, true), '');
    }

    protected function validateControlDevice(): string
    {
        if ($this->ReadPropertyInteger('TargetChargePowerVariable') > 0 && $this->ReadPropertyInteger('MaxChargePowerW') <= 0) {
            return $this->Translate('Max. charge power must be greater than 0 for a charge power target');
        }
        if ($this->ReadPropertyInteger('TargetDischargePowerVariable') > 0 && $this->ReadPropertyInteger('MaxDischargePowerW') <= 0) {
            return $this->Translate('Max. discharge power must be greater than 0 for a discharge power target');
        }
        return '';
    }

    // ------------------------------------------------------------------ state math

    /**
     * Derived battery state for an EOS mode and factor. With $applyPolicy the
     * control degradations apply: grid charging with 0 W or forbidden -> NON_EXPORT,
     * grid export forbidden -> SELF_CONSUMPTION. The display always shows the raw plan.
     */
    private function batteryState(string $modeId, float $factor, bool $applyPolicy): array
    {
        $factor = (is_nan($factor) || is_infinite($factor)) ? 0.0 : max(0.0, min(1.0, $factor));
        $mode = $this->eosBatteryMode($modeId);
        // EOS factors refer to EOS' own max_charge_power_w; until it is known, the property.
        $eosMax = $this->eosValue('max_charge_power_w');
        $maxCharge = max(0, is_numeric($eosMax) ? (int) round((float) $eosMax) : $this->ReadPropertyInteger('MaxChargePowerW'));
        $maxDischarge = max(0, $this->ReadPropertyInteger('MaxDischargePowerW'));
        $degraded = '';
        if ($applyPolicy && $mode['known']) {
            $id = $mode['id'];
            if ($id === 'GRID_SUPPORT_IMPORT' || $id === 'FORCED_CHARGE') {
                if (!$this->ReadPropertyBoolean('AllowGridCharge')) {
                    $degraded = $id . '→NON_EXPORT (' . $this->Translate('grid charging not allowed') . ')';
                } elseif (round($factor * $maxCharge) < 1) {
                    $degraded = $id . '→NON_EXPORT (0 W)';
                }
                if ($degraded !== '') {
                    $mode = $this->eosBatteryMode('NON_EXPORT');
                }
            } elseif ($id === 'GRID_SUPPORT_EXPORT' && !$this->ReadPropertyBoolean('AllowGridExport')) {
                $degraded = 'GRID_SUPPORT_EXPORT→SELF_CONSUMPTION (' . $this->Translate('grid export not allowed') . ')';
                $mode = $this->eosBatteryMode('SELF_CONSUMPTION');
            }
        }
        $chargeW = $mode['chargeFromFactor'] ? round($factor * $maxCharge) : 0.0;
        if ($mode['id'] === 'GRID_SUPPORT_EXPORT') {
            // The export factor is relative to the rated power EOS plans with, capped by the inverter limit.
            $dischargeW = min((float) $maxDischarge, round($factor * $maxCharge));
        } else {
            $dischargeW = $mode['discharge'] ? (float) $maxDischarge : 0.0;
        }
        return ['mode' => $mode, 'modeRaw' => $mode['id'], 'factor' => $factor, 'chargeW' => $chargeW, 'dischargeW' => $dischargeW, 'degraded' => $degraded];
    }

    private function desiredFromState(array $state, string $executionTime): array
    {
        $mode = $state['mode'];
        return [
            'modeRaw'       => $state['modeRaw'],
            'mode'          => $mode['value'],
            'factor'        => $state['factor'],
            'executionTime' => $executionTime,
            'degraded'      => $state['degraded'],
            'targets'       => [
                'Mode'             => $mode['value'],
                'ChargePowerW'     => $state['chargeW'],
                'DischargePowerW'  => $state['dischargeW'],
                'DischargeAllowed' => $mode['discharge'],
                'GridCharge'       => $mode['grid'],
            ],
            'context'       => [
                'PowerW'           => $state['chargeW'],
                'DischargePowerW'  => $state['dischargeW'],
                'DischargeAllowed' => $mode['discharge'],
                'GridCharge'       => $mode['grid'],
            ],
        ];
    }

    /** Warn once per instruction when the plan contradicts the SoC limits (config drift EOS vs. Symcon). */
    private function plausibilityCheck(array $instruction, array $state): void
    {
        $soc = (float) $this->GetValue('SoCSent') * 100.0;
        if ($soc <= 0.0) {
            return;
        }
        $message = '';
        if ($state['mode']['grid'] && $soc >= $this->ReadPropertyInteger('MaxSoC')) {
            $message = sprintf('SoC %.0f %% is at or above max SoC while %s is planned', $soc, $state['modeRaw']);
        } elseif (in_array($state['modeRaw'], ['GRID_SUPPORT_EXPORT', 'PEAK_SHAVING'], true) && $soc <= $this->ReadPropertyInteger('MinSoC')) {
            $message = sprintf('SoC %.0f %% is at or below min SoC while %s is planned', $soc, $state['modeRaw']);
        }
        $id = (string) ($instruction['execution_time'] ?? '') . '|' . $state['modeRaw']; // EOS ids change with every run
        if ($message !== '' && $id !== $this->ReadAttributeString('PlausibilityWarned')) {
            $this->LogMessage($message . ' - battery limits in EOS and Symcon may differ, press "Write to EOS"', KL_WARNING);
            $this->WriteAttributeString('PlausibilityWarned', $id);
        }
    }

    // ------------------------------------------------------------------ tile data

    /** Cache SoC, price and PV series of the current solution for the tile. */
    private function fetchSolutionSubset(): void
    {
        $id = $this->ReadPropertyString('DeviceID');
        $res = $this->forward(['Command' => 'GetSolution', 'Columns' => [$id . '_soc_factor', 'elec_price_amt_kwh']]);
        if (($res['ok'] ?? false) !== true || !is_array($res['series'] ?? null)) {
            return;
        }
        $subset = [];
        foreach ([$id . '_soc_factor' => 'soc', 'elec_price_amt_kwh' => 'price'] as $col => $key) {
            $subset[$key] = [];
            foreach ($res['series'][$col] ?? [] as $iso => $value) {
                $ts = $this->eosParseTime((string) $iso);
                if ($ts > 0 && $value !== null) {
                    $subset[$key][] = ['ts' => $ts, 'v' => $key === 'soc' ? round((float) $value * 100, 1) : (float) $value];
                }
            }
        }
        $this->WriteAttributeString('SolutionSubset', json_encode($subset));
    }

    private function tileState(): array
    {
        $subset = $this->eosJsonDecode($this->ReadAttributeString('SolutionSubset'), []);
        $soc = is_array($subset['soc'] ?? null) ? $subset['soc'] : [];
        $slot = 900;
        if (count($soc) > 1) {
            $slot = max(60, (int) ($soc[1]['ts'] - $soc[0]['ts']));
        }
        return [
            'device'       => $this->ReadPropertyString('DeviceID'),
            'now'          => $this->eosNow(),
            'slot'         => $slot,
            'modeRaw'      => (string) $this->GetValue('ModeRaw'),
            'factor'       => (float) $this->GetValue('Factor'),
            'targetW'      => (float) $this->GetValue('TargetChargePowerW'),
            'nextChange'   => (int) $this->GetValue('NextChange'),
            'nextMode'     => (string) $this->GetValue('NextMode'),
            'planStale'    => (bool) $this->GetValue('PlanStale'),
            'socSent'      => (float) $this->GetValue('SoCSent'),
            'control'      => [
                'mode'     => $this->ReadPropertyInteger('ControlMode'),
                'active'   => (bool) $this->GetValue('ControlActive'),
                'fallback' => (bool) $this->GetValue('FallbackActive'),
                'manual'   => (int) $this->GetValue('ManualMode'),
                'manualId' => $this->eosBatteryModeId((int) $this->GetValue('ManualMode')) ?? '',
                'result'   => (string) $this->GetValue('LastControlResult'),
                'ts'       => (int) $this->GetValue('LastControl'),
            ],
            'instructions' => array_map(static fn (array $i): array => [
                'ts'     => (int) $i['ts'],
                'mode'   => (string) ($i['operation_mode_id'] ?? ''),
                'factor' => (float) ($i['operation_mode_factor'] ?? 0),
            ], $this->instructionList()),
            'soc'          => $soc,
            'price'        => is_array($subset['price'] ?? null) ? $subset['price'] : [],
        ];
    }
}
