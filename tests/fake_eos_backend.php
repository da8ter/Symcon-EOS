<?php

declare(strict_types=1);

/*
 * FakeEOSBackend: models Akkudoktor-EOS 0.4.0rc1 as verified in the source
 * and live on 23.09.2026:
 *   - PUT /v1/config merges with exclude_none (null never clears) and deep_merge
 *     (dicts merged, lists replaced) into the runtime settings; the effective config
 *     is file + runtime settings, re-evaluated on every merge;
 *   - PUT /v1/config/<path> sets the value (null clears) and records it in the runtime
 *     settings; replacing a device map keeps removed keys in the runtime settings, so
 *     they come back on the next merge until save + reset;
 *   - missing paths answer 404 with a problem body; request bodies are validated with
 *     defaults (min_soc_percentage 0, max 100) before the merge, devices again after;
 *   - measurements: /value 404 for unknown keys, /data drops unknown keys silently,
 *     /samples 422 for keys without an energy channel; runCheck() mirrors the run
 *     pre-checks of optimization/genetic/configrequest.py (newest record decides).
 */

require_once __DIR__ . '/fake_eos_measurements.php';

final class FakeEOSBackend
{
    use FakeEOSMeasurements;

    public const BATTERY_DEFAULTS = [
        'capacity_wh' => 8000, 'charging_efficiency' => 0.88, 'discharging_efficiency' => 0.88,
        'levelized_cost_of_storage_amt_kwh' => 0.0, 'max_charge_power_w' => 5000.0, 'min_charge_power_w' => 50.0,
        'charge_rates' => [0.0, 0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9, 1.0], 'min_soc_percentage' => 0, 'max_soc_percentage' => 100,
        'grid_export_rates' => [0.25, 0.5, 0.75, 1.0], 'min_soc_deadline_datetime' => null, 'min_soc_max_duration_h' => null,
    ];
    public const APPLIANCE_DEFAULTS = [
        'consumption_wh' => 3000, 'duration_h' => 3, 'num_cycles' => 1, 'min_cycle_gap_h' => 0, 'schedule_mode' => 'ONCE',
        'time_windows' => null, 'earliest_start_datetime' => null, 'deadline_datetime' => null, 'deadline_policy' => 'BEST_EFFORT',
    ];
    public const INVERTER_DEFAULTS = [
        'max_power_w' => null, 'ac_to_dc_efficiency' => 1.0, 'dc_to_ac_efficiency' => 1.0, 'max_ac_charge_power_w' => null, 'battery_id' => null,
    ];
    public const GROUPS = ['batteries' => self::BATTERY_DEFAULTS, 'electric_vehicles' => self::BATTERY_DEFAULTS, 'inverters' => self::INVERTER_DEFAULTS, 'home_appliances' => self::APPLIANCE_DEFAULTS];

    public array $file = [];
    public array $runtime = [];
    public array $live = [];
    /** unix second => [key => float|null] */
    public array $records = [];
    public array $calls = [];
    public array $health = [];
    public ?array $plan = null;
    public ?array $solution = null;
    /** '' | 'connect' (curl error 7) | 'timeout' (curl error 28) */
    public string $down = '';
    public int $measurementMaxAge = 300;
    /** > 0: GET /v1/config/<path> answers with this HTTP error (e.g. 500) */
    public int $getConfigStatus = 0;
    /** Paths whose GET answers 500; a failing PUT /v1/config/file; EOS notation for durations ("1 hour 30 minutes"). */
    public array $failPaths = [];
    public bool $saveFails = false;
    public bool $normalizeDurations = false;

    public function __construct()
    {
        $this->health = ['status' => 'alive', 'pid' => 1, 'version' => '0.4.0rc1',
            'energy-management' => ['start_datetime' => date(DATE_ATOM, $this->now() - 3600), 'last_run_datetime' => date(DATE_ATOM, $this->now() - 600)]];
    }

    public function now(): int
    {
        return EOSClock::$now ?? time();
    }

    /** Start from a saved configuration file (as after an EOS restart). */
    public function load(array $config): void
    {
        $this->file = $config;
        $this->runtime = [];
        $this->live = $config;
    }

    // ---------------------------------------------------------------- helpers

    public static function deepMerge(mixed $source, mixed $update): mixed
    {
        if (is_array($source) && is_array($update) && !array_is_list($update) && !array_is_list($source)) {
            foreach ($update as $k => $v) {
                $source[$k] = array_key_exists($k, $source) ? self::deepMerge($source[$k], $v) : $v;
            }
            return $source;
        }
        if (is_array($source) && $source !== [] && !array_is_list($source) && $update === []) {
            return $source; // "{}" merged into a dict changes nothing (it arrives here as [])
        }
        return $update;
    }

    private static function dropNulls(mixed $value): mixed
    {
        if (!is_array($value) || array_is_list($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            if ($v !== null) {
                $out[$k] = self::dropNulls($v);
            }
        }
        return $out;
    }

    /** JSON round trip as on the wire: objects stay distinguishable from lists. */
    private static function wire(mixed $value): mixed
    {
        return json_decode((string) json_encode($value));
    }

    private static function toArray(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = (array) $value;
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = self::toArray($v);
            }
        }
        return $value;
    }

    private function result(bool $ok, int $status, mixed $data, ?string $error, int $errno = 0): array
    {
        return ['ok' => $ok, 'status' => $status, 'data' => $data, 'error' => $error, 'errno' => $errno];
    }

    private function unreachable(): ?array
    {
        if ($this->down === 'connect') {
            return $this->result(false, 0, null, 'Failed to connect to host.docker.internal port 8503: Connection refused', 7);
        }
        if ($this->down === 'timeout') {
            return $this->result(false, 0, null, 'Operation timed out after 10001 milliseconds with 0 bytes received', 28);
        }
        return null;
    }

    private function problem(int $status, string $title, string $detail, string $path): array
    {
        $body = ['type' => 'about:blank', 'title' => $title, 'status' => $status, 'detail' => $detail, 'instance' => $path];
        return $this->result(false, $status, $body, 'HTTP ' . $status . ': ' . $detail);
    }

    /** Request-body validation of device maps (types, ids, min < max with defaults). */
    private function validateBody(mixed $wire): ?string
    {
        $devices = $wire->devices ?? null;
        if (!$devices instanceof stdClass) {
            return null;
        }
        foreach (array_keys(self::GROUPS) as $group) {
            if (!property_exists($devices, $group) || $devices->$group === null) {
                continue;
            }
            if (!$devices->$group instanceof stdClass) {
                return 'devices.' . $group . ': Input should be a valid dictionary';
            }
            foreach ((array) $devices->$group as $key => $device) {
                if (!$device instanceof stdClass) {
                    return 'devices.' . $group . '.' . $key . ': Input should be a valid dictionary';
                }
                if (isset($device->device_id) && (string) $device->device_id !== (string) $key) {
                    return 'device_id must match map key';
                }
                if ($group === 'batteries' || $group === 'electric_vehicles') {
                    $min = (int) ($device->min_soc_percentage ?? 0);
                    $max = (int) ($device->max_soc_percentage ?? 100);
                    if ($min >= $max) {
                        return 'min_soc_percentage must be < max_soc_percentage';
                    }
                }
            }
        }
        return null;
    }

    /** Validation of the effective configuration after a change. */
    private function validateLive(array $live): ?string
    {
        foreach (['batteries', 'electric_vehicles'] as $group) {
            foreach ($live['devices'][$group] ?? [] as $key => $device) {
                $d = array_merge(self::BATTERY_DEFAULTS, is_array($device) ? $device : []);
                if ((int) $d['min_soc_percentage'] >= (int) $d['max_soc_percentage']) {
                    return 'min_soc_percentage must be < max_soc_percentage';
                }
            }
        }
        foreach ($live['devices']['home_appliances'] ?? [] as $key => $device) {
            $earliest = is_array($device) ? ($device['earliest_start_datetime'] ?? null) : null;
            $deadline = is_array($device) ? ($device['deadline_datetime'] ?? null) : null;
            if ($earliest !== null && $deadline !== null && strtotime((string) $deadline) <= strtotime((string) $earliest)) {
                return 'deadline_datetime ' . $deadline . ' must be after earliest_start_datetime ' . $earliest; // homeappliancesettings.py
            }
        }
        return null;
    }

    /** EOS writes durations back as pendulum words: str(Duration), e.g. "90 minutes" -> "1 hour 30 minutes". */
    public static function durationWords(string $duration): string
    {
        if (preg_match_all('/(\d+)\s*(weeks?|days?|hours?|minutes?|seconds?)/', $duration, $m, PREG_SET_ORDER) === 0) {
            return $duration;
        }
        $unit = ['week' => 604800, 'day' => 86400, 'hour' => 3600, 'minute' => 60, 'second' => 1];
        $seconds = 0;
        foreach ($m as $part) {
            $seconds += (int) $part[1] * $unit[rtrim($part[2], 's')];
        }
        $words = [];
        foreach ($unit as $name => $size) {
            $n = intdiv($seconds, $size);
            $seconds %= $size;
            if ($n > 0) {
                $words[] = $n . ' ' . $name . ($n > 1 ? 's' : '');
            }
        }
        return $words === [] ? '0 seconds' : implode(' ', $words);
    }

    /** Effective config with EOS defaults filled into device entries (as GET returns it). */
    public function effective(): array
    {
        $cfg = $this->live;
        foreach (self::GROUPS as $group => $defaults) {
            foreach ($cfg['devices'][$group] ?? [] as $key => $device) {
                $device = array_merge(['device_id' => $key], $defaults, is_array($device) ? $device : []);
                if ($group === 'home_appliances' && is_array($device['time_windows'] ?? null)) {
                    foreach ($device['time_windows']['windows'] ?? [] as $i => $w) {
                        $w['start_time'] = preg_match('/^\d\d:\d\d(:\d\d)?$/', (string) ($w['start_time'] ?? '')) ? (strlen($w['start_time']) === 5 ? $w['start_time'] . ':00' : $w['start_time']) . '.000000' : ($w['start_time'] ?? null);
                        if ($this->normalizeDurations && isset($w['duration'])) {
                            $w['duration'] = self::durationWords((string) $w['duration']);
                        }
                        $device['time_windows']['windows'][$i] = $w + ['day_of_week' => null, 'date' => null, 'locale' => null];
                    }
                }
                if ($group === 'batteries' || $group === 'electric_vehicles') {
                    $device['measurement_key_soc_factor'] = $key . '-soc-factor';
                }
                $cfg['devices'][$group][$key] = $device;
            }
        }
        return $cfg;
    }

    // ---------------------------------------------------------------- configuration API

    public function getConfig(): array
    {
        $this->calls[] = ['GET', '/v1/config'];
        return $this->unreachable() ?? $this->result(true, 200, $this->effective(), null);
    }

    public function getConfigPath(string $path): array
    {
        $this->calls[] = ['GET', '/v1/config/' . $path];
        if (($down = $this->unreachable()) !== null) {
            return $down;
        }
        if ($this->getConfigStatus > 0 || in_array($path, $this->failPaths, true)) {
            return $this->problem($this->getConfigStatus ?: 500, 'Internal Server Error', 'simulated failure', '/v1/config/' . $path);
        }
        $node = $this->effective();
        foreach (array_values(array_filter(explode('/', str_replace('.', '/', $path)), static fn (string $p): bool => $p !== '')) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $this->problem(404, 'Error on config value retrieval', "\"Invalid dict key at '" . $path . "': " . $part . "\"", '/v1/config/' . $path);
            }
            $node = $node[$part];
        }
        return $this->result(true, 200, $node, null);
    }

    public function putConfig(mixed $merge): array
    {
        $this->calls[] = ['PUT', '/v1/config', $merge];
        if (($down = $this->unreachable()) !== null) {
            return $down;
        }
        $wire = self::wire($merge);
        if (($problem = $this->validateBody($wire)) !== null) {
            return $this->problem(422, 'Request validation failed.', 'Request validation failed.', '/v1/config');
        }
        $previous = [$this->runtime, $this->live];
        $this->runtime = self::deepMerge($this->runtime, self::dropNulls(self::toArray($wire)));
        $this->live = self::deepMerge($this->file, $this->runtime);
        if (($problem = $this->validateLive($this->live)) !== null) {
            [$this->runtime, $this->live] = $previous;
            return $this->problem(400, 'Error on update of configuration', $problem, '/v1/config');
        }
        return $this->result(true, 200, $this->effective(), null);
    }

    public function putConfigPath(string $path, mixed $value): array
    {
        $this->calls[] = ['PUT', '/v1/config/' . $path, $value];
        if (($down = $this->unreachable()) !== null) {
            return $down;
        }
        $parts = array_values(array_filter(explode('/', str_replace('.', '/', $path)), static fn (string $p): bool => $p !== ''));
        $value = self::toArray(self::wire($value));
        // A device entry itself cannot be null, and a path below a missing device is refused.
        if (count($parts) >= 3 && $parts[0] === 'devices' && isset(self::GROUPS[$parts[1]])) {
            if (!isset($this->live['devices'][$parts[1]][$parts[2]])) {
                return $this->problem(400, 'Error on update of configuration', "Invalid dict key '" . $parts[2] . "'", '/v1/config/' . $path);
            }
            if (count($parts) === 3 && $value === null) {
                return $this->problem(400, 'Error on update of configuration', "'NoneType' object has no attribute 'device_id'", '/v1/config/' . $path);
            }
        }
        $live = $this->live;
        $node = &$live;
        foreach ($parts as $i => $part) {
            if ($i === count($parts) - 1) {
                $node[$part] = $value;
                break;
            }
            if (!isset($node[$part]) || !is_array($node[$part])) {
                $node[$part] = [];
            }
            $node = &$node[$part];
        }
        unset($node);
        if (($problem = $this->validateLive($live)) !== null) {
            return $this->problem(400, 'Error on update of configuration', $problem, '/v1/config/' . $path);
        }
        $this->live = $live;
        // Runtime setting: the value at the path is deep-merged, so removed dict keys survive there.
        $nest = $value;
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            $nest = [$parts[$i] => $nest];
        }
        $this->runtime = self::deepMerge($this->runtime, $nest);
        return $this->result(true, 200, $this->effective(), null);
    }

    public function saveConfigFile(): array
    {
        $this->calls[] = ['PUT', '/v1/config/file'];
        if (($down = $this->unreachable()) !== null) {
            return $down;
        }
        if ($this->saveFails) {
            return $this->problem(500, 'Error', 'config file not writable', '/v1/config/file');
        }
        $this->file = $this->live;
        return $this->result(true, 200, $this->effective(), null);
    }

    public function resetConfig(): array
    {
        $this->calls[] = ['POST', '/v1/config/reset'];
        if (($down = $this->unreachable()) !== null) {
            return $down;
        }
        $this->runtime = [];
        $this->live = $this->file;
        return $this->result(true, 200, $this->effective(), null);
    }

    // ---------------------------------------------------------------- measurements

    public function health(): array
    {
        $this->calls[] = ['GET', '/v1/health'];
        return $this->unreachable() ?? $this->result(true, 200, $this->health, null);
    }

    public function getPlan(): array
    {
        $this->calls[] = ['GET', '/v1/energy-management/plan'];
        if (($down = $this->unreachable()) !== null) {
            return $down;
        }
        return $this->plan !== null ? $this->result(true, 200, $this->plan, null) : $this->problem(404, 'Not Found', 'Did not find plan.', '/v1/energy-management/plan');
    }

    public function getSolution(): array
    {
        $this->calls[] = ['GET', '/v1/energy-management/optimization/solution'];
        if (($down = $this->unreachable()) !== null) {
            return $down;
        }
        return $this->solution !== null ? $this->result(true, 200, $this->solution, null) : $this->problem(404, 'Not Found', 'Did not find solution.', '/v1/energy-management/optimization/solution');
    }

    /** true: the run takes longer than the client waits (the client sees curl error 28). */
    public bool $optimizeSlow = false;

    public function optimize(int $timeoutSec = 600): array
    {
        $this->calls[] = ['POST', '/v1/optimize', $timeoutSec];
        if (($down = $this->unreachable()) !== null) {
            return $down;
        }
        return $this->optimizeSlow
            ? $this->result(false, 0, null, 'Operation timed out after ' . ($timeoutSec * 1000) . ' milliseconds with 0 bytes received', 28)
            : $this->result(true, 200, ['ok' => true], null);
    }

    /** Raw JSON text: device maps stay objects, also when empty (as EOS sends them). */
    public function getConfigRaw(string $path): array
    {
        $res = $path === '' ? $this->getConfig() : $this->getConfigPath($path);
        if (!$res['ok']) {
            return $res;
        }
        $data = $res['data'];
        if ($data === [] && preg_match('#^devices/(batteries|electric_vehicles|inverters|home_appliances)$#', $path) === 1) {
            $data = new stdClass();
        }
        return $this->result(true, 200, (string) json_encode($data, JSON_UNESCAPED_SLASHES), null);
    }
}
