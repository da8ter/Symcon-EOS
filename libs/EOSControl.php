<?php

declare(strict_types=1);

/*
 * Control layer shared by the EOS device modules: turns the active planner
 * instruction (or the fallback / manual mode) into a desired state and writes
 * it to the user's hardware through vendor-neutral bindings (target variables,
 * Symcon actions, a script) - see EOSControlBindings.
 *
 * The using class must use EOSCommon, EOSPlanDevice and EOSControlBindings,
 * define const CONTROL_PREFIX (e.g. 'EOSBAT'), call the register*() helpers
 * from Create(), setupControl() from ApplyChanges() (after the kernel check,
 * before the status early-returns), scheduleControl() from onPlanProcessed()
 * and implement:
 *   controlTargets(): array                     ['Key' => ['property' => 'TargetXVariable'], ...]
 *   desiredFromInstruction(array): ?array       null = unusable for control (unknown mode)
 *   desiredFallback(): ?array                   null = no intervention
 *   desiredManual(int $mode): array
 *   modeMapRows(): array                        [['mode' => 'IDLE', 'caption' => 'Locked'], ...]
 *   manualModeOptions(): array                  enumeration options without "Automatic"
 * Optional hooks: rowActionAllowed(array $desired, string $modeRaw): bool,
 * onDispatched(array $desired, bool $success): void, validateControlDevice(): string.
 *
 * Desired state: ['modeRaw', 'mode', 'factor', 'targets' => [Key => value], 'context' => [...],
 *                 'executionTime', 'degraded'] (+ 'source', 'trigger' added here).
 *
 * Writes never happen in the parent's SendDataToChildren thread: scheduleControl()
 * stores the desired state and arms a one-shot timer, runDispatch() does the work.
 */
if (!trait_exists('EOSControl')) {
    trait EOSControl
    {
        public const CONTROL_DISPLAY = 0;
        public const CONTROL_SIMULATE = 1;
        public const CONTROL_ACTIVE = 2;
        public const MANUAL_AUTO = 100;
        public const FALLBACK_NONE = -1;
        public const WATCHDOG_MS = 60000;
        public const MAX_RETRY_BACKOFF = 5;
        public const SLOW_WRITE_MS = 5000;

        // ---------------------------------------------------------------- registration

        protected function registerControlProperties(int $fallbackDefault): void
        {
            $this->RegisterPropertyInteger('ControlMode', self::CONTROL_DISPLAY);
            $this->RegisterPropertyInteger('HeartbeatSeconds', 0);
            $this->RegisterPropertyInteger('FallbackMode', $fallbackDefault);
            $this->RegisterPropertyInteger('ManualReturnMinutes', 0);
            $this->RegisterPropertyBoolean('ReleaseOnDisable', true);
            $this->RegisterPropertyString('ModeMap', '[]');
            $this->RegisterPropertyInteger('ActionTarget', 0);
            foreach ($this->modeMapRows() as $row) {
                $this->RegisterPropertyString('ModeAction_' . strtoupper((string) $row['mode']), '');
            }
            $this->RegisterPropertyString('ChangeAction', '');
            $this->RegisterPropertyInteger('ControlScript', 0);
            foreach ($this->controlTargets() as $target) {
                $this->RegisterPropertyInteger($target['property'], 0);
            }
            $this->RegisterAttributeString('Desired', '{}');
            $this->RegisterAttributeString('LastSent', '{}');
            $this->RegisterAttributeInteger('LastControlMode', 0);
            $this->RegisterAttributeInteger('ManualUntil', 0);
            $this->RegisterAttributeBoolean('ControlReady', false);
            $this->RegisterAttributeString('ControlErrorSig', '');
            $this->RegisterAttributeString('ControlProblem', '');
            $this->RegisterAttributeBoolean('ControlInitDone', false);
        }

        protected function registerControlVariables(int $position): void
        {
            $this->RegisterVariableBoolean('ControlActive', $this->Translate('Control active'), [
                'PRESENTATION'  => VARIABLE_PRESENTATION_SWITCH,
                'ICON_TRUE'     => 'Power',
                'ICON_FALSE'    => 'Power',
                'CAPTION_TRUE'  => $this->Translate('On'),
                'CAPTION_FALSE' => $this->Translate('Off'),
                'COLOR_TRUE'    => 0x008300,
                'COLOR_FALSE'   => 0x808080,
            ], $position);
            $this->EnableAction('ControlActive');

            $options = [['Value' => self::MANUAL_AUTO, 'Caption' => $this->Translate('Automatic'), 'Icon' => 'Repeat', 'Color' => 0x008300, 'IconActive' => true]];
            foreach ($this->manualModeOptions() as $option) {
                $options[] = $option;
            }
            $this->RegisterVariableInteger('ManualMode', $this->Translate('Manual mode'), [
                'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                'OPTIONS'      => json_encode($options, JSON_UNESCAPED_UNICODE),
            ], $position + 10);
            $this->EnableAction('ManualMode');

            $this->RegisterVariableBoolean('FallbackActive', $this->Translate('Fallback active'), $this->eosBoolPresentation('No', 'Yes', 0x808080, 0xFF0000, 'Warning'), $position + 20);
            $this->RegisterVariableInteger('LastControl', $this->Translate('Last control'), $this->eosDateTimePresentation('Execute'), $position + 30);
            $this->RegisterVariableString('LastControlResult', $this->Translate('Last control result'), $this->eosValuePresentation('Information'), $position + 40);
        }

        protected function registerControlTimers(): void
        {
            $this->RegisterTimer('Watchdog', 0, self::CONTROL_PREFIX . '_Watchdog($_IPS[\'TARGET\']);');
        }

        /** From ApplyChanges(): validate bindings, handle mode transitions, arm the watchdog. */
        protected function setupControl(): void
        {
            if (!$this->ReadAttributeBoolean('ControlInitDone')) {
                $this->SetValue('ControlActive', true);
                $this->SetValue('ManualMode', self::MANUAL_AUTO);
                $this->WriteAttributeBoolean('ControlInitDone', true);
            }
            $previous = $this->ReadAttributeInteger('LastControlMode');
            $current = $this->ReadPropertyInteger('ControlMode');
            if ($previous === self::CONTROL_ACTIVE && $current !== self::CONTROL_ACTIVE && $this->ReadPropertyBoolean('ReleaseOnDisable')) {
                $this->releaseDevice('disable', false);
            }
            $this->WriteAttributeInteger('LastControlMode', $current);
            // One guaranteed write after every Apply / restart: the hardware state is unknown.
            $this->WriteAttributeString('LastSent', '{}');

            $problem = $current === self::CONTROL_DISPLAY ? '' : $this->validateBindings();
            $this->WriteAttributeBoolean('ControlReady', $problem === '');
            $this->WriteAttributeString('ControlProblem', $problem);
            if ($problem !== '') {
                $this->recordResult($this->Translate('control not ready') . ': ' . $problem, false);
                $this->LogMessage($this->Translate('control not ready') . ': ' . $problem, KL_WARNING);
            }
            // Set only here, never in the hot path (SetTimerInterval restarts the countdown).
            $this->SetTimerInterval('Watchdog', $current === self::CONTROL_DISPLAY ? 0 : self::WATCHDOG_MS);
            if ($current === self::CONTROL_DISPLAY) {
                $this->WriteAttributeInteger('ManualUntil', 0);
                $this->SetValue('FallbackActive', false);
            }
        }

        // ---------------------------------------------------------------- decide

        /** Compute the desired state for the current situation; null = nothing to write. */
        protected function computeDesired(?array $active, string $trigger): ?array
        {
            if ($this->ReadPropertyInteger('ControlMode') === self::CONTROL_DISPLAY) {
                return null;
            }
            if (!$this->ReadAttributeBoolean('ControlReady')) {
                return null;
            }
            $manual = (int) $this->GetValue('ManualMode');
            $reason = '';
            if ($manual !== self::MANUAL_AUTO) {
                $desired = $this->desiredManual($manual);
                $source = 'manual';
            } elseif ($this->planUsable($active, $reason) && ($desired = $this->desiredFromInstruction($active)) !== null) {
                $source = 'plan';
            } else {
                if ($reason === '') {
                    $reason = 'unknown mode ' . (string) ($active['operation_mode_id'] ?? '');
                }
                if ($reason === 'gap') {
                    // Fresh plan, first instruction imminent: hold the last desired state.
                    $previous = $this->eosJsonDecode($this->ReadAttributeString('Desired'), []);
                    if (is_array($previous) && isset($previous['targets'])) {
                        $this->setFallbackActive(false, '');
                        $previous['trigger'] = $trigger;
                        return $previous;
                    }
                }
                $desired = $this->desiredFallback();
                $source = 'fallback';
            }
            $this->setFallbackActive($source === 'fallback', $reason);
            if ($desired === null) {
                $this->recordResult($this->Translate('fallback: no intervention'), false);
                return null;
            }
            $desired['source'] = $source;
            $desired['trigger'] = $trigger;
            return $desired;
        }

        /** Fast path for onPlanProcessed()/watchdog: store the desired state, defer the writes. */
        protected function scheduleControl(?array $active, string $trigger): void
        {
            if ($this->deviceBlocked()) {
                return;
            }
            $desired = $this->computeDesired($active, $trigger);
            if ($desired === null) {
                return;
            }
            $this->WriteAttributeString('Desired', json_encode($desired, JSON_UNESCAPED_UNICODE));
            $this->RegisterOnceTimer('Dispatch', self::CONTROL_PREFIX . '_Dispatch($_IPS[\'TARGET\']);');
        }

        protected function setFallbackActive(bool $on, string $reason): void
        {
            if ($on === (bool) $this->GetValue('FallbackActive')) {
                return;
            }
            $this->SetValue('FallbackActive', $on);
            $this->LogMessage($on ? sprintf($this->Translate('Fallback activated (%s)'), $reason) : $this->Translate('Fallback ended'), KL_NOTIFY);
        }

        // ---------------------------------------------------------------- execute

        /** Timer callback: write the stored desired state. */
        protected function runDispatch(bool $force = false): void
        {
            if ($this->ReadPropertyInteger('ControlMode') === self::CONTROL_DISPLAY || $this->deviceBlocked()) {
                return;
            }
            $desired = $this->eosJsonDecode($this->ReadAttributeString('Desired'), []);
            if (!is_array($desired) || !isset($desired['targets'])) {
                return;
            }
            if (!$this->GetValue('ControlActive')) {
                $this->recordResult($this->Translate('control switched off'), false);
                return;
            }
            $this->executeDesired($desired, $force, $this->ReadPropertyInteger('ControlMode') === self::CONTROL_SIMULATE);
        }

        protected function executeDesired(array $desired, bool $force, bool $sim): void
        {
            $now = $this->eosNow();
            $last = $this->eosJsonDecode($this->ReadAttributeString('LastSent'), []);
            $last = is_array($last) ? $last : [];
            $lastTargets = is_array($last['targets'] ?? null) ? $last['targets'] : [];
            $resolved = $this->resolveTargets($desired);

            $changed = [];
            $retry = [];
            $backoff = static fn (int $fail, int $failTs): bool => $now - $failTs >= 60 * min(max(1, $fail), self::MAX_RETRY_BACKOFF);
            foreach ($resolved as $key => $target) {
                $entry = $lastTargets[$key] ?? null;
                $fail = (int) ($entry['fail'] ?? 0);
                if ($fail > 0 && ($entry['fv'] ?? null) === $target['norm']) {
                    // The same value failed before: retry only after the backoff, never on every dispatch.
                    if ($backoff($fail, (int) ($entry['failTs'] ?? 0))) {
                        $retry[$key] = $target;
                    }
                    continue;
                }
                if ($entry === null || ($entry['v'] ?? null) !== $target['norm']) {
                    $changed[$key] = $target;
                }
            }
            $modeRaw = (string) ($desired['modeRaw'] ?? '');
            $modeChanged = $last === [] || $modeRaw !== (string) ($last['modeRaw'] ?? '');
            // Row action: pending until it ran successfully for this mode (or nothing is configured).
            $row = is_array($last['row'] ?? null) ? $last['row'] : ['modeRaw' => null, 'fail' => 0, 'failTs' => 0];
            $rowPending = $modeRaw !== '' && $modeRaw !== (string) ($row['modeRaw'] ?? '');
            // Backoff only for retrying the mode that failed; a new mode fires at once.
            $rowDue = $rowPending && ((int) ($row['fail'] ?? 0) === 0 || ($row['failMode'] ?? null) !== $modeRaw || $backoff((int) $row['fail'], (int) $row['failTs']));
            $heartbeatSeconds = $this->ReadPropertyInteger('HeartbeatSeconds');
            $heartbeat = $heartbeatSeconds > 0 && $last !== [] && $now - (int) ($last['ts'] ?? 0) >= $heartbeatSeconds;
            if (!$force && $changed === [] && !$modeChanged && !$heartbeat && $retry === [] && !$rowDue) {
                // Nothing to write, but a changed policy degradation must stay visible.
                $degraded = (string) ($desired['degraded'] ?? '');
                if ($degraded !== (string) ($last['degraded'] ?? '')) {
                    $last['degraded'] = $degraded;
                    $this->WriteAttributeString('LastSent', json_encode($last));
                    $this->recordResult('[' . (string) ($desired['source'] ?? 'plan') . '] ' . $this->Translate('nothing to write') . ($degraded !== '' ? ' · ' . $degraded : ''), false);
                }
                return;
            }
            $reason = ($changed === [] && !$modeChanged && !$force && $retry === [] && !$rowDue) ? 'heartbeat' : (string) ($desired['source'] ?? 'plan');
            $context = $this->buildContext($desired, $reason, $sim, array_keys($changed));

            $fragments = [];
            $failed = [];
            $started = microtime(true);
            // Heartbeat re-sends everything except targets whose failed value is still in its backoff.
            $inBackoff = static fn (?array $entry, string $norm): bool => $entry !== null && (int) ($entry['fail'] ?? 0) > 0 && ($entry['fv'] ?? null) === $norm && !$backoff((int) $entry['fail'], (int) ($entry['failTs'] ?? 0));
            $toWrite = $force ? $resolved : ($heartbeat ? array_filter($resolved, fn ($t, $k) => !$inBackoff($lastTargets[$k] ?? null, $t['norm']), ARRAY_FILTER_USE_BOTH) : $changed + $retry);
            foreach ($toWrite as $key => $target) {
                $result = $this->writeTarget($key, $target['varId'], $target['value'], $sim);
                if ($result['text'] !== '') {
                    $fragments[] = $result['text'];
                }
                $entry = $lastTargets[$key] ?? ['v' => null, 'ts' => 0, 'fail' => 0, 'failTs' => 0, 'fv' => null];
                if ($result['ok']) {
                    $entry = ['v' => $target['norm'], 'ts' => $now, 'fail' => 0, 'failTs' => 0, 'fv' => null];
                } else {
                    $failed[] = $result['text'];
                    if (!$result['skipped']) {
                        $entry['fail'] = (int) ($entry['fail'] ?? 0) + 1;
                        $entry['failTs'] = $now;
                        $entry['fv'] = $target['norm']; // remember what failed; a different value may be written at once
                    }
                }
                $lastTargets[$key] = $entry;
            }

            // Mode-row action: edge-triggered (once per mode), retried with backoff after a failure, never by the heartbeat.
            $rowSucceeded = false;
            if (($rowDue || $force) && $modeRaw !== '') {
                if ($this->rowActionAllowed($desired, $modeRaw)) {
                    $result = $this->runAction($this->modeMapRow($modeRaw)['action'], $context, $sim, $this->Translate('Action') . ' ' . $modeRaw);
                    if ($result['text'] !== '') {
                        $fragments[] = $result['text'];
                    }
                    if ($result['ok']) {
                        $rowSucceeded = true;
                    } else {
                        $failed[] = $result['text'];
                        $sameMode = ($row['failMode'] ?? null) === $modeRaw;
                        $row = ['modeRaw' => $row['modeRaw'] ?? null, 'fail' => $sameMode ? (int) ($row['fail'] ?? 0) + 1 : 1, 'failTs' => $now, 'failMode' => $modeRaw];
                    }
                } else {
                    $rowSucceeded = true; // nothing to fire for this mode
                }
                if ($rowSucceeded) {
                    $row = ['modeRaw' => $modeRaw, 'fail' => 0, 'failTs' => 0];
                }
            }
            foreach ([
                $this->runAction($this->ReadPropertyString('ChangeAction'), $context, $sim, $this->Translate('Action on change')),
                $this->runScript($this->ReadPropertyInteger('ControlScript'), $context, $sim, $this->Translate('Script on change')),
            ] as $result) {
                if ($result['text'] !== '') {
                    $fragments[] = $result['text'];
                    if (!$result['ok']) {
                        $failed[] = $result['text'];
                    }
                }
            }

            $this->WriteAttributeString('LastSent', json_encode([
                'targets'  => $lastTargets,
                'modeRaw'  => $modeRaw,
                'row'      => $row,
                'ts'       => $now,
                'source'   => $reason,
                'degraded' => (string) ($desired['degraded'] ?? ''),
            ]));
            $durationMs = (int) round((microtime(true) - $started) * 1000);
            $text = ($sim ? 'SIM ' : '') . '[' . $reason . '] ' . ($fragments !== [] ? implode(' · ', $fragments) : $this->Translate('nothing to write'));
            if (($desired['degraded'] ?? '') !== '') {
                $text .= ' · ' . (string) $desired['degraded'];
            }
            $this->recordResult($text);
            $this->SendDebug('Control', $text . ' (' . $durationMs . ' ms)', 0);
            $this->logThrottled($failed !== [] ? implode(' · ', $failed) : '');
            if ($durationMs > self::SLOW_WRITE_MS) {
                $this->LogMessage(sprintf($this->Translate('Control writes took %d ms - consider a script binding for slow devices'), $durationMs), KL_WARNING);
            }
            if (!$sim) {
                // Success means: nothing failed now AND no bound target or the mode action is still in a failed state.
                $pending = $modeRaw !== '' && $modeRaw !== (string) ($row['modeRaw'] ?? '') && (int) ($row['fail'] ?? 0) > 0;
                foreach ($resolved as $key => $target) {
                    if ((int) ($lastTargets[$key]['fail'] ?? 0) > 0) {
                        $pending = true;
                    }
                }
                $this->onDispatched($desired, $failed === [] && !$pending);
            }
        }

        // ---------------------------------------------------------------- user interaction

        /** RequestAction() body for ControlActive / ManualMode; false for unknown idents. */
        protected function handleControlAction(string $ident, mixed $value): bool
        {
            switch ($ident) {
                case 'ControlActive':
                    $on = (bool) $value;
                    $this->SetValue('ControlActive', $on);
                    if ($this->deviceBlocked()) {
                        return true; // switch state kept, nothing written under a foreign id
                    }
                    if (!$on) {
                        // Release only from a control mode that actually writes; "display only" never touches the device.
                        $mode = $this->ReadPropertyInteger('ControlMode');
                        if ($mode !== self::CONTROL_DISPLAY && $this->ReadPropertyBoolean('ReleaseOnDisable')) {
                            $this->releaseDevice('disable', $mode === self::CONTROL_SIMULATE);
                        }
                    } else {
                        $this->WriteAttributeString('LastSent', '{}');
                        $this->scheduleControl($this->activeInstruction(), 'user');
                    }
                    return true;
                case 'ManualMode':
                    $this->changeManualMode((int) $value);
                    return true;
                default:
                    return $this->handleFormAction($ident, $value);
            }
            return false;
        }

        protected function changeManualMode(int $mode): void
        {
            $this->SetValue('ManualMode', $mode);
            $minutes = $this->ReadPropertyInteger('ManualReturnMinutes');
            $this->WriteAttributeInteger('ManualUntil', ($mode !== self::MANUAL_AUTO && $minutes > 0) ? $this->eosNow() + $minutes * 60 : 0);
            // The hardware state is unknown after manual operation: write once.
            $this->WriteAttributeString('LastSent', '{}');
            $this->scheduleControl($this->activeInstruction(), 'manual');
        }

        /** Write the fallback state once (control switched off / mode left "active"). */
        protected function releaseDevice(string $reason, bool $sim): void
        {
            $desired = $this->desiredFallback();
            if ($desired === null) {
                $this->recordResult($this->Translate('control released without writing (fallback: no intervention)'));
                $this->LogMessage($this->Translate('Control released without writing'), KL_NOTIFY);
            } else {
                $desired['source'] = $reason;
                $desired['trigger'] = $reason;
                $this->executeDesired($desired, true, $sim);
                $this->LogMessage($this->Translate('Control released, fallback written'), KL_NOTIFY);
            }
            $this->WriteAttributeString('LastSent', '{}');
            $this->WriteAttributeString('Desired', '{}');
            $this->SetValue('FallbackActive', false);
        }

        /** Watchdog (60 s): stale/valid_until, manual auto-return, heartbeat, retries. */
        protected function runWatchdog(): void
        {
            if ($this->ReadPropertyInteger('ControlMode') === self::CONTROL_DISPLAY || $this->deviceBlocked()) {
                return;
            }
            $active = $this->activeInstruction();
            $this->updatePlanStale($active);
            $until = $this->ReadAttributeInteger('ManualUntil');
            if ((int) $this->GetValue('ManualMode') !== self::MANUAL_AUTO && $until > 0 && $this->eosNow() >= $until) {
                $this->SetValue('ManualMode', self::MANUAL_AUTO);
                $this->WriteAttributeInteger('ManualUntil', 0);
                $this->WriteAttributeString('LastSent', '{}');
                $this->LogMessage($this->Translate('Manual mode ended, back to automatic'), KL_NOTIFY);
            }
            $this->scheduleControl($active, 'tick');
        }

        /** Form button / script API: re-evaluate now and write synchronously. */
        protected function applyControlNow(bool $force): bool
        {
            if ($this->deviceBlocked()) {
                return false;
            }
            $desired = $this->computeDesired($this->activeInstruction(), 'user');
            if ($desired === null) {
                $this->UpdateFormField('ControlResult', 'caption', (string) $this->GetValue('LastControlResult'));
                return false;
            }
            $this->WriteAttributeString('Desired', json_encode($desired, JSON_UNESCAPED_UNICODE));
            $this->runDispatch($force);
            $this->UpdateFormField('ControlResult', 'caption', (string) $this->GetValue('LastControlResult'));
            return true;
        }

        // ---------------------------------------------------------------- helpers / hooks

        protected function recordResult(string $text, bool $touchTime = true): void
        {
            if ($touchTime) {
                $this->SetValue('LastControl', $this->eosNow());
            }
            $this->SetValue('LastControlResult', $this->eosShorten($text, 250));
        }

        /** Warn on failures only when the failure signature changes (and once when it clears). */
        protected function logThrottled(string $failures): void
        {
            $previous = $this->ReadAttributeString('ControlErrorSig');
            if ($failures === $previous) {
                return;
            }
            $this->WriteAttributeString('ControlErrorSig', $failures);
            if ($failures !== '') {
                $this->LogMessage($this->Translate('Control write failed') . ': ' . $failures, KL_WARNING);
            } elseif ($previous !== '') {
                $this->LogMessage($this->Translate('Control writes OK again'), KL_NOTIFY);
            }
        }

        /** Hook: may the mode-row action for $modeRaw fire now? (appliance: start rules) */
        protected function rowActionAllowed(array $desired, string $modeRaw): bool
        {
            return true;
        }

        /** Hook: a desired state was dispatched (not in simulation); $success = every write and action succeeded. */
        protected function onDispatched(array $desired, bool $success): void
        {
        }

        abstract protected function controlTargets(): array;

        abstract protected function desiredFromInstruction(array $instruction): ?array;

        abstract protected function desiredFallback(): ?array;

        abstract protected function desiredManual(int $manualMode): array;

        abstract protected function modeMapRows(): array;

        abstract protected function manualModeOptions(): array;
    }
}
