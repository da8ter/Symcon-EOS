<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/EOSCommon.php';
require_once __DIR__ . '/../libs/EOSPlanDevice.php';

/**
 * EOS Appliance: a shiftable household appliance (dishwasher, washing machine,
 * dryer) known to EOS. Shows the planned start and the RUN/OFF instruction,
 * reports completed cycles and keeps the appliance parameters (consumption,
 * duration, time windows, deadline) in the EOS configuration.
 */
class EOSAppliance extends IPSModuleStrict
{
    use EOSCommon;
    use EOSPlanDevice;

    private const MODULE_GUID = '{A7E2C4D9-3F61-4B8E-B2D5-6C9F0E1A7B34}';
    private const MODE_OFF = 0;
    private const MODE_RUN = 1;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('DeviceID', 'dishwasher1');
        $this->RegisterPropertyInteger('ConsumptionWh', 2000);
        $this->RegisterPropertyInteger('DurationH', 3);
        $this->RegisterPropertyInteger('NumCycles', 1);
        $this->RegisterPropertyString('ScheduleMode', 'ONCE');
        $this->RegisterPropertyString('TimeWindows', '[]');
        $this->RegisterPropertyInteger('MinCycleGapH', 0);
        $this->RegisterPropertyString('DeadlinePolicy', 'BEST_EFFORT');
        $this->RegisterPropertyInteger('DeadlineSourceVariable', 0);
        $this->RegisterPropertyInteger('EarliestStartSourceVariable', 0);
        $this->RegisterPropertyInteger('CyclesCompletedSourceVariable', 0);
        $this->RegisterPropertyBoolean('SyncTimesToEOS', true);
        $this->RegisterPropertyInteger('StaleAfterMinutes', 180);
        $this->RegisterPropertyInteger('ControlMode', 0);

        $this->registerPlanAttributes();
        $this->RegisterAttributeInteger('RegisteredDeadlineVar', 0);
        $this->RegisterAttributeInteger('RegisteredEarliestVar', 0);
        $this->RegisterAttributeInteger('RegisteredCyclesVar', 0);
        $this->RegisterAttributeString('LastTimesSent', '');

        $this->RegisterVariableInteger('Mode', $this->Translate('Instruction'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS'      => json_encode([
                ['Value' => self::MODE_OFF, 'Caption' => $this->Translate('Off'), 'Icon' => 'Power', 'Color' => 0x9A9A94, 'IconActive' => true],
                ['Value' => self::MODE_RUN, 'Caption' => $this->Translate('Run'), 'Icon' => 'Execute', 'Color' => 0x008300, 'IconActive' => true],
                ['Value' => self::EOS_MODE_UNKNOWN, 'Caption' => $this->Translate('Unknown'), 'Icon' => 'Warning', 'Color' => 0x808080, 'IconActive' => true],
            ], JSON_UNESCAPED_UNICODE),
        ], 10);
        $this->RegisterVariableString('ModeRaw', $this->Translate('Instruction (raw)'), $this->eosValuePresentation('Information'), 20);
        $this->RegisterVariableBoolean('Running', $this->Translate('Run now'), $this->eosBoolPresentation('No', 'Yes', 0x808080, 0x008300, 'Execute'), 30);
        $this->RegisterVariableInteger('PlannedStart', $this->Translate('Planned start'), $this->eosDateTimePresentation('Clock'), 40);
        $this->RegisterVariableInteger('PlannedEnd', $this->Translate('Planned end'), $this->eosDateTimePresentation('Clock'), 50);
        $this->RegisterVariableInteger('Deadline', $this->Translate('Deadline'), $this->eosDateTimePresentation('Alert'), 60);
        $this->RegisterVariableInteger('CyclesCompleted', $this->Translate('Cycles completed today'), $this->eosValuePresentation('Repeat'), 70);
        $this->registerPlanVariables(80);

        $this->RegisterTimer('SlotTimer', 0, 'EOSHA_ProcessPlan($_IPS[\'TARGET\']);');
        // EOS needs a completed-cycles value from the current day; keep it fresh.
        $this->RegisterTimer('CyclesPush', 0, 'EOSHA_PushCyclesCompleted($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        $this->registerOptionalSource('DeadlineSourceVariable', 'RegisteredDeadlineVar');
        $this->registerOptionalSource('EarliestStartSourceVariable', 'RegisteredEarliestVar');
        $this->registerOptionalSource('CyclesCompletedSourceVariable', 'RegisteredCyclesVar');
        $deviceId = $this->ReadPropertyString('DeviceID');
        $this->SetTimerInterval('CyclesPush', 0);

        if (!$this->validDeviceId($deviceId)) {
            $this->SetStatus(self::STATUS_BAD_DEVICE_ID);
            return;
        }
        if ($this->isDuplicateDeviceId($deviceId)) {
            $this->SetStatus(self::STATUS_DUPLICATE_ID);
            return;
        }
        if (!$this->parentUsable()) {
            $this->SetStatus(self::STATUS_NO_PARENT);
            return;
        }

        $this->SetStatus(IS_ACTIVE);
        $this->SetTimerInterval('CyclesPush', 900 * 1000);
        $this->syncTimes();
        $this->sendCyclesCompleted();
        $this->RefreshPlan();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }
        if ($Message !== VM_UPDATE || $SenderID <= 0) {
            return;
        }
        if ($SenderID === $this->ReadPropertyInteger('DeadlineSourceVariable') || $SenderID === $this->ReadPropertyInteger('EarliestStartSourceVariable')) {
            $this->syncTimes();
        } elseif ($SenderID === $this->ReadPropertyInteger('CyclesCompletedSourceVariable')) {
            $this->sendCyclesCompleted();
        }
    }

    // ------------------------------------------------------------------ public API (prefix EOSHA_)

    /** Set the deadline (unix timestamp, 0 = none) and write it to EOS. */
    public function SetDeadline(int $Timestamp): bool
    {
        $this->SetValue('Deadline', max(0, $Timestamp));
        return $this->writeTimes($Timestamp, $this->earliestStart());
    }

    public function PushCyclesCompleted(): bool
    {
        return $this->sendCyclesCompleted();
    }

    public function WriteConfigToEOS(): bool
    {
        $id = $this->ReadPropertyString('DeviceID');
        $appliance = [
            'device_id'       => $id,
            'consumption_wh'  => $this->ReadPropertyInteger('ConsumptionWh'),
            'duration_h'      => max(1, $this->ReadPropertyInteger('DurationH')),
            'num_cycles'      => max(1, $this->ReadPropertyInteger('NumCycles')),
            'min_cycle_gap_h' => $this->ReadPropertyInteger('MinCycleGapH'),
            'schedule_mode'   => $this->ReadPropertyString('ScheduleMode'),
            'deadline_policy' => $this->ReadPropertyString('DeadlinePolicy'),
        ];
        $windows = [];
        foreach ($this->eosJsonDecode($this->ReadPropertyString('TimeWindows'), []) ?: [] as $row) {
            $start = trim((string) ($row['start_time'] ?? ''));
            $duration = trim((string) ($row['duration'] ?? ''));
            if ($start !== '' && $duration !== '') {
                $windows[] = ['start_time' => $start, 'duration' => $duration];
            }
        }
        $appliance['time_windows'] = $windows !== [] ? ['windows' => $windows] : null;
        $deadline = (int) $this->GetValue('Deadline');
        $appliance['deadline_datetime'] = $deadline > time() ? $this->eosIsoNow($deadline) : null;
        $earliest = $this->earliestStart();
        $appliance['earliest_start_datetime'] = $earliest > time() ? $this->eosIsoNow($earliest) : null;

        $res = $this->forward(['Command' => 'GetConfig', 'Path' => 'devices/max_home_appliances']);
        $max = (int) ($res['data'] ?? 0);
        $merge = ['devices' => ['home_appliances' => [$id => $appliance]]];
        $count = $this->applianceCountInEOS();
        if ($max < $count) {
            $merge['devices']['max_home_appliances'] = $count;
        }
        $res = $this->forward(['Command' => 'MergeConfig', 'Value' => $merge]);
        if (($res['ok'] ?? false) !== true) {
            $this->UpdateFormField('ConfigInfo', 'caption', (string) ($res['error'] ?? '?'));
            return false;
        }
        $save = $this->forward(['Command' => 'SaveConfig']);
        $this->UpdateFormField('ConfigInfo', 'caption', ($save['ok'] ?? false) ? $this->Translate('Appliance configuration written to EOS.') : (string) ($save['error'] ?? '?'));
        return (bool) ($save['ok'] ?? false);
    }

    public function ReadConfigFromEOS(): bool
    {
        $id = $this->ReadPropertyString('DeviceID');
        $res = $this->forward(['Command' => 'GetConfig', 'Path' => 'devices/home_appliances/' . $id]);
        $ha = $res['data'] ?? null;
        if (($res['ok'] ?? false) !== true || !is_array($ha)) {
            $this->UpdateFormField('ConfigInfo', 'caption', sprintf($this->Translate('No appliance %s in EOS configuration.'), $id));
            return false;
        }
        foreach (['ConsumptionWh' => 'consumption_wh', 'DurationH' => 'duration_h', 'NumCycles' => 'num_cycles', 'MinCycleGapH' => 'min_cycle_gap_h'] as $field => $key) {
            if (isset($ha[$key])) {
                $this->UpdateFormField($field, 'value', (int) $ha[$key]);
            }
        }
        foreach (['ScheduleMode' => 'schedule_mode', 'DeadlinePolicy' => 'deadline_policy'] as $field => $key) {
            if (isset($ha[$key])) {
                $this->UpdateFormField($field, 'value', (string) $ha[$key]);
            }
        }
        $rows = [];
        foreach ($ha['time_windows']['windows'] ?? [] as $w) {
            $rows[] = ['start_time' => (string) ($w['start_time'] ?? ''), 'duration' => (string) ($w['duration'] ?? '')];
        }
        $this->UpdateFormField('TimeWindows', 'values', json_encode($rows));
        $this->UpdateFormField('ConfigInfo', 'caption', $this->Translate('Appliance configuration loaded from EOS. Press Apply to store.'));
        return true;
    }

    // ------------------------------------------------------------------ plan hooks

    protected function showInstruction(array $instruction): void
    {
        $modeId = strtoupper((string) ($instruction['operation_mode_id'] ?? ''));
        $run = $modeId === 'RUN' || $modeId === 'FORCED_RUN' || $modeId === 'RESUME';
        $known = in_array($modeId, ['RUN', 'OFF', 'IDLE', 'DEFER', 'PAUSE', 'RESUME', 'LIMIT_POWER', 'FORCED_RUN', 'FAULT'], true);
        if (!$known && $modeId !== (string) $this->GetValue('ModeRaw')) {
            $this->LogMessage(sprintf($this->Translate('Unknown operation mode %s'), $modeId), KL_WARNING);
        }
        $this->SetValue('Mode', $known ? ($run ? self::MODE_RUN : self::MODE_OFF) : self::EOS_MODE_UNKNOWN);
        $this->SetValue('ModeRaw', $modeId);
        $this->SetValue('Running', $run);
        $this->updatePlannedStart($run ? (int) $instruction['ts'] : null);
    }

    protected function showNoInstruction(): void
    {
        $this->SetValue('Mode', self::EOS_MODE_UNKNOWN);
        $this->SetValue('ModeRaw', '');
        $this->SetValue('Running', false);
        $this->SetValue('PlannedStart', 0);
        $this->SetValue('PlannedEnd', 0);
    }

    protected function onPlanProcessed(?array $active): void
    {
        if ($active === null) {
            $this->updatePlannedStart(null);
        }
        if ($this->ReadPropertyInteger('ControlMode') !== 0) {
            // Phase 2: control hook.
        }
    }

    // ------------------------------------------------------------------ internals

    /** Planned start = current RUN start, otherwise the first future RUN instruction. */
    private function updatePlannedStart(?int $activeRunTs): void
    {
        $start = $activeRunTs;
        if ($start === null) {
            $now = time();
            foreach ($this->instructionList() as $i) {
                if ((int) $i['ts'] > $now && strtoupper((string) ($i['operation_mode_id'] ?? '')) === 'RUN') {
                    $start = (int) $i['ts'];
                    break;
                }
            }
        }
        $this->SetValue('PlannedStart', $start ?? 0);
        $this->SetValue('PlannedEnd', $start !== null ? $start + $this->ReadPropertyInteger('DurationH') * 3600 : 0);
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

    private function earliestStart(): int
    {
        $var = $this->ReadPropertyInteger('EarliestStartSourceVariable');
        return ($var > 0 && IPS_VariableExists($var)) ? (int) GetValue($var) : 0;
    }

    private function syncTimes(): void
    {
        $var = $this->ReadPropertyInteger('DeadlineSourceVariable');
        $deadline = ($var > 0 && IPS_VariableExists($var)) ? (int) GetValue($var) : (int) $this->GetValue('Deadline');
        $this->SetValue('Deadline', max(0, $deadline));
        if ($this->ReadPropertyBoolean('SyncTimesToEOS')) {
            $this->writeTimes($deadline, $this->earliestStart());
        }
    }

    private function writeTimes(int $deadline, int $earliest): bool
    {
        if (!$this->parentUsable()) {
            return false;
        }
        $payload = [
            'deadline_datetime'       => $deadline > time() ? $this->eosIsoNow($deadline) : null,
            'earliest_start_datetime' => $earliest > time() ? $this->eosIsoNow($earliest) : null,
        ];
        $signature = json_encode($payload);
        if ($signature === $this->ReadAttributeString('LastTimesSent')) {
            return true;
        }
        $id = $this->ReadPropertyString('DeviceID');
        $res = $this->forward(['Command' => 'MergeConfig', 'Value' => ['devices' => ['home_appliances' => [$id => ['device_id' => $id] + $payload]]]]);
        if (($res['ok'] ?? false) !== true) {
            $this->LogMessage('EOS appliance time update failed: ' . (string) ($res['error'] ?? '?'), KL_WARNING);
            return false;
        }
        $this->WriteAttributeString('LastTimesSent', $signature);
        return true;
    }

    /** Without a source variable 0 is reported; EOS rejects runs without a value for the current day. */
    private function sendCyclesCompleted(): bool
    {
        if (!$this->parentUsable()) {
            return false;
        }
        $var = $this->ReadPropertyInteger('CyclesCompletedSourceVariable');
        $cycles = ($var > 0 && IPS_VariableExists($var)) ? max(0, (int) GetValue($var)) : 0;
        $cycles = min($cycles, max(1, $this->ReadPropertyInteger('NumCycles')));
        $this->SetValue('CyclesCompleted', $cycles);
        $res = $this->forward([
            'Command'  => 'PutMeasurement',
            'Key'      => $this->ReadPropertyString('DeviceID') . '.cycles_completed',
            'Value'    => (float) $cycles,
            'DateTime' => $this->eosIsoNow(),
        ]);
        if (($res['ok'] ?? false) !== true) {
            $this->LogMessage('cycles push failed: ' . (string) ($res['error'] ?? '?'), KL_WARNING);
            return false;
        }
        return true;
    }

    private function applianceCountInEOS(): int
    {
        $res = $this->forward(['Command' => 'GetConfig', 'Path' => 'devices/home_appliances']);
        $existing = is_array($res['data'] ?? null) ? array_keys($res['data']) : [];
        if (!in_array($this->ReadPropertyString('DeviceID'), $existing, true)) {
            $existing[] = $this->ReadPropertyString('DeviceID');
        }
        return count($existing);
    }
}
