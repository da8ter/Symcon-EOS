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
            $this->RegisterAttributeInteger('LastPushTs', 0);
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

        protected function handleSoCMessage(int $SenderID, int $Message): void
        {
            if ($Message === VM_UPDATE && $SenderID === $this->ReadPropertyInteger('SoCSourceVariable') && $SenderID > 0) {
                if (time() - $this->ReadAttributeInteger('LastPushTs') >= $this->ReadPropertyInteger('PushDebounce')) {
                    $this->PushSoC();
                }
            }
        }

        /** Override to suppress pushes (e.g. EV not plugged in). */
        protected function socPushAllowed(): bool
        {
            return true;
        }

        public function PushSoC(): bool
        {
            $varId = $this->ReadPropertyInteger('SoCSourceVariable');
            if ($varId <= 0 || !IPS_VariableExists($varId) || !$this->parentUsable() || !$this->socPushAllowed()) {
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
    }
}
