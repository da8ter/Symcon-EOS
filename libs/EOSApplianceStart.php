<?php

declare(strict_types=1);

/*
 * Start logic of the EOS Appliance: the desired state per instruction, the start lock
 * (StartPulseTs for the run duration, StartConfirmedTs), manual "run" on the edge and
 * the start bookkeeping after a dispatch. Uses the constants and helpers of EOSAppliance.
 */
if (!trait_exists('EOSApplianceStart')) {
    trait EOSApplianceStart
    {
        protected function desiredFromInstruction(array $instruction): ?array
        {
            $modeId = strtoupper((string) ($instruction['operation_mode_id'] ?? ''));
            if (!in_array($modeId, self::KNOWN_MODES, true)) {
                return null;
            }
            $id = (string) ($instruction['id'] ?? ($instruction['execution_time'] ?? ''));
            $executionTime = (string) ($instruction['execution_time'] ?? '');
            if (!in_array($modeId, self::RUN_MODES, true)) {
                return $this->desiredAppliance(false, false, $id, $executionTime, '');
            }
            $ts = (int) ($instruction['ts'] ?? 0);
            $now = $this->eosNow();
            $this->migrateStartedIds($id, $ts);
            if ($now >= $ts + $this->ReadPropertyInteger('StartGraceMinutes') * 60) {
                // e.g. Symcon restarted hours after the planned start: do not start late.
                $key = $executionTime . '|' . $modeId;
                if ($this->ReadAttributeString('MissedStartWarned') !== $key) {
                    $this->LogMessage(sprintf($this->Translate('Planned start of %s at %s missed (grace period over), not starting'), $this->ReadPropertyString('DeviceID'), date('H:i', $ts)), KL_WARNING);
                    $this->WriteAttributeString('MissedStartWarned', $key);
                }
                return $this->desiredAppliance(false, false, $id, $executionTime, $this->Translate('start missed (grace period)'), 'RUN');
            }
            $this->releaseUnconfirmedLockout($now);
            $degraded = '';
            $start = false;
            $pulse = $this->ReadAttributeInteger('StartPulseTs');
            if ($pulse > 0 && $now < $pulse + max(1, $this->ReadPropertyInteger('DurationH')) * 3600) {
                $degraded = $this->Translate('already started');
            } elseif ($this->isRunning()) {
                $degraded = $this->Translate('already running');
            } else {
                $start = true;
            }
            return $this->desiredAppliance(true, $start, $id, $executionTime, $degraded);
        }

        /** Manual "run" starts once, on the edge into "run" (ManualStartArmed), never again after a reset. */
        protected function desiredManual(int $manualMode): array
        {
            $run = $manualMode === self::MODE_RUN;
            return $this->desiredAppliance($run, $run && $this->ReadAttributeBoolean('ManualStartArmed') && !$this->isRunning(), 'manual', '', '');
        }

        protected function onManualModeChanged(int $mode): void
        {
            $this->WriteAttributeBoolean('ManualStartArmed', $mode === self::MODE_RUN);
        }

        /** RUN row action only for a real start; OFF row action only when stopping is allowed (or manual). */
        protected function rowActionAllowed(array $desired, string $modeRaw): bool
        {
            if ($modeRaw === 'RUN') {
                return !empty($desired['start']);
            }
            return $this->ReadPropertyBoolean('AllowStop') || ($desired['source'] ?? '') === 'manual';
        }

        /**
         * Start bookkeeping. The pulse binding (RUN action, else Enable false->true, else the
         * action/script on change) decides whether a start happened: StartPulseTs then locks
         * further plan starts for the run duration. StartConfirmedTs follows once the enable
         * target (if bound) holds true as well.
         */
        protected function onDispatched(array $desired, array $outcome): void
        {
            $now = $this->eosNow();
            if (!empty($desired['start'])) {
                if ($this->modeMapRow('RUN')['action'] !== '') {
                    $pulsed = $outcome['row'] === 'fired';
                } elseif (isset($outcome['targets']['Enable'])) {
                    $pulsed = $outcome['targets']['Enable'] === 'ok';
                } else {
                    $pulsed = $outcome['chg'] === 'ok';
                }
                if ($pulsed) {
                    $this->WriteAttributeInteger('StartPulseTs', $now);
                    $this->WriteAttributeInteger('StartConfirmedTs', 0);
                    $this->WriteAttributeBoolean('ManualStartArmed', false);
                }
            }
            // Confirmed once the enable target (if bound) holds "run" after the pulse, also on a later retry.
            $pulse = $this->ReadAttributeInteger('StartPulseTs');
            $enable = $outcome['targets']['Enable'] ?? null;
            if ($pulse > 0 && ($desired['mode'] ?? null) === self::MODE_RUN && $this->ReadAttributeInteger('StartConfirmedTs') < $pulse
                && ($enable === null || $enable === 'ok' || $enable === 'same')) {
                $this->WriteAttributeInteger('StartConfirmedTs', $now);
            }
        }

        private function desiredAppliance(bool $run, bool $start, string $id, string $executionTime, string $degraded, string $modeRaw = ''): array
        {
            $targets = [];
            if ($run) {
                $targets['Enable'] = true;
            } elseif ($this->ReadPropertyBoolean('AllowStop')) {
                $targets['Enable'] = false;
            }
            $modeRaw = $modeRaw !== '' ? $modeRaw : ($run ? 'RUN' : 'OFF');
            return [
                'modeRaw'       => $modeRaw,
                // One RUN action per planned start: a re-plan of the same start keeps the key,
                // a new cycle (another start time) fires again even without OFF in between.
                'rowKey'        => ($modeRaw === 'RUN' && $executionTime !== '') ? 'RUN@' . $executionTime : $modeRaw,
                'mode'          => $run ? self::MODE_RUN : self::MODE_OFF,
                'factor'        => 0.0,
                'executionTime' => $executionTime,
                'degraded'      => $degraded,
                'targets'       => $targets,
                'context'       => ['Run' => $run, 'Start' => $start, 'InstructionId' => $id],
                'start'         => $start,
                'startId'       => $id,
            ];
        }

        /** Before build 3 starts were remembered by instruction id: an id found there still locks. */
        private function migrateStartedIds(string $id, int $ts): void
        {
            $ids = $this->eosJsonDecode($this->ReadAttributeString('StartedInstructionIds'), []);
            if (!is_array($ids) || $ids === []) {
                return;
            }
            if (in_array($id, $ids, true) && $this->ReadAttributeInteger('StartPulseTs') === 0) {
                $this->WriteAttributeInteger('StartPulseTs', $ts);
                $this->WriteAttributeInteger('StartConfirmedTs', $ts);
            }
            $this->WriteAttributeString('StartedInstructionIds', '[]');
        }

        /**
         * With a running-source variable a start must show up as "running" within 5 minutes;
         * otherwise the pulse did not start anything (e.g. an action that reported OK but failed
         * inside the device) and the lockout is released so the grace period can retry.
         */
        private function releaseUnconfirmedLockout(int $now): void
        {
            $pulse = $this->ReadAttributeInteger('StartPulseTs');
            if ($pulse <= 0 || $this->ReadPropertyInteger('RunningSourceVariable') <= 0 || $now - $pulse < 300 || $this->isRunning()) {
                return;
            }
            if ($this->wasRunningSince($pulse)) {
                return; // it ran (and finished): the lockout stays
            }
            $this->WriteAttributeInteger('StartPulseTs', 0);
            $this->LogMessage(sprintf($this->Translate('%s did not report running after the start; the start may be retried'), $this->ReadPropertyString('DeviceID')), KL_WARNING);
        }

        /** Did the running-source variable change after $since (the appliance ran and finished)? */
        private function wasRunningSince(int $since): bool
        {
            $var = $this->ReadPropertyInteger('RunningSourceVariable');
            return $var > 0 && IPS_VariableExists($var) && (int) (IPS_GetVariable($var)['VariableChanged'] ?? 0) > $since;
        }

        private function isRunning(): bool
        {
            $var = $this->ReadPropertyInteger('RunningSourceVariable');
            return $var > 0 && IPS_VariableExists($var) && (bool) GetValue($var);
        }
    }
}
