<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/EOSCommon.php';
require_once __DIR__ . '/../libs/EOSPlanDevice.php';
require_once __DIR__ . '/../libs/EOSSoCPush.php';

/**
 * EOS Vehicle: the battery of an electric vehicle known to EOS.
 *
 * Pushes the vehicle SoC, keeps departure time and target SoC in the EOS
 * device configuration and shows the planned charging instruction (charge
 * power and current for the wallbox). Display only; ApplyInstruction() is the
 * hook for later control modes.
 */
class EOSVehicle extends IPSModuleStrict
{
    use EOSCommon;
    use EOSPlanDevice;
    use EOSSoCPush;

    private const MODULE_GUID = '{5D0C0E3A-7B1F-4E7A-9C7E-2E6E4B1A8F21}';

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
        $this->RegisterPropertyInteger('ControlMode', 0);
        $this->RegisterPropertyInteger('CapacityWh', 60000);
        $this->RegisterPropertyInteger('MaxChargePowerW', 11000);
        $this->RegisterPropertyInteger('Phases', 3);
        $this->RegisterPropertyInteger('Voltage', 230);
        $this->RegisterPropertyInteger('MaxSoC', 100);
        $this->RegisterPropertyFloat('ChargingEfficiency', 0.90);
        $this->RegisterPropertyString('ChargeRates', '0, 0.25, 0.5, 0.75, 1');

        $this->registerPlanAttributes();
        $this->RegisterAttributeInteger('RegisteredPluggedVar', 0);
        $this->RegisterAttributeInteger('RegisteredDepartureVar', 0);
        $this->RegisterAttributeString('LastDeadlineSent', '');

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

        $this->RegisterTimer('SoCPush', 0, 'EOSEV_PushSoC($_IPS[\'TARGET\']);');
        $this->RegisterTimer('SlotTimer', 0, 'EOSEV_ProcessPlan($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

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

    // ------------------------------------------------------------------ public API (prefix EOSEV_)

    /** Write departure time (unix timestamp, 0 = none) and target SoC to EOS. */
    public function SetDeparture(int $Timestamp): bool
    {
        $this->SetValue('Departure', max(0, $Timestamp));
        return $this->writeDeadline($Timestamp);
    }

    public function WriteConfigToEOS(): bool
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

        $res = $this->forward(['Command' => 'MergeConfig', 'Value' => ['devices' => ['max_electric_vehicles' => 1, 'electric_vehicles' => [$id => $ev]]]]);
        if (($res['ok'] ?? false) !== true) {
            $this->UpdateFormField('ConfigInfo', 'caption', (string) ($res['error'] ?? '?'));
            return false;
        }
        $save = $this->forward(['Command' => 'SaveConfig']);
        $this->UpdateFormField('ConfigInfo', 'caption', ($save['ok'] ?? false) ? $this->Translate('Vehicle configuration written to EOS.') : (string) ($save['error'] ?? '?'));
        return (bool) ($save['ok'] ?? false);
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
        $this->UpdateFormField('ConfigInfo', 'caption', $this->Translate('Vehicle configuration loaded from EOS. Press Apply to store.'));
        return true;
    }

    // ------------------------------------------------------------------ plan hooks

    protected function showInstruction(array $instruction): void
    {
        $modeId = (string) ($instruction['operation_mode_id'] ?? '');
        $factor = max(0.0, min(1.0, (float) ($instruction['operation_mode_factor'] ?? 0.0)));
        $mode = $this->eosBatteryMode($modeId);
        if (!$mode['known'] && $modeId !== (string) $this->GetValue('ModeRaw')) {
            $this->LogMessage(sprintf($this->Translate('Unknown operation mode %s'), $modeId), KL_WARNING);
        }
        // Any charging-type mode with a factor > 0 means: charge the car at factor × max power.
        $charging = $factor > 0.0 && $mode['id'] !== 'IDLE' && ($mode['grid'] || $mode['chargeFromFactor'] || !$mode['known']);
        $powerW = $charging ? round($factor * $this->ReadPropertyInteger('MaxChargePowerW')) : 0.0;
        $phases = max(1, $this->ReadPropertyInteger('Phases'));
        $voltage = max(100, $this->ReadPropertyInteger('Voltage'));
        $this->SetValue('Mode', $mode['value']);
        $this->SetValue('ModeRaw', $modeId);
        $this->SetValue('Factor', $factor);
        $this->SetValue('ChargingPlanned', $charging);
        $this->SetValue('TargetChargePowerW', $powerW);
        $this->SetValue('TargetChargeCurrentA', $charging ? round($powerW / ($phases * $voltage), 1) : 0.0);
    }

    protected function showNoInstruction(): void
    {
        $this->SetValue('Mode', self::EOS_MODE_UNKNOWN);
        $this->SetValue('ModeRaw', '');
        $this->SetValue('ChargingPlanned', false);
        $this->SetValue('TargetChargePowerW', 0.0);
        $this->SetValue('TargetChargeCurrentA', 0.0);
    }

    protected function onPlanProcessed(?array $active): void
    {
        if ($this->ReadPropertyInteger('ControlMode') !== 0) {
            // Phase 2: control hook.
        }
    }

    protected function socPushAllowed(): bool
    {
        return !$this->ReadPropertyBoolean('PushOnlyWhenPlugged') || $this->isPlugged();
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

    private function registerOptionalSource(string $property, string $attribute): void
    {
        $old = $this->ReadAttributeInteger($attribute);
        $src = $this->ReadPropertyInteger($property);
        if ($old > 0 && $old !== $src) {
            $this->UnregisterMessage($old, VM_UPDATE);
        }
        if ($src > 0 && IPS_VariableExists($src)) {
            $this->RegisterMessage($src, VM_UPDATE);
            $this->WriteAttributeInteger($attribute, $src);
        } else {
            $this->WriteAttributeInteger($attribute, 0);
        }
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
