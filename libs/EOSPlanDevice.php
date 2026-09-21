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
 */
if (!trait_exists('EOSPlanDevice')) {
    trait EOSPlanDevice
    {
        public const STATUS_NO_PARENT = 104;
        public const STATUS_BAD_DEVICE_ID = 201;
        public const STATUS_NO_SOURCE = 202;
        public const STATUS_DUPLICATE_ID = 203;
        /** Never sleep longer than this before re-evaluating the plan (ms). */
        public const MAX_SLOT_TIMER_MS = 6 * 3600 * 1000;

        public function GetCompatibleParents(): string
        {
            return json_encode(['type' => 'connect', 'moduleIDs' => [self::EOS_SERVER_GUID]]);
        }

        protected function registerPlanAttributes(): void
        {
            $this->RegisterAttributeString('Instructions', '[]');
            $this->RegisterAttributeString('PlanMeta', '{}');
            $this->RegisterAttributeBoolean('EmptyPlanWarned', false);
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

        protected function isDuplicateDeviceId(string $deviceId): bool
        {
            foreach (IPS_GetInstanceListByModuleID(self::MODULE_GUID) as $id) {
                if ($id === $this->InstanceID) {
                    continue;
                }
                if ((string) IPS_GetProperty($id, 'DeviceID') === $deviceId) {
                    return true;
                }
            }
            return false;
        }

        protected function validDeviceId(string $deviceId): bool
        {
            return $deviceId !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $deviceId) === 1;
        }

        // ------------------------------------------------------------------ plan handling

        public function ReceiveData(string $JSONString): string
        {
            $data = json_decode($JSONString, true);
            if (!is_array($data) || ($data['DataID'] ?? '') !== self::EOS_RX_GUID) {
                return '';
            }
            $event = (string) ($data['Event'] ?? '');
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
            if (!$this->parentUsable()) {
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
            $list = $this->eosJsonDecode($this->ReadAttributeString('Instructions'), []);
            $list = is_array($list) ? $list : [];
            $now = time();
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
                    $this->LogMessage('EOS plan contains no instructions for ' . $this->ReadPropertyString('DeviceID'), KL_WARNING);
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
            $now = time();
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
                'received'     => time(),
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
        }

        protected function updatePlanStale(?array $active = null): void
        {
            $meta = $this->eosJsonDecode($this->ReadAttributeString('PlanMeta'), []);
            $generated = $this->eosParseTime(is_array($meta) ? ($meta['generated_at'] ?? null) : null);
            $limit = $this->ReadPropertyInteger('StaleAfterMinutes') * 60;
            $stale = $generated === 0 || (time() - $generated) > $limit;
            if ($active === null && $this->activeInstruction() === null) {
                $stale = true;
            }
            $this->SetValue('PlanStale', $stale);
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
