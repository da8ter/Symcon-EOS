<?php

declare(strict_types=1);

/*
 * Comparison of a device entry in Symcon notation with the one EOS returns: the
 * three-way classification of EOSDeviceConfigSync and the notation rules (tolerant
 * numbers, empty = null, ISO times, clock times).
 */
if (!trait_exists('EOSConfigCompare')) {
    trait EOSConfigCompare
    {
        /** Per key of $s (Symcon): same, push (Symcon changed), eos (only EOS changed) or conflict. */
        protected function classifyConfig(array $s, array $e, array $b): array
        {
            $out = ['same' => [], 'push' => [], 'eos' => [], 'conflict' => []];
            foreach ($s as $key => $value) {
                $key = (string) $key;
                $eosValue = $e[$key] ?? null;
                $baseValue = $b[$key] ?? null;
                if ($this->valuesEqual($key, $eosValue, $value)) {
                    $out['same'][] = $key;
                    continue;
                }
                $symconChanged = !$this->valuesEqual($key, $baseValue, $value);
                $eosChanged = !$this->valuesEqual($key, $eosValue, $baseValue);
                if ($symconChanged && !$eosChanged) {
                    $out['push'][] = $key;
                } elseif (!$symconChanged && $eosChanged) {
                    $out['eos'][] = $key;
                } else {
                    $out['conflict'][] = $key;
                }
            }
            return $out;
        }

        /** Canonical equality: tolerant numbers (below 1 W for powers), null equals an empty value. */
        private function valuesEqual(string $key, mixed $eos, mixed $ours): bool
        {
            $empty = static fn (mixed $v): bool => $v === null || $v === [] || $v === '' || (is_array($v) && array_key_exists('windows', $v) && $v['windows'] === []);
            if ($empty($eos) || $empty($ours)) {
                return $empty($eos) && $empty($ours);
            }
            if (str_ends_with($key, '_w') && is_numeric($eos) && is_numeric($ours)) {
                return abs((float) $eos - (float) $ours) < 1.0; // integer form fields vs. float in EOS (3680.5 W)
            }
            return $this->configEquals($eos, $ours);
        }

        /** Structural equality with EOS notation tolerance (extra EOS keys, .000000 times, ISO offsets). */
        private function configEquals(mixed $eos, mixed $ours): bool
        {
            if (is_array($ours)) {
                if (!is_array($eos)) {
                    return false;
                }
                if (array_is_list($ours)) {
                    if (!array_is_list($eos) || count($eos) !== count($ours)) {
                        return false;
                    }
                    foreach ($ours as $i => $item) {
                        if (!$this->configEquals($eos[$i], $item)) {
                            return false;
                        }
                    }
                    return true;
                }
                foreach ($ours as $k => $item) { // EOS adds defaults (day_of_week, date, locale): compare our keys only
                    if (!array_key_exists($k, $eos) || !$this->configEquals($eos[$k], $item)) {
                        return false;
                    }
                }
                return true;
            }
            if (is_int($ours) || is_float($ours)) {
                return is_numeric($eos) && abs((float) $eos - (float) $ours) < 1e-6;
            }
            if (is_string($ours) && is_string($eos)) {
                $iso = '/^\d{4}-\d\d-\d\dT\d\d:\d\d/';
                if (preg_match($iso, $ours) === 1 && preg_match($iso, $eos) === 1) {
                    return $this->eosParseTime($ours) === $this->eosParseTime($eos); // EOS normalises the notation
                }
                // Clock times: EOS stores 08:00 as 08:00:00.000000.
                $clock = static fn (string $t): string => preg_match('/^(\d{1,2}):(\d\d)(?::(\d\d)(?:\.\d+)?)?$/', trim($t), $m) === 1
                    ? sprintf('%02d:%02d:%02d', (int) $m[1], (int) $m[2], (int) ($m[3] ?? 0)) : trim($t);
                return $clock($ours) === $clock($eos);
            }
            return $eos === $ours || ($eos === null && $ours === null);
        }
    }
}
