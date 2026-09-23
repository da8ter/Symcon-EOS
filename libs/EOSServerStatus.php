<?php

declare(strict_types=1);

/*
 * EOS Server: connection state and instance status. Status = f(reachable, version,
 * plan) set in one place; children are told about every change between usable and
 * unusable (deferred when that change is noticed inside a child's request); replies to
 * children are encoded tolerantly and feed the reachability.
 */
if (!trait_exists('EOSServerStatus')) {
    trait EOSServerStatus
    {
        /**
         * Reply to a child: tolerant JSON (an error text cut inside a multibyte character must
         * not turn the whole answer into false), and the transport result feeds the status.
         */
        private function reply(array $reply): string
        {
            $this->noteTransport($reply);
            return (string) (json_encode($reply, JSON_INVALID_UTF8_SUBSTITUTE) ?: '{"ok":false,"error":"reply not encodable"}');
        }

        /** A child's request reached EOS or not: connection errors (and two timeouts in a row) mean unreachable. */
        private function noteTransport(array $reply): void
        {
            if (!empty($reply['local'])) {
                return; // answered by this instance itself (e.g. a refusal): says nothing about EOS
            }
            if (($reply['ok'] ?? false) === true || (int) ($reply['status'] ?? -1) > 0) {
                $this->WriteAttributeInteger('TransportTimeouts', 0);
                if (!$this->ReadAttributeBoolean('Reachable')) {
                    $this->WriteAttributeBoolean('Reachable', true);
                    $this->SetValue('Connected', true);
                    $this->updateServerStatus(true);
                }
                return;
            }
            if ((int) ($reply['status'] ?? -1) !== 0) {
                return;
            }
            if ((int) ($reply['errno'] ?? 0) === 28) {
                $timeouts = $this->ReadAttributeInteger('TransportTimeouts') + 1;
                $this->WriteAttributeInteger('TransportTimeouts', $timeouts);
                if ($timeouts < 2) {
                    return; // one slow answer (e.g. during a GENETIC run) is not an outage
                }
            }
            $this->markUnreachable((string) ($reply['error'] ?? 'connection failed'), true);
        }

        private function applyHealth(array $health): void
        {
            $version = (string) ($health['version'] ?? '');
            $this->WriteAttributeBoolean('Reachable', true);
            $this->WriteAttributeInteger('TransportTimeouts', 0);
            $this->SetValue('Connected', true);
            $this->SetValue('Version', $version);
            $this->SetValue('LastRun', $this->eosParseTime($health['energy-management']['last_run_datetime'] ?? null));
            $expected = trim($this->ReadPropertyString('ExpectedVersion'));
            $versionOk = $expected === '' || str_starts_with($version, $expected);
            $this->WriteAttributeBoolean('VersionOk', $versionOk);
            if (!$versionOk) {
                $this->SetValue('LastError', sprintf($this->Translate('EOS version %s does not match the expected %s*'), $version, $expected));
            }
            $this->updateServerStatus();
        }

        /** LastError, except that a version mismatch keeps its text until it is resolved. */
        private function setServerError(string $error): void
        {
            if ($this->ReadAttributeBoolean('VersionOk')) {
                $this->SetValue('LastError', $error);
            }
        }

        private function serverUsable(int $status): bool
        {
            return in_array($status, [IS_ACTIVE, self::STATUS_VERSION, self::STATUS_NO_PLAN], true);
        }

        /**
         * The one place that sets the server status: unreachable (201) > version mismatch (202)
         * > no plan (203) > active. Children use the server in 102/202/203; every change between
         * unusable and usable is broadcast, deferred when called from inside a child's request.
         */
        private function updateServerStatus(bool $defer = false): void
        {
            $before = $this->GetStatus();
            $status = !$this->ReadAttributeBoolean('Reachable') ? self::STATUS_UNREACHABLE
                : (!$this->ReadAttributeBoolean('VersionOk') ? self::STATUS_VERSION
                : (!$this->ReadAttributeBoolean('PlanAvailable') ? self::STATUS_NO_PLAN : IS_ACTIVE));
            if ($status !== $before) {
                $this->SetStatus($status);
            }
            if ($this->serverUsable($status) !== $this->serverUsable($before)) {
                if ($defer) {
                    $this->RegisterOnceTimer('StatusBroadcast', 'IPS_RequestAction($_IPS[\'TARGET\'], \'BroadcastStatus\', \'\');');
                } else {
                    $this->broadcastStatus($this->serverUsable($status));
                }
            }
        }

        private function broadcastStatus(bool $connected): void
        {
            $this->SendDataToChildren(json_encode(['DataID' => self::EOS_RX_GUID, 'Event' => 'Status', 'Connected' => $connected]));
        }

        private function markUnreachable(string $error, bool $defer = false): void
        {
            $wasReachable = $this->ReadAttributeBoolean('Reachable');
            $this->WriteAttributeBoolean('Reachable', false);
            $this->WriteAttributeInteger('TransportTimeouts', 0);
            $this->SetValue('Connected', false);
            $this->SetValue('LastError', $error);
            if ($wasReachable) {
                $this->LogMessage(sprintf($this->Translate('EOS not reachable: %s'), $error), KL_WARNING);
            }
            $this->updateServerStatus($defer);
        }
    }
}
