<?php

declare(strict_types=1);

/*
 * Device entry in the EOS configuration, kept in step with the Symcon properties by a
 * three-way comparison against the last state known equal on both sides (SyncedConfig):
 *   Symcon changed since then          -> written to EOS
 *   only EOS changed (EOSdash)         -> left alone, offered in the form (FormFill)
 *   both changed to different values   -> conflict: nothing written, both values shown
 * Properties change only through the user, so kernel start, module reload and the
 * deferred ApplyChanges never overwrite what was edited in EOSdash. Without a snapshot
 * the base is the device picked in the open form (PickSnapshot), else adoptionBase():
 * without evidence of a Symcon edit EOS keeps what it has (the first sync after an update,
 * an id typed in for an existing entry) and only its empty fields are filled.
 *
 * The using class must use EOSCommon, EOSPlanDevice and EOSFormHelpers, define
 * DEVICE_COLLECTION (e.g. 'devices/batteries'), SINGLE_DEVICE (GENETIC supports only
 * one battery and one vehicle) and CONFIG_DEFAULTS (property => default), and provide
 * deviceConfig(): [path, device, merge] reading properties through configValue(), plus
 * configFieldMap(): [property => [eos key, int|float|string|rates|windows]]. The form
 * side (summary, FormFill, picker, buttons) lives in EOSDeviceConfigForm, the comparison
 * (classifyConfig and the EOS notation rules) in EOSConfigCompare.
 */
if (!trait_exists('EOSDeviceConfigSync')) {
    trait EOSDeviceConfigSync
    {
        /** Register the configuration properties (single source: CONFIG_DEFAULTS) and the sync state. */
        protected function registerConfigProperties(): void
        {
            foreach (self::CONFIG_DEFAULTS as $name => $default) {
                match (true) {
                    is_bool($default)  => $this->RegisterPropertyBoolean($name, $default),
                    is_int($default)   => $this->RegisterPropertyInteger($name, $default),
                    is_float($default) => $this->RegisterPropertyFloat($name, $default),
                    default            => $this->RegisterPropertyString($name, (string) $default),
                };
            }
            $this->RegisterPropertyString('EOSDevicePick', '');
            $this->RegisterAttributeString('SyncedConfig', '{}');
            $this->RegisterAttributeString('PickSnapshot', '{}');
            $this->RegisterAttributeString('EOSValues', '{}');
            // The EOS entry this instance used before its DeviceID changed, until it is removed.
            $this->RegisterAttributeString('PreviousDeviceID', '');
        }

        /** A configuration property, typed after its default in CONFIG_DEFAULTS. */
        protected function configValue(string $name): mixed
        {
            $default = self::CONFIG_DEFAULTS[$name];
            return match (true) {
                is_bool($default)  => $this->ReadPropertyBoolean($name),
                is_int($default)   => $this->ReadPropertyInteger($name),
                is_float($default) => $this->ReadPropertyFloat($name),
                default            => $this->ReadPropertyString($name),
            };
        }

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

        /** Force-write every differing field ("Overwrite EOS with these values"). */
        public function WriteConfigToEOS(): bool
        {
            if ($this->deviceBlocked() || !$this->validDeviceId($this->ReadPropertyString('DeviceID'))) {
                // Invalid, foreign or refused id: the entry is not ours to write (201/203/205).
                $this->UpdateFormField('ConfigInfo', 'caption', $this->Translate('The device id is invalid or not owned by this instance; nothing written to EOS.'));
                return false;
            }
            [$path, $device, $merge] = $this->deviceConfig();
            return $this->syncDeviceConfig($path, $device, $merge, true);
        }

        /**
         * Keep the device entry in EOS in step with the properties (see the file header).
         * $force writes every differing field. Sets STATUS_OTHER_DEVICE (and writes nothing)
         * when EOS already holds another device of a kind GENETIC supports only once.
         */
        protected function syncDeviceConfig(string $path, array $device, array $merge, bool $force): bool
        {
            if (!$this->parentUsable()) {
                $this->UpdateFormField('ConfigInfo', 'caption', $this->Translate('EOS Server not available, configuration not compared.'));
                return false;
            }
            $id = $this->ReadPropertyString('DeviceID');
            $read = $this->readConfig($path);
            if ($read['state'] === 'error') {
                $this->UpdateFormField('ConfigInfo', 'caption', sprintf($this->Translate('EOS configuration could not be read (%s); nothing compared, nothing written.'), $read['error']));
                return false;
            }
            $previousId = (string) ($this->syncedState()['id'] ?? '');
            if ($previousId !== '' && $previousId !== $id) {
                $this->WriteAttributeString('PreviousDeviceID', $previousId); // kept beyond this sync, see oldDeviceId()
            } elseif ($this->ReadAttributeString('PreviousDeviceID') === $id) {
                $this->WriteAttributeString('PreviousDeviceID', ''); // renamed back
            }
            $eos = is_array($read['value']) ? $read['value'] : null;
            if ($eos === null) {
                return $this->createDeviceEntry($id, $device, $merge, $previousId);
            }
            $base = $this->syncedBase($id) ?? $this->adoptionBase($device, $eos);
            $class = $this->classifyConfig($device, $eos, $base);
            if ($force) {
                $class['push'] = array_merge($class['push'], $class['eos'], $class['conflict']);
                $class['eos'] = $class['conflict'] = [];
            }
            $newBase = $base;
            foreach ($class['same'] as $key) {
                $newBase[$key] = $device[$key];
            }
            if ($class['push'] !== []) {
                if (!$this->writeConfigKeys($id, $device, $class['push'], $eos)) {
                    return false;
                }
                foreach ($class['push'] as $key) {
                    $newBase[$key] = $device[$key];
                    $eos[$key] = $device[$key]; // what EOS holds now (EOSValues below)
                }
                $this->forward(['Command' => 'SaveConfig']);
                $this->LogMessage(sprintf($this->Translate('Device configuration written to EOS (%s)'), implode(', ', $class['push'])), KL_NOTIFY);
            }
            $this->WriteAttributeString('SyncedConfig', json_encode(['v' => 1, 'id' => $id, 'base' => $newBase, 'ts' => $this->eosNow()]));
            $this->rememberEOSValues($id, $eos);
            $this->UpdateFormField('ConfigInfo', 'caption', $this->syncText($class, $device, $eos));
            $this->ensureDeviceMaximum();
            if ($previousId !== $id) {
                $this->onDeviceSynced($id, $previousId);
            }
            return true;
        }

        /** Entry missing in EOS: create it, unless GENETIC could not handle a second device of the kind. */
        private function createDeviceEntry(string $id, array $device, array $merge, string $previousId): bool
        {
            $other = $this->otherDevicesInEOS();
            if ($other !== '') {
                $message = ($previousId !== '' && $previousId !== $id && in_array($previousId, explode(', ', $other), true))
                    ? sprintf($this->Translate('EOS still holds the old device %s; remove it with "Remove old EOS entry", then Apply.'), $previousId)
                    : sprintf($this->Translate('EOS already has %s; pick it as device or remove it in EOSdash. Nothing was created.'), $other);
                $this->SetStatus(self::STATUS_OTHER_DEVICE);
                $this->UpdateFormField('ConfigInfo', 'caption', $message);
                $this->LogMessage($message, KL_WARNING);
                return false;
            }
            $res = $this->forward(['Command' => 'MergeConfig', 'Value' => $this->withoutNulls($merge)]);
            if (($res['ok'] ?? false) !== true) {
                $this->LogMessage(sprintf($this->Translate('Writing device configuration to EOS failed: %s'), (string) ($res['error'] ?? '?')), KL_WARNING);
                $this->UpdateFormField('ConfigInfo', 'caption', (string) ($res['error'] ?? '?'));
                return false;
            }
            $this->ensureDeviceMaximum();
            $this->forward(['Command' => 'SaveConfig']);
            $this->WriteAttributeString('SyncedConfig', json_encode(['v' => 1, 'id' => $id, 'base' => $device, 'ts' => $this->eosNow()]));
            $this->rememberEOSValues($id, $device);
            $this->LogMessage(sprintf($this->Translate('Device configuration written to EOS (%s)'), $this->Translate('all fields')), KL_NOTIFY);
            $this->UpdateFormField('ConfigInfo', 'caption', sprintf($this->Translate('Written to EOS: %s'), $this->Translate('all fields')));
            $this->onDeviceSynced($id, $previousId);
            return true;
        }

        /** Write $keys of $device into the EOS entry $eos: values by merge, cleared fields by path PUT null. */
        private function writeConfigKeys(string $id, array $device, array $keys, array $eos): bool
        {
            $values = ['device_id' => $id];
            foreach ($keys as $key) {
                if ($device[$key] !== null) {
                    $values[$key] = $device[$key];
                }
            }
            // EOS validates a partial body with its defaults (min 0 / max 100), so both limits go
            // together; the one not written keeps the value EOS holds (EOSdash may own it).
            foreach ([['min_soc_percentage', 'max_soc_percentage'], ['max_soc_percentage', 'min_soc_percentage']] as [$written, $partner]) {
                if (isset($values[$written]) && !isset($values[$partner]) && array_key_exists($partner, $device)) {
                    $values[$partner] = $eos[$partner] ?? $device[$partner];
                }
            }
            if (count($values) > 1) {
                $res = $this->forward(['Command' => 'MergeConfig', 'Value' => ['devices' => [basename(self::DEVICE_COLLECTION) => [$id => $values]]]]);
                if (($res['ok'] ?? false) !== true) {
                    $this->LogMessage(sprintf($this->Translate('Writing device configuration to EOS failed: %s'), (string) ($res['error'] ?? '?')), KL_WARNING);
                    $this->UpdateFormField('ConfigInfo', 'caption', (string) ($res['error'] ?? '?'));
                    return false;
                }
            }
            foreach ($keys as $key) {
                if ($device[$key] === null) {
                    // Only a path PUT with null clears a value (e.g. emptied time windows).
                    $res = $this->forward(['Command' => 'SetConfig', 'Path' => self::DEVICE_COLLECTION . '/' . $id . '/' . $key, 'Value' => null]);
                    if (($res['ok'] ?? false) !== true) {
                        $this->LogMessage(sprintf($this->Translate('Writing device configuration to EOS failed: %s'), (string) ($res['error'] ?? '?')), KL_WARNING);
                        return false;
                    }
                }
            }
            return true;
        }

        /** The EOS entry this instance used before its DeviceID changed ('' = none). */
        protected function oldDeviceId(): string
        {
            $current = $this->ReadPropertyString('DeviceID');
            foreach ([$this->ReadAttributeString('PreviousDeviceID'), (string) ($this->syncedState()['id'] ?? '')] as $old) {
                if ($old !== '' && $old !== $current) {
                    return $old;
                }
            }
            return '';
        }

        private function syncedState(): array
        {
            $synced = $this->eosJsonDecode($this->ReadAttributeString('SyncedConfig'), []);
            return is_array($synced) ? $synced : [];
        }

        /**
         * Last common state for $id: the snapshot, else a device picked in the form that is
         * still open (cleared when a form is built, at most an hour old); null = unknown.
         */
        private function syncedBase(string $id): ?array
        {
            $synced = $this->syncedState();
            if (($synced['id'] ?? '') === $id && is_array($synced['base'] ?? null)) {
                return $synced['base'];
            }
            $pick = $this->eosJsonDecode($this->ReadAttributeString('PickSnapshot'), []);
            if (is_array($pick) && ($pick['id'] ?? '') === $id && is_array($pick['eos'] ?? null) && $this->eosNow() - (int) ($pick['ts'] ?? 0) < 3600) {
                return $pick['eos'];
            }
            return null;
        }

        /** Re-read the EOS values the control math uses when older than $maxAge s (EOSdash may change them at any time). */
        protected function refreshEOSValues(int $maxAge): void
        {
            $id = $this->ReadPropertyString('DeviceID');
            $values = $this->eosJsonDecode($this->ReadAttributeString('EOSValues'), []);
            if (is_array($values) && ($values['id'] ?? '') === $id && $this->eosNow() - (int) ($values['ts'] ?? 0) < $maxAge) {
                return;
            }
            $read = $this->readConfig(self::DEVICE_COLLECTION . '/' . $id . '/max_charge_power_w');
            if ($read['state'] === 'ok') {
                $this->rememberEOSValues($id, ['max_charge_power_w' => $read['value']]);
            }
        }

        private function rememberEOSValues(string $id, array $entry): void
        {
            $this->WriteAttributeString('EOSValues', json_encode(['id' => $id, 'max_charge_power_w' => $entry['max_charge_power_w'] ?? null, 'ts' => $this->eosNow()]));
        }

        /** A value of this device's EOS entry as last read (e.g. max_charge_power_w for the control math). */
        protected function eosValue(string $key): mixed
        {
            $values = $this->eosJsonDecode($this->ReadAttributeString('EOSValues'), []);
            return (is_array($values) && ($values['id'] ?? '') === $this->ReadPropertyString('DeviceID')) ? ($values[$key] ?? null) : null;
        }

        private function syncText(array $class, array $device, array $eos): string
        {
            $parts = [];
            foreach (['eos' => 'changed in EOS', 'conflict' => 'conflict'] as $kind => $label) {
                foreach ($class[$kind] as $key) {
                    $parts[] = $this->Translate($label) . ' ' . $key . ': EOS ' . $this->eosShorten((string) json_encode($eos[$key] ?? null, JSON_UNESCAPED_UNICODE), 40) . ' / Symcon ' . $this->eosShorten((string) json_encode($device[$key], JSON_UNESCAPED_UNICODE), 40);
                }
            }
            if ($parts === []) {
                return $class['push'] !== [] ? sprintf($this->Translate('Written to EOS: %s'), implode(', ', $class['push'])) : $this->Translate('EOS configuration matches this instance.');
            }
            return $this->Translate('EOS differs; "Load values from EOS" takes them over, "Overwrite EOS with these values" keeps Symcon:') . ' ' . implode(' · ', $parts);
        }

        /** Hook: this instance now owns the EOS entry $id (created, adopted or renamed from $previousId). */
        protected function onDeviceSynced(string $id, string $previousId): void
        {
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

        /** Same rule as the comparison: a null never reaches EOS in a merge. */
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

    }
}
