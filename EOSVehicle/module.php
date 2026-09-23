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
require_once __DIR__ . '/../libs/EOSDeviceTimes.php';
require_once __DIR__ . '/../libs/EOSVehicleConfig.php';

/**
 * EOS Vehicle: the battery of an electric vehicle known to EOS.
 *
 * Pushes the vehicle SoC, keeps departure time and target SoC in the EOS
 * device configuration, shows the planned charging instruction (charge power
 * and current for the wallbox) and - with a control mode enabled - drives the
 * wallbox through vendor-neutral bindings (EOSControl).
 */
class EOSVehicle extends IPSModuleStrict
{
    use EOSCommon;
    use EOSPlanDevice;
    use EOSSoCPush;
    use EOSControlBindings;
    use EOSFormHelpers;
    use EOSControl;
    use EOSControlDispatch;
    use EOSDeviceConfigSync;
    use EOSDeviceConfigForm;
    use EOSDeviceTimes;
    use EOSVehicleConfig;

    private const MODULE_GUID = '{5D0C0E3A-7B1F-4E7A-9C7E-2E6E4B1A8F21}';
    /** Device map in the EOS configuration; GENETIC supports only one battery and one vehicle. */
    private const DEVICE_COLLECTION = 'devices/electric_vehicles';
    private const SINGLE_DEVICE = true;
    /** Configuration properties sent to EOS, with their defaults (also the base of a first sync). */
    private const CONFIG_DEFAULTS = ['TargetSoC' => 80, 'CapacityWh' => 60000, 'MaxChargePowerW' => 11000, 'MaxSoC' => 100, 'ChargingEfficiency' => 0.90, 'ChargeRates' => '0, 0.25, 0.5, 0.75, 1'];
    /** Stopped and released when the device id is invalid or not ours (blockDevice()). */
    private const BLOCK_TIMERS = ['SoCPush', 'SlotTimer', 'Watchdog', 'Retry', 'DeadlineExpiry'];
    private const SOURCE_ATTRIBUTES = ['RegisteredSoCVar', 'RegisteredPluggedVar', 'RegisteredDepartureVar'];
    private const CONTROL_PREFIX = 'EOSEV';
    /** Manual / fallback values reuse the battery enumeration: 0 = no charging, 5 = charge at max power. */
    private const CHARGE_OFF = 0;
    private const CHARGE_NOW = 5;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('DeviceID', 'ev1');
        $this->registerSoCProperties();
        $this->RegisterPropertyInteger('PluggedSourceVariable', 0);
        $this->RegisterPropertyBoolean('PushOnlyWhenPlugged', false);
        $this->RegisterPropertyInteger('DepartureSourceVariable', 0);
        $this->RegisterPropertyBoolean('SyncDepartureToEOS', true);
        $this->registerConfigProperties();
        $this->RegisterPropertyInteger('StaleAfterMinutes', 180);
        $this->RegisterPropertyInteger('Phases', 3);
        $this->RegisterPropertyInteger('Voltage', 230);
        $this->RegisterPropertyInteger('MinChargeCurrentA', 6);
        $this->RegisterPropertyInteger('MinSwitchIntervalSec', 300);
        $this->registerControlProperties(self::CHARGE_NOW);

        $this->registerPlanAttributes();
        $this->RegisterAttributeInteger('RegisteredPluggedVar', 0);
        $this->RegisterAttributeInteger('RegisteredDepartureVar', 0);
        $this->RegisterAttributeString('LastPluggedSoC', '');
        $this->RegisterAttributeString('TargetSoCNote', '');
        $this->RegisterAttributeString('LastChargeDesired', '{}');
        $this->RegisterAttributeBoolean('DepartureByScript', false);
        $this->RegisterAttributeString('PastDeadlineWarned', '');
        $this->RegisterAttributeInteger('LastChargeState', -1);
        $this->RegisterAttributeInteger('LastChargeSwitchTs', 0);

        $this->RegisterVariableInteger('Mode', $this->Translate('Operation mode'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS'      => json_encode($this->eosBatteryModeOptions(), JSON_UNESCAPED_UNICODE),
        ], 10);
        $this->RegisterVariableString('ModeRaw', $this->Translate('Operation mode (raw)'), $this->eosValuePresentation('Information'), 20);
        $this->RegisterVariableBoolean('ChargingPlanned', $this->Translate('Charging planned'), $this->eosBoolPresentation('No', 'Yes', 0x808080, 0x4A3AA7, 'Plug'), 30);
        $this->RegisterVariableFloat('Factor', $this->Translate('Factor'), $this->eosValuePresentation('Gauge', '', 2), 40);
        $this->RegisterVariableFloat('TargetChargePowerW', $this->Translate('Target charge power'), $this->eosValuePresentation('Electricity', ' W', 0), 50);
        $this->RegisterVariableFloat('TargetChargeCurrentA', $this->Translate('Target charge current'), $this->eosValuePresentation('Electricity', ' A', 1), 60);
        $this->RegisterVariableInteger('Departure', $this->Translate('Departure'), $this->eosDateTimePresentation('Car'), 70);
        $this->registerPlanVariables(80);
        $this->registerSoCVariables(120);
        $this->registerControlVariables(200);

        $this->RegisterTimer('SoCPush', 0, 'EOSEV_PushSoC($_IPS[\'TARGET\']);');
        $this->RegisterTimer('SlotTimer', 0, 'EOSEV_ProcessPlan($_IPS[\'TARGET\']);');
        // A passed departure must leave EOS at once (EOS charges immediately for a past deadline).
        $this->RegisterTimer('DeadlineExpiry', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'ReconcileDeparture\', \'\');');
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
        // An id that is invalid or used by another instance blocks everything (see EOSBattery).
        if (!$this->validDeviceId($deviceId)) {
            $this->blockDevice(self::STATUS_BAD_DEVICE_ID);
            return;
        }
        if ($this->deviceIdTaken($deviceId)) {
            $this->blockDevice(self::STATUS_DUPLICATE_ID);
            return;
        }
        $wasBlocked = $this->deviceBlocked(); // e.g. 205 from the last Apply: no release under that status
        $this->SetStatus(IS_ACTIVE);
        $this->setupControl(!$wasBlocked);
        $hasSource = $this->setupSoCSource();
        $this->registerOptionalSource('PluggedSourceVariable', 'RegisteredPluggedVar');
        $this->registerOptionalSource('DepartureSourceVariable', 'RegisteredDepartureVar');
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
        if ($this->ReadPropertyInteger('MaxSoC') <= 0) {
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
        $this->updateDeparture();
        $this->PushSoC();
        $this->RefreshPlan();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }
        if ($Message !== VM_UPDATE) {
            return;
        }
        if ($SenderID === $this->ReadPropertyInteger('DepartureSourceVariable') && $SenderID > 0) {
            $this->updateDeparture();
            return;
        }
        if ($SenderID === $this->ReadPropertyInteger('PluggedSourceVariable') && $SenderID > 0) {
            if (!$this->eosValueChanged($Data)) {
                return; // an update without a change of the plug state changes nothing
            }
            $this->ProcessPlan();
            if ($this->isPlugged()) {
                $this->PushSoC();
            }
            return;
        }
        $this->handleSoCMessage($SenderID, $Message, $Data);
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'ReconcileDeparture') {
            $this->reconcileDeparture();
            return;
        }
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

    // ------------------------------------------------------------------ public API (prefix EOSEV_)

    /** Write departure time (unix timestamp, 0 = none) and target SoC to EOS. */
    public function SetDeparture(int $Timestamp): bool
    {
        $this->SetValue('Departure', max(0, $Timestamp));
        $this->WriteAttributeBoolean('DepartureByScript', true);
        return $this->reconcileDeparture();
    }

    public function Dispatch(): void
    {
        $this->runDispatch();
    }

    public function Watchdog(): void
    {
        $this->runWatchdog();
    }

    public function ApplyControl(bool $Force): bool
    {
        return $this->applyControlNow($Force);
    }

    /** Manual override: 0 = charging off, 5 = charge now, 100 = automatic. */
    public function SetManualMode(int $Mode): void
    {
        $this->changeManualMode($Mode);
    }

    public function GetControlState(): string
    {
        return json_encode($this->controlState(), JSON_UNESCAPED_UNICODE);
    }

    // ------------------------------------------------------------------ plan hooks (display)

    protected function showInstruction(array $instruction): void
    {
        $modeId = (string) ($instruction['operation_mode_id'] ?? '');
        $state = $this->vehicleState($modeId, (float) ($instruction['operation_mode_factor'] ?? 0.0), false);
        if (!$state['mode']['known'] && $modeId !== (string) $this->GetValue('ModeRaw')) {
            $this->LogMessage(sprintf($this->Translate('Unknown operation mode %s'), $modeId), KL_WARNING);
        }
        $this->SetValue('Mode', $state['mode']['value']);
        $this->SetValue('ModeRaw', $modeId);
        $this->SetValue('Factor', $state['factor']);
        $this->SetValue('ChargingPlanned', $state['charging']);
        $this->SetValue('TargetChargePowerW', $state['powerW']);
        $this->SetValue('TargetChargeCurrentA', $state['currentA']);
    }

    protected function showNoInstruction(): void
    {
        $this->SetValue('Mode', self::EOS_MODE_UNKNOWN);
        $this->SetValue('ModeRaw', '');
        $this->SetValue('Factor', 0.0);
        $this->SetValue('ChargingPlanned', false);
        $this->SetValue('TargetChargePowerW', 0.0);
        $this->SetValue('TargetChargeCurrentA', 0.0);
    }

    protected function onPlanProcessed(?array $active): void
    {
        $this->scheduleControl($active, 'plan');
    }

    /**
     * "Only while plugged in": the source is trusted only while the car is plugged in,
     * but EOS still needs a fresh vehicle SoC in every record (else every run aborts),
     * so an unplugged car keeps reporting its last plugged value.
     */
    /** Every SoC push also keeps the departure in EOS current (source changes, EOS restarts, passed times). */
    protected function afterSoCPush(): void
    {
        $this->updateDeparture();
    }

    protected function socValueForPush(float $factor): float
    {
        if (!$this->ReadPropertyBoolean('PushOnlyWhenPlugged')) {
            return $factor;
        }
        if ($this->isPlugged()) {
            $this->WriteAttributeString('LastPluggedSoC', (string) $factor);
            return $factor;
        }
        $last = $this->ReadAttributeString('LastPluggedSoC');
        return $last !== '' ? (float) $last : $factor;
    }

    // ------------------------------------------------------------------ control hooks

    protected function controlTargets(): array
    {
        return [
            'Mode'          => ['property' => 'TargetModeVariable'],
            'ChargeAllowed' => ['property' => 'TargetChargeAllowedVariable'],
            'CurrentA'      => ['property' => 'TargetCurrentVariable'],
            'PowerW'        => ['property' => 'TargetPowerVariable'],
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
        return [
            ['Value' => self::CHARGE_OFF, 'Caption' => $this->Translate('Charging off'), 'Icon' => 'Power', 'Color' => 0x9A9A94, 'IconActive' => true],
            ['Value' => self::CHARGE_NOW, 'Caption' => $this->Translate('Charge now'), 'Icon' => 'Lightning', 'Color' => 0xE34948, 'IconActive' => true],
        ];
    }

    /**
     * Plan instruction -> desired wallbox state. Unplugged (with a plug variable)
     * means "off"; on/off flapping inside MinSwitchIntervalSec keeps the previous state.
     */
    protected function desiredFromInstruction(array $instruction): ?array
    {
        $modeId = (string) ($instruction['operation_mode_id'] ?? '');
        if (!$this->eosBatteryMode($modeId)['known']) {
            return null;
        }
        $state = $this->vehicleState($modeId, (float) ($instruction['operation_mode_factor'] ?? 0.0), true);
        $degraded = '';
        if ($this->ReadPropertyInteger('PluggedSourceVariable') > 0 && !$this->isPlugged()) {
            $state = $this->vehicleState('IDLE', 0.0, true);
            $degraded = $this->Translate('unplugged');
        } else {
            $previous = $this->ReadAttributeInteger('LastChargeState');
            $dwell = $this->ReadPropertyInteger('MinSwitchIntervalSec');
            if ($previous >= 0 && $state['charging'] !== ($previous === 1) && $this->eosNow() - $this->ReadAttributeInteger('LastChargeSwitchTs') < $dwell) {
                if ($previous === 0) {
                    $state = $this->vehicleState('IDLE', 0.0, true);
                    $degraded = $this->Translate('switch held back (minimum interval)');
                } else {
                    // Keep charging exactly as last written (EOS sends IDLE with factor 1.0, so a
                    // state rebuilt from it would charge at full power under a new mode).
                    $held = $this->eosJsonDecode($this->ReadAttributeString('LastChargeDesired'), []);
                    if (is_array($held) && isset($held['targets'])) {
                        $held['degraded'] = $this->Translate('switch held back (minimum interval)');
                        return $held;
                    }
                }
            }
        }
        return $this->desiredFromVehicleState($state, (string) ($instruction['execution_time'] ?? ''), $degraded);
    }

    protected function desiredFallback(): ?array
    {
        $fallback = $this->ReadPropertyInteger('FallbackMode');
        if ($fallback === self::FALLBACK_NONE) {
            return null;
        }
        return $this->desiredManual($fallback);
    }

    protected function desiredManual(int $manualMode): array
    {
        $state = $manualMode === self::CHARGE_NOW ? $this->vehicleState('FORCED_CHARGE', 1.0, true) : $this->vehicleState('IDLE', 0.0, true);
        return $this->desiredFromVehicleState($state, '', '');
    }

    /** Dwell clock and held state follow the charge binding (ChargeAllowed, else current/power/mode). */
    protected function onDispatched(array $desired, array $outcome): void
    {
        $keys = isset($outcome['targets']['ChargeAllowed']) ? ['ChargeAllowed'] : array_values(array_intersect(['CurrentA', 'PowerW', 'Mode'], array_keys($outcome['targets'])));
        foreach ($keys as $key) {
            if ($outcome['targets'][$key] !== 'ok' && $outcome['targets'][$key] !== 'same') {
                return; // the wallbox did not take the new state; keep the dwell clock as it was
            }
        }
        if ($keys === [] && !$outcome['success']) {
            return;
        }
        $charging = !empty($desired['targets']['ChargeAllowed']) ? 1 : 0;
        if ($charging === 1) {
            $this->WriteAttributeString('LastChargeDesired', json_encode($desired));
        }
        if ($this->ReadAttributeInteger('LastChargeState') !== $charging) {
            $this->WriteAttributeInteger('LastChargeState', $charging);
            $this->WriteAttributeInteger('LastChargeSwitchTs', $this->eosNow());
        }
    }

    protected function validateControlDevice(): string
    {
        $bound = $this->ReadPropertyInteger('TargetCurrentVariable') > 0 || $this->ReadPropertyInteger('TargetPowerVariable') > 0;
        if ($bound && $this->ReadPropertyInteger('MaxChargePowerW') <= 0) {
            return $this->Translate('Max. charge power must be greater than 0 for a current or power target');
        }
        return '';
    }

    // ------------------------------------------------------------------ state math

    /** Any charging-type mode with a factor > 0 means: charge the car at factor × max power. */
    private function vehicleState(string $modeId, float $factor, bool $forControl): array
    {
        $factor = (is_nan($factor) || is_infinite($factor)) ? 0.0 : max(0.0, min(1.0, $factor));
        $mode = $this->eosBatteryMode($modeId);
        $charging = $factor > 0.0 && $mode['id'] !== 'IDLE' && ($mode['grid'] || $mode['chargeFromFactor'] || !$mode['known']);
        $maxW = max(0, $this->ReadPropertyInteger('MaxChargePowerW'));
        $phases = max(1, $this->ReadPropertyInteger('Phases'));
        $voltage = max(100, $this->ReadPropertyInteger('Voltage'));
        $powerW = $charging ? round($factor * $maxW) : 0.0;
        $currentA = $charging ? round($powerW / ($phases * $voltage), 1) : 0.0;
        if ($forControl && $charging) {
            // Below the wallbox minimum the car would not charge at all; EOS wanted charging.
            $min = max(0, $this->ReadPropertyInteger('MinChargeCurrentA'));
            if ($currentA < $min) {
                $currentA = (float) $min;
                $powerW = round($min * $phases * $voltage);
            }
        }
        return ['mode' => $mode, 'factor' => $factor, 'charging' => $charging, 'powerW' => $powerW, 'currentA' => $currentA];
    }

    private function desiredFromVehicleState(array $state, string $executionTime, string $degraded): array
    {
        $mode = $state['mode'];
        return [
            'modeRaw'       => $state['charging'] ? $mode['id'] : 'IDLE',
            'mode'          => $state['charging'] ? $mode['value'] : self::CHARGE_OFF,
            'factor'        => $state['factor'],
            'executionTime' => $executionTime,
            'degraded'      => $degraded,
            'targets'       => [
                'Mode'          => $mode['value'],
                'ChargeAllowed' => $state['charging'],
                'CurrentA'      => $state['currentA'],
                'PowerW'        => $state['powerW'],
            ],
            'context'       => [
                'ChargeAllowed' => $state['charging'],
                'CurrentA'      => $state['currentA'],
                'PowerW'        => $state['powerW'],
                'Plugged'       => $this->isPlugged(),
            ],
        ];
    }

    // ------------------------------------------------------------------ internals

    private function isPlugged(): bool
    {
        $var = $this->ReadPropertyInteger('PluggedSourceVariable');
        if ($var <= 0 || !IPS_VariableExists($var)) {
            return true; // unknown: assume plugged in
        }
        return (bool) GetValue($var);
    }

}
