<?php

declare(strict_types=1);

/*
 * EOS Server: measurement writes. EOS 0.4.0rc1 reads SoC and cycle counts from the
 * newest measurement record only, so every record the server writes carries the
 * known "sticky" device values (SoCCache attribute).
 */
if (!trait_exists('EOSMeasurementBundle')) {
    trait EOSMeasurementBundle
    {
        /** Script API: same path as the device instances, so the newest record keeps every SoC value. */
        public function PutMeasurement(string $Key, float $Value, string $DateTime): bool
        {
            return $this->forwardPutMeasurement(['Key' => $Key, 'Value' => $Value, 'DateTime' => $DateTime !== '' ? $DateTime : $this->eosIsoNow()])['ok'];
        }

        /** ForwardData PutMeasurement: the value plus all cached sticky values in one record. */
        protected function forwardPutMeasurement(array $data): array
        {
            $key = (string) ($data['Key'] ?? '');
            $value = (float) ($data['Value'] ?? 0);
            $dateTime = (string) ($data['DateTime'] ?? $this->eosIsoNow());
            $this->rememberSticky($key, $value);
            // One request for the value plus all cached sticky values (PUT /v1/measurement/data
            // accepts any key): EOS then sees a complete record at this timestamp, never a
            // half-written one that a run in between would reject as "stale SoC".
            $bundle = $this->stickyBundle($key);
            if ($bundle === []) {
                $res = $this->client()->putMeasurementValue($key, $value, $dateTime);
            } else {
                $res = $this->client()->putMeasurementData(['start_datetime' => $dateTime, 'interval' => '1 minute', $key => [$value]] + array_map(static fn (float $v): array => [$v], $bundle));
            }
            if (!$res['ok']) {
                $this->SetValue('LastError', sprintf($this->Translate('Measurement: %s'), (string) $res['error']));
            }
            return ['ok' => $res['ok'], 'status' => $res['status'], 'errno' => $res['errno'] ?? 0, 'error' => $res['error']];
        }

        /** ForwardData PutSamples: meter samples, preceded by the sticky values for the same timestamp. */
        protected function forwardPutSamples(array $data): array
        {
            $samples = is_array($data['Samples'] ?? null) ? $data['Samples'] : [];
            // Meter readings carry no SoC/cycle keys. Write the cached sticky values for the
            // same timestamp FIRST, so the newest record is never a meter-only record.
            $bundle = $this->stickyBundle('');
            if ($bundle !== [] && $samples !== []) {
                // Stamped with the NEWEST sample (a history import arrives newest first), so the
                // newest record keeps every device value.
                $newest = max(array_map(fn (array $s): int => $this->eosParseTime((string) ($s['date_time'] ?? '')), $samples));
                $sticky = $this->client()->putMeasurementData(['start_datetime' => $this->eosIsoNow($newest > 0 ? $newest : null), 'interval' => '1 minute'] + array_map(static fn (float $v): array => [$v], $bundle));
                if (!$sticky['ok']) {
                    $this->SetValue('LastError', sprintf($this->Translate('Resent values: %s'), (string) $sticky['error']));
                }
            }
            $res = $this->client()->putMeasurementSamples($samples);
            if (!$res['ok']) {
                $this->SetValue('LastError', sprintf($this->Translate('Samples: %s'), (string) $res['error']));
            }
            return ['ok' => $res['ok'], 'status' => $res['status'], 'errno' => $res['errno'] ?? 0, 'error' => $res['error'], 'data' => $res['data']];
        }

        /**
         * EOS 0.4.0rc1 looks up device measurements (SoC factor, completed cycles)
         * with dropna=False: the newest measurement record decides, and a record
         * written for another key (EV SoC, meter reading, cycles) has NaN for the
         * others, which cancels the run. Work-around: remember the latest value of
         * these "sticky" keys and re-send all of them whenever a different key is
         * written, so the newest record always carries every device value.
         */
        private function rememberSticky(string $key, float $value): void
        {
            if (!$this->isStickyKey($key)) {
                return;
            }
            $cache = $this->eosJsonDecode($this->ReadAttributeString('SoCCache'), []);
            $cache = is_array($cache) ? $cache : [];
            $cache[$key] = ['value' => $value, 'ts' => $this->eosNow()];
            $this->WriteAttributeString('SoCCache', json_encode($cache));
        }

        /**
         * EOS 0.4.0rc1 looks up SoC and cycle counts in the newest measurement record
         * only (configrequest.py, dropna=False). Every record we write therefore has to
         * carry all known sticky values; this returns them as key => value, without
         * $exceptKey. SoC values expire with the EOS freshness limit, cycle counts do not.
         */
        private function stickyBundle(string $exceptKey): array
        {
            $cache = $this->eosJsonDecode($this->ReadAttributeString('SoCCache'), []);
            $cache = is_array($cache) ? $cache : [];
            $now = $this->eosNow();
            $maxAge = max(60, (int) ($this->ReadPropertyInteger('OptMeasurementMaxAge') ?: 300));
            $midnight = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone(date_default_timezone_get()))->setTime(0, 0)->getTimestamp();
            $bundle = [];
            $changed = false;
            foreach ($cache as $stickyKey => $entry) {
                $stickyKey = (string) $stickyKey;
                if (str_ends_with($stickyKey, '-soc-factor') && $now - (int) ($entry['ts'] ?? 0) > $maxAge) {
                    unset($cache[$stickyKey]); // too old for EOS anyway
                    $changed = true;
                    continue;
                }
                if (str_ends_with($stickyKey, '.cycles_completed') && (int) ($entry['ts'] ?? 0) < $midnight) {
                    // A new day starts with no completed cycles; yesterday's count must not be re-dated.
                    $cache[$stickyKey] = ['value' => 0.0, 'ts' => $now];
                    $changed = true;
                }
                if ($stickyKey !== $exceptKey) {
                    $bundle[$stickyKey] = (float) $cache[$stickyKey]['value'];
                }
            }
            if ($changed) {
                $this->WriteAttributeString('SoCCache', json_encode($cache));
            }
            return $bundle;
        }

        private function isStickyKey(string $key): bool
        {
            return str_ends_with($key, '-soc-factor') || str_ends_with($key, '.cycles_completed');
        }
    }
}
