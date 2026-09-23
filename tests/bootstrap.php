<?php

declare(strict_types=1);

/*
 * Isolated SDK double for the Symcon-EOS modules. Never talks to a running Symcon
 * or EOS: the module base class keeps properties, attributes, variables and timers
 * in memory, IPS_* functions read a small "world" of variables and instances.
 *
 * Two parents are available for device instances:
 *   connectToServer($m)      FakeEOS, a stand-in for the ForwardData contract of the
 *                            EOS Server splitter (fast, used by most device tests);
 *   connectToRealServer($m)  the real EOSServer module on top of FakeEOSClient
 *                            (tests/fake_eos_client.php), which models EOS itself.
 *
 * $GLOBALS['sdkMode']: 'legacy' keeps the old doubles (RequestAction and
 * IPS_RunActionWait throw on failure); 'live' behaves like Symcon 9.1 as measured on
 * 23.09.2026: failures come back as false / error text plus a warning, never as an
 * exception.
 *
 * Run: tests/run.sh
 */

require_once __DIR__ . '/../libs/EOSCommon.php';

const KR_READY = 10103, IPS_KERNELSTARTED = 10001, VM_UPDATE = 10603;
const IS_ACTIVE = 102, IS_INACTIVE = 104;
const KL_MESSAGE = 10201, KL_SUCCESS = 10202, KL_NOTIFY = 10203, KL_WARNING = 10204, KL_ERROR = 10205, KL_DEBUG = 10206;
const VARIABLETYPE_BOOLEAN = 0, VARIABLETYPE_INTEGER = 1, VARIABLETYPE_FLOAT = 2, VARIABLETYPE_STRING = 3;
const VARIABLE_PRESENTATION_VALUE_PRESENTATION = '{VALUE}', VARIABLE_PRESENTATION_SWITCH = '{SWITCH}',
    VARIABLE_PRESENTATION_SLIDER = '{SLIDER}', VARIABLE_PRESENTATION_ENUMERATION = '{ENUM}', VARIABLE_PRESENTATION_DATE_TIME = '{DATETIME}';
const LOCATION_CONTROL_GUID = '{45E97A63-F870-408A-B259-2933F7EABF74}';
const ARCHIVE_CONTROL_GUID = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';

/** Minimal stand-in for the EOS Server splitter (ForwardData side). */
final class FakeEOS
{
    public array $config = [];
    public array $plan = ['id' => 'plan-1', 'generated_at' => null, 'valid_from' => null, 'valid_until' => null];
    public array $instructions = [];
    public array $calls = [];
    public bool $configReadFails = false;

    public function handle(array $data): array
    {
        $this->calls[] = $data;
        switch ($data['Command'] ?? '') {
            case 'GetPlanForResource':
                $id = (string) $data['ResourceID'];
                return ['ok' => true, 'plan' => $this->plan, 'instructions' => array_values(array_filter($this->instructions, static fn ($i) => $i['resource_id'] === $id)), 'connected' => true];
            case 'GetStatus':
                return ['ok' => true, 'connected' => true, 'version' => '0.4.0rc1', 'status' => IS_ACTIVE];
            case 'GetConfig':
                if ($this->configReadFails) {
                    return ['ok' => false, 'error' => 'unreachable'];
                }
                $node = $this->config;
                foreach (array_filter(explode('/', (string) ($data['Path'] ?? ''))) as $part) {
                    if (!is_array($node) || !array_key_exists($part, $node)) {
                        return ['ok' => true, 'data' => null];
                    }
                    $node = $node[$part];
                }
                return ['ok' => true, 'data' => $node];
            case 'MergeConfig':
                $this->config = array_replace_recursive($this->config, $data['Value']);
                return ['ok' => true];
            case 'SetConfig':
                $parts = array_values(array_filter(explode('/', (string) ($data['Path'] ?? ''))));
                $node = &$this->config;
                foreach ($parts as $i => $part) {
                    if ($i === count($parts) - 1) {
                        $node[$part] = $data['Value'] ?? null;
                        break;
                    }
                    if (!isset($node[$part]) || !is_array($node[$part])) {
                        $node[$part] = [];
                    }
                    $node = &$node[$part];
                }
                unset($node);
                return ['ok' => true];
            case 'SaveConfig':
            case 'PutMeasurement':
            case 'PutSamples':
                return ['ok' => true, 'data' => null];
            case 'GetSolution':
                return ['ok' => true, 'series' => []];
        }
        return ['ok' => false, 'error' => 'unknown command'];
    }

    /** Instruction for a device at $ts (unix). */
    public function instruction(string $device, int $ts, string $mode, float $factor = 1.0): void
    {
        $this->instructions[] = ['id' => $device . '@' . $ts, 'resource_id' => $device, 'actuator_id' => $device,
            'execution_time' => date(DATE_ATOM, $ts), 'operation_mode_id' => $mode, 'operation_mode_factor' => $factor];
    }

    public function freshPlan(int $generatedAt): void
    {
        $this->plan['generated_at'] = date(DATE_ATOM, $generatedAt);
        $this->plan['valid_from'] = $this->plan['generated_at'];
        $this->plan['valid_until'] = date(DATE_ATOM, $generatedAt + 48 * 3600);
    }
}

class IPSModuleStrict
{
    public int $InstanceID;
    public array $properties = [], $attributes = [], $variables = [], $timers = [], $onceTimers = [], $logs = [], $debug = [], $formUpdates = [], $messages = [], $tileUpdates = [];
    public int $status = IS_ACTIVE;

    public function __construct(int $instanceId = 1000)
    {
        $this->InstanceID = $instanceId;
        $GLOBALS['objects'][$instanceId] = $this;
    }

    public function Create(): void {}
    public function ApplyChanges(): void {}
    public function Destroy(): void {}

    protected function RegisterPropertyInteger(string $k, int $v): void { $this->properties[$k] = $v; }
    protected function RegisterPropertyString(string $k, string $v): void { $this->properties[$k] = $v; }
    protected function RegisterPropertyBoolean(string $k, bool $v): void { $this->properties[$k] = $v; }
    protected function RegisterPropertyFloat(string $k, float $v): void { $this->properties[$k] = $v; }
    protected function ReadPropertyInteger(string $k): int { return (int) $this->properties[$k]; }
    protected function ReadPropertyString(string $k): string { return (string) $this->properties[$k]; }
    protected function ReadPropertyBoolean(string $k): bool { return (bool) $this->properties[$k]; }
    protected function ReadPropertyFloat(string $k): float { return (float) $this->properties[$k]; }

    protected function RegisterAttributeInteger(string $k, int $v): void { $this->attributes[$k] ??= $v; }
    protected function RegisterAttributeString(string $k, string $v): void { $this->attributes[$k] ??= $v; }
    protected function RegisterAttributeBoolean(string $k, bool $v): void { $this->attributes[$k] ??= $v; }
    protected function ReadAttributeInteger(string $k): int { return (int) $this->attr($k); }
    protected function ReadAttributeString(string $k): string { return (string) $this->attr($k); }
    protected function ReadAttributeBoolean(string $k): bool { return (bool) $this->attr($k); }
    protected function WriteAttributeInteger(string $k, int $v): void { $this->attr($k); $this->attributes[$k] = $v; }
    protected function WriteAttributeString(string $k, string $v): void { $this->attr($k); $this->attributes[$k] = $v; }
    protected function WriteAttributeBoolean(string $k, bool $v): void { $this->attr($k); $this->attributes[$k] = $v; }
    private function attr(string $k): mixed
    {
        if (!array_key_exists($k, $this->attributes)) {
            throw new RuntimeException('Attribute ' . $k . ' is not registered');
        }
        return $this->attributes[$k];
    }

    protected function RegisterVariableBoolean(string $ident, string $name, mixed $p = '', int $pos = 0): void { $this->variables[$ident] ??= ['type' => 0, 'value' => false, 'name' => $name, 'action' => false, 'setCount' => 0]; }
    protected function RegisterVariableInteger(string $ident, string $name, mixed $p = '', int $pos = 0): void { $this->variables[$ident] ??= ['type' => 1, 'value' => 0, 'name' => $name, 'action' => false, 'setCount' => 0]; }
    protected function RegisterVariableFloat(string $ident, string $name, mixed $p = '', int $pos = 0): void { $this->variables[$ident] ??= ['type' => 2, 'value' => 0.0, 'name' => $name, 'action' => false, 'setCount' => 0]; }
    protected function RegisterVariableString(string $ident, string $name, mixed $p = '', int $pos = 0): void { $this->variables[$ident] ??= ['type' => 3, 'value' => '', 'name' => $name, 'action' => false, 'setCount' => 0]; }
    protected function EnableAction(string $ident): void { $this->variables[$ident]['action'] = true; }
    protected function SetValue(string $ident, mixed $value): bool
    {
        if (!isset($this->variables[$ident])) {
            throw new RuntimeException('Variable ' . $ident . ' is not registered');
        }
        $this->variables[$ident]['value'] = $value;
        $this->variables[$ident]['setCount']++;
        return true;
    }
    protected function GetValue(string $ident): mixed
    {
        if (!isset($this->variables[$ident])) {
            throw new RuntimeException('Variable ' . $ident . ' is not registered');
        }
        return $this->variables[$ident]['value'];
    }
    public function value(string $ident): mixed { return $this->variables[$ident]['value']; }

    protected function RegisterTimer(string $name, int $ms, string $script): void { $this->timers[$name] = ['ms' => $ms, 'script' => $script]; }
    protected function SetTimerInterval(string $name, int $ms): void
    {
        if (!isset($this->timers[$name])) {
            throw new RuntimeException('Timer ' . $name . ' is not registered');
        }
        $this->timers[$name]['ms'] = $ms;
    }
    protected function RegisterOnceTimer(string $name, string $script): void { $this->onceTimers[] = ['name' => $name, 'script' => $script]; }

    /** Test helper: run all pending one-shot timers (Dispatch, ApplyLater ...). Returns how many ran. */
    public function fireOnce(): int
    {
        $pending = $this->onceTimers;
        $this->onceTimers = [];
        foreach ($pending as $t) {
            $this->runScript($t['script']);
        }
        return count($pending);
    }
    /** Test helper: run the script of a registered interval timer once. */
    public function fireTimer(string $name): void { $this->runScript($this->timers[$name]['script']); }
    private function runScript(string $script): void
    {
        if (preg_match('/^IPS_ApplyChanges\(/', $script)) { $this->ApplyChanges(); return; }
        if (preg_match("/^IPS_RequestAction\(\\\$_IPS\['TARGET'\], '([^']+)', '([^']*)'\)/", $script, $m)) { $this->RequestAction($m[1], $m[2]); return; }
        if (preg_match('/^[A-Z]+_(\w+)\(/', $script, $m)) { $this->{$m[1]}(); return; }
        throw new RuntimeException('Unknown timer script ' . $script);
    }

    /** Parent = a registered module object with ForwardData (real EOSServer) or else FakeEOS. */
    protected function SendDataToParent(string $json): string
    {
        $parentId = (int) ($GLOBALS['instances'][$this->InstanceID]['ConnectionID'] ?? 0);
        $parent = $GLOBALS['objects'][$parentId] ?? null;
        if ($parent !== null && method_exists($parent, 'ForwardData')) {
            return $parent->ForwardData($json);
        }
        return json_encode($GLOBALS['eos']->handle(json_decode($json, true)));
    }
    /** Deliver to every registered child whose ConnectionID points at this instance. */
    protected function SendDataToChildren(string $json): void
    {
        foreach ($GLOBALS['objects'] as $id => $child) {
            if ($id !== $this->InstanceID && (int) ($GLOBALS['instances'][$id]['ConnectionID'] ?? 0) === $this->InstanceID && method_exists($child, 'ReceiveData')) {
                $child->ReceiveData($json);
            }
        }
    }
    protected function RegisterMessage(int $id, int $message): void { $this->messages[$id][] = $message; }
    protected function UnregisterMessage(int $id, int $message): void { unset($this->messages[$id]); }
    protected function SetStatus(int $status): void
    {
        $this->status = $status;
        if (isset($GLOBALS['instances'][$this->InstanceID])) {
            $GLOBALS['instances'][$this->InstanceID]['InstanceStatus'] = $status;
        }
    }
    protected function GetStatus(): int { return $this->status; }
    protected function LogMessage(string $message, int $severity): void { $this->logs[] = [$severity, $message]; }
    protected function SendDebug(string $name, string $data, int $format): void { $this->debug[] = $name . ': ' . $data; }
    protected function Translate(string $text): string { return $text; }
    protected function UpdateFormField(string $field, string $param, mixed $value): void { $this->formUpdates[] = [$field, $param, $value]; }
    protected function SetVisualizationType(int $type): void {}
    protected function UpdateVisualizationValue(string $value): void { $this->tileUpdates[] = $value; }
}

// ---------------------------------------------------------------- world: variables, actions, scripts, instances
$GLOBALS['sdkMode'] = 'legacy';
$GLOBALS['world'] = [];          // id => ['VariableType', 'VariableAction', 'VariableCustomAction', 'VariableUpdated', 'value', 'fail', 'parent']
$GLOBALS['actions'] = [];        // RequestAction calls [id, value]
$GLOBALS['runActions'] = [];     // IPS_RunActionWait calls [actionID, parameters]
$GLOBALS['runScripts'] = [];     // IPS_RunScriptEx calls [scriptId, parameters]
$GLOBALS['scripts'] = [];        // existing script ids
$GLOBALS['instances'] = [];      // id => ['ConnectionID', 'InstanceStatus']
$GLOBALS['objects'] = [];        // id => module object (registered by the constructor)
$GLOBALS['instanceModules'] = []; // id => module GUID for plain (non-object) instances, e.g. Location Control
$GLOBALS['instanceProps'] = [];  // id => properties of plain instances
$GLOBALS['registry'] = false;    // true: IPS_GetInstanceListByModuleID lists registered module objects
$GLOBALS['logged'] = [];         // variable id => [['TimeStamp' => int, 'Value' => float], ...] for the archive double
$GLOBALS['sdkWarnings'] = [];    // SDK warnings no module handler absorbed (live mode)
$GLOBALS['eos'] = new FakeEOS();

function sdkLive(): bool { return $GLOBALS['sdkMode'] === 'live'; }
function sdkWarn(string $message): void { trigger_error($message, E_USER_WARNING); }
function setClock(?int $ts): void { EOSClock::$now = $ts; }
function nowTs(): int { return EOSClock::$now ?? time(); }

function worldVar(int $id, int $type, mixed $value, bool $actionable = true, bool $fail = false): void
{
    $GLOBALS['world'][$id] = ['VariableType' => $type, 'VariableAction' => $actionable ? 10001 : 0, 'VariableCustomAction' => 0,
        'VariableUpdated' => nowTs(), 'VariableChanged' => nowTs(), 'value' => $value, 'fail' => $fail, 'parent' => 0];
}
function IPS_GetKernelRunlevel(): int { return KR_READY; }
function IPS_VariableExists(int $id): bool { return isset($GLOBALS['world'][$id]); }
function IPS_ObjectExists(int $id): bool { return isset($GLOBALS['world'][$id]) || isset($GLOBALS['instances'][$id]) || in_array($id, $GLOBALS['scripts'], true); }
function IPS_GetVariable(int $id): array { return $GLOBALS['world'][$id]; }
function IPS_GetParent(int $id): int { return (int) ($GLOBALS['world'][$id]['parent'] ?? 0); }
function IPS_ScriptExists(int $id): bool { return in_array($id, $GLOBALS['scripts'], true); }
function IPS_InstanceExists(int $id): bool { return isset($GLOBALS['instances'][$id]) || isset($GLOBALS['instanceModules'][$id]); }
function IPS_GetInstance(int $id): array { return $GLOBALS['instances'][$id] ?? ['ConnectionID' => 0, 'InstanceStatus' => IS_INACTIVE]; }
function IPS_GetInstanceListByModuleID(string $guid): array
{
    $ids = array_keys(array_filter($GLOBALS['instanceModules'], static fn (string $g): bool => strcasecmp($g, $guid) === 0));
    if ($GLOBALS['registry']) {
        foreach ($GLOBALS['objects'] as $id => $object) {
            $class = new ReflectionClass($object);
            $own = $class->hasConstant('MODULE_GUID') ? $class->getConstant('MODULE_GUID') : ($class->getName() === 'EOSServer' ? $class->getConstant('EOS_SERVER_GUID') : null);
            if ($own !== null && strcasecmp((string) $own, $guid) === 0) {
                $ids[] = $id;
            }
        }
    }
    sort($ids);
    return $ids;
}
function IPS_GetProperty(int $id, string $name): mixed
{
    if (isset($GLOBALS['objects'][$id])) {
        return $GLOBALS['objects'][$id]->properties[$name] ?? null;
    }
    return $GLOBALS['instanceProps'][$id][$name] ?? null;
}
function GetValue(int $id): mixed { return $GLOBALS['world'][$id]['value']; }
/** Stub semantics (SymconStubs): custom action > 0 replaces the standard action, 1 = standard action switched off. */
function HasAction(int $id): bool
{
    $v = $GLOBALS['world'][$id] ?? null;
    if ($v === null) {
        return false;
    }
    $action = (int) $v['VariableCustomAction'] > 0 ? (int) $v['VariableCustomAction'] : (int) $v['VariableAction'];
    return $action >= 10000;
}
function RequestAction(int $id, mixed $value): bool
{
    if (sdkLive()) {
        if (!HasAction($id)) {
            sdkWarn('No valid action available');
            return false;
        }
        if (!empty($GLOBALS['world'][$id]['fail'])) {
            sdkWarn("\nFatal error: Uncaught Exception: device rejected value");
            return false;
        }
    } elseif (!empty($GLOBALS['world'][$id]['fail'])) {
        throw new RuntimeException('device rejected value');
    }
    $GLOBALS['actions'][] = [$id, $value];
    $GLOBALS['world'][$id]['value'] = $value;
    $GLOBALS['world'][$id]['VariableUpdated'] = nowTs();
    return true;
}
/** Live: returns the action output ('' = success), PHP error text on failure, false for an unknown action. */
function IPS_RunActionWait(string $actionID, array $parameters): string|false
{
    if ($actionID === '{UNKNOWN}') {
        if (sdkLive()) {
            sdkWarn('Aktion mit ID ' . $actionID . ' nicht gefunden!');
            return false;
        }
        throw new RuntimeException('action not found');
    }
    if ($actionID === '{FAIL}') {
        if (sdkLive()) {
            return "\nFatal error: Uncaught Exception: action failed in /var/lib/symcon/scripts/-:1\n";
        }
        throw new RuntimeException('action failed');
    }
    $GLOBALS['runActions'][] = [$actionID, $parameters];
    return $actionID === '{ECHO}' ? 'hello' : '';
}
function IPS_RunScriptEx(int $scriptId, array $parameters): bool
{
    if (!in_array($scriptId, $GLOBALS['scripts'], true)) {
        if (sdkLive()) {
            sdkWarn('Parameter for ScriptID is not inside of the specified bounds');
            return false;
        }
    }
    $GLOBALS['runScripts'][] = [$scriptId, $parameters];
    return true;
}
function AC_GetLoggingStatus(int $archive, int $varId): bool { return isset($GLOBALS['logged'][$varId]); }
/** Newest first and at most 10000 rows, as documented for Symcon. */
function AC_GetLoggedValues(int $archive, int $varId, int $start, int $end, int $limit): array
{
    $rows = array_values(array_filter($GLOBALS['logged'][$varId] ?? [], static fn (array $r): bool => $r['TimeStamp'] >= $start && ($end === 0 || $r['TimeStamp'] <= $end)));
    usort($rows, static fn (array $a, array $b): int => $b['TimeStamp'] <=> $a['TimeStamp']);
    $cap = ($limit > 0 && $limit < 10000) ? $limit : 10000;
    return array_slice($rows, 0, $cap);
}

/** Plain instance (no module object), e.g. Location Control or Archive Control. */
function plainInstance(int $id, string $guid, array $properties = []): void
{
    $GLOBALS['instanceModules'][$id] = $guid;
    $GLOBALS['instanceProps'][$id] = $properties;
}
function locationControl(float $lat, float $lon, int $id = 24570): void
{
    plainInstance($id, LOCATION_CONTROL_GUID, ['Location' => json_encode(['latitude' => $lat, 'longitude' => $lon])]);
}

// ---------------------------------------------------------------- assertions
$GLOBALS['checks'] = 0;
function check(bool $condition, string $label): void
{
    $GLOBALS['checks']++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
    echo "ok   $label\n";
}
function resetWorld(): void
{
    $GLOBALS['actions'] = [];
    $GLOBALS['runActions'] = [];
    $GLOBALS['runScripts'] = [];
    $GLOBALS['sdkWarnings'] = [];
}
/** Writes (RequestAction) to one variable id since the last reset. */
function writesTo(int $id): array
{
    return array_values(array_map(static fn ($a) => $a[1], array_filter($GLOBALS['actions'], static fn ($a) => $a[0] === $id)));
}

/** Device instance connected to the FakeEOS splitter double (id 2000). */
function connectToServer(IPSModuleStrict $m): void
{
    $GLOBALS['instances'][$m->InstanceID] = ['ConnectionID' => 2000, 'InstanceStatus' => IS_ACTIVE];
    $GLOBALS['instances'][2000] = ['ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE];
}

/*
 * Symcon prints SDK warnings and carries on; PHP notices and warnings raised by module
 * code itself are bugs. The doubles raise E_USER_WARNING: a module handler may absorb
 * it, otherwise it is recorded like Symcon's output. Everything else aborts the test.
 */
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ($severity === E_USER_WARNING) {
        $GLOBALS['sdkWarnings'][] = $message;
        return true;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
