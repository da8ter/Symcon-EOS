<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/EOSCommon.php';

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

    private const MODULE_GUID = '{F4B30383-1210-4169-93DA-5C9664447B42}';
    private const STATUS_NO_PARENT = 104;
    private const STATUS_BAD_DEVICE_ID = 201;
    private const STATUS_NO_SOC_SOURCE = 202;
    private const STATUS_DUPLICATE_ID = 203;
    /** Never sleep longer than this before re-evaluating the plan (ms). */
    private const MAX_SLOT_TIMER_MS = 6 * 3600 * 1000;

    public function GetCompatibleParents(): string
    {
        return json_encode(['type' => 'connect', 'moduleIDs' => [self::EOS_SERVER_GUID]]);
    }

    public function Create(): void
    {
        parent::Create();
        $this->SetVisualizationType(1);

        $this->RegisterPropertyString('DeviceID', 'battery1');
        $this->RegisterPropertyInteger('SoCSourceVariable', 0);
        $this->RegisterPropertyInteger('SoCUnit', 0);
        $this->RegisterPropertyInteger('PushInterval', 120);
        $this->RegisterPropertyInteger('PushDebounce', 10);
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

        $this->RegisterAttributeString('Instructions', '[]');
        $this->RegisterAttributeString('PlanMeta', '{}');
        $this->RegisterAttributeString('SolutionSubset', '{}');
        $this->RegisterAttributeInteger('LastPushTs', 0);
        $this->RegisterAttributeInteger('RegisteredSoCVar', 0);
        $this->RegisterAttributeBoolean('EmptyPlanWarned', false);

        $this->RegisterVariableInteger('Mode', $this->Translate('Operation mode'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS'      => json_encode($this->eosBatteryModeOptions(), JSON_UNESCAPED_UNICODE),
        ], 10);
        $this->RegisterVariableString('ModeRaw', $this->Translate('Operation mode (raw)'), $this->eosValuePresentation('Information'), 20);
        $this->RegisterVariableFloat('Factor', $this->Translate('Factor'), $this->eosValuePresentation('Gauge', '', 2), 30);
        $this->RegisterVariableFloat('TargetChargePowerW', $this->Translate('Target charge power'), $this->eosValuePresentation('Electricity', ' W', 0), 40);
        $this->RegisterVariableBoolean('DischargeAllowed', $this->Translate('Discharge allowed'), $this->eosBoolPresentation('No', 'Yes', 0x808080, 0x00A000, 'HollowArrowDown'), 50);
        $this->RegisterVariableBoolean('GridChargeActive', $this->Translate('Grid charging active'), $this->eosBoolPresentation('No', 'Yes', 0x808080, 0xC000C0, 'Plug'), 60);
        $this->RegisterVariableInteger('NextChange', $this->Translate('Next change'), $this->eosDateTimePresentation(), 70);
        $this->RegisterVariableString('NextMode', $this->Translate('Next mode'), $this->eosValuePresentation('HollowArrowRight'), 80);
        $this->RegisterVariableBoolean('PlanStale', $this->Translate('Plan stale'), $this->eosBoolPresentation('Current', 'Stale', 0x00A000, 0xFF0000, 'Warning'), 90);
        $this->RegisterVariableFloat('SoCSent', $this->Translate('SoC sent'), $this->eosValuePresentation('Battery', '', 3), 100);
        $this->RegisterVariableInteger('LastPush', $this->Translate('Last push'), $this->eosDateTimePresentation('Repeat'), 110);
        $this->RegisterVariableString('PlanJSON', $this->Translate('Plan (JSON)'), $this->eosValuePresentation('Script'), 120);

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

        // (Re-)register the SoC source variable for change messages.
        $old = $this->ReadAttributeInteger('RegisteredSoCVar');
        $src = $this->ReadPropertyInteger('SoCSourceVariable');
        if ($old > 0 && $old !== $src) {
            $this->UnregisterMessage($old, VM_UPDATE);
        }
        if ($src > 0 && IPS_VariableExists($src)) {
            $this->RegisterMessage($src, VM_UPDATE);
            $this->WriteAttributeInteger('RegisteredSoCVar', $src);
        } else {
            $this->WriteAttributeInteger('RegisteredSoCVar', 0);
        }

        $deviceId = $this->ReadPropertyString('DeviceID');
        if ($deviceId === '' || preg_match('/^[A-Za-z0-9_-]+$/', $deviceId) !== 1) {
            $this->SetTimerInterval('SoCPush', 0);
            $this->SetStatus(self::STATUS_BAD_DEVICE_ID);
            return;
        }
        if ($this->isDuplicateDeviceId($deviceId)) {
            $this->SetTimerInterval('SoCPush', 0);
            $this->SetStatus(self::STATUS_DUPLICATE_ID);
            return;
        }
        if ($src <= 0 || !IPS_VariableExists($src)) {
            $this->SetTimerInterval('SoCPush', 0);
            $this->SetStatus(self::STATUS_NO_SOC_SOURCE);
            return;
        }
        if (!$this->parentUsable()) {
            $this->SetTimerInterval('SoCPush', 0);
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
        if ($Message === VM_UPDATE && $SenderID === $this->ReadPropertyInteger('SoCSourceVariable')) {
            if (time() - $this->ReadAttributeInteger('LastPushTs') >= $this->ReadPropertyInteger('PushDebounce')) {
                $this->PushSoC();
            }
        }
    }

    // ------------------------------------------------------------------ data from the server

    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || ($data['DataID'] ?? '') !== self::EOS_RX_GUID) {
            return '';
        }
        $event = (string) ($data['Event'] ?? '');
        if ($event === 'PlanUpdated') {
            $this->storePlan($data['Plan'] ?? [], is_array($data['Instructions'] ?? null) ? $data['Instructions'] : []);
            $this->fetchSolutionSubset();
            $this->ProcessPlan();
        } elseif ($event === 'Status') {
            $this->updatePlanStale();
        }
        return '';
    }

    // ------------------------------------------------------------------ public API (prefix EOSBAT_)

    public function PushSoC(): bool
    {
        $varId = $this->ReadPropertyInteger('SoCSourceVariable');
        if ($varId <= 0 || !IPS_VariableExists($varId) || !$this->parentUsable()) {
            return false;
        }
        $raw = (float) GetValue($varId);
        $factor = $this->ReadPropertyInteger('SoCUnit') === 0 ? $raw / 100.0 : $raw;
        if ($factor > 1.0 && $factor <= 100.0) {
            $this->SendDebug('PushSoC', 'value ' . $raw . ' > 1 interpreted as percent', 0);
            $factor /= 100.0;
        }
        $factor = max(0.0, min(1.0, round($factor, 4)));

        $res = $this->forward([
            'Command'  => 'PutMeasurement',
            'Key'      => $this->ReadPropertyString('DeviceID') . '-soc-factor',
            'Value'    => $factor,
            'DateTime' => $this->eosIsoNow(),
        ]);
        if (($res['ok'] ?? false) === true) {
            $this->SetValue('SoCSent', $factor);
            $this->SetValue('LastPush', time());
            $this->WriteAttributeInteger('LastPushTs', time());
            $this->UpdateFormField('ActionResult', 'caption', sprintf($this->Translate('SoC %.3f sent'), $factor));
            return true;
        }
        $error = (string) ($res['error'] ?? 'no response');
        $this->LogMessage(sprintf($this->Translate('SoC push failed: %s'), $error), KL_WARNING);
        $this->UpdateFormField('ActionResult', 'caption', sprintf($this->Translate('SoC push failed: %s'), $error));
        return false;
    }

    public function RefreshPlan(): bool
    {
        if (!$this->parentUsable()) {
            return false;
        }
        $res = $this->forward(['Command' => 'GetPlanForResource', 'ResourceID' => $this->ReadPropertyString('DeviceID')]);
        if (($res['ok'] ?? false) !== true) {
            $this->UpdateFormField('ActionResult', 'caption', sprintf($this->Translate('Plan refresh failed: %s'), (string) ($res['error'] ?? '?')));
            $this->ProcessPlan();
            return false;
        }
        $instructions = is_array($res['instructions'] ?? null) ? $res['instructions'] : [];
        $this->storePlan(is_array($res['plan'] ?? null) ? $res['plan'] : [], $instructions);
        $this->fetchSolutionSubset();
        $this->ProcessPlan();
        $this->UpdateFormField('ActionResult', 'caption', sprintf($this->Translate('Plan refreshed: %d instructions'), count($instructions)));
        return true;
    }

    /**
     * Determine the active instruction (latest with execution_time <= now), the
     * next one, update the variables and arm the slot timer. Idempotent.
     */
    public function ProcessPlan(): void
    {
        $this->SetTimerInterval('SlotTimer', 0);
        $list = $this->eosJsonDecode($this->ReadAttributeString('Instructions'), []);
        $now = time();
        $active = null;
        $next = null;
        foreach (is_array($list) ? $list : [] as $instruction) {
            $ts = (int) ($instruction['ts'] ?? 0);
            if ($ts <= $now) {
                $active = $instruction;
            } elseif ($next === null) {
                $next = $instruction;
                break;
            }
        }

        if ($active !== null) {
            $this->showInstruction($active);
            $this->WriteAttributeBoolean('EmptyPlanWarned', false);
        } elseif ($list === []) {
            if (!$this->ReadAttributeBoolean('EmptyPlanWarned')) {
                $this->LogMessage('EOS plan contains no instructions for ' . $this->ReadPropertyString('DeviceID'), KL_WARNING);
                $this->WriteAttributeBoolean('EmptyPlanWarned', true);
            }
            $this->SetValue('Mode', self::EOS_MODE_UNKNOWN);
            $this->SetValue('ModeRaw', '');
        }

        $this->SetValue('NextChange', $next !== null ? (int) $next['ts'] : 0);
        $this->SetValue('NextMode', $next !== null ? (string) ($next['operation_mode_id'] ?? '') : '');
        if ($next !== null) {
            $delayMs = max(1000, ((int) $next['ts'] - $now) * 1000 + 500);
            $this->SetTimerInterval('SlotTimer', min($delayMs, self::MAX_SLOT_TIMER_MS));
        }

        $this->updatePlanStale($active);
        $this->ApplyInstruction($active);
        $this->UpdateVisualizationValue(json_encode($this->tileState(), JSON_UNESCAPED_UNICODE));
    }

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

    public function GetActiveInstruction(): string
    {
        $list = $this->eosJsonDecode($this->ReadAttributeString('Instructions'), []);
        $now = time();
        $active = null;
        foreach (is_array($list) ? $list : [] as $instruction) {
            if ((int) ($instruction['ts'] ?? 0) <= $now) {
                $active = $instruction;
            }
        }
        return json_encode($active, JSON_UNESCAPED_UNICODE);
    }

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

    // ------------------------------------------------------------------ control hook (phase 2)

    protected function ApplyInstruction(?array $instruction): void
    {
        if ($this->ReadPropertyInteger('ControlMode') === 0) {
            return; // display only
        }
    }

    // ------------------------------------------------------------------ internals

    /**
     * The EOS Server is usable for measurements even while it reports
     * "no plan yet" (203) or a version mismatch (202): EOS needs the SoC to
     * produce a plan in the first place. Only unreachable/inactive blocks.
     */
    private function parentUsable(): bool
    {
        $parentId = (int) IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parentId <= 0 || !IPS_InstanceExists($parentId)) {
            return false;
        }
        $status = (int) IPS_GetInstance($parentId)['InstanceStatus'];
        return in_array($status, [IS_ACTIVE, 202, 203], true);
    }

    private function forward(array $payload): array
    {
        $payload['DataID'] = self::EOS_TX_GUID;
        $raw = $this->SendDataToParent(json_encode($payload));
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : ['ok' => false, 'error' => 'no response from EOS Server'];
    }

    /** Cache SoC and price series of the current solution for the tile. */
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
        $list = $this->eosJsonDecode($this->ReadAttributeString('Instructions'), []);
        $subset = $this->eosJsonDecode($this->ReadAttributeString('SolutionSubset'), []);
        $slot = 900;
        $soc = is_array($subset['soc'] ?? null) ? $subset['soc'] : [];
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
            ], is_array($list) ? $list : []),
            'soc'          => $soc,
            'price'        => is_array($subset['price'] ?? null) ? $subset['price'] : [],
            'pv'           => is_array($subset['pv'] ?? null) ? $subset['pv'] : [],
        ];
    }

    private function storePlan(array $meta, array $instructions): void
    {
        $deviceId = $this->ReadPropertyString('DeviceID');
        $mine = [];
        foreach ($instructions as $instruction) {
            if (!is_array($instruction) || (string) ($instruction['resource_id'] ?? '') !== $deviceId) {
                continue;
            }
            $instruction['ts'] = $this->eosParseTime($instruction['execution_time'] ?? null);
            if ($instruction['ts'] > 0) {
                $mine[] = $instruction;
            }
        }
        usort($mine, static fn (array $a, array $b): int => $a['ts'] <=> $b['ts']);
        $this->WriteAttributeString('Instructions', json_encode($mine));
        $this->WriteAttributeString('PlanMeta', json_encode([
            'id'           => $meta['id'] ?? '',
            'generated_at' => $meta['generated_at'] ?? null,
            'valid_from'   => $meta['valid_from'] ?? null,
            'valid_until'  => $meta['valid_until'] ?? null,
            'received'     => time(),
        ]));
        $this->SetValue('PlanJSON', json_encode(array_map(static function (array $i): array {
            return [
                'time'   => $i['execution_time'] ?? '',
                'ts'     => $i['ts'],
                'mode'   => $i['operation_mode_id'] ?? '',
                'factor' => $i['operation_mode_factor'] ?? 0,
            ];
        }, $mine), JSON_UNESCAPED_UNICODE));
        $this->SendDebug('storePlan', count($mine) . ' instructions for ' . $deviceId, 0);
    }

    private function showInstruction(array $instruction): void
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

    private function updatePlanStale(?array $active = null): void
    {
        $meta = $this->eosJsonDecode($this->ReadAttributeString('PlanMeta'), []);
        $generated = $this->eosParseTime(is_array($meta) ? ($meta['generated_at'] ?? null) : null);
        $limit = $this->ReadPropertyInteger('StaleAfterMinutes') * 60;
        $stale = $generated === 0 || (time() - $generated) > $limit;
        if ($active === null) {
            $list = $this->eosJsonDecode($this->ReadAttributeString('Instructions'), []);
            $now = time();
            $active = null;
            foreach (is_array($list) ? $list : [] as $instruction) {
                if ((int) ($instruction['ts'] ?? 0) <= $now) {
                    $active = $instruction;
                }
            }
            if ($active === null) {
                $stale = true;
            }
        }
        $this->SetValue('PlanStale', $stale);
    }

    private function isDuplicateDeviceId(string $deviceId): bool
    {
        foreach (IPS_GetInstanceListByModuleID(self::MODULE_GUID) as $id) {
            if ($id === $this->InstanceID) {
                continue;
            }
            if ((string) IPS_GetProperty($id, 'DeviceID') === $deviceId) {
                return true;
            }
        }
        return false;
    }
}
