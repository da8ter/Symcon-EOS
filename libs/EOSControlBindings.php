<?php

declare(strict_types=1);

/*
 * Vendor-neutral execution primitives for the control layer. Nothing in here
 * knows about batteries, wallboxes or EOS modes; it only knows how to write a
 * value to a Symcon variable (RequestAction with type conversion), how to run
 * a Symcon action (SelectAction JSON) or a script, and how to read the
 * mode-mapping table from the configuration form.
 *
 * All executors return ['ok' => bool, 'text' => string, 'skipped' => bool, 'norm' => string].
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

        /** Write a value to a foreign variable via RequestAction. */
        protected function writeTarget(string $key, int $varId, mixed $value, bool $sim): array
        {
            if ($varId <= 0 || !IPS_VariableExists($varId)) {
                return ['ok' => false, 'skipped' => true, 'norm' => '', 'text' => $key . ': ' . $this->Translate('target variable missing')];
            }
            $var = IPS_GetVariable($varId);
            $action = (int) $var['VariableCustomAction'] >= 10000 ? (int) $var['VariableCustomAction'] : (int) $var['VariableAction'];
            if ($action < 10000) {
                return ['ok' => false, 'skipped' => true, 'norm' => '', 'text' => $key . ': ' . $this->Translate('target variable not actionable')];
            }
            try {
                $typed = $this->coerceToVariableType($value, (int) $var['VariableType']);
            } catch (\Throwable $e) {
                return ['ok' => false, 'skipped' => false, 'norm' => '', 'text' => $key . ': ' . $e->getMessage()];
            }
            $norm = $this->normalizeValue($typed);
            if ($sim) {
                return ['ok' => true, 'skipped' => false, 'norm' => $norm, 'text' => 'SIM ' . $key . '→' . $norm];
            }
            try {
                RequestAction($varId, $typed);
                return ['ok' => true, 'skipped' => false, 'norm' => $norm, 'text' => $key . '→' . $norm . ' OK'];
            } catch (\Throwable $e) {
                return ['ok' => false, 'skipped' => false, 'norm' => $norm, 'text' => $key . '→' . $norm . ' ' . $this->Translate('failed') . ': ' . $e->getMessage()];
            }
        }

        /** Run a Symcon action stored by a SelectAction element ({"actionID":..., "parameters":{...}}). */
        protected function runAction(string $json, array $context, bool $sim, string $label): array
        {
            if (trim($json) === '') {
                return ['ok' => true, 'skipped' => true, 'norm' => '', 'text' => ''];
            }
            $action = json_decode($json, true);
            if (!is_array($action) || trim((string) ($action['actionID'] ?? '')) === '') {
                return ['ok' => false, 'skipped' => true, 'norm' => '', 'text' => $label . ': ' . $this->Translate('invalid action')];
            }
            if ($sim) {
                return ['ok' => true, 'skipped' => false, 'norm' => '', 'text' => 'SIM ' . $label];
            }
            $parameters = is_array($action['parameters'] ?? null) ? $action['parameters'] : [];
            try {
                // Context first, the user's own parameters (TARGET, VALUE, ...) win on collision.
                IPS_RunActionWait((string) $action['actionID'], array_merge($context, $parameters));
                return ['ok' => true, 'skipped' => false, 'norm' => '', 'text' => $label . ' OK'];
            } catch (\Throwable $e) {
                return ['ok' => false, 'skipped' => false, 'norm' => '', 'text' => $label . ' ' . $this->Translate('failed') . ': ' . $e->getMessage()];
            }
        }

        /** Run a script asynchronously with the context in $_IPS. */
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
            try {
                IPS_RunScriptEx($scriptId, $context);
                return ['ok' => true, 'skipped' => false, 'norm' => '', 'text' => $label . ' OK'];
            } catch (\Throwable $e) {
                return ['ok' => false, 'skipped' => false, 'norm' => '', 'text' => $label . ' ' . $this->Translate('failed') . ': ' . $e->getMessage()];
            }
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

        /** Saved rows of the mode mapping table keyed by EOS mode id. */
        protected function modeMapSaved(): array
        {
            $rows = $this->eosJsonDecode($this->ReadPropertyString('ModeMap'), []);
            $map = [];
            foreach (is_array($rows) ? $rows : [] as $row) {
                if (is_array($row) && trim((string) ($row['mode'] ?? '')) !== '') {
                    $map[strtoupper(trim((string) $row['mode']))] = [
                        'value'  => (string) ($row['value'] ?? ''),
                        'action' => (string) ($row['action'] ?? ''),
                    ];
                }
            }
            return $map;
        }

        protected function modeMapRow(string $modeRaw): array
        {
            return $this->modeMapSaved()[strtoupper(trim($modeRaw))] ?? ['value' => '', 'action' => ''];
        }

        /**
         * Fill the ModeMap list in the configuration form: canonical row order from
         * modeMapRows(), translated captions, saved value/action merged by mode id.
         * The rows are sent as 'values' so that missing modes (older configs) appear.
         */
        protected function fillModeMap(array &$form): void
        {
            $saved = $this->modeMapSaved();
            $values = [];
            foreach ($this->modeMapRows() as $row) {
                $mode = strtoupper((string) $row['mode']);
                $values[] = [
                    'mode'    => $mode,
                    'caption' => $this->Translate((string) $row['caption']),
                    'value'   => $saved[$mode]['value'] ?? '',
                    'action'  => $saved[$mode]['action'] ?? '',
                ];
            }
            $this->fillFormList($form['elements'], 'ModeMap', $values);
        }

        protected function fillFormList(array &$nodes, string $name, array $values): void
        {
            foreach ($nodes as &$node) {
                if (!is_array($node)) {
                    continue;
                }
                if (($node['name'] ?? '') === $name) {
                    $node['values'] = $values;
                    $node['rowCount'] = max(1, count($values));
                    return;
                }
                if (isset($node['items']) && is_array($node['items'])) {
                    $this->fillFormList($node['items'], $name, $values);
                }
            }
            unset($node);
        }

        // ---------------------------------------------------------------- validation

        /** Any binding configured at all? */
        protected function bindingsConfigured(): bool
        {
            foreach ($this->controlTargets() as $target) {
                if ($this->ReadPropertyInteger($target['property']) > 0) {
                    return true;
                }
            }
            if (trim($this->ReadPropertyString('ChangeAction')) !== '' || $this->ReadPropertyInteger('ControlScript') > 0) {
                return true;
            }
            foreach ($this->modeMapSaved() as $row) {
                if ($row['value'] !== '' || $row['action'] !== '') {
                    return true;
                }
            }
            return false;
        }

        /** Empty string when all bindings are valid, otherwise the first problem. */
        protected function validateBindings(): string
        {
            if (!$this->bindingsConfigured()) {
                return $this->Translate('no bindings configured');
            }
            foreach ($this->controlTargets() as $key => $target) {
                $varId = $this->ReadPropertyInteger($target['property']);
                if ($varId <= 0) {
                    continue;
                }
                if (!IPS_VariableExists($varId)) {
                    return $key . ': ' . $this->Translate('target variable missing');
                }
                $var = IPS_GetVariable($varId);
                $action = (int) $var['VariableCustomAction'] >= 10000 ? (int) $var['VariableCustomAction'] : (int) $var['VariableAction'];
                if ($action < 10000) {
                    return $key . ': ' . $this->Translate('target variable not actionable');
                }
            }
            $change = trim($this->ReadPropertyString('ChangeAction'));
            if ($change !== '') {
                $decoded = json_decode($change, true);
                if (!is_array($decoded) || trim((string) ($decoded['actionID'] ?? '')) === '') {
                    return $this->Translate('Action on change') . ': ' . $this->Translate('invalid action');
                }
            }
            foreach ($this->modeMapSaved() as $mode => $row) {
                if ($row['action'] !== '') {
                    $decoded = json_decode($row['action'], true);
                    if (!is_array($decoded) || trim((string) ($decoded['actionID'] ?? '')) === '') {
                        return $mode . ': ' . $this->Translate('invalid action');
                    }
                }
            }
            $script = $this->ReadPropertyInteger('ControlScript');
            if ($script > 0 && !IPS_ScriptExists($script)) {
                return $this->Translate('Script on change') . ': ' . $this->Translate('script missing');
            }
            return $this->validateControlDevice();
        }

        /** Device-specific validation hook (e.g. max power must be > 0 when a power target is bound). */
        protected function validateControlDevice(): string
        {
            return '';
        }
    }
}
