<?php

declare(strict_types=1);

/*
 * EOS Server: EOS configuration -> form values ("Load from EOS") and the provider
 * option lists of the form. Counterpart of EOSConfigMapper::PropertiesToConfig().
 */
if (!trait_exists('EOSConfigFormValues')) {
    trait EOSConfigFormValues
    {
        /**
         * Convert an EOS configuration (GET /v1/config) into form field values.
         * Returns ['scalar' => [name => value], 'list' => [name => rows]].
         */
        protected function ConfigToFormValues(array $c): array
        {
            $s = [];
            $l = [];
            $g = static fn (array $a, array $path, mixed $default = null): mixed => self::dig($a, $path, $default);

            $s['GeneralLatitude'] = (float) $g($c, ['general', 'latitude'], 0.0);
            $s['GeneralLongitude'] = (float) $g($c, ['general', 'longitude'], 0.0);
            $s['GeneralConfigSaveMode'] = (string) $g($c, ['general', 'config_save_mode'], 'AUTOMATIC');
            $s['EmsMode'] = (string) $g($c, ['ems', 'mode'], 'DISABLED');
            $s['EmsInterval'] = (int) $g($c, ['ems', 'interval'], 900);
            $s['EmsStartupDelay'] = (int) $g($c, ['ems', 'startup_delay'], 5);
            $s['OptAlgorithm'] = (string) $g($c, ['optimization', 'algorithm'], 'GENETIC');
            $gen = ['optimization', 'genetic'];
            $s['OptIntervalSec'] = (int) $g($c, [...$gen, 'interval_sec'], 3600);
            $s['OptHorizonHours'] = (int) $g($c, [...$gen, 'horizon_hours'], 24);
            $s['OptIndividuals'] = (int) ($g($c, [...$gen, 'individuals'], 300) ?? 300);
            $s['OptGenerations'] = (int) ($g($c, [...$gen, 'generations'], 400) ?? 400);
            $s['OptSeed'] = (int) ($g($c, [...$gen, 'seed'], 0) ?? 0);
            $s['OptMeasurementMaxAge'] = (int) $g($c, [...$gen, 'measurement_max_age_seconds'], 300);
            $s['OptTailHorizonHours'] = (int) $g($c, [...$gen, 'tail_horizon_hours'], 48);
            $s['OptTerminalValueMode'] = (string) $g($c, [...$gen, 'terminal_value_mode'], 'AUTO');
            $s['OptTerminalValueEuroPerKwh'] = (float) $g($c, [...$gen, 'terminal_value_euro_per_kwh'], 0.0);
            $s['OptTerminalValueWindowHours'] = (int) $g($c, [...$gen, 'terminal_value_window_hours'], 24);
            $s['OptPenaltyEvSocMiss'] = (float) ($g($c, [...$gen, 'penalties', 'ev_soc_miss'], 10) ?? 10);
            $s['OptPenaltyAcChargeBreakEven'] = (float) ($g($c, [...$gen, 'penalties', 'ac_charge_break_even'], 1.0) ?? 1.0);
            $s['PredictionHours'] = (int) $g($c, ['prediction', 'hours'], 48);
            $s['PredictionHistoricHours'] = (int) $g($c, ['prediction', 'historic_hours'], 48);

            $s['ElecPriceProvider'] = (string) ($g($c, ['elecprice', 'provider']) ?? '');
            $l['ElecPriceFixedWindows'] = $this->windowsToRows($g($c, ['elecprice', 'elecpricefixed', 'elecprice_marketprice_amt_kwh', 'windows'], []));
            $s['ElecPriceEnergyChartsZone'] = (string) $g($c, ['elecprice', 'energycharts', 'bidding_zone'], 'DE-LU');
            $s['ElecPriceTibberToken'] = (string) ($g($c, ['elecprice', 'tibber', 'access_token']) ?? '');
            $s['ElecPriceTibberHomeId'] = (string) ($g($c, ['elecprice', 'tibber', 'home_id']) ?? '');
            $s['ElecPriceSmardFilterId'] = (int) $g($c, ['elecprice', 'smard', 'filter_id'], 4169);
            $s['ElecPriceSmardRegion'] = (string) $g($c, ['elecprice', 'smard', 'region'], 'DE');

            $s['ElecFeeProvider'] = (string) ($g($c, ['elecfee', 'provider']) ?? '');
            $l['ElecFeeConsumptionAmtWindows'] = $this->windowsToRows($g($c, ['elecfee', 'elecfeefixed', 'consumption_amt_kwh', 'windows'], []));
            $l['ElecFeeConsumptionPercentWindows'] = $this->windowsToRows($g($c, ['elecfee', 'elecfeefixed', 'consumption_percent_amt', 'windows'], []));
            $l['ElecFeeFeedinAmtWindows'] = $this->windowsToRows($g($c, ['elecfee', 'elecfeefixed', 'feedin_amt_kwh', 'windows'], []));
            $l['ElecFeeFeedinPercentWindows'] = $this->windowsToRows($g($c, ['elecfee', 'elecfeefixed', 'feedin_percent_amt', 'windows'], []));

            $s['FeedInProvider'] = (string) ($g($c, ['feedintariff', 'provider']) ?? '');
            $s['FeedInDirectMarketing'] = (bool) $g($c, ['feedintariff', 'direct_marketing_enabled'], false);
            $l['FeedInFixedWindows'] = $this->windowsToRows($g($c, ['feedintariff', 'feedintarifffixed', 'feed_in_tariff_amt_kwh', 'windows'], []));
            $s['FeedInEnergyChartsZone'] = (string) $g($c, ['feedintariff', 'energycharts', 'bidding_zone'], 'DE-LU');
            $s['FeedInDvhubBaseUrl'] = (string) $g($c, ['feedintariff', 'dvhubonline', 'base_url'], 'https://dvhub.online');
            $s['FeedInDvhubZone'] = (string) $g($c, ['feedintariff', 'dvhubonline', 'zone'], 'DE-LU');

            $s['PvProvider'] = (string) ($g($c, ['pvforecast', 'provider']) ?? '');
            $l['PvPlanes'] = $this->planesToRows($g($c, ['pvforecast', 'planes'], []) ?? []);
            $s['PvAkkudoktorBackend'] = (string) $g($c, ['pvforecast', 'akkudoktor', 'backend'], 'remote');
            $s['PvAkkudoktorResolution'] = (int) $g($c, ['pvforecast', 'akkudoktor', 'resolution_minutes'], 15);
            $s['PvAkkudoktorCalibration'] = (bool) $g($c, ['pvforecast', 'akkudoktor', 'calibration_enabled'], false);
            $s['PvForecastSolarApiKey'] = (string) ($g($c, ['pvforecast', 'forecastsolar', 'api_key']) ?? '');
            $s['PvSolcastApiKey'] = (string) ($g($c, ['pvforecast', 'solcast', 'api_key']) ?? '');
            $s['PvSolcastSiteId'] = (string) ($g($c, ['pvforecast', 'solcast', 'site_id']) ?? '');
            $s['PvNodeApiKey'] = (string) ($g($c, ['pvforecast', 'pvnode', 'api_key']) ?? '');
            $s['PvNodeSiteId'] = (string) ($g($c, ['pvforecast', 'pvnode', 'site_id']) ?? '');
            $s['PvNodeForecastDays'] = (int) $g($c, ['pvforecast', 'pvnode', 'forecast_days'], 2);

            $s['LoadProvider'] = (string) ($g($c, ['load', 'provider']) ?? '');
            $s['LoadYearEnergyKwh'] = (float) ($g($c, ['load', 'loadakkudoktor', 'loadakkudoktor_year_energy_kwh']) ?? 0.0);
            $s['WeatherProvider'] = (string) ($g($c, ['weather', 'provider']) ?? '');

            foreach (['MeasLoadEmrKeys' => 'load_emr_keys', 'MeasGridImportEmrKeys' => 'grid_import_emr_keys', 'MeasGridExportEmrKeys' => 'grid_export_emr_keys', 'MeasPvEmrKeys' => 'pv_production_emr_keys'] as $prop => $field) {
                $keys = $g($c, ['measurement', $field], []) ?? [];
                $l[$prop] = array_map(static fn ($k): array => ['key' => (string) $k], is_array($keys) ? $keys : []);
            }

            $inverters = $g($c, ['devices', 'inverters'], []);
            if (is_array($inverters) && $inverters !== []) {
                $inv = reset($inverters);
                $s['InverterID'] = (string) key($inverters);
                $s['InverterMaxPowerW'] = (int) round((float) ($inv['max_power_w'] ?? 0));
                $s['InverterMaxAcChargePowerW'] = (int) round((float) ($inv['max_ac_charge_power_w'] ?? 0));
                $s['InverterAcToDcEfficiency'] = (float) ($inv['ac_to_dc_efficiency'] ?? 1.0);
                $s['InverterDcToAcEfficiency'] = (float) ($inv['dc_to_ac_efficiency'] ?? 1.0);
            }
            return ['scalar' => $s, 'list' => $l];
        }

        /** Provider ids per section from a loaded config, with static fallback. */
        protected function ProviderOptions(array $config, string $section): array
        {
            $ids = self::dig($config, [$section, 'providers'], null);
            if (!is_array($ids) || $ids === []) {
                $ids = self::PROVIDER_FALLBACK[$section] ?? [];
            }
            $options = [['caption' => $this->Translate('- not set -'), 'value' => '']];
            foreach ($ids as $id) {
                $options[] = ['caption' => (string) $id, 'value' => (string) $id];
            }
            return $options;
        }

        private function windowsToRows(mixed $windows): array
        {
            $rows = [];
            foreach (is_array($windows) ? $windows : [] as $w) {
                $rows[] = [
                    'start_time' => (string) ($w['start_time'] ?? ''),
                    'duration'   => (string) ($w['duration'] ?? ''),
                    'value'      => (float) ($w['value'] ?? 0),
                ];
            }
            return $rows;
        }

        private function planesToRows(mixed $planes): array
        {
            $rows = [];
            foreach (is_array($planes) ? $planes : [] as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $rows[] = [
                    'peakpower'       => (float) ($p['peakpower'] ?? 0),
                    'surface_azimuth' => (float) ($p['surface_azimuth'] ?? 180),
                    'surface_tilt'    => (float) ($p['surface_tilt'] ?? 30),
                    'inverter_paco'   => (int) ($p['inverter_paco'] ?? 0),
                    'userhorizon'     => is_array($p['userhorizon'] ?? null) ? implode(',', $p['userhorizon']) : '',
                    'loss'            => (float) ($p['loss'] ?? 0),
                    'albedo'          => (float) ($p['albedo'] ?? 0),
                ];
            }
            return $rows;
        }
    }
}
