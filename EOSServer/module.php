<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/EOSClient.php';
require_once __DIR__ . '/../libs/EOSCommon.php';
require_once __DIR__ . '/../libs/EOSConfigMapper.php';
require_once __DIR__ . '/../libs/EOSFormHelpers.php';
require_once __DIR__ . '/../libs/EOSMeasurementBundle.php';
require_once __DIR__ . '/../libs/EOSServerConfig.php';

/**
 * EOS Server: splitter that talks to an Akkudoktor-EOS instance.
 *
 * Polls health and the energy management plan, distributes the plan to the
 * device instances (battery, ...) and forwards their measurements to EOS.
 * The EOS configuration can be loaded into and written from the form.
 */
class EOSServer extends IPSModuleStrict
{
    use EOSCommon;
    use EOSConfigMapper;
    use EOSFormHelpers;
    use EOSMeasurementBundle;
    use EOSServerConfig;

    private const STATUS_INACTIVE = 104;
    private const STATUS_UNREACHABLE = 201;
    private const STATUS_VERSION = 202;
    private const STATUS_NO_PLAN = 203;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Host', 'host.docker.internal');
        $this->RegisterPropertyInteger('Port', 8503);
        $this->RegisterPropertyInteger('Timeout', 10);
        $this->RegisterPropertyInteger('HealthInterval', 60);
        $this->RegisterPropertyInteger('PlanRefreshInterval', 900);
        $this->RegisterPropertyString('ExpectedVersion', '0.4');
        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterConfigProperties();

        $this->RegisterAttributeString('LastPlan', '');
        $this->RegisterAttributeString('LastSolution', '');
        $this->RegisterAttributeString('PlanHash', '');
        $this->RegisterAttributeString('LastRunSeen', '');
        $this->RegisterAttributeString('EOSConfigCache', '');
        $this->RegisterAttributeString('SoCCache', '{}');

        $this->RegisterVariableBoolean('Connected', $this->Translate('Connected'), $this->eosBoolPresentation('Offline', 'Online', 0xFF0000, 0x00A000, 'Network'), 10);
        $this->RegisterVariableString('Version', $this->Translate('EOS version'), $this->eosValuePresentation('Information'), 20);
        $this->RegisterVariableInteger('LastRun', $this->Translate('Last run'), $this->eosDateTimePresentation(), 30);
        $this->RegisterVariableString('PlanID', $this->Translate('Plan ID'), $this->eosValuePresentation('Script'), 40);
        $this->RegisterVariableInteger('PlanValidUntil', $this->Translate('Plan valid until'), $this->eosDateTimePresentation(), 50);
        $this->RegisterVariableFloat('TotalCosts', $this->Translate('Total costs'), $this->eosValuePresentation('Euro', ' €', 2), 60);
        $this->RegisterVariableFloat('TotalRevenues', $this->Translate('Total revenues'), $this->eosValuePresentation('Euro', ' €', 2), 70);
        $this->RegisterVariableFloat('Balance', $this->Translate('Balance'), $this->eosValuePresentation('Euro', ' €', 2), 80);
        $this->RegisterVariableString('LastError', $this->Translate('Last error'), $this->eosValuePresentation('Warning'), 90);

        $this->RegisterTimer('HealthPoll', 0, 'EOS_PollHealth($_IPS[\'TARGET\']);');
        $this->RegisterTimer('PlanRefresh', 0, 'EOS_FetchPlan($_IPS[\'TARGET\']);');
        $this->RegisterTimer('OptimizeRun', 0, 'EOS_RunOptimizeNow($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetTimerInterval('HealthPoll', 0);
            $this->SetTimerInterval('PlanRefresh', 0);
            $this->SetValue('Connected', false);
            $this->SetStatus(self::STATUS_INACTIVE);
            return;
        }

        $this->SetTimerInterval('HealthPoll', $this->ReadPropertyInteger('HealthInterval') * 1000);
        $this->SetTimerInterval('PlanRefresh', $this->ReadPropertyInteger('PlanRefreshInterval') * 1000);

        $this->PollHealth();
        // Re-distribute the cached plan so children can rebuild their schedule after a restart,
        // and always send the status so children waiting for the server recover even without a plan.
        $this->BroadcastPlan(false);
        $this->broadcastStatus((bool) $this->GetValue('Connected'));
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        $config = $this->eosJsonDecode($this->ReadAttributeString('EOSConfigCache'), []);
        $providers = [
            'ElecPriceProvider' => 'elecprice',
            'ElecFeeProvider'   => 'elecfee',
            'FeedInProvider'    => 'feedintariff',
            'PvProvider'        => 'pvforecast',
            'LoadProvider'      => 'load',
            'WeatherProvider'   => 'weather',
        ];
        $this->formWalk($form['elements'], function (array &$el) use ($providers, $config): bool {
            $name = $el['name'] ?? '';
            if (($el['type'] ?? '') === 'Select' && isset($providers[$name])) {
                $el['options'] = $this->ProviderOptions(is_array($config) ? $config : [], $providers[$name]);
            }
            return false;
        });
        $client = $this->client();
        $this->formWalk($form['actions'], function (array &$el) use ($client): bool {
            if (($el['name'] ?? '') === 'DashboardLink') {
                $el['caption'] = 'EOSdash: ' . $client->dashboardUrl();
            } elseif (($el['name'] ?? '') === 'SwaggerLink') {
                $el['caption'] = 'API: ' . $client->swaggerUrl();
            }
            return false;
        });
        return json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ------------------------------------------------------------------ public API (prefix EOS_)

    public function TestConnection(): bool
    {
        $res = $this->client()->health();
        if ($res['ok'] && is_array($res['data'])) {
            $this->applyHealth($res['data']);
            $msg = sprintf($this->Translate('Connection OK: EOS %s, last run %s'), (string) ($res['data']['version'] ?? '?'), (string) ($res['data']['energy-management']['last_run_datetime'] ?? '-'));
            $this->UpdateFormField('TestResult', 'caption', $msg);
            return true;
        }
        $this->markUnreachable((string) $res['error']);
        $this->UpdateFormField('TestResult', 'caption', sprintf($this->Translate('Connection failed: %s'), (string) $res['error']));
        return false;
    }

    public function PollHealth(): void
    {
        $res = $this->client()->health();
        if (!$res['ok'] || !is_array($res['data'])) {
            $this->markUnreachable((string) $res['error']);
            return;
        }
        $this->applyHealth($res['data']);
        $lastRun = (string) ($res['data']['energy-management']['last_run_datetime'] ?? '');
        if ($lastRun !== '' && $lastRun !== $this->ReadAttributeString('LastRunSeen')) {
            $this->WriteAttributeString('LastRunSeen', $lastRun);
            $this->FetchPlan();
        }
    }

    public function FetchPlan(): bool
    {
        $client = $this->client();
        $res = $client->getPlan();
        if (!$res['ok'] || !is_array($res['data'])) {
            if ($res['status'] === 404) {
                $this->SetValue('LastError', (string) $res['error']);
                $this->SetStatus(self::STATUS_NO_PLAN);
            } elseif ($res['status'] === 0) {
                $this->markUnreachable((string) $res['error']);
            } else {
                $this->SetValue('LastError', (string) $res['error']);
            }
            return false;
        }

        $plan = $res['data'];
        $instructions = is_array($plan['instructions'] ?? null) ? $plan['instructions'] : [];
        $hash = md5(json_encode($instructions) . (string) ($plan['generated_at'] ?? ''));
        $changed = $hash !== $this->ReadAttributeString('PlanHash');

        $this->WriteAttributeString('LastPlan', json_encode($plan));
        $this->WriteAttributeString('PlanHash', $hash);
        $this->SetValue('PlanID', (string) ($plan['id'] ?? ''));
        $this->SetValue('PlanValidUntil', $this->eosParseTime($plan['valid_until'] ?? null));
        $this->SetValue('LastError', '');

        $sol = $client->getSolution();
        if ($sol['ok'] && is_array($sol['data'])) {
            $this->WriteAttributeString('LastSolution', json_encode($sol['data']));
            $costs = (float) ($sol['data']['total_costs_amt'] ?? 0);
            $rev = (float) ($sol['data']['total_revenues_amt'] ?? 0);
            $this->SetValue('TotalCosts', $costs);
            $this->SetValue('TotalRevenues', $rev);
            $this->SetValue('Balance', $rev - $costs);
        } else {
            $this->SendDebug('FetchPlan', 'solution: ' . (string) $sol['error'], 0);
        }

        $this->SetStatus(IS_ACTIVE);
        $this->SendDebug('FetchPlan', sprintf('plan %s, %d instructions, changed=%s', (string) ($plan['id'] ?? ''), count($instructions), $changed ? 'yes' : 'no'), 0);
        $this->BroadcastPlan(true);
        return true;
    }

    public function Optimize(): void
    {
        $this->UpdateFormField('TestResult', 'caption', $this->Translate('Optimization started, result follows in the debug log and in the plan variables.'));
        $this->SetTimerInterval('OptimizeRun', 1000);
    }

    public function RunOptimizeNow(): void
    {
        $this->SetTimerInterval('OptimizeRun', 0);
        $res = $this->client()->optimize();
        if ($res['ok']) {
            $this->SendDebug('Optimize', 'finished ok', 0);
            $this->SetValue('LastError', '');
            $this->FetchPlan();
        } else {
            $this->SetValue('LastError', 'optimize: ' . (string) $res['error']);
            $this->LogMessage('EOS optimize failed: ' . (string) $res['error'], KL_WARNING);
        }
    }











    public function GetDashboardURL(): string
    {
        return $this->client()->dashboardUrl();
    }

    public function GetSwaggerURL(): string
    {
        return $this->client()->swaggerUrl();
    }

    public function GetPlan(): string
    {
        return $this->ReadAttributeString('LastPlan');
    }

    public function GetSolution(): string
    {
        return $this->ReadAttributeString('LastSolution');
    }

    // ------------------------------------------------------------------ data flow with children

    public function ForwardData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || ($data['DataID'] ?? '') !== self::EOS_TX_GUID) {
            return json_encode(['ok' => false, 'error' => 'invalid DataID']);
        }
        $command = (string) ($data['Command'] ?? '');
        switch ($command) {
            case 'PutMeasurement':
                return json_encode($this->forwardPutMeasurement($data));

            case 'PutSamples':
                return json_encode($this->forwardPutSamples($data));

            case 'GetPlanForResource':
                return json_encode($this->planForResource((string) ($data['ResourceID'] ?? '')));

            case 'GetSolution':
                return json_encode($this->solutionColumns(is_array($data['Columns'] ?? null) ? $data['Columns'] : []));

            case 'GetStatus':
                return json_encode([
                    'ok'        => true,
                    'connected' => (bool) $this->GetValue('Connected'),
                    'version'   => (string) $this->GetValue('Version'),
                    'status'    => $this->GetStatus(),
                ]);

            case 'GetConfig':
            case 'SetConfig':
            case 'MergeConfig':
            case 'SaveConfig':
            case 'RemoveDevice':
                return json_encode($this->forwardConfigCommand($command, $data));

            default:
                return json_encode(['ok' => false, 'error' => 'unknown command ' . $command]);
        }
    }

    // ------------------------------------------------------------------ internals

    private function client(): EOSClient
    {
        return new EOSClient(
            $this->ReadPropertyString('Host'),
            $this->ReadPropertyInteger('Port'),
            $this->ReadPropertyInteger('Timeout'),
            function (string $tag, string $message): void {
                $this->SendDebug('EOSClient ' . $tag, $message, 0);
            }
        );
    }

    private function applyHealth(array $health): void
    {
        $version = (string) ($health['version'] ?? '');
        $wasConnected = (bool) $this->GetValue('Connected');
        $this->SetValue('Connected', true);
        $this->SetValue('Version', $version);
        $this->SetValue('LastRun', $this->eosParseTime($health['energy-management']['last_run_datetime'] ?? null));

        $expected = trim($this->ReadPropertyString('ExpectedVersion'));
        if ($expected !== '' && !str_starts_with($version, $expected)) {
            $this->SetValue('LastError', 'version ' . $version . ' != ' . $expected . '*');
            $this->SetStatus(self::STATUS_VERSION);
            return;
        }
        if ($this->GetStatus() !== self::STATUS_NO_PLAN) {
            $this->SetStatus(IS_ACTIVE);
        }
        if (!$wasConnected) {
            // Children that went to "no active server" re-run ApplyChanges on any event;
            // a plan may not exist yet (203), so tell them explicitly.
            $this->broadcastStatus(true);
        }
    }

    private function broadcastStatus(bool $connected): void
    {
        $this->SendDataToChildren(json_encode(['DataID' => self::EOS_RX_GUID, 'Event' => 'Status', 'Connected' => $connected]));
    }

    private function markUnreachable(string $error): void
    {
        $wasConnected = (bool) $this->GetValue('Connected');
        $this->SetValue('Connected', false);
        $this->SetValue('LastError', $error);
        $this->SetStatus(self::STATUS_UNREACHABLE);
        if ($wasConnected) {
            $this->LogMessage('EOS not reachable: ' . $error, KL_WARNING);
            $this->broadcastStatus(false);
        }
    }

    private function BroadcastPlan(bool $force): void
    {
        $plan = $this->eosJsonDecode($this->ReadAttributeString('LastPlan'), null);
        if (!is_array($plan)) {
            return;
        }
        $this->SendDataToChildren(json_encode([
            'DataID'       => self::EOS_RX_GUID,
            'Event'        => 'PlanUpdated',
            'Force'        => $force,
            'Plan'         => [
                'id'           => $plan['id'] ?? '',
                'generated_at' => $plan['generated_at'] ?? null,
                'valid_from'   => $plan['valid_from'] ?? null,
                'valid_until'  => $plan['valid_until'] ?? null,
            ],
            'Instructions' => is_array($plan['instructions'] ?? null) ? $plan['instructions'] : [],
            'Connected'    => (bool) $this->GetValue('Connected'),
        ]));
    }

    private function planForResource(string $resourceId): array
    {
        $plan = $this->eosJsonDecode($this->ReadAttributeString('LastPlan'), null);
        if (!is_array($plan)) {
            return ['ok' => false, 'error' => 'no plan cached'];
        }
        $mine = array_values(array_filter(
            is_array($plan['instructions'] ?? null) ? $plan['instructions'] : [],
            static fn (array $i): bool => (string) ($i['resource_id'] ?? '') === $resourceId
        ));
        return [
            'ok'           => true,
            'plan'         => [
                'id'           => $plan['id'] ?? '',
                'generated_at' => $plan['generated_at'] ?? null,
                'valid_from'   => $plan['valid_from'] ?? null,
                'valid_until'  => $plan['valid_until'] ?? null,
            ],
            'instructions' => $mine,
            'connected'    => (bool) $this->GetValue('Connected'),
        ];
    }

    private function solutionColumns(array $columns): array
    {
        $solution = $this->eosJsonDecode($this->ReadAttributeString('LastSolution'), null);
        if (!is_array($solution)) {
            return ['ok' => false, 'error' => 'no solution cached'];
        }
        $series = [];
        foreach (['solution', 'prediction'] as $frame) {
            $data = $solution[$frame]['data'] ?? null;
            if (!is_array($data)) {
                continue;
            }
            foreach ($data as $ts => $row) {
                if (!is_array($row)) {
                    continue;
                }
                foreach ($row as $col => $value) {
                    if ($columns === [] || in_array($col, $columns, true)) {
                        $series[$col][(string) $ts] = $value;
                    }
                }
            }
        }
        return ['ok' => true, 'tz' => $solution['solution']['tz'] ?? null, 'series' => $series];
    }
}
