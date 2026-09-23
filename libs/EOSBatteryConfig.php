<?php

declare(strict_types=1);

/*
 * EOS Battery: device configuration in EOS (capacity, power, SoC limits, efficiencies,
 * storage cost). Split out of the module to keep it readable; uses syncDeviceConfig()
 * from EOSDeviceConfigSync.
 */
if (!trait_exists('EOSBatteryConfig')) {
    trait EOSBatteryConfig
    {
        /** Force-write the battery parameters to EOS (ApplyChanges does it automatically when they differ). */
        public function WriteConfigToEOS(): bool
        {
            [$path, $device, $merge] = $this->deviceConfig();
            return $this->syncDeviceConfig($path, $device, $merge, true);
        }

        /** [config path, device entry, merge payload] for the EOS configuration. */
        private function deviceConfig(): array
        {
            $id = $this->ReadPropertyString('DeviceID');
            $battery = [
                'device_id'                         => $id,
                'capacity_wh'                       => $this->ReadPropertyInteger('CapacityWh'),
                'max_charge_power_w'                => $this->ReadPropertyInteger('MaxChargePowerW'),
                'min_soc_percentage'                => $this->ReadPropertyInteger('MinSoC'),
                'max_soc_percentage'                => $this->ReadPropertyInteger('MaxSoC'),
                'charging_efficiency'               => $this->ReadPropertyFloat('ChargingEfficiency'),
                'discharging_efficiency'            => $this->ReadPropertyFloat('DischargingEfficiency'),
                'levelized_cost_of_storage_amt_kwh' => $this->ReadPropertyFloat('LcosAmtKwh'),
            ];
            return ['devices/batteries/' . $id, $battery, ['devices' => ['batteries' => [$id => $battery]]]];
        }

        public function ReadConfigFromEOS(): bool
        {
            $id = $this->ReadPropertyString('DeviceID');
            $res = $this->forward(['Command' => 'GetConfig', 'Path' => 'devices/batteries/' . $id]);
            $bat = $res['data'] ?? null;
            if (($res['ok'] ?? false) !== true || !is_array($bat)) {
                $this->UpdateFormField('ConfigInfo', 'caption', sprintf($this->Translate('No battery %s in EOS configuration.'), $id));
                return false;
            }
            $map = [
                'CapacityWh'            => ['capacity_wh', 'int'],
                'MaxChargePowerW'       => ['max_charge_power_w', 'int'],
                'MinSoC'                => ['min_soc_percentage', 'int'],
                'MaxSoC'                => ['max_soc_percentage', 'int'],
                'ChargingEfficiency'    => ['charging_efficiency', 'float'],
                'DischargingEfficiency' => ['discharging_efficiency', 'float'],
                'LcosAmtKwh'            => ['levelized_cost_of_storage_amt_kwh', 'float'],
            ];
            foreach ($map as $field => [$key, $type]) {
                if (isset($bat[$key])) {
                    $this->UpdateFormField($field, 'value', $type === 'int' ? (int) $bat[$key] : (float) $bat[$key]);
                }
            }
            $this->UpdateFormField('ConfigInfo', 'caption', $this->Translate('Values from EOS loaded into the form because they differ. Apply stores them in Symcon, Cancel keeps the Symcon values.'));
            return true;
        }
    }
}
