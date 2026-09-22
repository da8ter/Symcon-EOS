<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/EOSCommon.php';
require_once __DIR__ . '/../libs/EOSPlanDevice.php';
require_once __DIR__ . '/../libs/EOSSoCPush.php';
require_once __DIR__ . '/../libs/EOSControlBindings.php';
require_once __DIR__ . '/../libs/EOSControl.php';

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
    use EOSControl;

    private const MODULE_GUID = '{5D0C0E3A-7B1F-4E7A-9C7E-2E6E4B1A8F21}';
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
        $this->RegisterPropertyInteger('TargetSoC', 80);
        $this->RegisterPropertyInteger('StaleAfterMinutes', 180);
        $this->RegisterPropertyInteger('CapacityWh', 60000);
        $this->RegisterPropertyInteger('MaxChargePowerW', 11000);
        $this->RegisterPropertyInteger('Phases', 3);
        $this->RegisterPropertyInteger('Voltage', 230);
        $this->RegisterPropertyInteger('MaxSoC', 100);
        $this->RegisterPropertyFloat('ChargingEfficiency', 0.90);
        $this->RegisterPropertyString('ChargeRates', '0, 0.25, 0.5, 0.75, 1');
        $this->RegisterPropertyInteger('MinChargeCurrentA', 6);
        $this->RegisterPropertyInteger('MinSwitchIntervalSec', 300);
        $this->registerControlProperties(self::CHARGE_NOW);

        $this->registerPlanAttributes();
        $this->RegisterAttributeInteger('RegisteredPluggedVar', 0);
        $this->RegisterAttributeInteger('RegisteredDepartureVar', 0);
        $this->RegisterAttributeString('LastDeadlineSent', '');
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

        $this->setupControl();
        $hasSource = $this->setupSoCSource();
        $this->registerOptionalSource('PluggedSourceVariable', 'RegisteredPluggedVar');
        $this->registerOptionalSource('DepartureSourceVariable', 'RegisteredDepartureVar');
        $deviceId = $this->ReadPropertyString('DeviceID');
        $this->SetTimerInterval('SoCPush', 0);

        if (!$this->validDeviceId($deviceId)) {
            $this->SetStatus(self::STATUS_BAD_DEVICE_ID);
            return;
        }
        if ($this->isDuplicateDeviceId($deviceId)) {
            $this->SetStatus(self::STATUS_DUPLICATE_ID);
            return;
        }
        if (!$hasSource) {
            $this->SetStatus(self::STATUS_NO_SOURCE);
            return;
        }
        if (!$this->parentUsable()) {
            $this->SetStatus(self::STATUS_NO_PARENT);
            return;
        }

        $this->SetStatus(IS_ACTIVE);
        $this->SetTimerInterval('SoCPush', $this->ReadPropertyInteger('PushInterval') * 1000);
        $this->updateDeparture();
        [$path, $device, $merge] = $this->deviceConfig();
        $this->syncDeviceConfig($path, $device, $merge, false);
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
            $this->ProcessPlan();
            if ($this->isPlugged()) {
                $this->PushSoC();
            }
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
        return json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ------------------------------------------------------------------ public API (prefix EOSEV_)

    /** Write departure time (unix timestamp, 0 = none) and target SoC to EOS. */
    public function SetDeparture(int $Timestamp): bool
    {
        $this->SetValue('Departure', max(0, $Timestamp));
        return $this->writeDeadline($Timestamp);
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

    /** Force-write the vehicle parameters to EOS (ApplyChanges does it automatically when they differ). */
    public function WriteConfigToEOS(): bool
    {
        [$path, $device, $merge] = $this->deviceConfig();
        return $this->syncDeviceConfig($path, $device, $merge, true);
    }

    /** [config path, device entry, merge payload] for the EOS configuration. */
    private function deviceConfig(): array
    {
        $id = $this->ReadPropertyString('DeviceID');
        $rates = array_values(array_filter(array_map(
            static fn (string $v): float => (float) trim($v),
            explode(',', $this->ReadPropertyString('ChargeRates'))
        ), static fn (float $v): bool => $v >= 0.0 && $v <= 1.0));
        $ev = [
            'device_id'           => $id,
            'capacity_wh'         => $this->ReadPropertyInteger('CapacityWh'),
            'max_charge_power_w'  => $this->ReadPropertyInteger('MaxChargePowerW'),
            'min_soc_percentage'  => $this->ReadPropertyInteger('TargetSoC'),
            'max_soc_percentage'  => $this->ReadPropertyInteger('MaxSoC'),
            'charging_efficiency' => $this->ReadPropertyFloat('ChargingEfficiency'),
        ];
        if (count($rates) >= 2) {
            sort($rates);
            $ev['charge_rates'] = $rates;
        }
        $departure = (int) $this->GetValue('Departure');
        $ev['min_soc_deadline_datetime'] = $departure > time() ? $this->eosIsoNow($departure) : null;
        return ['devices/electric_vehicles/' . $id, $ev, ['devices' => ['max_electric_vehicles' => 1, 'electric_vehicles' => [$id => $ev]]]];
    }

    public function ReadConfigFromEOS(): bool
    {
        $id = $this->ReadPropertyString('DeviceID');
        $res = $this->forward(['Command' => 'GetConfig', 'Path' => 'devices/electric_vehicles/' . $id]);
        $ev = $res['data'] ?? null;
        if (($res['ok'] ?? false) !== true || !is_array($ev)) {
            $this->UpdateFormField('ConfigInfo', 'caption', sprintf($this->Translate('No vehicle %s in EOS configuration.'), $id));
            return false;
        }
        $map = [
            'CapacityWh'         => ['capacity_wh', 'int'],
            'MaxChargePowerW'    => ['max_charge_power_w', 'int'],
            'TargetSoC'          => ['min_soc_percentage', 'int'],
            'MaxSoC'             => ['max_soc_percentage', 'int'],
            'ChargingEfficiency' => ['charging_efficiency', 'float'],
        ];
        foreach ($map as $field => [$key, $type]) {
            if (isset($ev[$key])) {
                $this->UpdateFormField($field, 'value', $type === 'int' ? (int) $ev[$key] : (float) $ev[$key]);
            }
        }
        if (is_array($ev['charge_rates'] ?? null)) {
            $this->UpdateFormField('ChargeRates', 'value', implode(', ', $ev['charge_rates']));
        }
        $this->UpdateFormField('ConfigInfo', 'caption', $this->Translate('Values from EOS loaded into the form because they differ. Apply stores them in Symcon, Cancel keeps the Symcon values.'));
        return true;
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

    protected function socPushAllowed(): bool
    {
        return !$this->ReadPropertyBoolean('PushOnlyWhenPlugged') || $this->isPlugged();
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
            if ($previous >= 0 && $state['charging'] !== ($previous === 1) && time() - $this->ReadAttributeInteger('LastChargeSwitchTs') < $dwell) {
                $state = $previous === 1 ? $this->vehicleState('FORCED_CHARGE', max($state['factor'], 0.01), true) : $this->vehicleState('IDLE', 0.0, true);
                $degraded = $this->Translate('switch held back (minimum interval)');
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

    protected function onDispatched(array $desired, bool $sim): void
    {
        $charging = !empty($desired['targets']['ChargeAllowed']) ? 1 : 0;
        if ($this->ReadAttributeInteger('LastChargeState') !== $charging) {
            $this->WriteAttributeInteger('LastChargeState', $charging);
            $this->WriteAttributeInteger('LastChargeSwitchTs', time());
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

    /** Read the departure source variable (unix timestamp) and sync it to EOS if changed. */
    private function updateDeparture(): void
    {
        $var = $this->ReadPropertyInteger('DepartureSourceVariable');
        if ($var <= 0 || !IPS_VariableExists($var)) {
            return;
        }
        $ts = (int) GetValue($var);
        $this->SetValue('Departure', max(0, $ts));
        if ($this->ReadPropertyBoolean('SyncDepartureToEOS')) {
            $this->writeDeadline($ts);
        }
    }

    private function writeDeadline(int $ts): bool
    {
        if (!$this->parentUsable()) {
            return false;
        }
        $deadline = $ts > time() ? $this->eosIsoNow($ts) : '';
        if ($deadline === $this->ReadAttributeString('LastDeadlineSent')) {
            return true;
        }
        $id = $this->ReadPropertyString('DeviceID');
        $res = $this->forward(['Command' => 'MergeConfig', 'Value' => ['devices' => ['electric_vehicles' => [$id => [
            'device_id'                 => $id,
            'min_soc_deadline_datetime' => $deadline !== '' ? $deadline : null,
            'min_soc_percentage'        => $this->ReadPropertyInteger('TargetSoC'),
        ]]]]]);
        if (($res['ok'] ?? false) !== true) {
            $this->LogMessage('EOS departure update failed: ' . (string) ($res['error'] ?? '?'), KL_WARNING);
            return false;
        }
        $this->WriteAttributeString('LastDeadlineSent', $deadline);
        $this->SendDebug('Departure', $deadline !== '' ? $deadline : 'none', 0);
        return true;
    }
}
