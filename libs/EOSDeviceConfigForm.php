<?php

declare(strict_types=1);

/*
 * Form side of the device configuration sync (EOSDeviceConfigSync): comparison text,
 * FormFill (only fields EOS changed, never pending Symcon edits), the "Device in EOS"
 * picker, "Load values from EOS" and "Remove old EOS entry".
 */
if (!trait_exists('EOSDeviceConfigForm')) {
    trait EOSDeviceConfigForm
    {
        /** Load every mapped field of the EOS entry into the open form ("Load values from EOS"). */
        public function ReadConfigFromEOS(): bool
        {
            if (!$this->validDeviceId($this->ReadPropertyString('DeviceID'))) {
                $this->UpdateFormField('ConfigInfo', 'caption', $this->Translate('The device id is invalid; nothing loaded.'));
                return false;
            }
            $read = $this->readConfig(self::DEVICE_COLLECTION . '/' . $this->ReadPropertyString('DeviceID'));
            if ($read['state'] !== 'ok' || !is_array($read['value'])) {
                $this->UpdateFormField('ConfigInfo', 'caption', sprintf($this->Translate('No device %s in EOS configuration.'), $this->ReadPropertyString('DeviceID')));
                return false;
            }
            $this->loadIntoForm($read['value'], null);
            $this->UpdateFormField('ConfigInfo', 'caption', $this->Translate('Values from EOS loaded into the form. Apply stores them in Symcon, Cancel keeps the Symcon values.'));
            return true;
        }

        /**
         * Form open: when EOS holds newer or conflicting values, pull them into the open form
         * shortly after it appeared (UpdateFormField needs the form to be open, the form is
         * built before that). Pending Symcon edits are never overwritten.
         */
        protected function registerFormFillTimer(): void
        {
            $this->RegisterTimer('FormFill', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'FillFormFromEOS\', \'\');');
        }

        /** [class, eos entry] for the form, or null when there is nothing to compare. */
        private function formComparison(string $path, array $device): ?array
        {
            if (!$this->parentUsable()) {
                return null;
            }
            $read = $this->readConfig($path);
            if ($read['state'] !== 'ok' || !is_array($read['value'])) {
                return null;
            }
            $id = $this->ReadPropertyString('DeviceID');
            return [$this->classifyConfig($device, $read['value'], $this->syncedBase($id) ?? $this->defaultDeviceEntry()), $read['value']];
        }

        protected function armFormFillIfDiffers(string $path, array $device): void
        {
            $cmp = $this->formComparison($path, $device);
            if ($cmp !== null && ($cmp[0]['eos'] !== [] || $cmp[0]['conflict'] !== [])) {
                $this->SetTimerInterval('FormFill', 1500);
            }
        }

        /** FormFill timer: load the fields EOS changed (and conflicts) into the open form. */
        protected function fillFormFromEOS(): void
        {
            [$path, $device] = $this->deviceConfig();
            $cmp = $this->formComparison($path, $device);
            if ($cmp === null) {
                return;
            }
            $keys = array_merge($cmp[0]['eos'], $cmp[0]['conflict']);
            if ($keys !== [] && $this->loadIntoForm($cmp[1], $keys) > 0) {
                $this->UpdateFormField('ConfigInfo', 'caption', $this->Translate('Values changed in EOS were loaded into the form: Apply stores them in Symcon, Cancel keeps the Symcon values.') . ' (' . implode(', ', $keys) . ')');
            }
        }

        /** Picker: take over an existing EOS device (id and all its values) into the open form. */
        protected function pickDevice(string $id): void
        {
            if ($id === '') {
                return;
            }
            $this->UpdateFormField('DeviceID', 'value', $id);
            $read = $this->readConfig(self::DEVICE_COLLECTION . '/' . $id);
            if ($read['state'] !== 'ok' || !is_array($read['value'])) {
                return;
            }
            $this->WriteAttributeString('PickSnapshot', json_encode(['id' => $id, 'eos' => $read['value'], 'ts' => $this->eosNow()]));
            $this->loadIntoForm($read['value'], null);
            $this->UpdateFormField('ConfigInfo', 'caption', sprintf($this->Translate('Device %s loaded from EOS. Apply adopts it; only fields changed afterwards are written.'), $id));
        }

        /** Button: delete the EOS entry this instance used before its DeviceID changed. */
        protected function removeOldEOSEntry(): void
        {
            $old = (string) ($this->syncedState()['id'] ?? '');
            if ($old === '' || $old === $this->ReadPropertyString('DeviceID')) {
                $this->UpdateFormField('ConfigInfo', 'caption', $this->Translate('No old EOS entry to remove.'));
                return;
            }
            $res = $this->forward(['Command' => 'RemoveDevice', 'Collection' => self::DEVICE_COLLECTION, 'DeviceID' => $old]);
            if (($res['ok'] ?? false) !== true) {
                $this->UpdateFormField('ConfigInfo', 'caption', (string) ($res['error'] ?? '?'));
                return;
            }
            $this->WriteAttributeString('SyncedConfig', json_encode(['v' => 1, 'id' => '', 'base' => [], 'ts' => $this->eosNow()]));
            $this->LogMessage(sprintf($this->Translate('Old EOS entry %s removed'), $old), KL_NOTIFY);
            $this->UpdateFormField('ConfigInfo', 'caption', sprintf($this->Translate('Old EOS entry %s removed. Press Apply to create the new one.'), $old));
        }

        /** Put EOS values into form fields; $keys = EOS keys to load, null = all mapped fields. */
        protected function loadIntoForm(array $eos, ?array $keys): int
        {
            $loaded = 0;
            foreach ($this->configFieldMap() as $property => [$key, $type]) {
                if (($keys !== null && !in_array($key, $keys, true)) || !array_key_exists($key, $eos)) {
                    continue;
                }
                $value = $eos[$key];
                switch ($type) {
                    case 'int':
                        $this->UpdateFormField($property, 'value', (int) round((float) $value));
                        break;
                    case 'float':
                        $this->UpdateFormField($property, 'value', (float) $value);
                        break;
                    case 'rates':
                        $this->UpdateFormField($property, 'value', implode(', ', array_map(static fn (mixed $r): string => (string) round((float) $r, 4), is_array($value) ? $value : [])));
                        break;
                    case 'windows':
                        $rows = [];
                        foreach (is_array($value) ? ($value['windows'] ?? []) : [] as $w) {
                            $rows[] = ['start_time' => preg_replace('/\.0+$/', '', (string) ($w['start_time'] ?? '')), 'duration' => (string) ($w['duration'] ?? ''),
                                'day_of_week' => $w['day_of_week'] ?? null, 'date' => $w['date'] ?? null, 'locale' => $w['locale'] ?? null];
                        }
                        $this->UpdateFormField($property, 'values', json_encode($rows));
                        break;
                    default:
                        $this->UpdateFormField($property, 'value', (string) $value);
                }
                $loaded++;
            }
            return $loaded;
        }

        /** Text for the form: how does EOS relate to this instance? */
        protected function eosConfigSummary(string $path, array $device): string
        {
            if (!$this->parentUsable()) {
                return $this->Translate('EOS Server not available, configuration not compared.');
            }
            $read = $this->readConfig($path);
            if ($read['state'] === 'error') {
                return sprintf($this->Translate('EOS configuration could not be read (%s); nothing compared, nothing written.'), $read['error']);
            }
            if (!is_array($read['value'])) {
                $other = $this->otherDevicesInEOS();
                return $other !== ''
                    ? sprintf($this->Translate('EOS already has %s; pick it as device or remove it in EOSdash. Nothing was created.'), $other)
                    : $this->Translate('Device not in EOS yet; it is created on Apply.');
            }
            $class = $this->classifyConfig($device, $read['value'], $this->syncedBase($this->ReadPropertyString('DeviceID')) ?? $this->defaultDeviceEntry());
            if ($class['eos'] === [] && $class['conflict'] === []) {
                return $class['push'] !== [] ? sprintf($this->Translate('Apply writes to EOS: %s'), implode(', ', $class['push'])) : $this->Translate('EOS configuration matches this instance.');
            }
            return $this->syncText($class, $device, $read['value']);
        }

        // ------------------------------------------------------------------ device picker (form)

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
            $old = (string) ($this->syncedState()['id'] ?? '');
            $this->setFormAttribute($form['elements'], 'RemoveOldEntry', 'visible', $old !== '' && $old !== $current);
        }
    }
}
