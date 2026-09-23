<?php

declare(strict_types=1);

/*
 * Time fields of a device entry in EOS (vehicle departure, appliance deadline and
 * earliest start): read, compare and set or clear them with a path PUT - a null in
 * a merge never clears anything in EOS. Uses readConfig()/forward() from
 * EOSDeviceConfigSync and EOSPlanDevice.
 */
if (!trait_exists('EOSDeviceTimes')) {
    trait EOSDeviceTimes
    {
        /** Time of a field of this device's EOS entry as unix time; 0 = none, -1 = not readable. */
        protected function eosTimeField(string $field): int
        {
            $read = $this->readConfig(self::DEVICE_COLLECTION . '/' . $this->ReadPropertyString('DeviceID') . '/' . $field);
            if ($read['state'] !== 'ok') {
                return -1;
            }
            return is_string($read['value']) ? $this->eosParseTime($read['value']) : 0;
        }

        /**
         * Bring one time field of the device entry to $wantTs (0 = no time) with a path PUT:
         * only a path PUT with null clears a value in EOS, a null in a merge is ignored.
         * Returns 'same', 'written', 'failed' or 'skipped' (no entry yet, EOS not readable).
         */
        protected function reconcileTimeField(string $field, int $wantTs): string
        {
            $path = self::DEVICE_COLLECTION . '/' . $this->ReadPropertyString('DeviceID') . '/' . $field;
            $read = $this->readConfig($path);
            if ($read['state'] !== 'ok') {
                return 'skipped';
            }
            $eosTs = is_string($read['value']) ? $this->eosParseTime($read['value']) : 0;
            if ($wantTs === $eosTs && ($wantTs > 0 || $read['value'] === null)) {
                return 'same';
            }
            $res = $this->forward(['Command' => 'SetConfig', 'Path' => $path, 'Value' => $wantTs > 0 ? $this->eosIsoNow($wantTs) : null]);
            if (($res['ok'] ?? false) !== true) {
                $this->LogMessage(sprintf($this->Translate('EOS %s update failed: %s'), $field, (string) ($res['error'] ?? '?')), KL_WARNING);
                return 'failed';
            }
            return 'written';
        }

        /** Arm a one-shot style interval timer for the next time in $times (unix, 0 = none); never longer than 6 h. */
        protected function armExpiryTimer(string $timer, array $times): void
        {
            $future = array_filter($times, fn (int $t): bool => $t > $this->eosNow());
            $next = $future === [] ? 0 : min($future);
            $this->SetTimerInterval($timer, $next > 0 ? min(($next - $this->eosNow() + 1) * 1000, 6 * 3600 * 1000) : 0);
        }

        /** Warn once per value (attribute $attribute remembers the last warned value). */
        protected function warnOnce(string $attribute, string $value, string $message): void
        {
            if ($this->ReadAttributeString($attribute) === $value) {
                return;
            }
            $this->WriteAttributeString($attribute, $value);
            if ($value !== '') {
                $this->LogMessage($message, KL_WARNING);
            }
        }
    }
}
