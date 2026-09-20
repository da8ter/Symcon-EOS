<?php

declare(strict_types=1);

/*
 * REST client for Akkudoktor-EOS (v0.4.x API).
 *
 * Plain PHP without any Symcon dependency so it can be exercised from the CLI.
 * All modules run in the same PHP worker, hence the class_exists guard.
 *
 * Every call returns the same shape:
 *   ['ok' => bool, 'status' => int, 'data' => mixed, 'error' => ?string]
 * where `error` carries the `detail` field of an EOS problem response if present.
 */
if (!class_exists('EOSClient')) {
    class EOSClient
    {
        public const DEFAULT_TIMEOUT = 10;
        public const OPTIMIZE_TIMEOUT = 600;

        private string $baseUrl;
        private int $timeout;
        /** @var callable|null fn(string $tag, string $message): void */
        private $debug;

        public function __construct(string $host, int $port, int $timeoutSec = self::DEFAULT_TIMEOUT, ?callable $debug = null)
        {
            $host = trim($host);
            if ($host === '') {
                $host = '127.0.0.1';
            }
            $scheme = 'http://';
            if (str_starts_with($host, 'http://') || str_starts_with($host, 'https://')) {
                $scheme = '';
            }
            $this->baseUrl = rtrim($scheme . $host, '/') . ':' . $port;
            $this->timeout = max(1, $timeoutSec);
            $this->debug = $debug;
        }

        public function baseUrl(): string
        {
            return $this->baseUrl;
        }

        public function dashboardUrl(int $dashPort = 8504): string
        {
            $url = preg_replace('/:\d+$/', '', $this->baseUrl);
            return $url . ':' . $dashPort . '/';
        }

        public function swaggerUrl(): string
        {
            return $this->baseUrl . '/docs';
        }

        // ---------------------------------------------------------------- health / plan

        public function health(): array
        {
            return $this->request('GET', '/v1/health');
        }

        public function getPlan(): array
        {
            return $this->request('GET', '/v1/energy-management/plan');
        }

        public function getSolution(): array
        {
            return $this->request('GET', '/v1/energy-management/optimization/solution');
        }

        public function getSolutionNative(string $algorithm = 'GENETIC'): array
        {
            return $this->request('GET', '/v1/energy-management/optimization/solution/' . rawurlencode($algorithm));
        }

        public function optimize(): array
        {
            return $this->request('POST', '/v1/optimize', [], new stdClass(), self::OPTIMIZE_TIMEOUT);
        }

        // ---------------------------------------------------------------- measurements

        public function putMeasurementValue(string $key, float $value, string $isoDatetime): array
        {
            return $this->request('PUT', '/v1/measurement/value', [
                'datetime' => $isoDatetime,
                'key'      => $key,
                'value'    => $value,
            ]);
        }

        /**
         * Batch upload: {"start_datetime": ..., "interval": "15 minutes", "<key>": [..], ...}
         */
        public function putMeasurementData(array $data): array
        {
            return $this->request('PUT', '/v1/measurement/data', [], $data);
        }

        public function getMeasurementSeries(string $key, ?string $interval = null): array
        {
            $query = ['key' => $key];
            if ($interval !== null) {
                $query['interval'] = $interval;
            }
            return $this->request('GET', '/v1/measurement/series', $query);
        }

        public function getMeasurementKeys(): array
        {
            return $this->request('GET', '/v1/measurement/keys');
        }

        // ---------------------------------------------------------------- predictions

        public function getPredictionSeries(string $key, ?string $interval = null): array
        {
            $query = ['key' => $key];
            if ($interval !== null) {
                $query['interval'] = $interval;
            }
            return $this->request('GET', '/v1/prediction/series', $query);
        }

        public function getPredictionKeys(): array
        {
            return $this->request('GET', '/v1/prediction/keys');
        }

        public function updatePredictions(bool $force = false): array
        {
            return $this->request('POST', '/v1/prediction/update', $force ? ['force_update' => 'true'] : [], null, 300);
        }

        public function importPrediction(string $providerId, array $data): array
        {
            return $this->request('PUT', '/v1/prediction/import/' . rawurlencode($providerId), [], $data);
        }

        // ---------------------------------------------------------------- configuration

        public function getConfig(): array
        {
            return $this->request('GET', '/v1/config');
        }

        public function getConfigPath(string $path): array
        {
            return $this->request('GET', '/v1/config/' . $this->encodePath($path));
        }

        /** Merge a partial settings object into the running configuration. */
        public function putConfig(array $merge): array
        {
            return $this->request('PUT', '/v1/config', [], $merge);
        }

        public function putConfigPath(string $path, mixed $value): array
        {
            return $this->request('PUT', '/v1/config/' . $this->encodePath($path), [], $value, null, true);
        }

        public function saveConfigFile(): array
        {
            return $this->request('PUT', '/v1/config/file');
        }

        public function resetConfig(): array
        {
            return $this->request('POST', '/v1/config/reset');
        }

        // ---------------------------------------------------------------- core

        /**
         * @param mixed $body      Encoded as JSON when not null. Pass new stdClass() for `{}`.
         * @param bool  $rawValue  When true the body is a bare JSON value (config path PUT), not an object.
         */
        public function request(string $method, string $path, array $query = [], mixed $body = null, ?int $timeout = null, bool $rawValue = false): array
        {
            $url = $this->baseUrl . $path;
            if ($query !== []) {
                $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            }

            $headers = ['Accept: application/json'];
            $payload = null;
            if ($body !== null || $rawValue) {
                $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($payload === false) {
                    return $this->result(false, 0, null, 'JSON encode failed: ' . json_last_error_msg());
                }
                $headers[] = 'Content-Type: application/json';
            }

            $this->log('request', $method . ' ' . $url . ($payload !== null ? ' body=' . $this->shorten($payload) : ''));

            $ch = curl_init($url);
            if ($ch === false) {
                return $this->result(false, 0, null, 'curl_init failed');
            }
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_CONNECTTIMEOUT => min(5, $this->timeout),
                CURLOPT_TIMEOUT        => $timeout ?? $this->timeout,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }

            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $curlError = curl_error($ch);
            unset($ch);

            if ($raw === false) {
                $this->log('error', $method . ' ' . $path . ': ' . $curlError);
                return $this->result(false, 0, null, $curlError !== '' ? $curlError : 'connection failed');
            }

            $data = null;
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                $data = json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
            }

            if ($status < 200 || $status >= 300) {
                $error = 'HTTP ' . $status;
                if (is_array($data)) {
                    $detail = $data['detail'] ?? ($data['title'] ?? null);
                    if (is_array($detail)) {
                        $detail = json_encode($detail, JSON_UNESCAPED_UNICODE);
                    }
                    if (is_string($detail) && $detail !== '') {
                        $error .= ': ' . $detail;
                    }
                } elseif (is_string($data) && $data !== '') {
                    $error .= ': ' . $this->shorten($data);
                }
                $this->log('error', $method . ' ' . $path . ' -> ' . $error);
                return $this->result(false, $status, $data, $error);
            }

            $this->log('response', $method . ' ' . $path . ' -> ' . $status);
            return $this->result(true, $status, $data, null);
        }

        private function encodePath(string $path): string
        {
            $parts = array_filter(explode('/', trim(str_replace('.', '/', $path), '/')), static fn (string $p): bool => $p !== '');
            return implode('/', array_map('rawurlencode', $parts));
        }

        private function result(bool $ok, int $status, mixed $data, ?string $error): array
        {
            return ['ok' => $ok, 'status' => $status, 'data' => $data, 'error' => $error];
        }

        private function log(string $tag, string $message): void
        {
            if ($this->debug !== null) {
                ($this->debug)($tag, $message);
            }
        }

        private function shorten(string $text, int $max = 300): string
        {
            return strlen($text) > $max ? substr($text, 0, $max) . '…' : $text;
        }
    }
}
