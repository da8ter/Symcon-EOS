<?php

declare(strict_types=1);

/*
 * Configuration-form helpers shared by the EOS modules: one recursive walker over
 * form elements plus the setters built on it, the mode-mapping table with its
 * per-mode action pickers, and the onChange/timer callbacks of the device forms.
 */
if (!trait_exists('EOSFormHelpers')) {
    trait EOSFormHelpers
    {
        /** Apply $fn(array &$element) to every element, recursively into items; stops when $fn returns true. */
        protected function formWalk(array &$nodes, callable $fn): bool
        {
            foreach ($nodes as &$node) {
                if (!is_array($node)) {
                    continue;
                }
                if ($fn($node) === true) {
                    unset($node);
                    return true;
                }
                if (isset($node['items']) && is_array($node['items']) && $this->formWalk($node['items'], $fn)) {
                    unset($node);
                    return true;
                }
            }
            unset($node);
            return false;
        }

        protected function setFormAttribute(array &$nodes, string $name, string $attribute, mixed $value): void
        {
            $this->formWalk($nodes, static function (array &$node) use ($name, $attribute, $value): bool {
                if (($node['name'] ?? '') !== $name) {
                    return false;
                }
                $node[$attribute] = $value;
                return true;
            });
        }

        protected function fillFormItems(array &$nodes, string $name, array $items): void
        {
            $this->formWalk($nodes, static function (array &$node) use ($name, $items): bool {
                if (($node['name'] ?? '') !== $name) {
                    return false;
                }
                $node['items'] = array_merge(is_array($node['items'] ?? null) ? $node['items'] : [], $items);
                return true;
            });
        }

        protected function fillFormList(array &$nodes, string $name, array $values): void
        {
            $this->formWalk($nodes, static function (array &$node) use ($name, $values): bool {
                if (($node['name'] ?? '') !== $name) {
                    return false;
                }
                $node['values'] = $values;
                $node['rowCount'] = max(1, count($values));
                return true;
            });
        }

        /**
         * Configuration form: fill the ModeMap list (canonical row order, translated
         * captions, saved values) and generate one SelectAction per EOS mode inside the
         * panel named ModeActions. The action pickers get the chosen action target as
         * targetID, so the dialog opens with that variable/instance and lists its actions.
         */
        protected function fillModeMap(array &$form): void
        {
            $saved = $this->modeMapSaved();
            $values = [];
            $pickers = [];
            $target = $this->actionTargetId();
            foreach ($this->modeMapRows() as $row) {
                $mode = strtoupper((string) $row['mode']);
                $caption = $this->Translate((string) $row['caption']);
                $values[] = ['mode' => $mode, 'caption' => $caption, 'value' => $saved[$mode]['value'] ?? ''];
                $picker = ['type' => 'SelectAction', 'name' => 'ModeAction_' . $mode, 'caption' => $this->Translate('Action')];
                if ($target > 0) {
                    $picker['targetID'] = $target;
                }
                // One collapsed panel per mode keeps the option short until a mode is opened.
                $pickers[] = ['type' => 'ExpansionPanel', 'caption' => $caption . ' (' . $mode . ')', 'expanded' => false, 'items' => [$picker]];
            }
            $this->fillFormList($form['elements'], 'ModeMap', $values);
            $this->fillFormItems($form['elements'], 'ModeActions', $pickers);
            if ($target > 0) {
                $this->setFormAttribute($form['elements'], 'ChangeAction', 'targetID', $target);
            }
        }

        /** Object the action pickers open with: explicit ActionTarget, else the bound mode variable. */
        protected function actionTargetId(): int
        {
            $target = $this->ReadPropertyInteger('ActionTarget');
            if ($target <= 0 && isset($this->controlTargets()['Mode'])) {
                $target = $this->ReadPropertyInteger($this->controlTargets()['Mode']['property']);
            }
            return ($target > 0 && IPS_ObjectExists($target)) ? $target : 0;
        }

        /** onChange/timer callbacks of the configuration form; false for unknown idents. */
        protected function handleFormAction(string $ident, mixed $value): bool
        {
            switch ($ident) {
                case 'PickDeviceId':
                    if (trim((string) $value) !== '') {
                        $this->UpdateFormField('DeviceID', 'value', trim((string) $value));
                    }
                    return true;
                case 'FillFormFromEOS':
                    $this->SetTimerInterval('FormFill', 0);
                    $this->ReadConfigFromEOS();
                    return true;
                case 'SetActionTarget':
                    // Form onChange: point the open action pickers at the newly chosen target.
                    $data = json_decode((string) $value, true);
                    $target = (int) ($data['target'] ?? 0);
                    if ($target <= 0) {
                        $target = (int) ($data['modeVar'] ?? 0);
                    }
                    if ($target > 0 && IPS_ObjectExists($target)) {
                        foreach ($this->modeMapRows() as $row) {
                            $this->UpdateFormField('ModeAction_' . strtoupper((string) $row['mode']), 'targetID', $target);
                        }
                        $this->UpdateFormField('ChangeAction', 'targetID', $target);
                    }
                    return true;
            }
            return false;
        }
    }
}
