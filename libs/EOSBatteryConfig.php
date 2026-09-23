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
        /** [config path, device entry, merge payload] for the EOS configuration. */
        private function deviceConfig(): array
        {
            $id = $this->ReadPropertyString('DeviceID');
            $battery = [
                'device_id'                         => $id,
                'capacity_wh'                       => $this->configValue('CapacityWh'),
                'max_charge_power_w'                => $this->configValue('MaxChargePowerW'),
                'min_soc_percentage'                => $this->configValue('MinSoC'),
                'max_soc_percentage'                => $this->configValue('MaxSoC'),
                'charging_efficiency'               => $this->configValue('ChargingEfficiency'),
                'discharging_efficiency'            => $this->configValue('DischargingEfficiency'),
                'levelized_cost_of_storage_amt_kwh' => $this->configValue('LcosAmtKwh'),
            ];
            return [self::DEVICE_COLLECTION . '/' . $id, $battery, ['devices' => ['batteries' => [$id => $battery]]]];
        }

        /** Property => [EOS key, type] for loading EOS values into the form. */
        private function configFieldMap(): array
        {
            return [
                'CapacityWh'            => ['capacity_wh', 'int'],
                'MaxChargePowerW'       => ['max_charge_power_w', 'int'],
                'MinSoC'                => ['min_soc_percentage', 'int'],
                'MaxSoC'                => ['max_soc_percentage', 'int'],
                'ChargingEfficiency'    => ['charging_efficiency', 'float'],
                'DischargingEfficiency' => ['discharging_efficiency', 'float'],
                'LcosAmtKwh'            => ['levelized_cost_of_storage_amt_kwh', 'float'],
            ];
        }

        /**
         * This instance now owns battery $id: the (only) inverter must point at it, otherwise
         * every GENETIC run aborts ("Inverter battery_id must match the configured battery").
         */
        protected function onDeviceSynced(string $id, string $previousId): void
        {
            $read = $this->readConfig('devices/inverters');
            $inverters = ($read['state'] === 'ok' && is_array($read['value'])) ? $read['value'] : [];
            if (count($inverters) !== 1) {
                return;
            }
            $invId = (string) array_key_first($inverters);
            $linked = (string) ($inverters[$invId]['battery_id'] ?? '');
            $batteries = $this->readConfig(self::DEVICE_COLLECTION);
            if ($batteries['state'] !== 'ok') {
                return; // unknown which batteries exist: leave the link alone
            }
            $linkedExists = $linked !== '' && is_array($batteries['value']) && array_key_exists($linked, $batteries['value']);
            if ($linked === $id || ($linkedExists && $linked !== $previousId)) {
                return; // already ours, or deliberately linked to another existing battery
            }
            $res = $this->forward(['Command' => 'SetConfig', 'Path' => 'devices/inverters/' . $invId . '/battery_id', 'Value' => $id]);
            if (($res['ok'] ?? false) === true) {
                $this->forward(['Command' => 'SaveConfig']);
                $this->LogMessage(sprintf($this->Translate('Inverter %s now points at battery %s'), $invId, $id), KL_NOTIFY);
            }
        }

    }
}
