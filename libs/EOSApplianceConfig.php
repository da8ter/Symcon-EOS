<?php

declare(strict_types=1);

/*
 * EOS Appliance: device configuration in EOS (consumption, duration, time
 * windows, deadline) and the cycles-completed measurement. Split out of the
 * module to keep it readable; uses forward()/parentUsable() from EOSPlanDevice.
 */
if (!trait_exists('EOSApplianceConfig')) {
    trait EOSApplianceConfig
    {
        /** Force-write the appliance parameters to EOS (ApplyChanges does it automatically when they differ). */
        public function WriteConfigToEOS(): bool
        {
            [$path, $device, $merge] = $this->deviceConfig();
            return $this->syncDeviceConfig($path, $device, $merge, true);
        }

        /** [config path, device entry, merge payload]; raises devices/max_home_appliances when needed. */
        private function deviceConfig(): array
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

            $merge = ['devices' => ['home_appliances' => [$id => $appliance]]];
            if ($this->parentUsable()) {
                $res = $this->forward(['Command' => 'GetConfig', 'Path' => 'devices/max_home_appliances']);
                $count = $this->applianceCountInEOS();
                if ((int) ($res['data'] ?? 0) < $count) {
                    $merge['devices']['max_home_appliances'] = $count;
                }
            }
            return ['devices/home_appliances/' . $id, $appliance, $merge];
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
            $this->UpdateFormField('ConfigInfo', 'caption', $this->Translate('Values taken over from EOS. Press Apply to store them.'));
            return true;
        }

        // ------------------------------------------------------------------ times and measurements

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
}
