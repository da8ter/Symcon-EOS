<?php

declare(strict_types=1);

/*
 * State-of-charge push for battery-like devices (battery, electric vehicle).
 *
 * The using class must use EOSPlanDevice (forward(), parentUsable()), register
 * a timer 'SoCPush' calling <PREFIX>_PushSoC and the variables SoCSent (float)
 * and LastPush (int). Properties/attributes are registered via
 * registerSoCProperties(); the source variable is (re-)registered with
 * setupSoCSource() from ApplyChanges() and change messages are handled by
 * handleSoCMessage() from MessageSink().
 */
if (!trait_exists('EOSSoCPush')) {
    trait EOSSoCPush
    {
        protected function registerSoCProperties(): void
        {
            $this->RegisterPropertyInteger('SoCSourceVariable', 0);
            $this->RegisterPropertyInteger('SoCUnit', 0); // 0 = percent, 1 = factor
            $this->RegisterPropertyInteger('PushInterval', 120);
            $this->RegisterPropertyInteger('PushDebounce', 10);
            // 0 = off; otherwise a source not updated for this long is not sent (EOS then refuses
            // to plan with a stale SoC and the devices fall back, instead of planning on old data).
            $this->RegisterPropertyInteger('SoCMaxAgeMinutes', 0);
            $this->RegisterAttributeInteger('LastPushTs', 0);
            $this->RegisterAttributeInteger('LastPushAttemptTs', 0);
            $this->RegisterAttributeInteger('PushFailCount', 0);
            $this->RegisterAttributeString('SoCWarned', '');
            $this->RegisterAttributeInteger('RegisteredSoCVar', 0);
        }

        protected function registerSoCVariables(int $position): void
        {
            $this->RegisterVariableFloat('SoCSent', $this->Translate('SoC sent'), $this->eosValuePresentation('Battery', '', 3), $position);
            $this->RegisterVariableInteger('LastPush', $this->Translate('Last push'), $this->eosDateTimePresentation('Repeat'), $position + 10);
        }

        /** Returns true when a usable source variable is configured. */
        protected function setupSoCSource(): bool
        {
            $old = $this->ReadAttributeInteger('RegisteredSoCVar');
            $src = $this->ReadPropertyInteger('SoCSourceVariable');
            if ($old > 0 && $old !== $src) {
                $this->UnregisterMessage($old, VM_UPDATE);
            }
            if ($src > 0 && IPS_VariableExists($src)) {
                $this->RegisterMessage($src, VM_UPDATE);
                $this->WriteAttributeInteger('RegisteredSoCVar', $src);
                return true;
            }
            $this->WriteAttributeInteger('RegisteredSoCVar', 0);
            return false;
        }

        /**
         * Source updated: push when the value changed, not on every update (the push timer
         * keeps EOS fresh). Debounced on the last ATTEMPT, with a backoff after failures, so a
         * rejected push cannot turn every source update into a request plus a warning.
         */
        protected function handleSoCMessage(int $SenderID, int $Message, array $Data = []): void
        {
            if ($Message !== VM_UPDATE || $SenderID !== $this->ReadPropertyInteger('SoCSourceVariable') || $SenderID <= 0 || !$this->eosValueChanged($Data)) {
                return;
            }
            $wait = max($this->ReadPropertyInteger('PushDebounce'), $this->ReadAttributeInteger('PushFailCount') > 0 ? 60 * min(5, $this->ReadAttributeInteger('PushFailCount')) : 0);
            if ($this->eosNow() - $this->ReadAttributeInteger('LastPushAttemptTs') >= $wait) {
                $this->PushSoC();
            }
        }

        /** VM_UPDATE data: [0] new value, [1] changed (bool), [2] old value; unknown layout = changed. */
        protected function eosValueChanged(array $Data): bool
        {
            if (array_key_exists(1, $Data) && is_bool($Data[1])) {
                return $Data[1];
            }
            if (array_key_exists(0, $Data) && array_key_exists(2, $Data)) {
                return $Data[0] !== $Data[2];
            }
            return true;
        }

        /**
         * Source value -> SoC factor 0..1. Percent: up to 105 % is clamped to 100 %. Factor:
         * up to 1.05 is clamped to 1; 1.05..105 looks like percent and is divided once.
         * Anything else is rejected (null) instead of being sent as a wrong SoC.
         */
        protected function socFactor(float $raw): ?float
        {
            if ($this->ReadPropertyInteger('SoCUnit') === 1 && $raw > 1.05 && $raw <= 105.0) {
                $this->warnSoCOnce('percent', sprintf($this->Translate('SoC source value %s looks like percent although "factor" is set; it is divided by 100'), (string) $raw));
                $raw /= 100.0;
            } elseif ($this->ReadPropertyInteger('SoCUnit') === 0) {
                $raw /= 100.0;
            }
            if ($raw < 0.0 || $raw > 1.05) {
                return null;
            }
            return round(min(1.0, $raw), 4);
        }

        private function warnSoCOnce(string $kind, string $message): void
        {
            if ($this->ReadAttributeString('SoCWarned') !== $kind) {
                $this->WriteAttributeString('SoCWarned', $kind);
                $this->LogMessage($message, KL_WARNING);
            }
        }

        /** Hook after a successful push (the vehicle reconciles its departure there). */
        protected function afterSoCPush(): void
        {
        }

        /** Override to replace the value that is sent (e.g. an unplugged vehicle keeps its last value). */
        protected function socValueForPush(float $factor): float
        {
            return $factor;
        }

        public function PushSoC(): bool
        {
            $varId = $this->ReadPropertyInteger('SoCSourceVariable');
            if ($varId <= 0 || !IPS_VariableExists($varId) || !$this->parentUsable() || $this->deviceBlocked()) {
                return false;
            }
            $maxAge = $this->ReadPropertyInteger('SoCMaxAgeMinutes');
            if ($maxAge > 0 && $this->eosNow() - (int) (IPS_GetVariable($varId)['VariableUpdated'] ?? 0) > $maxAge * 60) {
                $this->warnSoCOnce('stale', sprintf($this->Translate('SoC source not updated for more than %d minutes; not sent to EOS'), $maxAge));
                return false;
            }
            $raw = (float) GetValue($varId);
            $factor = $this->socFactor($raw);
            if ($factor === null) {
                $this->warnSoCOnce('range', sprintf($this->Translate('SoC source value %s is out of range; not sent to EOS'), (string) $raw));
                return false;
            }
            $factor = $this->socValueForPush($factor);
            $this->WriteAttributeInteger('LastPushAttemptTs', $this->eosNow());

            $res = $this->forward([
                'Command'  => 'PutMeasurement',
                'Key'      => $this->ReadPropertyString('DeviceID') . '-soc-factor',
                'Value'    => $factor,
                'DateTime' => $this->eosIsoNow(),
            ]);
            if (($res['ok'] ?? false) === true) {
                if ($this->ReadAttributeInteger('PushFailCount') > 0) {
                    $this->LogMessage($this->Translate('SoC push works again'), KL_NOTIFY);
                }
                $this->WriteAttributeInteger('PushFailCount', 0);
                $this->WriteAttributeString('SoCWarned', '');
                $this->SetValue('SoCSent', $factor);
                $this->SetValue('LastPush', $this->eosNow());
                $this->WriteAttributeInteger('LastPushTs', $this->eosNow());
                $this->UpdateFormField('ActionResult', 'caption', sprintf($this->Translate('SoC %.3f sent'), $factor));
                $this->afterSoCPush();
                return true;
            }
            $error = (string) ($res['error'] ?? 'no response');
            $fails = $this->ReadAttributeInteger('PushFailCount') + 1;
            $this->WriteAttributeInteger('PushFailCount', $fails);
            if ($fails === 1) {
                $this->LogMessage(sprintf($this->Translate('SoC push failed: %s'), $error), KL_WARNING); // once, until it works again
            }
            $this->UpdateFormField('ActionResult', 'caption', sprintf($this->Translate('SoC push failed: %s'), $error));
            return false;
        }
    }
}
