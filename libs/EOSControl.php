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
 * onDispatched(array $desired, array $outcome): void (outcome per target, mode-row
 * action and change action plus 'success'), validateControlDevice(): string.
 *
 * Desired state: ['modeRaw', 'mode', 'factor', 'targets' => [Key => value], 'context' => [...],
 *                 'executionTime', 'degraded'] (+ 'source', 'trigger' added here).
 *
 * Writes never happen in the parent's SendDataToChildren thread: scheduleControl()
 * stores the desired state and arms a one-shot timer, runDispatch() (EOSControlDispatch)
 * does the work.
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
            // Last plan instruction the control acted on, for the short gap before a new plan starts.
            $this->RegisterAttributeString('LastPlanInstruction', '{}');
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
            // Retries after the backoff and per-target heartbeats, armed after every dispatch.
            $this->RegisterTimer('Retry', 0, self::CONTROL_PREFIX . '_Dispatch($_IPS[\'TARGET\']);');
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
            // Release once when "active" is left; not again when the master switch already released.
            if ($previous === self::CONTROL_ACTIVE && $current !== self::CONTROL_ACTIVE && $this->ReadPropertyBoolean('ReleaseOnDisable') && (bool) $this->GetValue('ControlActive')) {
                $this->releaseDevice('disable', false);
            }
            if ($previous !== $current) {
                // A state computed under another mode (e.g. a simulation) is never replayed.
                $this->WriteAttributeString('Desired', '{}');
                $this->WriteAttributeString('LastPlanInstruction', '{}');
            }
            $this->WriteAttributeInteger('LastControlMode', $current);
            // One guaranteed write after every Apply / restart: the hardware state is unknown.
            $this->WriteAttributeString('LastSent', json_encode(['v' => 2, 'resync' => true]));

            $check = $current === self::CONTROL_DISPLAY ? ['blocking' => '', 'warnings' => []] : $this->checkBindings();
            $problem = $check['blocking'] !== '' ? $check['blocking'] : implode(' · ', $check['warnings']);
            $this->WriteAttributeBoolean('ControlReady', $check['blocking'] === '');
            $this->WriteAttributeString('ControlProblem', $problem);
            if ($check['blocking'] !== '') {
                $this->recordResult($this->Translate('control not ready') . ': ' . $problem, false);
                $this->LogMessage($this->Translate('control not ready') . ': ' . $problem, KL_WARNING);
            } elseif ($problem !== '') {
                // A broken single binding is skipped (with backoff); fallback and the other bindings keep working.
                $this->LogMessage($this->Translate('control binding problem') . ': ' . $problem, KL_WARNING);
            }
            // Set only here, never in the hot path (SetTimerInterval restarts the countdown).
            $this->SetTimerInterval('Watchdog', $current === self::CONTROL_DISPLAY ? 0 : self::WATCHDOG_MS);
            $this->SetTimerInterval('Retry', 0);
            if ($current === self::CONTROL_DISPLAY) {
                // ManualUntil stays: after returning to "active" the manual mode ends on time.
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
                $held = json_encode(['instruction' => $active, 'generatedAt' => $this->planGeneratedAt()]);
                if ($this->ReadAttributeString('LastPlanInstruction') !== $held) {
                    $this->WriteAttributeString('LastPlanInstruction', $held);
                }
            } else {
                if ($reason === '') {
                    $reason = 'unknown mode ' . (string) ($active['operation_mode_id'] ?? '');
                }
                if ($reason === 'gap' && ($held = $this->heldInstruction()) !== null && ($desired = $this->desiredFromInstruction($held)) !== null) {
                    // Fresh plan, first instruction imminent: keep following the last instruction,
                    // re-evaluated with today's policy, limits and plug state.
                    $this->setFallbackActive(false, '');
                    $desired['source'] = 'plan';
                    $desired['trigger'] = $trigger;
                    return $desired;
                }
                $desired = $this->desiredFallback();
                $source = 'fallback';
            }
            $this->setFallbackActive($source === 'fallback', $reason);
            if ($desired === null) {
                $text = $this->Translate('fallback: no intervention');
                if ((string) $this->GetValue('LastControlResult') !== $text) {
                    $this->recordResult($text, false);
                }
                return null;
            }
            $desired['source'] = $source;
            $desired['trigger'] = $trigger;
            return $desired;
        }

        /** Last plan instruction acted on, while its plan is still fresh; null otherwise. */
        protected function heldInstruction(): ?array
        {
            $held = $this->eosJsonDecode($this->ReadAttributeString('LastPlanInstruction'), []);
            if (!is_array($held) || !is_array($held['instruction'] ?? null)) {
                return null;
            }
            $age = $this->eosNow() - (int) ($held['generatedAt'] ?? 0);
            return $age <= $this->ReadPropertyInteger('StaleAfterMinutes') * 60 ? $held['instruction'] : null;
        }

        /** Fast path for onPlanProcessed()/watchdog: store the desired state, defer the writes. */
        protected function scheduleControl(?array $active, string $trigger): void
        {
            // Switched off: released once already, nothing to decide until it is switched on again.
            if ($this->deviceBlocked() || !$this->GetValue('ControlActive')) {
                return;
            }
            $desired = $this->computeDesired($active, $trigger);
            if ($desired === null) {
                $this->dropDesired();
                return;
            }
            if ($trigger === 'tick' && isset($this->lastSent()['targets'])) {
                // Watchdog: only a changed state needs a dispatch; retries and heartbeats run on the Retry timer.
                $stored = $this->eosJsonDecode($this->ReadAttributeString('Desired'), []);
                unset($stored['trigger']);
                $compare = $desired;
                unset($compare['trigger']);
                if (json_encode($stored) === json_encode($compare)) {
                    return;
                }
            }
            $this->WriteAttributeString('Desired', json_encode($desired, JSON_UNESCAPED_UNICODE));
            $this->RegisterOnceTimer('Dispatch', self::CONTROL_PREFIX . '_Dispatch($_IPS[\'TARGET\']);');
        }

        /**
         * Nothing to drive (fallback "no intervention", bindings not ready): the stored state must
         * not be replayed by retries or heartbeats. The device is left alone from here on, so its
         * state is unknown when control resumes.
         */
        protected function dropDesired(): void
        {
            if ($this->ReadAttributeString('Desired') === '{}') {
                return;
            }
            $this->WriteAttributeString('Desired', '{}');
            $this->WriteAttributeString('LastSent', json_encode(['v' => 2, 'resync' => true]));
            $this->SetTimerInterval('Retry', 0);
        }

        protected function setFallbackActive(bool $on, string $reason): void
        {
            if ($on === (bool) $this->GetValue('FallbackActive')) {
                return;
            }
            $this->SetValue('FallbackActive', $on);
            $this->LogMessage($on ? sprintf($this->Translate('Fallback activated (%s)'), $reason) : $this->Translate('Fallback ended'), KL_NOTIFY);
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
            $this->onManualModeChanged($mode);
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
                $this->onManualModeChanged(self::MANUAL_AUTO);
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

        /**
         * Warn when something starts failing (a new target or a new kind of error) and once when
         * everything works again. $failing is the state after the dispatch, including targets that
         * wait in their backoff; a heartbeat that skips them therefore does not count as recovery.
         */
        protected function logThrottled(array $failing, string $text): void
        {
            $previous = $this->eosJsonDecode($this->ReadAttributeString('ControlErrorSig'), []);
            $previous = is_array($previous) ? $previous : []; // text from before 23.09.2026 counts as nothing
            if ($failing === $previous) {
                return;
            }
            $this->WriteAttributeString('ControlErrorSig', json_encode($failing));
            if (array_diff($failing, $previous) !== []) {
                $this->LogMessage($this->Translate('Control write failed') . ': ' . ($text !== '' ? $text : implode(', ', $failing)), KL_WARNING);
            } elseif ($failing === []) {
                $this->LogMessage($this->Translate('Control writes OK again'), KL_NOTIFY);
            }
        }

        /** Hook: may the mode-row action for $modeRaw fire now? (appliance: start rules) */
        protected function rowActionAllowed(array $desired, string $modeRaw): bool
        {
            return true;
        }

        /** Hook: the manual mode was set by the user, a script or the automatic return. */
        protected function onManualModeChanged(int $mode): void
        {
        }

        /** Hook: a desired state was dispatched (not in simulation); see EOSControlDispatch::deviceSideOk(). */
        protected function onDispatched(array $desired, array $outcome): void
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
