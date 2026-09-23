<?php

declare(strict_types=1);

/*
 * Shared helpers for all Symcon-EOS modules: data-flow GUIDs, operation-mode
 * tables, ISO-8601 time handling and presentation builders.
 */
if (!class_exists('EOSClock')) {
    /** Single time source of the library. Symcon never sets it; the test bench pins it. */
    final class EOSClock
    {
        public static ?int $now = null;
    }
}

if (!trait_exists('EOSCommon')) {
    trait EOSCommon
    {
        /** Child -> Server (ForwardData). Server: implemented, devices: parentRequirements. */
        public const EOS_TX_GUID = '{8C2B1A5E-2D35-4723-8EEA-6B71A7B2428F}';
        /** Server -> Child (ReceiveData). Server: childRequirements, devices: implemented. */
        public const EOS_RX_GUID = '{EAB78E68-BFF7-4608-BA75-9ECDB5165208}';
        /** Module GUID of the EOS Server splitter. */
        public const EOS_SERVER_GUID = '{8042C326-0C4E-400A-9973-8AF1F9E59E71}';

        public const EOS_MODE_UNKNOWN = 99;

        /**
         * Battery operation modes emitted by the GENETIC planner (geneticsolution.py,
         * _battery_operation_from_solution) mapped to Symcon enumeration values.
         */
        public const BATTERY_MODES = [
            'IDLE'                => ['value' => 0, 'discharge' => false, 'grid' => false, 'chargeFromFactor' => false, 'color' => 0x9A9A94, 'icon' => 'Sleep'],
            'SELF_CONSUMPTION'    => ['value' => 1, 'discharge' => true,  'grid' => false, 'chargeFromFactor' => false, 'color' => 0x008300, 'icon' => 'Sun'],
            'NON_EXPORT'          => ['value' => 2, 'discharge' => false, 'grid' => false, 'chargeFromFactor' => false, 'color' => 0x2A78D6, 'icon' => 'Battery'],
            'PEAK_SHAVING'        => ['value' => 3, 'discharge' => true,  'grid' => false, 'chargeFromFactor' => false, 'color' => 0xEB6834, 'icon' => 'HollowArrowDown'],
            'GRID_SUPPORT_IMPORT' => ['value' => 4, 'discharge' => false, 'grid' => true,  'chargeFromFactor' => true,  'color' => 0x4A3AA7, 'icon' => 'Plug'],
            'FORCED_CHARGE'       => ['value' => 5, 'discharge' => false, 'grid' => true,  'chargeFromFactor' => true,  'color' => 0xE34948, 'icon' => 'Lightning'],
            'GRID_SUPPORT_EXPORT' => ['value' => 6, 'discharge' => true,  'grid' => false, 'chargeFromFactor' => false, 'color' => 0x1BAF7A, 'icon' => 'HollowArrowUp'],
        ];

        /** Captions in English; translated via locale.json. */
        public const BATTERY_MODE_CAPTIONS = [
            'IDLE'                => 'Locked',
            'SELF_CONSUMPTION'    => 'Self consumption',
            'NON_EXPORT'          => 'PV charging only',
            'PEAK_SHAVING'        => 'Discharge only',
            'GRID_SUPPORT_IMPORT' => 'Grid charging',
            'FORCED_CHARGE'       => 'Forced charging',
            'GRID_SUPPORT_EXPORT' => 'Grid export',
        ];

        protected function eosBatteryMode(string $modeId): array
        {
            $key = strtoupper(trim($modeId));
            if (isset(self::BATTERY_MODES[$key])) {
                return self::BATTERY_MODES[$key] + ['id' => $key, 'known' => true];
            }
            return ['value' => self::EOS_MODE_UNKNOWN, 'discharge' => true, 'grid' => false, 'chargeFromFactor' => false, 'color' => 0x808080, 'icon' => 'Warning', 'id' => $key, 'known' => false];
        }

        /** EOS mode id for a Symcon enumeration value, null for unknown values. */
        protected function eosBatteryModeId(int $value): ?string
        {
            foreach (self::BATTERY_MODES as $id => $mode) {
                if ($mode['value'] === $value) {
                    return $id;
                }
            }
            return null;
        }

        protected function eosBatteryModeOptions(): array
        {
            $options = [];
            foreach (self::BATTERY_MODES as $id => $mode) {
                $options[] = [
                    'Value'      => $mode['value'],
                    'Caption'    => $this->Translate(self::BATTERY_MODE_CAPTIONS[$id]),
                    'Icon'       => $mode['icon'],
                    'Color'      => $mode['color'],
                    'IconActive' => true,
                ];
            }
            $options[] = ['Value' => self::EOS_MODE_UNKNOWN, 'Caption' => $this->Translate('Unknown'), 'Icon' => 'Warning', 'Color' => 0x808080, 'IconActive' => true];
            return $options;
        }

        // ---------------------------------------------------------------- time helpers

        /** Current unix time; every time decision of the library goes through here. */
        protected function eosNow(): int
        {
            return EOSClock::$now ?? time();
        }

        /** Parse an ISO-8601 string with offset (as EOS sends it) into a unix timestamp; 0 on failure. */
        protected function eosParseTime(?string $iso): int
        {
            if ($iso === null || trim($iso) === '') {
                return 0;
            }
            try {
                return (new DateTimeImmutable($iso))->getTimestamp();
            } catch (\Throwable $e) {
                return 0;
            }
        }

        /** Current time as ISO-8601 with offset, e.g. 2026-09-20T09:30:00+02:00. */
        protected function eosIsoNow(?int $timestamp = null): string
        {
            $dt = new DateTimeImmutable('@' . ($timestamp ?? $this->eosNow()));
            return $dt->setTimezone(new DateTimeZone(date_default_timezone_get()))->format(DATE_ATOM);
        }

        // ---------------------------------------------------------------- presentation builders

        protected function eosBoolPresentation(string $captionFalse, string $captionTrue, int $colorFalse = 0x808080, int $colorTrue = 0x00A000, string $icon = ''): array
        {
            $option = static function (bool $value, string $caption, int $color): array {
                return [
                    'Value'              => $value,
                    'Caption'            => $caption,
                    'IconActive'         => false,
                    'IconValue'          => '',
                    'ColorActive'        => true,
                    'ColorValue'         => $color,
                    'ContentColorActive' => false,
                    'ContentColorValue'  => -1,
                ];
            };
            $presentation = [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'OPTIONS'      => json_encode([
                    $option(false, $this->Translate($captionFalse), $colorFalse),
                    $option(true, $this->Translate($captionTrue), $colorTrue),
                ], JSON_UNESCAPED_UNICODE),
            ];
            if ($icon !== '') {
                $presentation['ICON'] = $icon;
            }
            return $presentation;
        }

        protected function eosValuePresentation(string $icon = '', string $suffix = '', int $digits = 0): array
        {
            $presentation = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
            if ($icon !== '') {
                $presentation['ICON'] = $icon;
            }
            if ($suffix !== '') {
                $presentation['SUFFIX'] = $suffix;
            }
            $presentation['DIGITS'] = $digits;
            return $presentation;
        }

        protected function eosDateTimePresentation(string $icon = 'Clock'): array
        {
            return ['PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME, 'ICON' => $icon];
        }

        // ---------------------------------------------------------------- misc

        protected function eosJsonDecode(string $json, mixed $default = []): mixed
        {
            if ($json === '') {
                return $default;
            }
            $decoded = json_decode($json, true);
            return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
        }

        protected function eosShorten(string $text, int $max = 200): string
        {
            return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '…' : $text;
        }
    }
}
