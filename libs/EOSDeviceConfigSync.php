<?php

declare(strict_types=1);

/*
 * Device entry in the EOS configuration, kept equal to the Symcon properties:
 * automatic compare-and-write on ApplyChanges, comparison text and EOS-to-form
 * loading when the configuration form opens, and the "Device in EOS" picker.
 *
 * The using class must use EOSCommon, EOSPlanDevice and EOSFormHelpers
 * (setFormAttribute), define DEVICE_COLLECTION (e.g. 'devices/batteries') and
 * SINGLE_DEVICE (true when GENETIC supports only one device of the kind) and
 * provide deviceConfig(): [path, device, merge] plus ReadConfigFromEOS().
 */
if (!trait_exists('EOSDeviceConfigSync')) {
    trait EOSDeviceConfigSync
    {
        /**
         * Read a configuration path. state: 'ok' (value may be null for a null field),
         * 'missing' (EOS answered 404) or 'error' (anything else: nothing may be
         * concluded from it, nothing may be written).
         */
        protected function readConfig(string $path): array
        {
            $res = $this->forward(['Command' => 'GetConfig', 'Path' => $path]);
            if (($res['ok'] ?? false) === true) {
                return ['state' => 'ok', 'value' => $res['data'] ?? null, 'error' => ''];
            }
            if ((int) ($res['status'] ?? 0) === 404) {
                return ['state' => 'missing', 'value' => null, 'error' => ''];
            }
            return ['state' => 'error', 'value' => null, 'error' => (string) ($res['error'] ?? 'no response from EOS Server')];
        }

        /**
         * Keep the device entry in the EOS configuration equal to the Symcon properties.
         * Without $force nothing is sent when EOS already holds the same values, so the
         * automatic call from ApplyChanges() is free of side effects on every restart.
         * Sets STATUS_OTHER_DEVICE (and writes nothing) when EOS already holds another
         * device of a kind GENETIC supports only once.
         */
        protected function syncDeviceConfig(string $path, array $device, array $merge, bool $force): bool
        {
            // null means "not set here": never delete a value the user maintains in EOSdash.
            $device = array_filter($device, static fn (mixed $v): bool => $v !== null);
            if (!$this->parentUsable()) {
                $this->UpdateFormField('ConfigInfo', 'caption', $this->Translate('EOS Server not available, configuration not compared.'));
                return false;
            }
            $read = $this->readConfig($path);
            if ($read['state'] === 'error') {
                $this->UpdateFormField('ConfigInfo', 'caption', sprintf($this->Translate('EOS configuration could not be read (%s); nothing compared, nothing written.'), $read['error']));
                return false;
            }
            $eos = is_array($read['value']) ? $read['value'] : null;
            if ($eos === null) {
                $other = $this->otherDevicesInEOS();
                if ($other !== '') {
                    $message = sprintf($this->Translate('EOS already has %s; pick it as device or remove it in EOSdash. Nothing was created.'), $other);
                    $this->SetStatus(self::STATUS_OTHER_DEVICE);
                    $this->UpdateFormField('ConfigInfo', 'caption', $message);
                    $this->LogMessage($message, KL_WARNING);
                    return false;
                }
            }
            $diff = $this->configDiff($eos, $device);
            if (!$force && $diff === []) {
                $this->UpdateFormField('ConfigInfo', 'caption', $this->Translate('EOS configuration matches this instance.'));
                $this->ensureDeviceMaximum();
                return true;
            }
            $res = $this->forward(['Command' => 'MergeConfig', 'Value' => $this->withoutNulls($merge)]);
            if (($res['ok'] ?? false) !== true) {
                $this->LogMessage(sprintf($this->Translate('Writing device configuration to EOS failed: %s'), (string) ($res['error'] ?? '?')), KL_WARNING);
                $this->UpdateFormField('ConfigInfo', 'caption', (string) ($res['error'] ?? '?'));
                return false;
            }
            $this->ensureDeviceMaximum();
            $save = $this->forward(['Command' => 'SaveConfig']);
            $keys = $diff !== [] ? implode(', ', $diff) : $this->Translate('all fields');
            $this->LogMessage(sprintf($this->Translate('Device configuration written to EOS (%s)'), $keys), KL_NOTIFY);
            $this->UpdateFormField('ConfigInfo', 'caption', ($save['ok'] ?? false) ? sprintf($this->Translate('Written to EOS: %s'), $keys) : (string) ($save['error'] ?? '?'));
            return (bool) ($save['ok'] ?? false);
        }

        /** Other device ids in EOS for a kind GENETIC supports only once; '' when none (or not such a kind). */
        protected function otherDevicesInEOS(): string
        {
            if (!self::SINGLE_DEVICE) {
                return '';
            }
            $read = $this->readConfig(self::DEVICE_COLLECTION);
            $ids = ($read['state'] === 'ok' && is_array($read['value'])) ? array_map('strval', array_keys($read['value'])) : [];
            return implode(', ', array_values(array_diff($ids, [$this->ReadPropertyString('DeviceID')])));
        }

        /**
         * devices/max_<kind> must allow this device: raised on its own, independent of the
         * device entry (a matching entry with max 0 makes every GENETIC run abort). Never
         * lowered; null means "no limit".
         */
        protected function ensureDeviceMaximum(): void
        {
            $kind = basename(self::DEVICE_COLLECTION);
            $read = $this->readConfig('devices/max_' . $kind);
            if ($read['state'] !== 'ok' || $read['value'] === null || !is_numeric($read['value'])) {
                return;
            }
            $needed = 1;
            if (!self::SINGLE_DEVICE) {
                $all = $this->readConfig(self::DEVICE_COLLECTION);
                $ids = ($all['state'] === 'ok' && is_array($all['value'])) ? array_map('strval', array_keys($all['value'])) : [];
                $needed = count(array_unique(array_merge($ids, [$this->ReadPropertyString('DeviceID')])));
            }
            if ((int) $read['value'] >= $needed) {
                return;
            }
            $res = $this->forward(['Command' => 'SetConfig', 'Path' => 'devices/max_' . $kind, 'Value' => $needed]);
            if (($res['ok'] ?? false) !== true) {
                $this->LogMessage(sprintf($this->Translate('Writing device configuration to EOS failed: %s'), (string) ($res['error'] ?? '?')), KL_WARNING);
                return;
            }
            $this->forward(['Command' => 'SaveConfig']);
            $this->LogMessage(sprintf($this->Translate('Raised %s to %d in EOS'), 'devices/max_' . $kind, $needed), KL_NOTIFY);
        }

        /**
         * Form open: when EOS differs, pull its values into the open form shortly after
         * it appeared (UpdateFormField needs the form to be open, GetConfigurationForm runs
         * before that). Apply then stores them in Symcon, Cancel keeps the Symcon values.
         */
        protected function registerFormFillTimer(): void
        {
            $this->RegisterTimer('FormFill', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'FillFormFromEOS\', \'\');');
        }

        protected function armFormFillIfDiffers(string $path, array $device): void
        {
            $device = array_filter($device, static fn (mixed $v): bool => $v !== null);
            if (!$this->parentUsable()) {
                return;
            }
            $read = $this->readConfig($path);
            if ($read['state'] === 'ok' && is_array($read['value']) && $this->configDiff($read['value'], $device) !== []) {
                $this->SetTimerInterval('FormFill', 1500);
            }
        }

        /** Same rule as the comparison: a null never reaches EOS, so it cannot delete a value kept there. */
        private function withoutNulls(array $data): array
        {
            $clean = [];
            foreach ($data as $key => $value) {
                if ($value === null) {
                    continue;
                }
                $clean[$key] = (is_array($value) && !array_is_list($value)) ? $this->withoutNulls($value) : $value;
            }
            return $clean;
        }

        /** Text for the form: does EOS hold the same values as this instance? */
        protected function eosConfigSummary(string $path, array $device): string
        {
            $device = array_filter($device, static fn (mixed $v): bool => $v !== null);
            if (!$this->parentUsable()) {
                return $this->Translate('EOS Server not available, configuration not compared.');
            }
            $read = $this->readConfig($path);
            if ($read['state'] === 'error') {
                return sprintf($this->Translate('EOS configuration could not be read (%s); nothing compared, nothing written.'), $read['error']);
            }
            $eos = is_array($read['value']) ? $read['value'] : null;
            if ($eos === null) {
                $other = $this->otherDevicesInEOS();
                return $other !== ''
                    ? sprintf($this->Translate('EOS already has %s; pick it as device or remove it in EOSdash. Nothing was created.'), $other)
                    : $this->Translate('Device not in EOS yet; it is created on Apply.');
            }
            $diff = $this->configDiff($eos, $device);
            if ($diff === []) {
                return $this->Translate('EOS configuration matches this instance.');
            }
            $parts = [];
            foreach ($diff as $key) {
                $parts[] = $key . ': EOS ' . $this->eosShorten((string) json_encode($eos[$key] ?? null, JSON_UNESCAPED_UNICODE), 40) . ' / Symcon ' . $this->eosShorten((string) json_encode($device[$key], JSON_UNESCAPED_UNICODE), 40);
            }
            return $this->Translate('EOS differs; its values are loaded into the form. Apply stores them in Symcon, Cancel keeps the Symcon values:') . ' ' . implode(' · ', $parts);
        }

        /** Keys of $device whose value differs from the EOS entry (all keys when EOS has no entry). */
        protected function configDiff(?array $eos, array $device): array
        {
            $diff = [];
            foreach ($device as $key => $value) {
                if ($eos === null || !array_key_exists($key, $eos) || !$this->configEquals($eos[$key], $value)) {
                    $diff[] = (string) $key;
                }
            }
            return $diff;
        }

        private function configEquals(mixed $eos, mixed $ours): bool
        {
            if (is_array($ours)) {
                if (!is_array($eos)) {
                    return false;
                }
                if (array_is_list($ours)) {
                    if (!array_is_list($eos) || count($eos) !== count($ours)) {
                        return false;
                    }
                    foreach ($ours as $i => $item) {
                        if (!$this->configEquals($eos[$i], $item)) {
                            return false;
                        }
                    }
                    return true;
                }
                foreach ($ours as $k => $item) { // EOS adds defaults (day_of_week, date, locale): compare our keys only
                    if (!array_key_exists($k, $eos) || !$this->configEquals($eos[$k], $item)) {
                        return false;
                    }
                }
                return true;
            }
            if (is_int($ours) || is_float($ours)) {
                return is_numeric($eos) && abs((float) $eos - (float) $ours) < 1e-6;
            }
            if (is_string($ours) && is_string($eos)) {
                $a = $this->eosParseTime($ours);
                $b = $this->eosParseTime($eos);
                if ($a > 0 && $b > 0) {
                    return $a === $b; // timestamps: EOS normalises the notation
                }
                return $ours === $eos || preg_replace('/\.0+$/', '', $ours) === preg_replace('/\.0+$/', '', $eos); // 08:00:00 vs 08:00:00.000000
            }
            return $eos === $ours || ($eos === null && $ours === null);
        }

        // ------------------------------------------------------------------ device picker (form)

        /** Property behind the "Device in EOS" select; the chosen id is copied into DeviceID via onChange. */
        protected function registerDevicePicker(): void
        {
            $this->RegisterPropertyString('EOSDevicePick', '');
        }

        /** Fill the select named EOSDevicePick with the device ids EOS knows in DEVICE_COLLECTION. */
        protected function fillDevicePicker(array &$form): void
        {
            $current = $this->ReadPropertyString('DeviceID');
            $options = [['caption' => sprintf($this->Translate('- select from EOS (current: %s) -'), $current), 'value' => '']];
            if (!$this->parentUsable()) {
                $options[0]['caption'] = $this->Translate('- EOS Server not available -');
            } else {
                $read = $this->readConfig(self::DEVICE_COLLECTION);
                if ($read['state'] === 'error') {
                    $options[0]['caption'] = $this->Translate('- EOS configuration not readable -');
                }
                foreach (array_keys(($read['state'] === 'ok' && is_array($read['value'])) ? $read['value'] : []) as $id) {
                    $options[] = ['caption' => (string) $id . ((string) $id === $current ? ' ✓' : ''), 'value' => (string) $id];
                }
            }
            $this->setFormAttribute($form['elements'], 'EOSDevicePick', 'options', $options);
        }
    }
}
