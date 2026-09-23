<?php

declare(strict_types=1);

/*
 * Control layer, execution side: runs a stored desired state against the bindings
 * (target variables, mode-row action, action and script on change), keeps the
 * per-target write state in the LastSent attribute (dedupe, retries with backoff,
 * heartbeat) and reports the outcome. Used together with EOSControl.
 *
 * LastSent (v2):
 *   {v:2, ts, source, modeRaw, degraded, resync,
 *    targets: {Key: {v, ts, fail, failTs, fv, err}},  v/ts = last value written successfully
 *    row: {modeRaw, fail, failTs[, failMode]},        mode-row action, fires once per mode
 *    chg: {fail, failTs}}                             action and script on change
 * Rules per bound target (n = wanted value): a value that failed before is retried only
 * after the backoff; anything else that differs from the last success, or still carries
 * a failure, is written at once; an unchanged healthy target is re-sent when its own
 * heartbeat is due. A skipped target (missing, not actionable, not convertible) counts
 * as a failure, so it runs through the backoff instead of being "changed" every time.
 */
if (!trait_exists('EOSControlDispatch')) {
    trait EOSControlDispatch
    {
        /** Timer callback (Dispatch one-shot and Retry timer): write the stored desired state. */
        protected function runDispatch(bool $force = false): void
        {
            if ($this->ReadPropertyInteger('ControlMode') === self::CONTROL_DISPLAY || $this->deviceBlocked()) {
                return;
            }
            $desired = $this->eosJsonDecode($this->ReadAttributeString('Desired'), []);
            if (!is_array($desired) || !isset($desired['targets'])) {
                return;
            }
            if (!$this->GetValue('ControlActive')) {
                $this->SetTimerInterval('Retry', 0);
                $this->recordResult($this->Translate('control switched off'), false);
                return;
            }
            $this->executeDesired($desired, $force, $this->ReadPropertyInteger('ControlMode') === self::CONTROL_SIMULATE);
        }

        protected function backoffSeconds(int $fail): int
        {
            return 60 * min(max(1, $fail), self::MAX_RETRY_BACKOFF);
        }

        /** Heartbeat interval in s: 0 = off, otherwise at least 5. */
        protected function heartbeatSeconds(): int
        {
            $seconds = $this->ReadPropertyInteger('HeartbeatSeconds');
            return $seconds > 0 ? max(5, $seconds) : 0;
        }

        protected function lastSent(): array
        {
            $last = $this->eosJsonDecode($this->ReadAttributeString('LastSent'), []);
            return is_array($last) ? $last : [];
        }

        /** What is due for $desired given the write state $last (one decision for dispatch and retry timer). */
        protected function dispatchPlan(array $desired, array $last, int $now): array
        {
            $entries = is_array($last['targets'] ?? null) ? $last['targets'] : [];
            $resolved = $this->resolveTargets($desired);
            $heartbeatSeconds = $this->heartbeatSeconds();
            $change = [];
            $retry = [];
            $heartbeat = [];
            foreach ($resolved as $key => $target) {
                $entry = $entries[$key] ?? null;
                $fail = (int) ($entry['fail'] ?? 0);
                if ($entry !== null && $fail > 0 && ($entry['fv'] ?? null) === $target['norm']) {
                    if ($now - (int) ($entry['failTs'] ?? 0) >= $this->backoffSeconds($fail)) {
                        $retry[$key] = $target;
                    }
                    continue;
                }
                if ($entry === null || ($entry['v'] ?? null) !== $target['norm'] || $fail > 0) {
                    $change[$key] = $target;
                } elseif ($heartbeatSeconds > 0 && $now - (int) ($entry['ts'] ?? 0) >= $heartbeatSeconds) {
                    $heartbeat[$key] = $target;
                }
            }
            $modeRaw = (string) ($desired['modeRaw'] ?? '');
            // The mode-row action fires once per row key: the mode, or a finer key a device sets
            // (the appliance: RUN plus the planned start, so each new cycle fires once).
            $rowKey = $modeRaw !== '' ? (string) ($desired['rowKey'] ?? $modeRaw) : '';
            $row = is_array($last['row'] ?? null) ? $last['row'] : ['modeRaw' => null, 'fail' => 0, 'failTs' => 0];
            $rowPending = $rowKey !== '' && $rowKey !== (string) ($row['modeRaw'] ?? '');
            // Backoff only for retrying the key that failed; a new key fires at once.
            $rowDue = $rowPending && ((int) ($row['fail'] ?? 0) === 0 || ($row['failMode'] ?? null) !== $rowKey
                || $now - (int) ($row['failTs'] ?? 0) >= $this->backoffSeconds((int) $row['fail']));
            $chg = is_array($last['chg'] ?? null) ? $last['chg'] : ['fail' => 0, 'failTs' => 0];
            return [
                'resolved'    => $resolved,
                'change'      => $change,
                'retry'       => $retry,
                'heartbeat'   => $heartbeat,
                'modeRaw'     => $modeRaw,
                'rowKey'      => $rowKey,
                'modeChanged' => !isset($last['modeRaw']) || $modeRaw !== (string) $last['modeRaw'],
                'row'         => $row,
                'rowPending'  => $rowPending,
                'rowDue'      => $rowDue,
                'chg'         => $chg,
                'chgRetry'    => (int) ($chg['fail'] ?? 0) > 0 && $now - (int) ($chg['failTs'] ?? 0) >= $this->backoffSeconds((int) $chg['fail']),
            ];
        }

        protected function executeDesired(array $desired, bool $force, bool $sim): void
        {
            $now = $this->eosNow();
            $last = $this->lastSent();
            $entries = is_array($last['targets'] ?? null) ? $last['targets'] : [];
            $plan = $this->dispatchPlan($desired, $last, $now);
            $modeRaw = $plan['modeRaw'];
            $due = $force || $plan['change'] !== [] || $plan['retry'] !== [] || $plan['heartbeat'] !== [] || $plan['modeChanged'] || $plan['rowDue'] || $plan['chgRetry'];
            if (!$due) {
                // Nothing to write, but a changed policy degradation must stay visible.
                $degraded = (string) ($desired['degraded'] ?? '');
                if ($degraded !== (string) ($last['degraded'] ?? '')) {
                    $last['degraded'] = $degraded;
                    $this->WriteAttributeString('LastSent', json_encode($last));
                    $this->recordResult('[' . (string) ($desired['source'] ?? 'plan') . '] ' . $this->Translate('nothing to write') . ($degraded !== '' ? ' · ' . $degraded : ''), false);
                }
                $this->armRetryTimer($last, $plan['resolved']);
                return;
            }
            $source = (string) ($desired['source'] ?? 'plan');
            if ($source === 'disable') {
                $reason = 'disable';
            } elseif ($force) {
                $reason = 'force';
            } elseif ($plan['change'] !== [] || $plan['modeChanged'] || $plan['rowDue']) {
                $reason = $source;
            } else {
                $reason = $plan['heartbeat'] !== [] ? 'heartbeat' : 'retry';
            }
            $context = $this->buildContext($desired, $reason, $sim, array_keys($plan['change'])) + [
                'Retried'     => implode(',', array_keys($plan['retry'])),
                'Heartbeat'   => $plan['heartbeat'] !== [],
                'ModeChanged' => $plan['modeChanged'],
                // First write after Apply or a Symcon restart: the device state was unknown.
                'Resync'      => !empty($last['resync']),
            ];

            $fragments = [];
            $failed = [];
            $outcome = ['targets' => [], 'row' => 'notdue', 'chg' => 'none'];
            $started = microtime(true);
            $toWrite = $force ? $plan['resolved'] : $plan['change'] + $plan['retry'] + $plan['heartbeat'];
            foreach ($plan['resolved'] as $key => $target) {
                if (!isset($toWrite[$key])) {
                    $entry = $entries[$key] ?? [];
                    $outcome['targets'][$key] = ((int) ($entry['fail'] ?? 0) === 0 && ($entry['v'] ?? null) === $target['norm']) ? 'same' : 'blocked';
                    continue;
                }
                $result = $this->writeTarget($key, $target['varId'], $target['value'], $sim);
                if ($result['text'] !== '') {
                    $fragments[] = $result['text'];
                }
                $entry = $entries[$key] ?? ['v' => null, 'ts' => 0, 'fail' => 0, 'failTs' => 0, 'fv' => null, 'err' => ''];
                if ($result['ok']) {
                    $entry = ['v' => $target['norm'], 'ts' => $now, 'fail' => 0, 'failTs' => 0, 'fv' => null, 'err' => ''];
                    $outcome['targets'][$key] = 'ok';
                } else {
                    $failed[] = $result['text'];
                    $entry['fail'] = (int) ($entry['fail'] ?? 0) + 1;
                    $entry['failTs'] = $now;
                    $entry['fv'] = $target['norm']; // remember what failed; a different value may be written at once
                    $entry['err'] = (string) ($result['err'] ?? 'failed');
                    $outcome['targets'][$key] = 'fail';
                }
                $entries[$key] = $entry;
            }

            // Mode-row action: edge-triggered (once per mode), retried with backoff after a failure, never by the heartbeat.
            $row = $plan['row'];
            $rowFired = false;
            if (($plan['rowDue'] || $force) && $modeRaw !== '') {
                $outcome['row'] = 'none';
                $rowDone = true; // nothing to fire for this mode
                if ($this->rowActionAllowed($desired, $modeRaw)) {
                    $result = $this->runAction($this->modeMapRow($modeRaw)['action'], $context, $sim, $this->Translate('Action') . ' ' . $modeRaw);
                    if ($result['text'] !== '') {
                        $fragments[] = $result['text'];
                    }
                    if (!$result['ok']) {
                        $rowDone = false;
                        $failed[] = $result['text'];
                        $sameKey = ($row['failMode'] ?? null) === $plan['rowKey'];
                        $row = ['modeRaw' => $row['modeRaw'] ?? null, 'fail' => $sameKey ? (int) ($row['fail'] ?? 0) + 1 : 1, 'failTs' => $now, 'failMode' => $plan['rowKey']];
                        $outcome['row'] = 'failed';
                    } elseif (empty($result['skipped'])) {
                        $rowFired = true;
                        $outcome['row'] = 'fired';
                    }
                }
                if ($rowDone) {
                    $row = ['modeRaw' => $plan['rowKey'], 'fail' => 0, 'failTs' => 0];
                }
            } elseif ($plan['rowPending']) {
                $outcome['row'] = 'waiting'; // failed before, still in its backoff
            }

            // Action and script on change: on every change, mode change, heartbeat or forced write; not for pure retries.
            $chg = $plan['chg'];
            if ($force || $plan['change'] !== [] || $plan['heartbeat'] !== [] || $plan['modeChanged'] || $rowFired || $plan['chgRetry']) {
                $chgFailed = false;
                $ran = false;
                foreach ([
                    $this->runAction($this->ReadPropertyString('ChangeAction'), $context, $sim, $this->Translate('Action on change')),
                    $this->runScript($this->ReadPropertyInteger('ControlScript'), $context, $sim, $this->Translate('Script on change')),
                ] as $result) {
                    if ($result['text'] !== '') {
                        $fragments[] = $result['text'];
                        $ran = true;
                        if (!$result['ok']) {
                            $failed[] = $result['text'];
                            $chgFailed = true;
                        }
                    }
                }
                if ($ran) {
                    $outcome['chg'] = $chgFailed ? 'fail' : 'ok';
                    $chg = $chgFailed ? ['fail' => (int) ($chg['fail'] ?? 0) + 1, 'failTs' => $now] : ['fail' => 0, 'failTs' => 0];
                }
            }

            $lastNew = [
                'v'        => 2,
                'ts'       => $now,
                'source'   => $reason,
                'modeRaw'  => $modeRaw,
                'degraded' => (string) ($desired['degraded'] ?? ''),
                'resync'   => false,
                'targets'  => $entries,
                'row'      => $row,
                'chg'      => $chg,
            ];
            $this->WriteAttributeString('LastSent', json_encode($lastNew));
            $durationMs = (int) round((microtime(true) - $started) * 1000);
            $text = ($sim ? 'SIM ' : '') . '[' . $reason . '] ' . ($fragments !== [] ? implode(' · ', $fragments) : $this->Translate('nothing to write'));
            if (($desired['degraded'] ?? '') !== '') {
                $text .= ' · ' . (string) $desired['degraded'];
            }
            $this->recordResult($text);
            $this->SendDebug('Control', $text . ' (' . $durationMs . ' ms)', 0);
            $this->logThrottled($this->failureSignature($lastNew), implode(' · ', $failed));
            if ($durationMs > self::SLOW_WRITE_MS) {
                $this->LogMessage(sprintf($this->Translate('Control writes took %d ms - consider a script binding for slow devices'), $durationMs), KL_WARNING);
            }
            $this->armRetryTimer($lastNew, $plan['resolved']);
            if (!$sim) {
                $outcome['success'] = $this->deviceSideOk($outcome);
                $this->onDispatched($desired, $outcome);
            }
        }

        /**
         * Did the device take the state? Targets written or unchanged, the mode-row action
         * fired, done or not needed. The action and script on change only count when nothing
         * else is bound (then they are the device binding).
         */
        protected function deviceSideOk(array $outcome): bool
        {
            foreach ($outcome['targets'] as $state) {
                if ($state !== 'ok' && $state !== 'same') {
                    return false;
                }
            }
            if (in_array($outcome['row'], ['failed', 'waiting'], true)) {
                return false;
            }
            $deviceBound = $outcome['targets'] !== [] || $outcome['row'] === 'fired';
            return $deviceBound || $outcome['chg'] !== 'fail';
        }

        /** Everything failing after a dispatch, attempted now or waiting in its backoff, as sorted "key:error". */
        protected function failureSignature(array $last): array
        {
            $failing = [];
            foreach ((array) ($last['targets'] ?? []) as $key => $entry) {
                if ((int) ($entry['fail'] ?? 0) > 0) {
                    $failing[] = $key . ':' . (string) ($entry['err'] ?? 'failed');
                }
            }
            if ((int) ($last['row']['fail'] ?? 0) > 0) {
                $failing[] = 'row:' . (string) ($last['row']['failMode'] ?? '');
            }
            if ((int) ($last['chg']['fail'] ?? 0) > 0) {
                $failing[] = 'chg';
            }
            sort($failing);
            return $failing;
        }

        /**
         * One timer for everything time-driven after a dispatch: retries after the backoff,
         * per-target heartbeats, the mode-row and change-action retries. Armed on absolute due
         * times, so re-arming from every dispatch cannot starve it.
         */
        protected function armRetryTimer(array $last, array $resolved): void
        {
            $due = [];
            $heartbeatSeconds = $this->heartbeatSeconds();
            foreach (array_keys($resolved) as $key) {
                $entry = $last['targets'][$key] ?? null;
                if (!is_array($entry)) {
                    continue;
                }
                if ((int) ($entry['fail'] ?? 0) > 0) {
                    $due[] = (int) $entry['failTs'] + $this->backoffSeconds((int) $entry['fail']);
                } elseif ($heartbeatSeconds > 0) {
                    $due[] = (int) ($entry['ts'] ?? 0) + $heartbeatSeconds;
                }
            }
            if ((int) ($last['row']['fail'] ?? 0) > 0) {
                $due[] = (int) $last['row']['failTs'] + $this->backoffSeconds((int) $last['row']['fail']);
            }
            if ((int) ($last['chg']['fail'] ?? 0) > 0) {
                $due[] = (int) $last['chg']['failTs'] + $this->backoffSeconds((int) $last['chg']['fail']);
            }
            if ($due === []) {
                $this->SetTimerInterval('Retry', 0);
                return;
            }
            // Counted from this moment, not from the second the dispatch started: writes take time, and
            // SetTimerInterval starts counting when it is called, so whole seconds would add up per cycle.
            // The 500 ms lead makes eosNow() reach the due second when the timer fires; it also keeps the interval above 0 (= off).
            $delayMs = (int) round((min($due) - $this->eosNowFloat()) * 1000);
            $this->SetTimerInterval('Retry', min(3600000, max(0, $delayMs)) + 500);
        }
    }
}
