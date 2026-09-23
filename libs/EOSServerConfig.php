<?php

declare(strict_types=1);

/*
 * EOS Server: configuration access - the public EOS_* configuration functions, the
 * form actions (load from / write to EOS, raw access, Symcon location) and the
 * configuration commands the device instances send through ForwardData.
 */
if (!trait_exists('EOSServerConfig')) {
    trait EOSServerConfig
    {
        /** The configuration (or a path) as EOS sent it; raw text, so an empty map stays "{}". */
        public function GetConfig(string $Path): string
        {
            $res = $this->client()->getConfigRaw($Path);
            if (!$res['ok']) {
                $this->SetValue('LastError', sprintf($this->Translate('Configuration %s: %s'), $Path, (string) $res['error']));
                return '';
            }
            return (string) $res['data'];
        }
        public function SetConfig(string $Path, string $ValueJSON): bool
        {
            // Objects stay objects: "{}" must reach EOS as {} (device maps), not as [].
            $value = json_decode($ValueJSON);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $value = $ValueJSON;
            }
            $res = $this->client()->putConfigPath($Path, $value);
            if (!$res['ok']) {
                $this->SetValue('LastError', sprintf($this->Translate('Configuration %s: %s'), $Path, (string) $res['error']));
            }
            return $res['ok'];
        }
        public function SaveConfig(): bool
        {
            $res = $this->client()->saveConfigFile();
            $this->UpdateFormField('ConfigInfo', 'caption', $res['ok'] ? $this->Translate('Configuration saved to EOS.config.json.') : (string) $res['error']);
            if (!$res['ok']) {
                $this->SetValue('LastError', sprintf($this->Translate('Save configuration: %s'), (string) $res['error']));
            }
            return $res['ok'];
        }
        public function LoadConfigFromEOS(): bool
        {
            $res = $this->client()->getConfig();
            if (!$res['ok'] || !is_array($res['data'])) {
                $this->UpdateFormField('ConfigInfo', 'caption', (string) $res['error']);
                return false;
            }
            $this->WriteAttributeString('EOSConfigCache', json_encode($res['data']));
            $values = $this->ConfigToFormValues($res['data']);
            foreach ($values['scalar'] as $name => $value) {
                $this->UpdateFormField($name, 'value', $value);
            }
            foreach ($values['list'] as $name => $rows) {
                $this->UpdateFormField($name, 'values', json_encode($rows));
            }
            $this->UpdateFormField('ConfigInfo', 'caption', $this->Translate('Configuration loaded from EOS. Review the fields and press Apply to store them in Symcon.'));
            return true;
        }
        public function WriteConfigToEOS(): bool
        {
            $merge = $this->PropertiesToConfig();
            if ($this->configErrors !== []) {
                // EOS rejects the whole request for one bad field: refuse before sending anything.
                $this->UpdateFormField('ConfigInfo', 'caption', implode(' · ', $this->configErrors));
                $this->SetValue('LastError', sprintf($this->Translate('Write configuration: %s'), implode(' · ', $this->configErrors)));
                return false;
            }
            if (isset($merge['devices']['inverters'])) {
                // GENETIC supports one inverter: never add a second one under another id.
                $current = $this->client()->getConfigPath('devices/inverters');
                $others = ($current['ok'] && is_array($current['data'])) ? array_diff(array_map('strval', array_keys($current['data'])), array_keys($merge['devices']['inverters'])) : [];
                if ($others !== []) {
                    $message = sprintf($this->Translate('EOS already has inverter %s; set the inverter ID to it (GENETIC supports one). Nothing written.'), implode(', ', $others));
                    $this->UpdateFormField('ConfigInfo', 'caption', $message);
                    $this->SetValue('LastError', sprintf($this->Translate('Write configuration: %s'), $message));
                    return false;
                }
            }
            if (isset($merge['measurement'])) {
                // EOS replaces lists: keep the keys registered by the meter instances (and in EOSdash).
                $current = $this->client()->getConfigPath('measurement');
                if (!$current['ok'] || !is_array($current['data'])) {
                    $message = sprintf($this->Translate('Measurement keys could not be read from EOS (%s); nothing written.'), (string) $current['error']);
                    $this->UpdateFormField('ConfigInfo', 'caption', $message);
                    $this->SetValue('LastError', sprintf($this->Translate('Write configuration: %s'), $message));
                    return false;
                }
                foreach ($merge['measurement'] as $field => $keys) {
                    $existing = is_array($current['data'][$field] ?? null) ? array_map('strval', $current['data'][$field]) : [];
                    $merge['measurement'][$field] = array_values(array_unique(array_merge($existing, $keys)));
                }
            }
            $this->SendDebug('WriteConfig', json_encode($merge, JSON_UNESCAPED_UNICODE), 0);
            $res = $this->client()->putConfig($merge);
            if (!$res['ok']) {
                $this->UpdateFormField('ConfigInfo', 'caption', (string) $res['error']);
                $this->SetValue('LastError', sprintf($this->Translate('Write configuration: %s'), (string) $res['error']));
                return false;
            }
            if (is_array($res['data'])) {
                $this->WriteAttributeString('EOSConfigCache', json_encode($res['data']));
            }
            $this->UpdateFormField('ConfigInfo', 'caption', $this->Translate('Configuration written to EOS.'));
            return true;
        }
        /** Location of the Symcon Location Control (property "Location": {"latitude", "longitude"}). */
        public function UseSymconLocation(): void
        {
            $ids = IPS_GetInstanceListByModuleID('{45E97A63-F870-408A-B259-2933F7EABF74}');
            $location = $ids !== [] ? json_decode((string) IPS_GetProperty($ids[0], 'Location'), true) : null;
            $lat = (float) ($location['latitude'] ?? 0);
            $lon = (float) ($location['longitude'] ?? 0);
            if ($lat == 0.0 && $lon == 0.0) {
                $this->UpdateFormField('ConfigInfo', 'caption', $this->Translate('No location set in Symcon (Location Control).'));
                return;
            }
            $this->UpdateFormField('GeneralLatitude', 'value', $lat);
            $this->UpdateFormField('GeneralLongitude', 'value', $lon);
            $this->UpdateFormField('ConfigInfo', 'caption', sprintf($this->Translate('Location taken from Symcon: %s / %s. Press Apply to store.'), (string) $lat, (string) $lon));
        }
        public function ReadRawConfig(string $Path): string
        {
            $json = $this->GetConfig($Path);
            $this->UpdateFormField('RawResult', 'caption', $json !== '' ? $this->eosShorten($json, 400) : (string) $this->GetValue('LastError'));
            $this->UpdateFormField('RawConfigValue', 'value', $json);
            return $json;
        }
        public function WriteRawConfig(string $Path, string $ValueJSON): bool
        {
            $ok = $this->SetConfig($Path, $ValueJSON);
            $this->UpdateFormField('RawResult', 'caption', $ok ? 'OK: ' . $Path : (string) $this->GetValue('LastError'));
            return $ok;
        }
        public function WriteRawMerge(string $JSON): bool
        {
            $merge = json_decode($JSON); // objects stay objects ("{}" is not a list)
            if (!is_object($merge)) {
                $this->UpdateFormField('RawResult', 'caption', $this->Translate('Invalid JSON'));
                return false;
            }
            $res = $this->client()->putConfig($merge);
            $this->UpdateFormField('RawResult', 'caption', $res['ok'] ? 'OK' : (string) $res['error']);
            return $res['ok'];
        }

        /**
         * Delete a device entry. EOS keeps a removed map key in its runtime settings and
         * brings it back with the next merge, so: map without the key -> save -> reset
         * (reload from the file), all in this one call (no other child can write between,
         * Symcon serialises the calls of this instance).
         */
        protected function removeDevice(string $collection, string $id): array
        {
            if (preg_match('#^devices/(batteries|electric_vehicles|inverters|home_appliances)$#', $collection) !== 1 || $id === '') {
                return ['ok' => false, 'status' => 0, 'error' => 'invalid device ' . $collection . '/' . $id];
            }
            $current = $this->client()->getConfigPath($collection);
            if (!$current['ok']) {
                return ['ok' => false, 'status' => $current['status'], 'error' => $current['error']];
            }
            $map = is_array($current['data']) ? $current['data'] : [];
            if (!array_key_exists($id, $map)) {
                return ['ok' => true, 'status' => 200, 'error' => null];
            }
            unset($map[$id]);
            $clean = new stdClass(); // stays a JSON object, also when it becomes empty
            foreach ($map as $key => $entry) {
                // computed fields of the GET answer are not settable
                $clean->{$key} = array_filter(is_array($entry) ? $entry : [], static fn (mixed $v, string $k): bool => !str_starts_with($k, 'measurement_key') && $k !== 'capacity_estimate', ARRAY_FILTER_USE_BOTH);
            }
            foreach ([
                fn (): array => $this->client()->putConfigPath($collection, $clean),
                fn (): array => $this->client()->saveConfigFile(),
                fn (): array => $this->client()->resetConfig(),
            ] as $step) {
                $res = $step();
                if (!$res['ok']) {
                    $this->SetValue('LastError', sprintf($this->Translate('Remove %s: %s'), $collection . '/' . $id, (string) $res['error']));
                    return ['ok' => false, 'status' => $res['status'], 'error' => $res['error']];
                }
            }
            $this->LogMessage(sprintf($this->Translate('Device %s removed from EOS'), $collection . '/' . $id), KL_NOTIFY);
            return ['ok' => true, 'status' => 200, 'error' => null];
        }

        /**
         * Why would EOS refuse to plan? The device checks of optimization/genetic/configrequest.py
         * (maxima, one battery/vehicle/inverter, inverter present and linked, unique ids) plus
         * min < max SoC. [] = nothing found.
         */
        protected function configProblems(array $config): array
        {
            $devices = is_array($config['devices'] ?? null) ? $config['devices'] : [];
            $problems = [];
            $all = [];
            foreach (['batteries', 'electric_vehicles', 'inverters', 'home_appliances'] as $group) {
                $entries = is_array($devices[$group] ?? null) ? $devices[$group] : [];
                $max = $devices['max_' . $group] ?? null;
                if ($max !== null && count($entries) > (int) $max) {
                    $problems[] = sprintf($this->Translate('devices.%s: %d entries, maximum %d'), $group, count($entries), (int) $max);
                }
                if ($group !== 'home_appliances' && count($entries) > 1) {
                    $problems[] = sprintf($this->Translate('devices.%s: GENETIC supports one, EOS has %s'), $group, implode(', ', array_keys($entries)));
                }
                foreach ($entries as $id => $entry) {
                    $all[] = (string) $id;
                    if (($group === 'batteries' || $group === 'electric_vehicles') && is_array($entry) && (int) ($entry['min_soc_percentage'] ?? 0) >= (int) ($entry['max_soc_percentage'] ?? 100)) {
                        $problems[] = sprintf($this->Translate('%s: min. SoC not below max. SoC'), (string) $id);
                    }
                }
            }
            if (count($all) !== count(array_unique($all))) {
                $problems[] = $this->Translate('device ids are not unique across device kinds');
            }
            $inverters = is_array($devices['inverters'] ?? null) ? $devices['inverters'] : [];
            $battery = array_key_first(is_array($devices['batteries'] ?? null) ? $devices['batteries'] : []);
            if ($inverters === []) {
                $problems[] = $this->Translate('no inverter (set it in the section "Inverter")');
            } elseif (count($inverters) === 1 && (reset($inverters)['battery_id'] ?? null) !== $battery) {
                $problems[] = sprintf($this->Translate('inverter battery_id is %s, the battery is %s'), json_encode(reset($inverters)['battery_id'] ?? null), json_encode($battery));
            }
            return $problems;
        }

        /** ForwardData GetConfig / SetConfig / MergeConfig / SaveConfig / RemoveDevice. */
        protected function forwardConfigCommand(string $command, array $data): array
        {
            switch ($command) {
                case 'GetConfig':
                    $res = $this->client()->getConfigPath((string) ($data['Path'] ?? ''));
                    // An EOS error answer is a problem body, never configuration data.
                    return ['ok' => $res['ok'], 'status' => $res['status'], 'errno' => $res['errno'] ?? 0, 'data' => $res['ok'] ? $res['data'] : null, 'error' => $res['error']];

                case 'SetConfig':
                    $res = $this->client()->putConfigPath((string) ($data['Path'] ?? ''), $data['Value'] ?? null);
                    return ['ok' => $res['ok'], 'status' => $res['status'], 'errno' => $res['errno'] ?? 0, 'error' => $res['error']];

                case 'MergeConfig':
                    $res = $this->client()->putConfig(is_array($data['Value'] ?? null) ? $data['Value'] : []);
                    return ['ok' => $res['ok'], 'status' => $res['status'], 'errno' => $res['errno'] ?? 0, 'error' => $res['error']];

                case 'SaveConfig':
                    $res = $this->client()->saveConfigFile();
                    return ['ok' => $res['ok'], 'status' => $res['status'], 'errno' => $res['errno'] ?? 0, 'error' => $res['error']];

                case 'RemoveDevice':
                    return $this->removeDevice((string) ($data['Collection'] ?? ''), (string) ($data['DeviceID'] ?? ''));
            }
            return ['ok' => false, 'error' => 'unknown command ' . $command];
        }
    }
}
