<?php

declare(strict_types=1);

/*
 * Isolated SDK double for the Symcon-EOS device modules. Never talks to a running
 * Symcon or EOS: the module base class keeps properties, attributes, variables and
 * timers in memory, IPS_* functions read a small "world" of variables, and
 * SendDataToParent() is answered by FakeEOS, a stand-in for the EOS Server splitter.
 *
 * Run: php tests/control_test.php && php tests/sync_meter_test.php
 */

const KR_READY = 10103, IPS_KERNELSTARTED = 10001, VM_UPDATE = 10603;
const IS_ACTIVE = 102, IS_INACTIVE = 104;
const KL_MESSAGE = 10201, KL_SUCCESS = 10202, KL_NOTIFY = 10203, KL_WARNING = 10204, KL_ERROR = 10205, KL_DEBUG = 10206;
const VARIABLETYPE_BOOLEAN = 0, VARIABLETYPE_INTEGER = 1, VARIABLETYPE_FLOAT = 2, VARIABLETYPE_STRING = 3;
const VARIABLE_PRESENTATION_VALUE_PRESENTATION = '{VALUE}', VARIABLE_PRESENTATION_SWITCH = '{SWITCH}',
    VARIABLE_PRESENTATION_SLIDER = '{SLIDER}', VARIABLE_PRESENTATION_ENUMERATION = '{ENUM}', VARIABLE_PRESENTATION_DATE_TIME = '{DATETIME}';

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

    protected function RegisterVariableBoolean(string $ident, string $name, mixed $p = '', int $pos = 0): void { $this->variables[$ident] ??= ['type' => 0, 'value' => false, 'name' => $name, 'action' => false]; }
    protected function RegisterVariableInteger(string $ident, string $name, mixed $p = '', int $pos = 0): void { $this->variables[$ident] ??= ['type' => 1, 'value' => 0, 'name' => $name, 'action' => false]; }
    protected function RegisterVariableFloat(string $ident, string $name, mixed $p = '', int $pos = 0): void { $this->variables[$ident] ??= ['type' => 2, 'value' => 0.0, 'name' => $name, 'action' => false]; }
    protected function RegisterVariableString(string $ident, string $name, mixed $p = '', int $pos = 0): void { $this->variables[$ident] ??= ['type' => 3, 'value' => '', 'name' => $name, 'action' => false]; }
    protected function EnableAction(string $ident): void { $this->variables[$ident]['action'] = true; }
    protected function SetValue(string $ident, mixed $value): bool
    {
        if (!isset($this->variables[$ident])) {
            throw new RuntimeException('Variable ' . $ident . ' is not registered');
        }
        $this->variables[$ident]['value'] = $value;
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

    protected function SendDataToParent(string $json): string
    {
        return json_encode($GLOBALS['eos']->handle(json_decode($json, true)));
    }
    protected function RegisterMessage(int $id, int $message): void { $this->messages[$id][] = $message; }
    protected function UnregisterMessage(int $id, int $message): void { unset($this->messages[$id]); }
    protected function SetStatus(int $status): void { $this->status = $status; }
    protected function GetStatus(): int { return $this->status; }
    protected function LogMessage(string $message, int $severity): void { $this->logs[] = [$severity, $message]; }
    protected function SendDebug(string $name, string $data, int $format): void { $this->debug[] = $name . ': ' . $data; }
    protected function Translate(string $text): string { return $text; }
    protected function UpdateFormField(string $field, string $param, mixed $value): void { $this->formUpdates[] = [$field, $param, $value]; }
    protected function SetVisualizationType(int $type): void {}
    protected function UpdateVisualizationValue(string $value): void { $this->tileUpdates[] = $value; }
}

// ---------------------------------------------------------------- world: variables, actions, scripts
$GLOBALS['world'] = [];      // id => ['VariableType', 'VariableAction', 'VariableCustomAction', 'value', 'fail' => bool]
$GLOBALS['actions'] = [];    // RequestAction calls [id, value]
$GLOBALS['runActions'] = []; // IPS_RunActionWait calls [actionID, parameters]
$GLOBALS['runScripts'] = []; // IPS_RunScriptEx calls [scriptId, parameters]
$GLOBALS['scripts'] = [];    // existing script ids
$GLOBALS['instances'] = [];  // id => ['ConnectionID', 'InstanceStatus']
$GLOBALS['eos'] = new FakeEOS();

function worldVar(int $id, int $type, mixed $value, bool $actionable = true, bool $fail = false): void
{
    $GLOBALS['world'][$id] = ['VariableType' => $type, 'VariableAction' => $actionable ? 10001 : 0, 'VariableCustomAction' => 0, 'value' => $value, 'fail' => $fail];
}
function IPS_GetKernelRunlevel(): int { return KR_READY; }
function IPS_VariableExists(int $id): bool { return isset($GLOBALS['world'][$id]); }
function IPS_ObjectExists(int $id): bool { return isset($GLOBALS['world'][$id]) || isset($GLOBALS['instances'][$id]) || in_array($id, $GLOBALS['scripts'], true); }
function IPS_GetVariable(int $id): array { return $GLOBALS['world'][$id]; }
function IPS_ScriptExists(int $id): bool { return in_array($id, $GLOBALS['scripts'], true); }
function IPS_InstanceExists(int $id): bool { return isset($GLOBALS['instances'][$id]); }
function IPS_GetInstance(int $id): array { return $GLOBALS['instances'][$id] ?? ['ConnectionID' => 0, 'InstanceStatus' => IS_INACTIVE]; }
function IPS_GetInstanceListByModuleID(string $guid): array { return []; }
function IPS_GetProperty(int $id, string $name): mixed { return null; }
function GetValue(int $id): mixed { return $GLOBALS['world'][$id]['value']; }
function RequestAction(int $id, mixed $value): bool
{
    if (!empty($GLOBALS['world'][$id]['fail'])) {
        throw new RuntimeException('device rejected value');
    }
    $GLOBALS['actions'][] = [$id, $value];
    $GLOBALS['world'][$id]['value'] = $value;
    return true;
}
function IPS_RunActionWait(string $actionID, array $parameters): void
{
    if ($actionID === '{FAIL}') {
        throw new RuntimeException('action failed');
    }
    $GLOBALS['runActions'][] = [$actionID, $parameters];
}
function IPS_RunScriptEx(int $scriptId, array $parameters): void { $GLOBALS['runScripts'][] = [$scriptId, $parameters]; }

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
}
/** Writes (RequestAction) to one variable id since the last reset. */
function writesTo(int $id): array
{
    return array_values(array_map(static fn ($a) => $a[1], array_filter($GLOBALS['actions'], static fn ($a) => $a[0] === $id)));
}

/** Device instance 1000 connected to the fake server 2000. */
function connectToServer(IPSModuleStrict $m): void
{
    $GLOBALS['instances'][$m->InstanceID] = ['ConnectionID' => 2000, 'InstanceStatus' => IS_ACTIVE];
    $GLOBALS['instances'][2000] = ['ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE];
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
