<?php

declare(strict_types=1);

/*
 * Shared behaviour of EOS device modules (battery, electric vehicle, home
 * appliance): parent handling, ForwardData helper, plan storage per resource,
 * active/next instruction evaluation with an exact slot timer, stale detection
 * and the duplicate device-id check.
 *
 * The using class must
 *   - use EOSCommon,
 *   - define const MODULE_GUID,
 *   - register the attributes via registerPlanAttributes() and the variables
 *     NextChange (int), NextMode (string), PlanStale (bool), PlanJSON (string),
 *   - register a timer 'SlotTimer' calling <PREFIX>_ProcessPlan,
 *   - implement showInstruction(array $instruction): void and showNoInstruction(): void,
 *   - optionally override onPlanProcessed(?array $active): void.
 *
 * planUsable() is the single predicate the control layer relies on: plan age,
 * valid_until and "no active instruction" (with a gap tolerance for plans that
 * start at the next slot boundary). EOS reachability is deliberately NOT part
 * of it - a stored plan keeps running autonomously between EOS runs.
 */
if (!trait_exists('EOSPlanDevice')) {
    trait EOSPlanDevice
    {
        public const STATUS_NO_PARENT = 104;
        public const STATUS_BAD_DEVICE_ID = 201;
        public const STATUS_NO_SOURCE = 202;
        public const STATUS_DUPLICATE_ID = 203;
        /** EOS already holds another device of a kind GENETIC supports only once (battery, vehicle). */
        public const STATUS_OTHER_DEVICE = 205;
        /** Min. SoC not below max. SoC: EOS would reject the device configuration. */
        public const STATUS_BAD_LIMITS = 206;
        /** Never sleep longer than this before re-evaluating the plan (ms). */
        public const MAX_SLOT_TIMER_MS = 6 * 3600 * 1000;
        /** A plan whose first instruction is at most this far ahead is not "without instruction" (s). */
        public const GAP_TOLERANCE_S = 900;

        public function GetCompatibleParents(): string
        {
            return json_encode(['type' => 'connect', 'moduleIDs' => [self::EOS_SERVER_GUID]]);
        }

        protected function registerPlanAttributes(): void
        {
            $this->RegisterAttributeString('Instructions', '[]');
            $this->RegisterAttributeString('PlanMeta', '{}');
            $this->RegisterAttributeBoolean('EmptyPlanWarned', false);
            $this->RegisterAttributeBoolean('SkewWarned', false);
        }

        protected function registerPlanVariables(int $position): void
        {
            $this->RegisterVariableInteger('NextChange', $this->Translate('Next change'), $this->eosDateTimePresentation(), $position);
            $this->RegisterVariableString('NextMode', $this->Translate('Next mode'), $this->eosValuePresentation('HollowArrowRight'), $position + 10);
            $this->RegisterVariableBoolean('PlanStale', $this->Translate('Plan stale'), $this->eosBoolPresentation('Current', 'Stale', 0x00A000, 0xFF0000, 'Warning'), $position + 20);
            $this->RegisterVariableString('PlanJSON', $this->Translate('Plan (JSON)'), $this->eosValuePresentation('Script'), $position + 30);
        }

        // ------------------------------------------------------------------ parent / data flow

        /**
         * The EOS Server is usable for measurements even while it reports
         * "no plan yet" (203) or a version mismatch (202): EOS needs the
         * measurements to produce a plan in the first place.
         */
        protected function parentUsable(): bool
        {
            $parentId = (int) IPS_GetInstance($this->InstanceID)['ConnectionID'];
            if ($parentId <= 0 || !IPS_InstanceExists($parentId)) {
                return false;
            }
            $status = (int) IPS_GetInstance($parentId)['InstanceStatus'];
            return in_array($status, [IS_ACTIVE, 202, 203], true);
        }

        protected function forward(array $payload): array
        {
            $payload['DataID'] = self::EOS_TX_GUID;
            $raw = $this->SendDataToParent(json_encode($payload));
            $decoded = json_decode((string) $raw, true);
            return is_array($decoded) ? $decoded : ['ok' => false, 'error' => 'no response from EOS Server'];
        }

        /**
         * Another battery, vehicle or appliance instance uses the same device id (EOS needs
         * ids unique across all device kinds). Tie-break: the instance with the lowest
         * InstanceID keeps working, so a second instance never disables the first one.
         */
        protected function isDuplicateDeviceId(string $deviceId): bool
        {
            $unreadable = false;
            foreach (self::EOS_DEVICE_MODULE_GUIDS as $guid) {
                foreach (IPS_GetInstanceListByModuleID($guid) as $id) {
                    if ($id >= $this->InstanceID) {
                        continue;
                    }
                    // During a module reload a sibling may be mid-recreation: Symcon warns and answers false.
                    $other = @IPS_GetProperty($id, 'DeviceID');
                    if (!is_string($other)) {
                        $unreadable = true;
                    } elseif ($other === $deviceId) {
                        return true;
                    }
                }
            }
            if ($unreadable) {
                $this->RegisterOnceTimer('ApplyLater', 'IPS_ApplyChanges($_IPS[\'TARGET\']);');
            }
            return false;
        }

        /** Statuses in which the instance must neither push, nor process plans, nor drive hardware. */
        protected function deviceBlocked(): bool
        {
            return in_array($this->GetStatus(), [self::STATUS_BAD_DEVICE_ID, self::STATUS_DUPLICATE_ID, self::STATUS_OTHER_DEVICE], true);
        }

        /**
         * Enter a blocking status: stop every timer and give up all source-variable
         * messages (re-registered by the next ApplyChanges), so nothing is sent or written
         * under a device id this instance does not own.
         */
        protected function blockDevice(int $status): void
        {
            $this->SetStatus($status);
            foreach (self::BLOCK_TIMERS as $timer) {
                $this->SetTimerInterval($timer, 0);
            }
            foreach (self::SOURCE_ATTRIBUTES as $attribute) {
                $var = $this->ReadAttributeInteger($attribute);
                if ($var > 0) {
                    $this->UnregisterMessage($var, VM_UPDATE);
                    $this->WriteAttributeInteger($attribute, 0);
                }
            }
        }

        protected function validDeviceId(string $deviceId): bool
        {
            // Leading letter: a purely numeric id like "0" becomes a JSON list in the device map.
            return preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $deviceId) === 1;
        }

        // ------------------------------------------------------------------ plan handling

        public function ReceiveData(string $JSONString): string
        {
            $data = json_decode($JSONString, true);
            if (!is_array($data) || ($data['DataID'] ?? '') !== self::EOS_RX_GUID) {
                return '';
            }
            $event = (string) ($data['Event'] ?? '');
            // Restart race: the server may come up after this child. Once it is
            // usable, redo ApplyChanges so the instance leaves status 104 and
            // starts its timers. Deferred: we are inside the parent's
            // SendDataToChildren call and must not call back into it here.
            if ($this->GetStatus() === self::STATUS_NO_PARENT && $this->parentUsable()) {
                $this->RegisterOnceTimer('ApplyLater', 'IPS_ApplyChanges($_IPS[\'TARGET\']);');
            }
            if ($this->deviceBlocked()) {
                return '';
            }
            if ($event === 'PlanUpdated') {
                $this->storePlan($data['Plan'] ?? [], is_array($data['Instructions'] ?? null) ? $data['Instructions'] : []);
                $this->onPlanStored();
                $this->ProcessPlan();
            } elseif ($event === 'Status') {
                $this->updatePlanStale();
            }
            return '';
        }

        public function RefreshPlan(): bool
        {
            if (!$this->parentUsable() || $this->deviceBlocked()) {
                return false;
            }
            $res = $this->forward(['Command' => 'GetPlanForResource', 'ResourceID' => $this->ReadPropertyString('DeviceID')]);
            if (($res['ok'] ?? false) !== true) {
                $this->UpdateFormField('ActionResult', 'caption', sprintf($this->Translate('Plan refresh failed: %s'), (string) ($res['error'] ?? '?')));
                $this->ProcessPlan();
                return false;
            }
            $instructions = is_array($res['instructions'] ?? null) ? $res['instructions'] : [];
            $this->storePlan(is_array($res['plan'] ?? null) ? $res['plan'] : [], $instructions);
            $this->onPlanStored();
            $this->ProcessPlan();
            $this->UpdateFormField('ActionResult', 'caption', sprintf($this->Translate('Plan refreshed: %d instructions'), count($instructions)));
            return true;
        }

        /**
         * Active instruction = latest with execution_time <= now, next = first in the
         * future. Updates variables, arms the slot timer exactly on the next change.
         * Idempotent; called by the timer, on new plans and after a restart.
         */
        public function ProcessPlan(): void
        {
            $this->SetTimerInterval('SlotTimer', 0);
            if ($this->deviceBlocked()) {
                return;
            }
            $list = $this->eosJsonDecode($this->ReadAttributeString('Instructions'), []);
            $list = is_array($list) ? $list : [];
            $now = $this->eosNow();
            $active = null;
            $next = null;
            foreach ($list as $instruction) {
                $ts = (int) ($instruction['ts'] ?? 0);
                if ($ts <= $now) {
                    $active = $instruction;
                } elseif ($next === null) {
                    $next = $instruction;
                    break;
                }
            }

            if ($active !== null) {
                $this->showInstruction($active);
                $this->WriteAttributeBoolean('EmptyPlanWarned', false);
            } else {
                if ($list === [] && !$this->ReadAttributeBoolean('EmptyPlanWarned')) {
                    $this->LogMessage(sprintf($this->Translate('The EOS plan contains no instructions for %s'), $this->ReadPropertyString('DeviceID')), KL_WARNING);
                    $this->WriteAttributeBoolean('EmptyPlanWarned', true);
                }
                $this->showNoInstruction();
            }

            $this->SetValue('NextChange', $next !== null ? (int) $next['ts'] : 0);
            $this->SetValue('NextMode', $next !== null ? (string) ($next['operation_mode_id'] ?? '') : '');
            if ($next !== null) {
                $delayMs = max(1000, ((int) $next['ts'] - $now) * 1000 + 500);
                $this->SetTimerInterval('SlotTimer', min($delayMs, self::MAX_SLOT_TIMER_MS));
            }

            $this->updatePlanStale($active);
            $this->onPlanProcessed($active);
        }

        public function GetActiveInstruction(): string
        {
            return json_encode($this->activeInstruction(), JSON_UNESCAPED_UNICODE);
        }

        protected function activeInstruction(): ?array
        {
            $list = $this->eosJsonDecode($this->ReadAttributeString('Instructions'), []);
            $now = $this->eosNow();
            $active = null;
            foreach (is_array($list) ? $list : [] as $instruction) {
                if ((int) ($instruction['ts'] ?? 0) <= $now) {
                    $active = $instruction;
                }
            }
            return $active;
        }

        protected function instructionList(): array
        {
            $list = $this->eosJsonDecode($this->ReadAttributeString('Instructions'), []);
            return is_array($list) ? $list : [];
        }

        /** First instruction in the future, null if none. */
        protected function nextInstruction(): ?array
        {
            $now = $this->eosNow();
            foreach ($this->instructionList() as $instruction) {
                if ((int) ($instruction['ts'] ?? 0) > $now) {
                    return $instruction;
                }
            }
            return null;
        }

        protected function planGeneratedAt(): int
        {
            $meta = $this->eosJsonDecode($this->ReadAttributeString('PlanMeta'), []);
            return $this->eosParseTime(is_array($meta) ? ($meta['generated_at'] ?? null) : null);
        }

        protected function planValidUntil(): int
        {
            $meta = $this->eosJsonDecode($this->ReadAttributeString('PlanMeta'), []);
            return $this->eosParseTime(is_array($meta) ? ($meta['valid_until'] ?? null) : null);
        }

        /**
         * Is the stored plan good enough to drive hardware? $reason receives
         * 'stale', 'expired', 'gap' (fresh plan, next instruction within the
         * tolerance, hold the last state) or 'no instruction'.
         */
        protected function planUsable(?array $active, string &$reason): bool
        {
            $reason = '';
            $generated = $this->planGeneratedAt();
            if ($generated === 0 || ($this->eosNow() - $generated) > $this->ReadPropertyInteger('StaleAfterMinutes') * 60) {
                $reason = 'stale';
                return false;
            }
            $until = $this->planValidUntil();
            if ($until > 0 && $this->eosNow() > $until + self::GAP_TOLERANCE_S) {
                $reason = 'expired';
                return false;
            }
            if ($active === null) {
                $next = $this->nextInstruction();
                $reason = ($next !== null && (int) $next['ts'] - $this->eosNow() <= self::GAP_TOLERANCE_S) ? 'gap' : 'no instruction';
                return false;
            }
            return true;
        }

        protected function storePlan(array $meta, array $instructions): void
        {
            $deviceId = $this->ReadPropertyString('DeviceID');
            $mine = [];
            foreach ($instructions as $instruction) {
                if (!is_array($instruction) || (string) ($instruction['resource_id'] ?? '') !== $deviceId) {
                    continue;
                }
                $instruction['ts'] = $this->eosParseTime($instruction['execution_time'] ?? null);
                if ($instruction['ts'] > 0) {
                    $mine[] = $instruction;
                }
            }
            usort($mine, static fn (array $a, array $b): int => $a['ts'] <=> $b['ts']);
            $this->WriteAttributeString('Instructions', json_encode($mine));
            $this->WriteAttributeString('PlanMeta', json_encode([
                'id'           => $meta['id'] ?? '',
                'generated_at' => $meta['generated_at'] ?? null,
                'valid_from'   => $meta['valid_from'] ?? null,
                'valid_until'  => $meta['valid_until'] ?? null,
            ]));
            $this->SetValue('PlanJSON', json_encode(array_map(static function (array $i): array {
                return [
                    'time'   => $i['execution_time'] ?? '',
                    'ts'     => $i['ts'],
                    'mode'   => $i['operation_mode_id'] ?? '',
                    'factor' => $i['operation_mode_factor'] ?? 0,
                ];
            }, $mine), JSON_UNESCAPED_UNICODE));
            $this->SendDebug('storePlan', count($mine) . ' instructions for ' . $deviceId, 0);

            // Clock skew between the EOS host and Symcon makes every plan start "in the future".
            $generated = $this->eosParseTime($meta['generated_at'] ?? null);
            $skewed = $generated > $this->eosNow() + 60;
            if ($skewed && !$this->ReadAttributeBoolean('SkewWarned')) {
                $this->LogMessage(sprintf($this->Translate('The EOS plan is dated %d s in the future (generated_at); check the clocks of the EOS host and Symcon'), $generated - $this->eosNow()), KL_WARNING);
            }
            $this->WriteAttributeBoolean('SkewWarned', $skewed);
        }

        protected function updatePlanStale(?array $active = null): void
        {
            $meta = $this->eosJsonDecode($this->ReadAttributeString('PlanMeta'), []);
            $generated = $this->eosParseTime(is_array($meta) ? ($meta['generated_at'] ?? null) : null);
            $limit = $this->ReadPropertyInteger('StaleAfterMinutes') * 60;
            $stale = $generated === 0 || ($this->eosNow() - $generated) > $limit;
            if ($active === null && $this->activeInstruction() === null) {
                $stale = true;
            }
            if ((bool) $this->GetValue('PlanStale') !== $stale) {
                $this->SetValue('PlanStale', $stale);
            }
        }

        /** Register/unregister VM_UPDATE for an optional source variable property (remembered in an attribute). */
        protected function registerOptionalSource(string $property, string $attribute): void
        {
            $old = $this->ReadAttributeInteger($attribute);
            $src = $this->ReadPropertyInteger($property);
            if ($old > 0 && $old !== $src) {
                $this->UnregisterMessage($old, VM_UPDATE);
            }
            if ($src > 0 && IPS_VariableExists($src)) {
                $this->RegisterMessage($src, VM_UPDATE);
                $this->WriteAttributeInteger($attribute, $src);
            } else {
                $this->WriteAttributeInteger($attribute, 0);
            }
        }

        /** Hook: a new plan was stored (before ProcessPlan). */
        protected function onPlanStored(): void
        {
        }

        /** Hook: variables are up to date (control, visualization). */
        protected function onPlanProcessed(?array $active): void
        {
        }

        abstract protected function showInstruction(array $instruction): void;

        abstract protected function showNoInstruction(): void;
    }
}
