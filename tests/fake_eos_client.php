<?php

declare(strict_types=1);

/*
 * FakeEOSClient: replaces libs/EOSClient.php (load this file first; the class_exists
 * guard there then skips the real curl client). Every EOSClient instance talks to one
 * shared FakeEOSBackend (tests/fake_eos_backend.php), the model of Akkudoktor-EOS.
 */

require_once __DIR__ . '/fake_eos_backend.php';

if (!class_exists('EOSClient')) {
    /** Same public surface as libs/EOSClient.php; all instances share $GLOBALS['eosBackend']. */
    final class EOSClient
    {
        public const DEFAULT_TIMEOUT = 10;
        public const OPTIMIZE_TIMEOUT = 600;

        public function __construct(private string $host = '127.0.0.1', private int $port = 8503, private int $timeoutSec = self::DEFAULT_TIMEOUT, private $debug = null)
        {
        }

        private function b(): FakeEOSBackend
        {
            return $GLOBALS['eosBackend'];
        }

        public function baseUrl(): string { return 'http://' . $this->host . ':' . $this->port; }
        public function dashboardUrl(int $dashPort = 8504): string { return 'http://' . $this->host . ':' . $dashPort; }
        public function swaggerUrl(): string { return $this->baseUrl() . '/docs'; }
        public function health(): array { return $this->b()->health(); }
        public function getPlan(): array { return $this->b()->getPlan(); }
        public function getSolution(): array { return $this->b()->getSolution(); }
        public function getSolutionNative(string $algorithm = 'GENETIC'): array { return $this->b()->getSolution(); }
        public function optimize(int $timeoutSec = self::OPTIMIZE_TIMEOUT): array { return $this->b()->optimize($timeoutSec); }
        public function getConfigRaw(string $path): array { return $this->b()->getConfigRaw($path); }
        public function putMeasurementValue(string $key, float $value, string $isoDatetime): array { return $this->b()->putMeasurementValue($key, $value, $isoDatetime); }
        public function putMeasurementData(array $data): array { return $this->b()->putMeasurementData($data); }
        public function putMeasurementSamples(array $samples): array { return $this->b()->putMeasurementSamples($samples); }
        public function getMeasurementKeys(): array { return $this->b()->getMeasurementKeys(); }
        public function getConfig(): array { return $this->b()->getConfig(); }
        public function getConfigPath(string $path): array { return $this->b()->getConfigPath($path); }
        public function putConfig(array|object $merge): array { return $this->b()->putConfig($merge); }
        public function putConfigPath(string $path, mixed $value): array { return $this->b()->putConfigPath($path, $value); }
        public function saveConfigFile(): array { return $this->b()->saveConfigFile(); }
        public function resetConfig(): array { return $this->b()->resetConfig(); }
    }
}

$GLOBALS['eosBackend'] = new FakeEOSBackend();

/** The real EOSServer module as instance 2000, created on first use. */
function realServer(): EOSServer
{
    $server = $GLOBALS['objects'][2000] ?? null;
    if ($server instanceof EOSServer) {
        return $server;
    }
    $GLOBALS['instances'][2000] = ['ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE];
    $server = new EOSServer(2000);
    $server->Create();
    $server->ApplyChanges();
    return $server;
}

/** Device instance connected to the real EOSServer (which talks to FakeEOSBackend). */
function connectToRealServer(IPSModuleStrict $m): EOSServer
{
    $server = realServer();
    $GLOBALS['instances'][$m->InstanceID] = ['ConnectionID' => 2000, 'InstanceStatus' => IS_ACTIVE];
    return $server;
}
