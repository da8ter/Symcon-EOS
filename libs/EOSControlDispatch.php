<?php

declare(strict_types=1);

/*
 * Control layer, execution side: runs a stored desired state against the bindings
 * (target variables, mode-row action, action and script on change), keeps the
 * per-target write state in the LastSent attribute (dedupe, retries with backoff,
 * heartbeat) and reports the outcome. Used together with EOSControl.
 */
if (!trait_exists('EOSControlDispatch')) {
    trait EOSControlDispatch
    {
        // ---------------------------------------------------------------- execute

        /** Timer callback: write the stored desired state. */
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
                $this->recordResult($this->Translate('control switched off'), false);
                return;
            }
            $this->executeDesired($desired, $force, $this->ReadPropertyInteger('ControlMode') === self::CONTROL_SIMULATE);
        }

        protected function executeDesired(array $desired, bool $force, bool $sim): void
        {
            $now = $this->eosNow();
            $last = $this->eosJsonDecode($this->ReadAttributeString('LastSent'), []);
            $last = is_array($last) ? $last : [];
            $lastTargets = is_array($last['targets'] ?? null) ? $last['targets'] : [];
            $resolved = $this->resolveTargets($desired);

            $changed = [];
            $retry = [];
            $backoff = static fn (int $fail, int $failTs): bool => $now - $failTs >= 60 * min(max(1, $fail), self::MAX_RETRY_BACKOFF);
            foreach ($resolved as $key => $target) {
                $entry = $lastTargets[$key] ?? null;
                $fail = (int) ($entry['fail'] ?? 0);
                if ($fail > 0 && ($entry['fv'] ?? null) === $target['norm']) {
                    // The same value failed before: retry only after the backoff, never on every dispatch.
                    if ($backoff($fail, (int) ($entry['failTs'] ?? 0))) {
                        $retry[$key] = $target;
                    }
                    continue;
                }
                if ($entry === null || ($entry['v'] ?? null) !== $target['norm']) {
                    $changed[$key] = $target;
                }
            }
            $modeRaw = (string) ($desired['modeRaw'] ?? '');
            $modeChanged = $last === [] || $modeRaw !== (string) ($last['modeRaw'] ?? '');
            // Row action: pending until it ran successfully for this mode (or nothing is configured).
            $row = is_array($last['row'] ?? null) ? $last['row'] : ['modeRaw' => null, 'fail' => 0, 'failTs' => 0];
            $rowPending = $modeRaw !== '' && $modeRaw !== (string) ($row['modeRaw'] ?? '');
            // Backoff only for retrying the mode that failed; a new mode fires at once.
            $rowDue = $rowPending && ((int) ($row['fail'] ?? 0) === 0 || ($row['failMode'] ?? null) !== $modeRaw || $backoff((int) $row['fail'], (int) $row['failTs']));
            $heartbeatSeconds = $this->ReadPropertyInteger('HeartbeatSeconds');
            $heartbeat = $heartbeatSeconds > 0 && $last !== [] && $now - (int) ($last['ts'] ?? 0) >= $heartbeatSeconds;
            if (!$force && $changed === [] && !$modeChanged && !$heartbeat && $retry === [] && !$rowDue) {
                // Nothing to write, but a changed policy degradation must stay visible.
                $degraded = (string) ($desired['degraded'] ?? '');
                if ($degraded !== (string) ($last['degraded'] ?? '')) {
                    $last['degraded'] = $degraded;
                    $this->WriteAttributeString('LastSent', json_encode($last));
                    $this->recordResult('[' . (string) ($desired['source'] ?? 'plan') . '] ' . $this->Translate('nothing to write') . ($degraded !== '' ? ' · ' . $degraded : ''), false);
                }
                return;
            }
            $reason = ($changed === [] && !$modeChanged && !$force && $retry === [] && !$rowDue) ? 'heartbeat' : (string) ($desired['source'] ?? 'plan');
            $context = $this->buildContext($desired, $reason, $sim, array_keys($changed));

            $fragments = [];
            $failed = [];
            $started = microtime(true);
            // Heartbeat re-sends everything except targets whose failed value is still in its backoff.
            $inBackoff = static fn (?array $entry, string $norm): bool => $entry !== null && (int) ($entry['fail'] ?? 0) > 0 && ($entry['fv'] ?? null) === $norm && !$backoff((int) $entry['fail'], (int) ($entry['failTs'] ?? 0));
            $toWrite = $force ? $resolved : ($heartbeat ? array_filter($resolved, fn ($t, $k) => !$inBackoff($lastTargets[$k] ?? null, $t['norm']), ARRAY_FILTER_USE_BOTH) : $changed + $retry);
            foreach ($toWrite as $key => $target) {
                $result = $this->writeTarget($key, $target['varId'], $target['value'], $sim);
                if ($result['text'] !== '') {
                    $fragments[] = $result['text'];
                }
                $entry = $lastTargets[$key] ?? ['v' => null, 'ts' => 0, 'fail' => 0, 'failTs' => 0, 'fv' => null];
                if ($result['ok']) {
                    $entry = ['v' => $target['norm'], 'ts' => $now, 'fail' => 0, 'failTs' => 0, 'fv' => null];
                } else {
                    $failed[] = $result['text'];
                    if (!$result['skipped']) {
                        $entry['fail'] = (int) ($entry['fail'] ?? 0) + 1;
                        $entry['failTs'] = $now;
                        $entry['fv'] = $target['norm']; // remember what failed; a different value may be written at once
                    }
                }
                $lastTargets[$key] = $entry;
            }

            // Mode-row action: edge-triggered (once per mode), retried with backoff after a failure, never by the heartbeat.
            $rowSucceeded = false;
            if (($rowDue || $force) && $modeRaw !== '') {
                if ($this->rowActionAllowed($desired, $modeRaw)) {
                    $result = $this->runAction($this->modeMapRow($modeRaw)['action'], $context, $sim, $this->Translate('Action') . ' ' . $modeRaw);
                    if ($result['text'] !== '') {
                        $fragments[] = $result['text'];
                    }
                    if ($result['ok']) {
                        $rowSucceeded = true;
                    } else {
                        $failed[] = $result['text'];
                        $sameMode = ($row['failMode'] ?? null) === $modeRaw;
                        $row = ['modeRaw' => $row['modeRaw'] ?? null, 'fail' => $sameMode ? (int) ($row['fail'] ?? 0) + 1 : 1, 'failTs' => $now, 'failMode' => $modeRaw];
                    }
                } else {
                    $rowSucceeded = true; // nothing to fire for this mode
                }
                if ($rowSucceeded) {
                    $row = ['modeRaw' => $modeRaw, 'fail' => 0, 'failTs' => 0];
                }
            }
            foreach ([
                $this->runAction($this->ReadPropertyString('ChangeAction'), $context, $sim, $this->Translate('Action on change')),
                $this->runScript($this->ReadPropertyInteger('ControlScript'), $context, $sim, $this->Translate('Script on change')),
            ] as $result) {
                if ($result['text'] !== '') {
                    $fragments[] = $result['text'];
                    if (!$result['ok']) {
                        $failed[] = $result['text'];
                    }
                }
            }

            $this->WriteAttributeString('LastSent', json_encode([
                'targets'  => $lastTargets,
                'modeRaw'  => $modeRaw,
                'row'      => $row,
                'ts'       => $now,
                'source'   => $reason,
                'degraded' => (string) ($desired['degraded'] ?? ''),
            ]));
            $durationMs = (int) round((microtime(true) - $started) * 1000);
            $text = ($sim ? 'SIM ' : '') . '[' . $reason . '] ' . ($fragments !== [] ? implode(' · ', $fragments) : $this->Translate('nothing to write'));
            if (($desired['degraded'] ?? '') !== '') {
                $text .= ' · ' . (string) $desired['degraded'];
            }
            $this->recordResult($text);
            $this->SendDebug('Control', $text . ' (' . $durationMs . ' ms)', 0);
            $this->logThrottled($failed !== [] ? implode(' · ', $failed) : '');
            if ($durationMs > self::SLOW_WRITE_MS) {
                $this->LogMessage(sprintf($this->Translate('Control writes took %d ms - consider a script binding for slow devices'), $durationMs), KL_WARNING);
            }
            if (!$sim) {
                // Success means: nothing failed now AND no bound target or the mode action is still in a failed state.
                $pending = $modeRaw !== '' && $modeRaw !== (string) ($row['modeRaw'] ?? '') && (int) ($row['fail'] ?? 0) > 0;
                foreach ($resolved as $key => $target) {
                    if ((int) ($lastTargets[$key]['fail'] ?? 0) > 0) {
                        $pending = true;
                    }
                }
                $this->onDispatched($desired, $failed === [] && !$pending);
            }
        }
    }
}
