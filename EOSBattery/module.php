<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/EOSCommon.php';
require_once __DIR__ . '/../libs/EOSPlanDevice.php';
require_once __DIR__ . '/../libs/EOSSoCPush.php';

/**
 * EOS Battery: represents one stationary battery known to EOS.
 *
 * Pushes the state of charge to EOS through the EOS Server splitter and
 * shows the currently active planner instruction (operation mode, factor,
 * target power) plus the next scheduled change. Phase 1 displays only;
 * ApplyInstruction() is the hook for later control modes.
 */
class EOSBattery extends IPSModuleStrict
{
    use EOSCommon;
    use EOSPlanDevice;
    use EOSSoCPush;

    private const MODULE_GUID = '{F4B30383-1210-4169-93DA-5C9664447B42}';

    public function Create(): void
    {
        parent::Create();
        $this->SetVisualizationType(1);

        $this->RegisterPropertyString('DeviceID', 'battery1');
        $this->registerSoCProperties();
        $this->RegisterPropertyInteger('StaleAfterMinutes', 180);
        $this->RegisterPropertyInteger('ControlMode', 0);
        $this->RegisterPropertyInteger('CapacityWh', 10000);
        $this->RegisterPropertyInteger('MaxChargePowerW', 5000);
        $this->RegisterPropertyInteger('MaxDischargePowerW', 5000);
        $this->RegisterPropertyInteger('MinSoC', 10);
        $this->RegisterPropertyInteger('MaxSoC', 95);
        $this->RegisterPropertyFloat('ChargingEfficiency', 0.95);
        $this->RegisterPropertyFloat('DischargingEfficiency', 0.95);
        $this->RegisterPropertyFloat('LcosAmtKwh', 0.0);

        $this->registerPlanAttributes();
        $this->RegisterAttributeString('SolutionSubset', '{}');

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

        $this->RegisterTimer('SoCPush', 0, 'EOSBAT_PushSoC($_IPS[\'TARGET\']);');
        $this->RegisterTimer('SlotTimer', 0, 'EOSBAT_ProcessPlan($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        $hasSource = $this->setupSoCSource();
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

    // ------------------------------------------------------------------ device configuration in EOS

    public function WriteConfigToEOS(): bool
    {
        $id = $this->ReadPropertyString('DeviceID');
        $battery = [
            'device_id'                         => $id,
            'capacity_wh'                       => $this->ReadPropertyInteger('CapacityWh'),
            'max_charge_power_w'                => $this->ReadPropertyInteger('MaxChargePowerW'),
            'min_soc_percentage'                => $this->ReadPropertyInteger('MinSoC'),
            'max_soc_percentage'                => $this->ReadPropertyInteger('MaxSoC'),
            'charging_efficiency'               => $this->ReadPropertyFloat('ChargingEfficiency'),
            'discharging_efficiency'            => $this->ReadPropertyFloat('DischargingEfficiency'),
            'levelized_cost_of_storage_amt_kwh' => $this->ReadPropertyFloat('LcosAmtKwh'),
        ];
        $res = $this->forward(['Command' => 'MergeConfig', 'Value' => ['devices' => ['batteries' => [$id => $battery]]]]);
        if (($res['ok'] ?? false) !== true) {
            $this->UpdateFormField('ConfigInfo', 'caption', (string) ($res['error'] ?? '?'));
            return false;
        }
        $save = $this->forward(['Command' => 'SaveConfig']);
        $this->UpdateFormField('ConfigInfo', 'caption', ($save['ok'] ?? false) ? $this->Translate('Battery configuration written to EOS.') : (string) ($save['error'] ?? '?'));
        return (bool) ($save['ok'] ?? false);
    }

    public function ReadConfigFromEOS(): bool
    {
        $id = $this->ReadPropertyString('DeviceID');
        $res = $this->forward(['Command' => 'GetConfig', 'Path' => 'devices/batteries/' . $id]);
        $bat = $res['data'] ?? null;
        if (($res['ok'] ?? false) !== true || !is_array($bat)) {
            $this->UpdateFormField('ConfigInfo', 'caption', sprintf($this->Translate('No battery %s in EOS configuration.'), $id));
            return false;
        }
        $map = [
            'CapacityWh'            => ['capacity_wh', 'int'],
            'MaxChargePowerW'       => ['max_charge_power_w', 'int'],
            'MinSoC'                => ['min_soc_percentage', 'int'],
            'MaxSoC'                => ['max_soc_percentage', 'int'],
            'ChargingEfficiency'    => ['charging_efficiency', 'float'],
            'DischargingEfficiency' => ['discharging_efficiency', 'float'],
            'LcosAmtKwh'            => ['levelized_cost_of_storage_amt_kwh', 'float'],
        ];
        foreach ($map as $field => [$key, $type]) {
            if (isset($bat[$key])) {
                $this->UpdateFormField($field, 'value', $type === 'int' ? (int) $bat[$key] : (float) $bat[$key]);
            }
        }
        $this->UpdateFormField('ConfigInfo', 'caption', $this->Translate('Battery configuration loaded from EOS. Press Apply to store.'));
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
        $this->SetValue('Mode', $mode['value']);
        $this->SetValue('ModeRaw', $modeId);
        $this->SetValue('Factor', $factor);
        $this->SetValue('DischargeAllowed', $mode['discharge']);
        $this->SetValue('GridChargeActive', $mode['grid']);
        $this->SetValue('TargetChargePowerW', $mode['chargeFromFactor'] ? round($factor * $this->ReadPropertyInteger('MaxChargePowerW')) : 0.0);
    }

    protected function showNoInstruction(): void
    {
        $this->SetValue('Mode', self::EOS_MODE_UNKNOWN);
        $this->SetValue('ModeRaw', '');
    }

    protected function onPlanStored(): void
    {
        $this->fetchSolutionSubset();
    }

    protected function onPlanProcessed(?array $active): void
    {
        $this->ApplyInstruction($active);
        $this->UpdateVisualizationValue(json_encode($this->tileState(), JSON_UNESCAPED_UNICODE));
    }

    /** Control hook (phase 2). ControlMode 0 = display only. */
    protected function ApplyInstruction(?array $instruction): void
    {
        if ($this->ReadPropertyInteger('ControlMode') === 0) {
            return;
        }
    }

    // ------------------------------------------------------------------ tile data

    /** Cache SoC, price and PV series of the current solution for the tile. */
    private function fetchSolutionSubset(): void
    {
        $id = $this->ReadPropertyString('DeviceID');
        $res = $this->forward(['Command' => 'GetSolution', 'Columns' => [$id . '_soc_factor', 'elec_price_amt_kwh', 'pvforecast_ac_energy_wh']]);
        if (($res['ok'] ?? false) !== true || !is_array($res['series'] ?? null)) {
            return;
        }
        $subset = [];
        foreach ([$id . '_soc_factor' => 'soc', 'elec_price_amt_kwh' => 'price', 'pvforecast_ac_energy_wh' => 'pv'] as $col => $key) {
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
            'now'          => time(),
            'slot'         => $slot,
            'modeRaw'      => (string) $this->GetValue('ModeRaw'),
            'factor'       => (float) $this->GetValue('Factor'),
            'targetW'      => (float) $this->GetValue('TargetChargePowerW'),
            'nextChange'   => (int) $this->GetValue('NextChange'),
            'nextMode'     => (string) $this->GetValue('NextMode'),
            'planStale'    => (bool) $this->GetValue('PlanStale'),
            'socSent'      => (float) $this->GetValue('SoCSent'),
            'instructions' => array_map(static fn (array $i): array => [
                'ts'     => (int) $i['ts'],
                'mode'   => (string) ($i['operation_mode_id'] ?? ''),
                'factor' => (float) ($i['operation_mode_factor'] ?? 0),
            ], $this->instructionList()),
            'soc'          => $soc,
            'price'        => is_array($subset['price'] ?? null) ? $subset['price'] : [],
            'pv'           => is_array($subset['pv'] ?? null) ? $subset['pv'] : [],
        ];
    }
}
