<?php

declare(strict_types=1);

/*
 * Vendor-neutral execution primitives for the control layer. Nothing in here
 * knows about batteries, wallboxes or EOS modes; it only knows how to write a
 * value to a Symcon variable (RequestAction with type conversion), how to run
 * a Symcon action (SelectAction JSON) or a script, and how to read the
 * mode-mapping table from the configuration form.
 *
 * All executors return ['ok' => bool, 'text' => string, 'skipped' => bool, 'norm' => string];
 * writeTarget() adds 'err' (missing | unactionable | coerce | failed) for failures.
 */
if (!trait_exists('EOSControlBindings')) {
    trait EOSControlBindings
    {
        // ---------------------------------------------------------------- values

        /**
         * Convert a value to the type of a Symcon variable (0 bool, 1 int, 2 float, 3 string).
         * Ambiguous strings for booleans throw instead of silently becoming true.
         */
        protected function coerceToVariableType(mixed $value, int $type): mixed
        {
            switch ($type) {
                case 0:
                    if (is_bool($value)) {
                        return $value;
                    }
                    if (is_int($value) || is_float($value)) {
                        return $value != 0;
                    }
                    $s = strtolower(trim((string) $value));
                    if (in_array($s, ['1', 'true', 'on', 'yes', 'ja', 'ein', 'an'], true)) {
                        return true;
                    }
                    if (in_array($s, ['0', 'false', 'off', 'no', 'nein', 'aus', ''], true)) {
                        return false;
                    }
                    throw new InvalidArgumentException(sprintf('cannot convert "%s" to boolean', $s));
                case 1:
                    if (is_bool($value)) {
                        return $value ? 1 : 0;
                    }
                    if (is_string($value) && !is_numeric(trim($value))) {
                        throw new InvalidArgumentException(sprintf('cannot convert "%s" to integer', $value));
                    }
                    return (int) round((float) $value);
                case 2:
                    if (is_bool($value)) {
                        return $value ? 1.0 : 0.0;
                    }
                    if (is_string($value) && !is_numeric(trim($value))) {
                        throw new InvalidArgumentException(sprintf('cannot convert "%s" to float', $value));
                    }
                    return (float) $value;
                default:
                    if (is_bool($value)) {
                        return $value ? 'true' : 'false';
                    }
                    if (is_float($value)) {
                        return $this->normalizeValue($value);
                    }
                    return (string) $value;
            }
        }

        /** Canonical string form used for change detection (float rounded to 3 digits). */
        protected function normalizeValue(mixed $value): string
        {
            if (is_bool($value)) {
                return $value ? '1' : '0';
            }
            if (is_int($value)) {
                return (string) $value;
            }
            if (is_float($value)) {
                $s = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
                return $s === '-0' || $s === '' ? '0' : $s;
            }
            return (string) $value;
        }

        // ---------------------------------------------------------------- executors
        //
        // Symcon reports failures of RequestAction, IPS_RunActionWait and IPS_RunScriptEx
        // through the return value plus a printed warning, never as an exception
        // (measured on Symcon 9.1). The return value decides; warnings only feed the text.

        /** Run an SDK call, collecting the warnings it prints instead of letting them reach the output. */
        protected function callCapturing(callable $call, array &$warnings): mixed
        {
            $warnings = [];
            set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
                $warnings[] = trim(preg_replace('/\s+/', ' ', $message) ?? $message);
                return true;
            }, E_WARNING | E_USER_WARNING | E_NOTICE | E_USER_NOTICE);
            try {
                return $call();
            } catch (\Throwable $e) {
                $warnings[] = $e->getMessage();
                return false;
            } finally {
                restore_error_handler();
            }
        }

        /** Can Symcon switch this variable? A custom action of 1 means "standard action switched off". */
        protected function isActionable(int $varId): bool
        {
            if (function_exists('HasAction')) {
                return HasAction($varId);
            }
            $var = IPS_GetVariable($varId);
            $custom = (int) $var['VariableCustomAction'];
            return $custom > 0 ? $custom >= 10000 : (int) $var['VariableAction'] >= 10000;
        }

        /** Error text for a failed call: the first printed warning, otherwise $fallback. */
        private function failureText(array $warnings, string $fallback): string
        {
            return $this->eosShorten($warnings !== [] ? (string) $warnings[0] : $fallback, 160);
        }

        /** Write a value to a foreign variable via RequestAction. */
        protected function writeTarget(string $key, int $varId, mixed $value, bool $sim): array
        {
            if ($varId <= 0 || !IPS_VariableExists($varId)) {
                return ['ok' => false, 'skipped' => true, 'err' => 'missing', 'norm' => '', 'text' => $key . ': ' . $this->Translate('target variable missing')];
            }
            if (!$this->isActionable($varId)) {
                return ['ok' => false, 'skipped' => true, 'err' => 'unactionable', 'norm' => '', 'text' => $key . ': ' . $this->Translate('target variable not actionable')];
            }
            try {
                $typed = $this->coerceToVariableType($value, (int) IPS_GetVariable($varId)['VariableType']);
            } catch (\Throwable $e) {
                return ['ok' => false, 'skipped' => false, 'err' => 'coerce', 'norm' => '', 'text' => $key . ': ' . $e->getMessage()];
            }
            $norm = $this->normalizeValue($typed);
            if ($sim) {
                return ['ok' => true, 'skipped' => false, 'norm' => $norm, 'text' => 'SIM ' . $key . '→' . $norm];
            }
            $warnings = [];
            $ok = $this->callCapturing(static fn (): mixed => RequestAction($varId, $typed), $warnings);
            if ($ok === false) {
                return ['ok' => false, 'skipped' => false, 'err' => 'failed', 'norm' => $norm, 'text' => $key . '→' . $norm . ' ' . $this->Translate('failed') . ': ' . $this->failureText($warnings, 'RequestAction returned false')];
            }
            return ['ok' => true, 'skipped' => false, 'norm' => $norm, 'text' => $key . '→' . $norm . ' OK'];
        }

        /** Run a Symcon action stored by a SelectAction element ({"actionID":..., "parameters":{...}}). */
        protected function runAction(string $json, array $context, bool $sim, string $label): array
        {
            if (trim($json) === '') {
                return ['ok' => true, 'skipped' => true, 'norm' => '', 'text' => ''];
            }
            $action = json_decode($json, true);
            if (!is_array($action)) {
                return ['ok' => false, 'skipped' => true, 'norm' => '', 'text' => $label . ': ' . $this->Translate('invalid action')];
            }
            if (trim((string) ($action['actionID'] ?? '')) === '') {
                return ['ok' => true, 'skipped' => true, 'norm' => '', 'text' => '']; // "{}" = no action selected
            }
            if ($sim) {
                return ['ok' => true, 'skipped' => false, 'norm' => '', 'text' => 'SIM ' . $label];
            }
            $parameters = is_array($action['parameters'] ?? null) ? $action['parameters'] : [];
            $warnings = [];
            // Context first, the user's own parameters (TARGET, VALUE, ...) win on collision.
            $output = $this->callCapturing(static fn (): mixed => IPS_RunActionWait((string) $action['actionID'], array_merge($context, $parameters)), $warnings);
            if ($output === false) {
                return ['ok' => false, 'skipped' => false, 'norm' => '', 'text' => $label . ' ' . $this->Translate('failed') . ': ' . $this->failureText($warnings, 'action not found')];
            }
            // The return value is the output of the action; PHP errors inside the action appear there.
            $output = trim((string) $output);
            if (preg_match('/(^|\n)\s*(Fatal error|Parse error|Warning|Error)\s*:/', $output) === 1) {
                return ['ok' => false, 'skipped' => false, 'norm' => '', 'text' => $label . ' ' . $this->Translate('failed') . ': ' . $this->failureText([preg_replace('/\s+/', ' ', $output)], $output)];
            }
            return ['ok' => true, 'skipped' => false, 'norm' => '', 'text' => $label . ' OK' . ($output !== '' ? ' (' . $this->eosShorten(preg_replace('/\s+/', ' ', $output) ?? $output, 60) . ')' : '')];
        }

        /** Start a script asynchronously with the context in $_IPS ("OK" means started). */
        protected function runScript(int $scriptId, array $context, bool $sim, string $label): array
        {
            if ($scriptId <= 0) {
                return ['ok' => true, 'skipped' => true, 'norm' => '', 'text' => ''];
            }
            if (!IPS_ScriptExists($scriptId)) {
                return ['ok' => false, 'skipped' => true, 'norm' => '', 'text' => $label . ': ' . $this->Translate('script missing')];
            }
            if ($sim) {
                return ['ok' => true, 'skipped' => false, 'norm' => '', 'text' => 'SIM ' . $label];
            }
            $warnings = [];
            $started = $this->callCapturing(static fn (): mixed => IPS_RunScriptEx($scriptId, $context), $warnings);
            if ($started === false) {
                return ['ok' => false, 'skipped' => false, 'norm' => '', 'text' => $label . ' ' . $this->Translate('failed') . ': ' . $this->failureText($warnings, 'script not started')];
            }
            return ['ok' => true, 'skipped' => false, 'norm' => '', 'text' => $label . ' OK'];
        }

        // ---------------------------------------------------------------- desired state -> writes

        /** Bound targets with their values; the Mode target is looked up in the mapping table. */
        protected function resolveTargets(array $desired): array
        {
            $resolved = [];
            $targets = is_array($desired['targets'] ?? null) ? $desired['targets'] : [];
            foreach ($this->controlTargets() as $key => $target) {
                $varId = $this->ReadPropertyInteger($target['property']);
                if ($varId <= 0) {
                    continue;
                }
                if ($key === 'Mode') {
                    $value = $this->modeMapRow((string) ($desired['modeRaw'] ?? ''))['value'];
                    if ($value === '') {
                        continue;
                    }
                } elseif (!array_key_exists($key, $targets) || $targets[$key] === null) {
                    continue;
                } else {
                    $value = $targets[$key];
                }
                $norm = $this->normalizeValue($value);
                if (IPS_VariableExists($varId)) {
                    try {
                        $norm = $this->normalizeValue($this->coerceToVariableType($value, (int) IPS_GetVariable($varId)['VariableType']));
                    } catch (\Throwable $e) {
                        // keep the raw normalisation; writeTarget() reports the conversion problem
                    }
                }
                $resolved[$key] = ['varId' => $varId, 'value' => $value, 'norm' => $norm];
            }
            return $resolved;
        }

        protected function buildContext(array $desired, string $reason, bool $sim, array $changedKeys): array
        {
            $context = [
                'InstanceID'    => $this->InstanceID,
                'DeviceID'      => $this->ReadPropertyString('DeviceID'),
                'Reason'        => $reason,
                'Source'        => (string) ($desired['source'] ?? ''),
                'ModeRaw'       => (string) ($desired['modeRaw'] ?? ''),
                'Mode'          => (int) ($desired['mode'] ?? 0),
                'Factor'        => (float) ($desired['factor'] ?? 0.0),
                'ExecutionTime' => (string) ($desired['executionTime'] ?? ''),
                'Simulation'    => $sim,
                'Changed'       => implode(',', $changedKeys),
                'Degraded'      => (string) ($desired['degraded'] ?? ''),
            ];
            foreach (is_array($desired['context'] ?? null) ? $desired['context'] : [] as $key => $value) {
                $context[$key] = is_array($value) ? json_encode($value) : $value;
            }
            return $context;
        }

        // ---------------------------------------------------------------- mode map (form list)

        /** '' for "no action" (empty string or JSON without actionID), otherwise the JSON unchanged. */
        protected function normalizeActionJson(string $json): string
        {
            $json = trim($json);
            if ($json === '') {
                return '';
            }
            $decoded = json_decode($json, true);
            if (is_array($decoded) && trim((string) ($decoded['actionID'] ?? '')) === '') {
                return '';
            }
            return $json;
        }

        /** Saved rows of the mode mapping table keyed by EOS mode id. */
        protected function modeMapSaved(): array
        {
            $rows = $this->eosJsonDecode($this->ReadPropertyString('ModeMap'), []);
            $map = [];
            foreach ($this->modeMapRows() as $row) {
                $mode = strtoupper((string) $row['mode']);
                $map[$mode] = ['value' => '', 'action' => $this->normalizeActionJson($this->ReadPropertyString('ModeAction_' . $mode))];
            }
            foreach (is_array($rows) ? $rows : [] as $row) {
                $mode = strtoupper(trim((string) ($row['mode'] ?? '')));
                if (is_array($row) && isset($map[$mode])) {
                    $map[$mode]['value'] = (string) ($row['value'] ?? '');
                }
            }
            return $map;
        }

        protected function modeMapRow(string $modeRaw): array
        {
            return $this->modeMapSaved()[strtoupper(trim($modeRaw))] ?? ['value' => '', 'action' => ''];
        }

        // ---------------------------------------------------------------- diagnostics

        /** Snapshot for <PREFIX>_GetControlState() and debugging. */
        protected function controlState(): array
        {
            return [
                'controlMode'    => $this->ReadPropertyInteger('ControlMode'),
                'controlActive'  => (bool) $this->GetValue('ControlActive'),
                'controlReady'   => $this->ReadAttributeBoolean('ControlReady'),
                'problem'        => $this->ReadAttributeString('ControlProblem'),
                'manualMode'     => (int) $this->GetValue('ManualMode'),
                'manualUntil'    => $this->ReadAttributeInteger('ManualUntil'),
                'fallbackActive' => (bool) $this->GetValue('FallbackActive'),
                'lastControl'    => (int) $this->GetValue('LastControl'),
                'lastResult'     => (string) $this->GetValue('LastControlResult'),
                'desired'        => $this->eosJsonDecode($this->ReadAttributeString('Desired'), []),
                'lastSent'       => $this->eosJsonDecode($this->ReadAttributeString('LastSent'), []),
            ];
        }

        // ---------------------------------------------------------------- validation

        /** Any binding configured at all? Mode-map values only count where a Mode target exists. */
        protected function bindingsConfigured(): bool
        {
            foreach ($this->controlTargets() as $target) {
                if ($this->ReadPropertyInteger($target['property']) > 0) {
                    return true;
                }
            }
            if ($this->normalizeActionJson($this->ReadPropertyString('ChangeAction')) !== '' || $this->ReadPropertyInteger('ControlScript') > 0) {
                return true;
            }
            $hasMode = isset($this->controlTargets()['Mode']);
            foreach ($this->modeMapSaved() as $row) {
                if ($row['action'] !== '' || ($hasMode && $row['value'] !== '')) {
                    return true;
                }
            }
            return false;
        }

        /**
         * 'blocking': the configuration cannot work at all (control stays off until Apply);
         * 'warnings': single bindings that are skipped with backoff while the rest - fallback
         * included - keeps working.
         */
        protected function checkBindings(): array
        {
            if (!$this->bindingsConfigured()) {
                return ['blocking' => $this->Translate('no bindings configured'), 'warnings' => []];
            }
            $warnings = [];
            foreach ($this->controlTargets() as $key => $target) {
                $varId = $this->ReadPropertyInteger($target['property']);
                if ($varId <= 0) {
                    continue;
                }
                if (!IPS_VariableExists($varId)) {
                    $warnings[] = $key . ': ' . $this->Translate('target variable missing');
                    continue;
                }
                if (IPS_GetParent($varId) === $this->InstanceID) {
                    // e.g. ManualMode as mode target: the first write would switch this instance itself.
                    return ['blocking' => $key . ': ' . $this->Translate('a variable of this instance cannot be a target'), 'warnings' => []];
                }
                if (!$this->isActionable($varId)) {
                    $warnings[] = $key . ': ' . $this->Translate('target variable not actionable');
                    continue;
                }
                if ($key === 'Mode') {
                    $type = (int) IPS_GetVariable($varId)['VariableType'];
                    foreach ($this->modeMapSaved() as $mode => $row) {
                        if ($row['value'] === '') {
                            continue;
                        }
                        try {
                            $this->coerceToVariableType($row['value'], $type);
                        } catch (\Throwable $e) {
                            return ['blocking' => $mode . ': ' . $e->getMessage(), 'warnings' => []];
                        }
                    }
                }
            }
            $change = $this->normalizeActionJson($this->ReadPropertyString('ChangeAction'));
            if ($change !== '' && !is_array(json_decode($change, true))) {
                return ['blocking' => $this->Translate('Action on change') . ': ' . $this->Translate('invalid action'), 'warnings' => []];
            }
            foreach ($this->modeMapSaved() as $mode => $row) {
                if ($row['action'] !== '' && !is_array(json_decode($row['action'], true))) {
                    return ['blocking' => $mode . ': ' . $this->Translate('invalid action'), 'warnings' => []];
                }
            }
            $script = $this->ReadPropertyInteger('ControlScript');
            if ($script > 0 && !IPS_ScriptExists($script)) {
                $warnings[] = $this->Translate('Script on change') . ': ' . $this->Translate('script missing');
            }
            return ['blocking' => $this->validateControlDevice(), 'warnings' => $warnings];
        }

        /** First problem as text ('' = all bindings fine); kept for diagnostics. */
        protected function validateBindings(): string
        {
            $check = $this->checkBindings();
            return $check['blocking'] !== '' ? $check['blocking'] : (string) ($check['warnings'][0] ?? '');
        }

        /** Device-specific validation hook (e.g. max power must be > 0 when a power target is bound). */
        protected function validateControlDevice(): string
        {
            return '';
        }
    }
}
