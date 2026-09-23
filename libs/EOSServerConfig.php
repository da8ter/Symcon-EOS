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
        public function GetConfig(string $Path): string
        {
            $res = $Path === '' ? $this->client()->getConfig() : $this->client()->getConfigPath($Path);
            if (!$res['ok']) {
                $this->SetValue('LastError', 'config ' . $Path . ': ' . (string) $res['error']);
                return '';
            }
            return json_encode($res['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        public function SetConfig(string $Path, string $ValueJSON): bool
        {
            $value = json_decode($ValueJSON, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $value = $ValueJSON;
            }
            $res = $this->client()->putConfigPath($Path, $value);
            if (!$res['ok']) {
                $this->SetValue('LastError', 'config ' . $Path . ': ' . (string) $res['error']);
            }
            return $res['ok'];
        }
        public function SaveConfig(): bool
        {
            $res = $this->client()->saveConfigFile();
            $this->UpdateFormField('ConfigInfo', 'caption', $res['ok'] ? $this->Translate('Configuration saved to EOS.config.json.') : (string) $res['error']);
            if (!$res['ok']) {
                $this->SetValue('LastError', 'save config: ' . (string) $res['error']);
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
            $this->SendDebug('WriteConfig', json_encode($merge, JSON_UNESCAPED_UNICODE), 0);
            $res = $this->client()->putConfig($merge);
            if (!$res['ok']) {
                $this->UpdateFormField('ConfigInfo', 'caption', (string) $res['error']);
                $this->SetValue('LastError', 'write config: ' . (string) $res['error']);
                return false;
            }
            if (is_array($res['data'])) {
                $this->WriteAttributeString('EOSConfigCache', json_encode($res['data']));
            }
            $this->UpdateFormField('ConfigInfo', 'caption', $this->Translate('Configuration written to EOS.'));
            return true;
        }
        public function UseSymconLocation(): void
        {
            $loc = json_decode(IPS_GetLocation(), true);
            $lat = (float) ($loc['Latitude'] ?? 0);
            $lon = (float) ($loc['Longitude'] ?? 0);
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
            $merge = json_decode($JSON, true);
            if (!is_array($merge)) {
                $this->UpdateFormField('RawResult', 'caption', 'invalid JSON');
                return false;
            }
            $res = $this->client()->putConfig($merge);
            $this->UpdateFormField('RawResult', 'caption', $res['ok'] ? 'OK' : (string) $res['error']);
            return $res['ok'];
        }

        /** ForwardData GetConfig / SetConfig / MergeConfig / SaveConfig. */
        protected function forwardConfigCommand(string $command, array $data): array
        {
            switch ($command) {
                case 'GetConfig':
                    $res = $this->client()->getConfigPath((string) ($data['Path'] ?? ''));
                    return ['ok' => $res['ok'], 'data' => $res['data'], 'error' => $res['error']];

                case 'SetConfig':
                    $res = $this->client()->putConfigPath((string) ($data['Path'] ?? ''), $data['Value'] ?? null);
                    return ['ok' => $res['ok'], 'error' => $res['error']];

                case 'MergeConfig':
                    $res = $this->client()->putConfig(is_array($data['Value'] ?? null) ? $data['Value'] : []);
                    return ['ok' => $res['ok'], 'error' => $res['error']];

                case 'SaveConfig':
                    $res = $this->client()->saveConfigFile();
                    return ['ok' => $res['ok'], 'error' => $res['error']];
            }
            return ['ok' => false, 'error' => 'unknown command ' . $command];
        }
    }
}
