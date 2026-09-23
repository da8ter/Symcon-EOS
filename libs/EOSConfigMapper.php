<?php

declare(strict_types=1);

/*
 * Maps the EOS Server module properties to the EOS configuration object
 * (SettingsEOS, v0.4.x) and back. Used by the configuration panels of the
 * EOS Server form. Symcon only writes to EOS on explicit user action.
 */
if (!trait_exists('EOSConfigMapper')) {
    trait EOSConfigMapper
    {
        /** Problems found by PropertiesToConfig(); EOS would reject the whole write for any of them. */
        private array $configErrors = [];

        /** Static fallback provider lists (v0.4.0rc1) used until a config was loaded from EOS. */
        public const PROVIDER_FALLBACK = [
            'elecprice'    => ['ElecPriceAkkudoktor', 'ElecPriceEnergyCharts', 'ElecPriceFixed', 'ElecPriceImport', 'ElecPriceSMARD', 'ElecPriceTibber'],
            'elecfee'      => ['ElecFeeFixed', 'ElecFeeImport'],
            'feedintariff' => ['FeedInTariffAkkudoktor', 'FeedInTariffDvhubOnline', 'FeedInTariffEnergyCharts', 'FeedInTariffFixed', 'FeedInTariffImport', 'FeedInTariffSMARD', 'FeedInTariffTibber'],
            'pvforecast'   => ['PVForecastAkkudoktor', 'PVForecastForecastSolar', 'PVForecastImport', 'PVForecastPVNode', 'PVForecastSolcast', 'PVForecastVrm'],
            'load'         => ['LoadAkkudoktor', 'LoadAkkudoktorAdjusted', 'LoadImport', 'LoadVrm'],
            'weather'      => ['BrightSky', 'ClearOutside', 'OpenMeteo', 'WeatherImport'],
        ];

        /** Register all configuration properties. Called from Create(). */
        protected function RegisterConfigProperties(): void
        {
            // inverter (GENETIC needs exactly one; 0 W = not managed by Symcon)
            $this->RegisterPropertyString('InverterID', 'inv1');
            $this->RegisterPropertyInteger('InverterMaxPowerW', 0);
            $this->RegisterPropertyInteger('InverterMaxAcChargePowerW', 0);
            $this->RegisterPropertyFloat('InverterAcToDcEfficiency', 0.95);
            $this->RegisterPropertyFloat('InverterDcToAcEfficiency', 0.95);
            // general
            $this->RegisterPropertyFloat('GeneralLatitude', 0.0);
            $this->RegisterPropertyFloat('GeneralLongitude', 0.0);
            $this->RegisterPropertyString('GeneralConfigSaveMode', 'AUTOMATIC');
            // ems
            $this->RegisterPropertyString('EmsMode', 'OPTIMIZATION');
            $this->RegisterPropertyInteger('EmsInterval', 900);
            $this->RegisterPropertyInteger('EmsStartupDelay', 15);
            // optimization
            $this->RegisterPropertyString('OptAlgorithm', 'GENETIC');
            $this->RegisterPropertyInteger('OptIntervalSec', 900);
            $this->RegisterPropertyInteger('OptHorizonHours', 24);
            $this->RegisterPropertyInteger('OptIndividuals', 300);
            $this->RegisterPropertyInteger('OptGenerations', 400);
            $this->RegisterPropertyInteger('OptSeed', 0);
            $this->RegisterPropertyInteger('OptMeasurementMaxAge', 300);
            $this->RegisterPropertyInteger('OptTailHorizonHours', 48);
            $this->RegisterPropertyString('OptTerminalValueMode', 'AUTO');
            $this->RegisterPropertyFloat('OptTerminalValueEuroPerKwh', 0.0);
            $this->RegisterPropertyInteger('OptTerminalValueWindowHours', 24);
            $this->RegisterPropertyFloat('OptPenaltyEvSocMiss', 10.0);
            $this->RegisterPropertyFloat('OptPenaltyAcChargeBreakEven', 1.0);
            // prediction
            $this->RegisterPropertyInteger('PredictionHours', 48);
            $this->RegisterPropertyInteger('PredictionHistoricHours', 48);
            // elecprice
            $this->RegisterPropertyString('ElecPriceProvider', '');
            $this->RegisterPropertyString('ElecPriceFixedWindows', '[]');
            $this->RegisterPropertyString('ElecPriceEnergyChartsZone', 'DE-LU');
            $this->RegisterPropertyString('ElecPriceTibberToken', '');
            $this->RegisterPropertyString('ElecPriceTibberHomeId', '');
            $this->RegisterPropertyInteger('ElecPriceSmardFilterId', 4169);
            $this->RegisterPropertyString('ElecPriceSmardRegion', 'DE');
            // elecfee
            $this->RegisterPropertyString('ElecFeeProvider', '');
            $this->RegisterPropertyString('ElecFeeConsumptionAmtWindows', '[]');
            $this->RegisterPropertyString('ElecFeeConsumptionPercentWindows', '[]');
            $this->RegisterPropertyString('ElecFeeFeedinAmtWindows', '[]');
            $this->RegisterPropertyString('ElecFeeFeedinPercentWindows', '[]');
            // feedintariff
            $this->RegisterPropertyString('FeedInProvider', '');
            $this->RegisterPropertyBoolean('FeedInDirectMarketing', false);
            $this->RegisterPropertyString('FeedInFixedWindows', '[]');
            $this->RegisterPropertyString('FeedInEnergyChartsZone', 'DE-LU');
            $this->RegisterPropertyString('FeedInDvhubBaseUrl', 'https://dvhub.online');
            $this->RegisterPropertyString('FeedInDvhubZone', 'DE-LU');
            // pvforecast
            $this->RegisterPropertyString('PvProvider', '');
            $this->RegisterPropertyString('PvPlanes', '[]');
            $this->RegisterPropertyString('PvAkkudoktorBackend', 'remote');
            $this->RegisterPropertyInteger('PvAkkudoktorResolution', 15);
            $this->RegisterPropertyBoolean('PvAkkudoktorCalibration', false);
            $this->RegisterPropertyString('PvForecastSolarApiKey', '');
            $this->RegisterPropertyString('PvSolcastApiKey', '');
            $this->RegisterPropertyString('PvSolcastSiteId', '');
            $this->RegisterPropertyString('PvNodeApiKey', '');
            $this->RegisterPropertyString('PvNodeSiteId', '');
            $this->RegisterPropertyInteger('PvNodeForecastDays', 2);
            // load
            $this->RegisterPropertyString('LoadProvider', '');
            $this->RegisterPropertyFloat('LoadYearEnergyKwh', 0.0);
            // weather
            $this->RegisterPropertyString('WeatherProvider', '');
            // measurement keys
            $this->RegisterPropertyString('MeasLoadEmrKeys', '[]');
            $this->RegisterPropertyString('MeasGridImportEmrKeys', '[]');
            $this->RegisterPropertyString('MeasGridExportEmrKeys', '[]');
            $this->RegisterPropertyString('MeasPvEmrKeys', '[]');
            // expert
            $this->RegisterPropertyString('RawConfigPath', '');
            $this->RegisterPropertyString('RawConfigValue', '');
            $this->RegisterPropertyString('RawConfigJSON', '');
        }

        /**
         * Build the partial EOS configuration object from the module properties.
         * Empty strings, empty lists and unset providers are omitted so EOS keeps
         * its own values for them.
         */
        protected function PropertiesToConfig(): array
        {
            $this->configErrors = [];
            $cfg = [];

            $general = [];
            $lat = $this->ReadPropertyFloat('GeneralLatitude');
            $lon = $this->ReadPropertyFloat('GeneralLongitude');
            if ($lat != 0.0 || $lon != 0.0) {
                $general['latitude'] = $lat;
                $general['longitude'] = $lon;
            }
            $this->putIfSet($general, 'config_save_mode', $this->ReadPropertyString('GeneralConfigSaveMode'));
            $this->putIfSet($cfg, 'general', $general);

            $cfg['ems'] = [
                'mode'          => $this->ReadPropertyString('EmsMode'),
                'interval'      => $this->ReadPropertyInteger('EmsInterval'),
                'startup_delay' => max(1, $this->ReadPropertyInteger('EmsStartupDelay')), // EOS minimum is 1 s
            ];

            $seed = $this->ReadPropertyInteger('OptSeed');
            $cfg['optimization'] = [
                'algorithm' => $this->ReadPropertyString('OptAlgorithm'),
                'genetic'   => [
                    'interval_sec'                => $this->ReadPropertyInteger('OptIntervalSec'),
                    'horizon_hours'               => $this->ReadPropertyInteger('OptHorizonHours'),
                    'individuals'                 => $this->ReadPropertyInteger('OptIndividuals'),
                    'generations'                 => $this->ReadPropertyInteger('OptGenerations'),
                    'seed'                        => $seed > 0 ? $seed : null,
                    'measurement_max_age_seconds' => $this->ReadPropertyInteger('OptMeasurementMaxAge'),
                    'tail_horizon_hours'          => $this->ReadPropertyInteger('OptTailHorizonHours'),
                    'terminal_value_mode'         => $this->ReadPropertyString('OptTerminalValueMode'),
                    'terminal_value_euro_per_kwh' => $this->ReadPropertyFloat('OptTerminalValueEuroPerKwh'),
                    'terminal_value_window_hours' => $this->ReadPropertyInteger('OptTerminalValueWindowHours'),
                    'penalties'                   => [
                        'ev_soc_miss'          => $this->ReadPropertyFloat('OptPenaltyEvSocMiss'),
                        'ac_charge_break_even' => $this->ReadPropertyFloat('OptPenaltyAcChargeBreakEven'),
                    ],
                ],
            ];

            $cfg['prediction'] = [
                'hours'          => $this->ReadPropertyInteger('PredictionHours'),
                'historic_hours' => $this->ReadPropertyInteger('PredictionHistoricHours'),
            ];

            // elecprice
            $ep = [];
            $this->putIfSet($ep, 'provider', $this->ReadPropertyString('ElecPriceProvider'));
            $this->putWindows($ep, ['elecpricefixed', 'elecprice_marketprice_amt_kwh'], 'ElecPriceFixedWindows');
            $this->putIfSet($ep, ['energycharts', 'bidding_zone'], $this->ReadPropertyString('ElecPriceEnergyChartsZone'));
            $this->putIfSet($ep, ['tibber', 'access_token'], $this->ReadPropertyString('ElecPriceTibberToken'));
            $this->putIfSet($ep, ['tibber', 'home_id'], $this->ReadPropertyString('ElecPriceTibberHomeId'));
            if ($this->ReadPropertyInteger('ElecPriceSmardFilterId') > 0) {
                $ep['smard']['filter_id'] = $this->ReadPropertyInteger('ElecPriceSmardFilterId');
            }
            $this->putIfSet($ep, ['smard', 'region'], $this->ReadPropertyString('ElecPriceSmardRegion'));
            $this->putIfSet($cfg, 'elecprice', $ep);

            // elecfee
            $ef = [];
            $this->putIfSet($ef, 'provider', $this->ReadPropertyString('ElecFeeProvider'));
            $this->putWindows($ef, ['elecfeefixed', 'consumption_amt_kwh'], 'ElecFeeConsumptionAmtWindows');
            $this->putWindows($ef, ['elecfeefixed', 'consumption_percent_amt'], 'ElecFeeConsumptionPercentWindows');
            $this->putWindows($ef, ['elecfeefixed', 'feedin_amt_kwh'], 'ElecFeeFeedinAmtWindows');
            $this->putWindows($ef, ['elecfeefixed', 'feedin_percent_amt'], 'ElecFeeFeedinPercentWindows');
            $this->putIfSet($cfg, 'elecfee', $ef);

            // feedintariff
            $fi = ['direct_marketing_enabled' => $this->ReadPropertyBoolean('FeedInDirectMarketing')];
            $this->putIfSet($fi, 'provider', $this->ReadPropertyString('FeedInProvider'));
            $this->putWindows($fi, ['feedintarifffixed', 'feed_in_tariff_amt_kwh'], 'FeedInFixedWindows');
            $this->putIfSet($fi, ['energycharts', 'bidding_zone'], $this->ReadPropertyString('FeedInEnergyChartsZone'));
            $this->putIfSet($fi, ['dvhubonline', 'base_url'], $this->ReadPropertyString('FeedInDvhubBaseUrl'));
            $this->putIfSet($fi, ['dvhubonline', 'zone'], $this->ReadPropertyString('FeedInDvhubZone'));
            $cfg['feedintariff'] = $fi;

            // pvforecast
            $pv = [];
            $this->putIfSet($pv, 'provider', $this->ReadPropertyString('PvProvider'));
            $planes = $this->PlanesFromProperty();
            if ($planes !== []) {
                $pv['planes'] = $planes;
                $pv['max_planes'] = count($planes);
            }
            $pv['akkudoktor'] = [
                'backend'             => $this->ReadPropertyString('PvAkkudoktorBackend'),
                'resolution_minutes'  => $this->ReadPropertyInteger('PvAkkudoktorResolution'),
                'calibration_enabled' => $this->ReadPropertyBoolean('PvAkkudoktorCalibration'),
            ];
            $this->putIfSet($pv, ['forecastsolar', 'api_key'], $this->ReadPropertyString('PvForecastSolarApiKey'));
            $this->putIfSet($pv, ['solcast', 'api_key'], $this->ReadPropertyString('PvSolcastApiKey'));
            $this->putIfSet($pv, ['solcast', 'site_id'], $this->ReadPropertyString('PvSolcastSiteId'));
            $this->putIfSet($pv, ['pvnode', 'api_key'], $this->ReadPropertyString('PvNodeApiKey'));
            $this->putIfSet($pv, ['pvnode', 'site_id'], $this->ReadPropertyString('PvNodeSiteId'));
            if (isset($pv['pvnode'])) {
                $pv['pvnode']['forecast_days'] = $this->ReadPropertyInteger('PvNodeForecastDays');
            }
            $cfg['pvforecast'] = $pv;

            // load
            $load = [];
            $this->putIfSet($load, 'provider', $this->ReadPropertyString('LoadProvider'));
            if ($this->ReadPropertyFloat('LoadYearEnergyKwh') > 0) {
                $load['loadakkudoktor']['loadakkudoktor_year_energy_kwh'] = $this->ReadPropertyFloat('LoadYearEnergyKwh');
            }
            $this->putIfSet($cfg, 'load', $load);

            // weather
            $weather = [];
            $this->putIfSet($weather, 'provider', $this->ReadPropertyString('WeatherProvider'));
            $this->putIfSet($cfg, 'weather', $weather);

            // measurement
            $meas = [];
            foreach (['MeasLoadEmrKeys' => 'load_emr_keys', 'MeasGridImportEmrKeys' => 'grid_import_emr_keys', 'MeasGridExportEmrKeys' => 'grid_export_emr_keys', 'MeasPvEmrKeys' => 'pv_production_emr_keys'] as $prop => $field) {
                $keys = $this->KeysFromProperty($prop);
                if ($keys !== []) {
                    $meas[$field] = $keys;
                }
            }
            $this->putIfSet($cfg, 'measurement', $meas);

            // inverter: battery_id is computed at write time from the battery instance on this server
            if ($this->ReadPropertyInteger('InverterMaxPowerW') > 0) {
                $invId = trim($this->ReadPropertyString('InverterID')) !== '' ? trim($this->ReadPropertyString('InverterID')) : 'inv1';
                $inverter = [
                    'device_id'           => $invId,
                    'max_power_w'         => (float) $this->ReadPropertyInteger('InverterMaxPowerW'),
                    'ac_to_dc_efficiency' => $this->ReadPropertyFloat('InverterAcToDcEfficiency'),
                    'dc_to_ac_efficiency' => $this->ReadPropertyFloat('InverterDcToAcEfficiency'),
                ];
                if ($this->ReadPropertyInteger('InverterMaxAcChargePowerW') > 0) {
                    $inverter['max_ac_charge_power_w'] = (float) $this->ReadPropertyInteger('InverterMaxAcChargePowerW');
                }
                $battery = $this->connectedBatteryId();
                if ($battery !== '') {
                    $inverter['battery_id'] = $battery;
                }
                $cfg['devices'] = ['max_inverters' => 1, 'inverters' => [$invId => $inverter]];
            }

            return $cfg;
        }

        // ---------------------------------------------------------------- helpers

        protected static function dig(array $a, array $path, mixed $default = null): mixed
        {
            $cur = $a;
            foreach ($path as $p) {
                if (!is_array($cur) || !array_key_exists($p, $cur)) {
                    return $default;
                }
                $cur = $cur[$p];
            }
            return $cur ?? $default;
        }

        /** @param string|array $path */
        private function putIfSet(array &$target, string|array $path, mixed $value): void
        {
            if ($value === null || $value === '' || $value === []) {
                return;
            }
            $path = is_array($path) ? $path : [$path];
            $ref = &$target;
            foreach ($path as $i => $p) {
                if ($i === count($path) - 1) {
                    $ref[$p] = $value;
                    return;
                }
                if (!isset($ref[$p]) || !is_array($ref[$p])) {
                    $ref[$p] = [];
                }
                $ref = &$ref[$p];
            }
        }

        private function putWindows(array &$target, array $path, string $property): void
        {
            $rows = $this->eosJsonDecode($this->ReadPropertyString($property), []);
            $windows = [];
            foreach (is_array($rows) ? $rows : [] as $row) {
                $start = trim((string) ($row['start_time'] ?? ''));
                $duration = trim((string) ($row['duration'] ?? ''));
                if ($start === '' || $duration === '') {
                    continue;
                }
                $windows[] = ['start_time' => $start, 'duration' => $duration, 'value' => (float) ($row['value'] ?? 0)];
            }
            if ($windows !== []) {
                $this->putIfSet($target, [...$path, 'windows'], $windows);
            }
        }

        private function PlanesFromProperty(): array
        {
            $rows = $this->eosJsonDecode($this->ReadPropertyString('PvPlanes'), []);
            $planes = [];
            foreach (is_array($rows) ? $rows : [] as $row) {
                $peak = (float) ($row['peakpower'] ?? 0);
                if ($peak <= 0) {
                    continue;
                }
                $plane = [
                    'peakpower'       => $peak,
                    'surface_azimuth' => (float) ($row['surface_azimuth'] ?? 180),
                    'surface_tilt'    => (float) ($row['surface_tilt'] ?? 30),
                ];
                if ((int) ($row['inverter_paco'] ?? 0) > 0) {
                    $plane['inverter_paco'] = (int) $row['inverter_paco'];
                }
                if (isset($row['loss']) && (float) $row['loss'] > 0) {
                    $plane['loss'] = (float) $row['loss'];
                }
                if (isset($row['albedo']) && (float) $row['albedo'] > 0) {
                    $plane['albedo'] = (float) $row['albedo'];
                }
                $horizon = trim((string) ($row['userhorizon'] ?? ''));
                if ($horizon !== '') {
                    // A list for EOS: array_values() closes the gaps an empty item would leave.
                    $tokens = array_values(array_filter(array_map('trim', explode(',', $horizon)), static fn (string $v): bool => $v !== ''));
                    foreach ($tokens as $token) {
                        if (!is_numeric($token)) {
                            $this->configErrors[] = sprintf($this->Translate('PV plane %d: horizon value "%s" is not a number'), count($planes) + 1, $token);
                        }
                    }
                    $plane['userhorizon'] = array_map('floatval', $tokens);
                }
                $planes[] = $plane;
            }
            return $planes;
        }

        /** DeviceID of the one battery instance connected to this server; '' when none or several. */
        protected function connectedBatteryId(): string
        {
            $ids = [];
            foreach (IPS_GetInstanceListByModuleID('{F4B30383-1210-4169-93DA-5C9664447B42}') as $id) {
                if ((int) IPS_GetInstance($id)['ConnectionID'] === $this->InstanceID) {
                    $ids[] = (string) IPS_GetProperty($id, 'DeviceID');
                }
            }
            return count($ids) === 1 ? $ids[0] : '';
        }

        private function KeysFromProperty(string $property): array
        {
            $rows = $this->eosJsonDecode($this->ReadPropertyString($property), []);
            $keys = [];
            foreach (is_array($rows) ? $rows : [] as $row) {
                $k = trim((string) ($row['key'] ?? ''));
                if ($k !== '') {
                    $keys[] = $k;
                }
            }
            return $keys;
        }
    }
}
