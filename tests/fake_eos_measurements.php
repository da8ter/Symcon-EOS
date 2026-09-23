<?php

declare(strict_types=1);

/*
 * Measurement side of FakeEOSBackend: known keys, the record store ("newest record
 * decides") and runCheck(), the run pre-checks of optimization/genetic/configrequest.py.
 */

trait FakeEOSMeasurements
{
    /** Keys EOS accepts: device measurement keys plus the registered energy meter keys. */
    public function knownKeys(): array
    {
        $keys = [];
        foreach (['batteries', 'electric_vehicles'] as $group) {
            foreach (array_keys($this->live['devices'][$group] ?? []) as $id) {
                $keys[] = $id . '-soc-factor';
            }
        }
        foreach (array_keys($this->live['devices']['home_appliances'] ?? []) as $id) {
            $keys[] = $id . '.cycles_completed';
        }
        return array_merge($keys, $this->emrKeys());
    }

    public function emrKeys(): array
    {
        $keys = [];
        foreach (['load_emr_keys', 'grid_import_emr_keys', 'grid_export_emr_keys', 'pv_production_emr_keys'] as $field) {
            foreach ($this->live['measurement'][$field] ?? [] as $key) {
                $keys[] = (string) $key;
            }
        }
        return $keys;
    }

    private static function second(string $iso): int
    {
        return (new DateTimeImmutable($iso))->getTimestamp();
    }

    public function putMeasurementValue(string $key, float $value, string $iso): array
    {
        $this->calls[] = ['PUT', '/v1/measurement/value', ['key' => $key, 'value' => $value, 'datetime' => $iso]];
        if (($down = $this->unreachable()) !== null) {
            return $down;
        }
        if (!in_array($key, $this->knownKeys(), true)) {
            return $this->problem(404, 'Not Found', "Key '" . $key . "' is not available.", '/v1/measurement/value');
        }
        $this->records[self::second($iso)][$key] = $value;
        return $this->result(true, 200, null, null);
    }

    public function putMeasurementData(array $data): array
    {
        $this->calls[] = ['PUT', '/v1/measurement/data', $data];
        if (($down = $this->unreachable()) !== null) {
            return $down;
        }
        $start = self::second((string) $data['start_datetime']);
        $step = ($data['interval'] ?? '1 minute') === '1 minute' ? 60 : 3600;
        $known = $this->knownKeys();
        foreach ($data as $key => $values) {
            if ($key === 'start_datetime' || $key === 'interval' || !in_array($key, $known, true)) {
                continue; // EOS silently drops unknown keys
            }
            foreach (array_values((array) $values) as $i => $v) {
                $this->records[$start + $i * $step][$key] = $v === null ? null : (float) $v;
            }
        }
        return $this->result(true, 200, null, null);
    }

    public function putMeasurementSamples(array $samples): array
    {
        $this->calls[] = ['PUT', '/v1/measurement/samples', $samples];
        if (($down = $this->unreachable()) !== null) {
            return $down;
        }
        $emr = $this->emrKeys();
        foreach ($samples as $s) {
            if (!in_array((string) $s['key'], $emr, true)) {
                return $this->problem(422, 'Unprocessable Entity', "No energy channel definition for '" . $s['key'] . "'.", '/v1/measurement/samples');
            }
        }
        foreach ($samples as $s) {
            $this->records[self::second((string) $s['date_time'])][(string) $s['key']] = (float) $s['value'];
        }
        return $this->result(true, 200, ['accepted' => count($samples)], null);
    }

    public function getMeasurementKeys(): array
    {
        return $this->unreachable() ?? $this->result(true, 200, $this->knownKeys(), null);
    }

    /** The newest record at or before $now that lies within $maxAge (dropna=False: that record decides). */
    private function newestRecord(int $now, int $maxAge): ?array
    {
        $best = null;
        foreach ($this->records as $ts => $row) {
            if ($ts <= $now && $ts >= $now - $maxAge && ($best === null || $ts > $best[0])) {
                $best = [$ts, $row];
            }
        }
        return $best;
    }

    /** Run pre-checks of configrequest.py:72-139 plus the measurement lookups; [] = the run can start. */
    public function runCheck(?int $now = null): array
    {
        $now ??= $this->now();
        $errors = [];
        $devices = $this->live['devices'] ?? [];
        $groups = [];
        foreach (array_keys(self::GROUPS) as $group) {
            $entries = array_keys($devices[$group] ?? []);
            $max = $devices['max_' . $group] ?? null;
            if ($max !== null && count($entries) > (int) $max) {
                $errors[] = 'devices.' . $group . ' exceeds configured maximum ' . (int) $max . '.';
            }
            if ($group !== 'home_appliances' && count($entries) > 1) {
                $errors[] = 'GENETIC supports at most one device in devices.' . $group . '.';
            }
            $groups[$group] = $entries;
        }
        $all = array_merge(...array_values($groups));
        if (count($all) !== count(array_unique($all))) {
            $errors[] = 'Device ids must be unique across device groups.';
        }
        $battery = $groups['batteries'][0] ?? null;
        if ($groups['inverters'] !== []) {
            $inverter = $devices['inverters'][$groups['inverters'][0]];
            if (($inverter['battery_id'] ?? null) !== $battery) {
                $errors[] = 'Inverter battery_id must match the configured battery.';
            }
        } else {
            $errors[] = 'Configure an inverter to model PV and grid energy flows.';
        }
        $newest = $this->newestRecord($now, $this->measurementMaxAge);
        foreach (array_merge($groups['batteries'], $groups['electric_vehicles']) as $id) {
            if ($newest === null || ($newest[1][$id . '-soc-factor'] ?? null) === null) {
                $errors[] = 'Fresh SoC missing for ' . $id;
            }
        }
        $dayStart = (int) (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone(date_default_timezone_get()))->setTime(0, 0)->getTimestamp();
        $today = $this->newestRecord($now, $now - $dayStart);
        foreach ($groups['home_appliances'] as $id) {
            if ($today === null || ($today[1][$id . '.cycles_completed'] ?? null) === null) {
                $errors[] = 'Invalid completed cycle count for ' . $id;
            }
        }
        return $errors;
    }

    // ---------------------------------------------------------------- plan, health, optimize
}
