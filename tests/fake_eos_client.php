<?php

declare(strict_types=1);

/*
 * FakeEOSClient: replaces libs/EOSClient.php (load this file first; the class_exists
 * guard there then skips the real curl client). Every EOSClient instance talks to one
 * shared FakeEOSBackend that models Akkudoktor-EOS 0.4.0rc1 as verified in the source
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

final class FakeEOSBackend
{
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
        return null;
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
        if ($this->getConfigStatus > 0) {
            return $this->problem($this->getConfigStatus, 'Internal Server Error', 'simulated failure', '/v1/config/' . $path);
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

if (!class_exists('EOSClient')) {
    /** Same public surface as libs/EOSClient.php; all instances share $GLOBALS['eosBackend']. */
    final class EOSClient
    {
        public const DEFAULT_TIMEOUT = 10;
        public const OPTIMIZE_TIMEOUT = 600;

        public function __construct(private string $host = '127.0.0.1', private int $port = 8503, private int $timeoutSec = self::DEFAULT_TIMEOUT, private $debug = null)
        {
        }

        private function b(): FakeEOSBackend
        {
            return $GLOBALS['eosBackend'];
        }

        public function baseUrl(): string { return 'http://' . $this->host . ':' . $this->port; }
        public function dashboardUrl(int $dashPort = 8504): string { return 'http://' . $this->host . ':' . $dashPort; }
        public function swaggerUrl(): string { return $this->baseUrl() . '/docs'; }
        public function health(): array { return $this->b()->health(); }
        public function getPlan(): array { return $this->b()->getPlan(); }
        public function getSolution(): array { return $this->b()->getSolution(); }
        public function getSolutionNative(string $algorithm = 'GENETIC'): array { return $this->b()->getSolution(); }
        public function optimize(int $timeoutSec = self::OPTIMIZE_TIMEOUT): array { return $this->b()->optimize($timeoutSec); }
        public function getConfigRaw(string $path): array { return $this->b()->getConfigRaw($path); }
        public function putMeasurementValue(string $key, float $value, string $isoDatetime): array { return $this->b()->putMeasurementValue($key, $value, $isoDatetime); }
        public function putMeasurementData(array $data): array { return $this->b()->putMeasurementData($data); }
        public function putMeasurementSamples(array $samples): array { return $this->b()->putMeasurementSamples($samples); }
        public function getMeasurementKeys(): array { return $this->b()->getMeasurementKeys(); }
        public function getConfig(): array { return $this->b()->getConfig(); }
        public function getConfigPath(string $path): array { return $this->b()->getConfigPath($path); }
        public function putConfig(array|object $merge): array { return $this->b()->putConfig($merge); }
        public function putConfigPath(string $path, mixed $value): array { return $this->b()->putConfigPath($path, $value); }
        public function saveConfigFile(): array { return $this->b()->saveConfigFile(); }
        public function resetConfig(): array { return $this->b()->resetConfig(); }
    }
}

$GLOBALS['eosBackend'] = new FakeEOSBackend();

/** The real EOSServer module as instance 2000, created on first use. */
function realServer(): EOSServer
{
    $server = $GLOBALS['objects'][2000] ?? null;
    if ($server instanceof EOSServer) {
        return $server;
    }
    $GLOBALS['instances'][2000] = ['ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE];
    $server = new EOSServer(2000);
    $server->Create();
    $server->ApplyChanges();
    return $server;
}

/** Device instance connected to the real EOSServer (which talks to FakeEOSBackend). */
function connectToRealServer(IPSModuleStrict $m): EOSServer
{
    $server = realServer();
    $GLOBALS['instances'][$m->InstanceID] = ['ConnectionID' => 2000, 'InstanceStatus' => IS_ACTIVE];
    return $server;
}
