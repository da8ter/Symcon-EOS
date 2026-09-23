<?php

declare(strict_types=1);

/*
 * EOS Vehicle: device configuration in EOS (capacity, power, SoC limits, charge rates)
 * and the departure deadline. Split out of the module to keep it readable; uses
 * forward()/parentUsable() from EOSPlanDevice and syncDeviceConfig() from EOSDeviceConfigSync.
 */
if (!trait_exists('EOSVehicleConfig')) {
    trait EOSVehicleConfig
    {
        /** Force-write the vehicle parameters to EOS (ApplyChanges does it automatically when they differ). */
        /** [config path, device entry, merge payload] for the EOS configuration. */
        private function deviceConfig(): array
        {
            $id = $this->ReadPropertyString('DeviceID');
            $rates = array_values(array_filter(array_map(
                static fn (string $v): float => (float) trim($v),
                explode(',', (string) $this->configValue('ChargeRates'))
            ), static fn (float $v): bool => $v >= 0.0 && $v <= 1.0));
            $ev = [
                'device_id'           => $id,
                'capacity_wh'         => $this->configValue('CapacityWh'),
                'max_charge_power_w'  => $this->configValue('MaxChargePowerW'),
                'min_soc_percentage'  => $this->targetSoCForEOS(),
                'max_soc_percentage'  => $this->configValue('MaxSoC'),
                'charging_efficiency' => $this->configValue('ChargingEfficiency'),
            ];
            if (count($rates) >= 2) {
                sort($rates);
                $ev['charge_rates'] = $rates;
            }
            return [self::DEVICE_COLLECTION . '/' . $id, $ev, ['devices' => ['electric_vehicles' => [$id => $ev]]]];
        }

        /** Property => [EOS key, type] for loading EOS values into the form. */
        private function configFieldMap(): array
        {
            return [
                'CapacityWh'         => ['capacity_wh', 'int'],
                'MaxChargePowerW'    => ['max_charge_power_w', 'int'],
                'TargetSoC'          => ['min_soc_percentage', 'int'],
                'MaxSoC'             => ['max_soc_percentage', 'int'],
                'ChargingEfficiency' => ['charging_efficiency', 'float'],
                'ChargeRates'        => ['charge_rates', 'rates'],
            ];
        }

        /** EOS requires min_soc_percentage < max_soc_percentage: a target of 100 % at max 100 % becomes 99 %. */
        private function targetSoCForEOS(): int
        {
            $target = (int) $this->configValue('TargetSoC');
            $max = (int) $this->configValue('MaxSoC');
            if ($target < $max) {
                return $target;
            }
            $sent = max(0, $max - 1);
            $note = $target . '>' . $sent;
            if ($this->ReadAttributeString('TargetSoCNote') !== $note) {
                $this->WriteAttributeString('TargetSoCNote', $note);
                $this->LogMessage(sprintf($this->Translate('Target SoC %d %% is sent as %d %%: EOS requires it below the max. SoC'), $target, $sent), KL_NOTIFY);
            }
            return $sent;
        }

        /**
         * The departure belongs to Symcon only when syncing is on AND a source exists (a
         * source variable, or SetDeparture was used). Otherwise the deadline in EOS belongs
         * to EOSdash and is never written.
         */
        private function departureOwned(): bool
        {
            return $this->ReadPropertyBoolean('SyncDepartureToEOS')
                && ($this->ReadPropertyInteger('DepartureSourceVariable') > 0 || $this->ReadAttributeBoolean('DepartureByScript'));
        }

        /** Departure source variable -> Departure variable -> deadline in EOS. */
        private function updateDeparture(): void
        {
            $var = $this->ReadPropertyInteger('DepartureSourceVariable');
            if ($var > 0 && IPS_VariableExists($var)) {
                $this->SetValue('Departure', max(0, (int) GetValue($var)));
            }
            $this->reconcileDeparture();
        }

        /**
         * EOS treats a deadline in the past as "due right now" and charges at once, so a
         * passed departure is cleared (expiry timer, every SoC push, ApplyChanges).
         */
        private function reconcileDeparture(): bool
        {
            if (!$this->parentUsable() || $this->deviceBlocked()) {
                return false;
            }
            $now = $this->eosNow();
            if (!$this->departureOwned()) {
                $this->SetTimerInterval('DeadlineExpiry', 0);
                $eos = $this->eosTimeField('min_soc_deadline_datetime');
                $this->warnOnce('PastDeadlineWarned', ($eos > 0 && $eos <= $now) ? (string) $eos : '', sprintf($this->Translate('EOS holds a departure time in the past (%s); EOS then charges to the target SoC right away. Clear it in EOSdash or give this instance a departure source.'), $this->eosIsoNow(max(0, $eos))));
                return true;
            }
            $departure = (int) $this->GetValue('Departure');
            $want = $departure > $now ? $departure : 0;
            $this->armExpiryTimer('DeadlineExpiry', [$want]);
            $result = $this->reconcileTimeField('min_soc_deadline_datetime', $want);
            if ($result === 'written') {
                $this->forward(['Command' => 'SaveConfig']);
                $this->SendDebug('Departure', $want > 0 ? $this->eosIsoNow($want) : 'cleared', 0);
            }
            return $result !== 'failed';
        }
    }
}
