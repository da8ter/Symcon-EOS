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
        /** [config path, device entry, merge payload]; devices/max_home_appliances is handled by ensureDeviceMaximum(). */
        private function deviceConfig(): array
        {
            $id = $this->ReadPropertyString('DeviceID');
            $appliance = [
                'device_id'       => $id,
                'consumption_wh'  => $this->configValue('ConsumptionWh'),
                'duration_h'      => max(1, (int) $this->configValue('DurationH')),
                'num_cycles'      => max(1, (int) $this->configValue('NumCycles')),
                'min_cycle_gap_h' => $this->configValue('MinCycleGapH'),
                'schedule_mode'   => $this->configValue('ScheduleMode'),
                'deadline_policy' => $this->configValue('DeadlinePolicy'),
            ];
            $windows = [];
            foreach ($this->eosJsonDecode((string) $this->configValue('TimeWindows'), []) ?: [] as $row) {
                $start = trim((string) ($row['start_time'] ?? ''));
                $duration = trim((string) ($row['duration'] ?? ''));
                if ($start !== '' && $duration !== '') {
                    // day_of_week, date and locale come from EOSdash (hidden columns) and are written back unchanged.
                    $windows[] = ['start_time' => $start, 'duration' => $duration] + array_filter(
                        ['day_of_week' => $row['day_of_week'] ?? null, 'date' => $row['date'] ?? null, 'locale' => $row['locale'] ?? null],
                        static fn (mixed $v): bool => $v !== null && $v !== ''
                    );
                }
            }
            $appliance['time_windows'] = $windows !== [] ? ['windows' => $windows] : null;
            // Deadline and earliest start are not part of the entry: reconcileTimes() owns them.

            return [self::DEVICE_COLLECTION . '/' . $id, $appliance, ['devices' => ['home_appliances' => [$id => $appliance]]]];
        }

        /** Property => [EOS key, type] for loading EOS values into the form. */
        private function configFieldMap(): array
        {
            return [
                'ConsumptionWh'  => ['consumption_wh', 'int'],
                'DurationH'      => ['duration_h', 'int'],
                'NumCycles'      => ['num_cycles', 'int'],
                'MinCycleGapH'   => ['min_cycle_gap_h', 'int'],
                'ScheduleMode'   => ['schedule_mode', 'string'],
                'DeadlinePolicy' => ['deadline_policy', 'string'],
                'TimeWindows'    => ['time_windows', 'windows'],
            ];
        }

        // ------------------------------------------------------------------ times and measurements

        private function earliestStart(): int
        {
            $var = $this->ReadPropertyInteger('EarliestStartSourceVariable');
            return ($var > 0 && IPS_VariableExists($var)) ? (int) GetValue($var) : 0;
        }

        /**
         * Deadline and earliest start belong to Symcon only when syncing is on AND a source
         * exists (a source variable, or SetDeadline was used); otherwise they belong to EOSdash.
         */
        private function timesOwned(): bool
        {
            return $this->ReadPropertyBoolean('SyncTimesToEOS')
                && ($this->ReadPropertyInteger('DeadlineSourceVariable') > 0 || $this->ReadPropertyInteger('EarliestStartSourceVariable') > 0 || $this->ReadAttributeBoolean('TimesByScript'));
        }

        /** Source variables -> Deadline variable -> times in EOS. */
        private function syncTimes(): void
        {
            $var = $this->ReadPropertyInteger('DeadlineSourceVariable');
            if ($var > 0 && IPS_VariableExists($var)) {
                $this->SetValue('Deadline', max(0, (int) GetValue($var)));
            }
            $this->reconcileTimes();
        }

        /**
         * Bring deadline and earliest start in EOS to the Symcon values; a passed time is
         * cleared (a past STRICT deadline makes the optimization fail). Runs on ApplyChanges,
         * on source changes, with every cycles push and from the expiry timer.
         */
        private function reconcileTimes(): bool
        {
            if (!$this->parentUsable() || $this->deviceBlocked()) {
                return false;
            }
            $now = $this->eosNow();
            if (!$this->timesOwned()) {
                $this->SetTimerInterval('TimesExpiry', 0);
                $eos = $this->eosTimeField('deadline_datetime');
                $past = $eos > 0 && $eos <= $now && $this->ReadPropertyString('DeadlinePolicy') === 'STRICT';
                $this->warnOnce('PastDeadlineWarned', $past ? (string) $eos : '', sprintf($this->Translate('EOS holds a deadline in the past (%s) with policy STRICT; the optimization fails. Clear it in EOSdash or give this instance a deadline source.'), $this->eosIsoNow(max(0, $eos))));
                return true;
            }
            $deadline = (int) $this->GetValue('Deadline');
            $earliest = $this->earliestStart();
            $want = ['earliest_start_datetime' => $earliest > $now ? $earliest : 0, 'deadline_datetime' => $deadline > $now ? $deadline : 0];
            $this->armExpiryTimer('TimesExpiry', array_values($want));
            $written = false;
            $ok = true;
            foreach ($want as $field => $ts) {
                $result = $this->reconcileTimeField($field, $ts);
                $written = $written || $result === 'written';
                $ok = $ok && $result !== 'failed';
            }
            if ($written) {
                $this->forward(['Command' => 'SaveConfig']);
            }
            return $ok;
        }

        /** Without a source variable 0 is reported; EOS rejects runs without a value for the current day. */
        private function sendCyclesCompleted(): bool
        {
            if (!$this->parentUsable() || $this->deviceBlocked()) {
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
            $this->syncTimes();
            return true;
        }
    }
}
