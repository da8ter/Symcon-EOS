<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/EOSCommon.php';

/**
 * EOS Meter: feeds cumulative energy meter readings (load, grid import, grid
 * export, PV production) from Symcon variables into the EOS measurement store,
 * registers the measurement keys in the EOS configuration and can import the
 * history from the Symcon archive so the load forecast adapts immediately.
 */
class EOSMeter extends IPSModuleStrict
{
    use EOSCommon;

    private const MODULE_GUID = '{C3B9F1E2-8D47-4A6B-9E0F-5A1D2C3B4E56}';
    private const ARCHIVE_GUID = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
    private const STATUS_NO_PARENT = 104;
    private const STATUS_NO_METERS = 201;
    private const CATEGORY_FIELD = [
        'load'        => 'load_emr_keys',
        'grid_import' => 'grid_import_emr_keys',
        'grid_export' => 'grid_export_emr_keys',
        'pv'          => 'pv_production_emr_keys',
    ];

    public function GetCompatibleParents(): string
    {
        return json_encode(['type' => 'connect', 'moduleIDs' => [self::EOS_SERVER_GUID]]);
    }

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Meters', '[]');
        $this->RegisterPropertyInteger('PushInterval', 300);
        $this->RegisterPropertyInteger('HistoryHours', 48);
        $this->RegisterPropertyBoolean('PushOnChange', false);

        $this->RegisterAttributeString('RegisteredVars', '[]');
        $this->RegisterAttributeInteger('LastPushTs', 0);

        $this->RegisterVariableInteger('LastPush', $this->Translate('Last push'), $this->eosDateTimePresentation('Repeat'), 10);
        $this->RegisterVariableInteger('PushedValues', $this->Translate('Values sent (last push)'), $this->eosValuePresentation('Information'), 20);
        $this->RegisterVariableString('LastError', $this->Translate('Last error'), $this->eosValuePresentation('Warning'), 30);

        $this->RegisterTimer('MeterPush', 0, 'EOSMTR_Push($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        // (Re-)register change messages for all meter variables.
        foreach ($this->eosJsonDecode($this->ReadAttributeString('RegisteredVars'), []) ?: [] as $old) {
            $this->UnregisterMessage((int) $old, VM_UPDATE);
        }
        $registered = [];
        if ($this->ReadPropertyBoolean('PushOnChange')) {
            foreach ($this->meters() as $meter) {
                $this->RegisterMessage($meter['variable'], VM_UPDATE);
                $registered[] = $meter['variable'];
            }
        }
        $this->WriteAttributeString('RegisteredVars', json_encode($registered));

        $this->SetTimerInterval('MeterPush', 0);
        if ($this->meters() === []) {
            $this->SetStatus(self::STATUS_NO_METERS);
            return;
        }
        if (!$this->parentUsable()) {
            $this->SetStatus(self::STATUS_NO_PARENT);
            return;
        }
        $this->SetStatus(IS_ACTIVE);
        $this->SetTimerInterval('MeterPush', $this->ReadPropertyInteger('PushInterval') * 1000);
        $this->WriteKeysToEOS();
        $this->Push();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }
        if ($Message === VM_UPDATE && time() - $this->ReadAttributeInteger('LastPushTs') >= 30) {
            $this->Push();
        }
    }

    public function ReceiveData(string $JSONString): string
    {
        return '';
    }

    // ------------------------------------------------------------------ public API (prefix EOSMTR_)

    /** Send the current reading of every configured meter to EOS. */
    public function Push(): bool
    {
        if (!$this->parentUsable()) {
            return false;
        }
        $samples = [];
        $now = $this->eosIsoNow();
        foreach ($this->meters() as $meter) {
            $samples[] = ['date_time' => $now, 'key' => $meter['key'], 'value' => $this->readKwh($meter)];
        }
        if ($samples === []) {
            return false;
        }
        $res = $this->forward(['Command' => 'PutSamples', 'Samples' => $samples]);
        if (($res['ok'] ?? false) !== true) {
            $this->SetValue('LastError', (string) ($res['error'] ?? '?'));
            $this->UpdateFormField('ActionResult', 'caption', (string) ($res['error'] ?? '?'));
            return false;
        }
        $this->SetValue('LastError', '');
        $this->SetValue('LastPush', time());
        $this->SetValue('PushedValues', count($samples));
        $this->WriteAttributeInteger('LastPushTs', time());
        $this->UpdateFormField('ActionResult', 'caption', sprintf($this->Translate('%d meter readings sent.'), count($samples)));
        return true;
    }

    /** Register the configured keys in measurement.*_emr_keys of EOS (union with existing keys). */
    public function WriteKeysToEOS(): bool
    {
        if (!$this->parentUsable()) {
            return false;
        }
        $res = $this->forward(['Command' => 'GetConfig', 'Path' => 'measurement']);
        $current = is_array($res['data'] ?? null) ? $res['data'] : [];
        $merge = [];
        foreach (self::CATEGORY_FIELD as $category => $field) {
            $keys = is_array($current[$field] ?? null) ? $current[$field] : [];
            foreach ($this->meters() as $meter) {
                if ($meter['category'] === $category && !in_array($meter['key'], $keys, true)) {
                    $keys[] = $meter['key'];
                }
            }
            if ($keys !== ($current[$field] ?? [])) {
                $merge[$field] = array_values($keys);
            }
        }
        if ($merge === []) {
            return true;
        }
        $res = $this->forward(['Command' => 'MergeConfig', 'Value' => ['measurement' => $merge]]);
        if (($res['ok'] ?? false) !== true) {
            $this->SetValue('LastError', 'keys: ' . (string) ($res['error'] ?? '?'));
            return false;
        }
        $this->forward(['Command' => 'SaveConfig']);
        $this->SendDebug('WriteKeysToEOS', json_encode($merge), 0);
        return true;
    }

    /**
     * Import logged history of all meters from the Symcon archive into EOS.
     * Hours <= 0 uses the HistoryHours property.
     */
    public function ImportHistory(int $Hours): bool
    {
        if (!$this->parentUsable()) {
            return false;
        }
        $hours = $Hours > 0 ? $Hours : $this->ReadPropertyInteger('HistoryHours');
        $archives = IPS_GetInstanceListByModuleID(self::ARCHIVE_GUID);
        if ($archives === []) {
            $this->UpdateFormField('ActionResult', 'caption', $this->Translate('No archive control found.'));
            return false;
        }
        $archive = (int) $archives[0];
        $end = time();
        $start = $end - $hours * 3600;
        $total = 0;
        $ok = true;
        foreach ($this->meters() as $meter) {
            if (!AC_GetLoggingStatus($archive, $meter['variable'])) {
                $this->SendDebug('ImportHistory', 'variable ' . $meter['variable'] . ' is not logged', 0);
                continue;
            }
            $values = AC_GetLoggedValues($archive, $meter['variable'], $start, $end, 0);
            $samples = [];
            foreach ($values as $v) {
                $samples[] = [
                    'date_time' => $this->eosIsoNow((int) $v['TimeStamp']),
                    'key'       => $meter['key'],
                    'value'     => $this->toKwh((float) $v['Value'], $meter),
                ];
                if (count($samples) >= 5000) {
                    $ok = $this->sendSamples($samples) && $ok;
                    $total += count($samples);
                    $samples = [];
                }
            }
            if ($samples !== []) {
                $ok = $this->sendSamples($samples) && $ok;
                $total += count($samples);
            }
        }
        $msg = $ok ? sprintf($this->Translate('%d history values imported.'), $total) : (string) $this->GetValue('LastError');
        $this->UpdateFormField('ActionResult', 'caption', $msg);
        $this->LogMessage('EOS meter history import: ' . $msg, KL_NOTIFY);
        return $ok;
    }

    // ------------------------------------------------------------------ internals

    /** @return array<int, array{variable:int, key:string, category:string, unit:int}> */
    private function meters(): array
    {
        $rows = $this->eosJsonDecode($this->ReadPropertyString('Meters'), []);
        $meters = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $var = (int) ($row['variable'] ?? 0);
            $key = trim((string) ($row['key'] ?? ''));
            $category = (string) ($row['category'] ?? 'load');
            if ($var <= 0 || !IPS_VariableExists($var) || $key === '' || !isset(self::CATEGORY_FIELD[$category])) {
                continue;
            }
            $meters[] = ['variable' => $var, 'key' => $key, 'category' => $category, 'unit' => (int) ($row['unit'] ?? 0)];
        }
        return $meters;
    }

    private function readKwh(array $meter): float
    {
        return $this->toKwh((float) GetValue($meter['variable']), $meter);
    }

    private function toKwh(float $value, array $meter): float
    {
        return $meter['unit'] === 1 ? round($value / 1000.0, 4) : round($value, 4);
    }

    private function sendSamples(array $samples): bool
    {
        $res = $this->forward(['Command' => 'PutSamples', 'Samples' => $samples]);
        if (($res['ok'] ?? false) !== true) {
            $this->SetValue('LastError', 'import: ' . (string) ($res['error'] ?? '?'));
            return false;
        }
        return true;
    }

    private function parentUsable(): bool
    {
        $parentId = (int) IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parentId <= 0 || !IPS_InstanceExists($parentId)) {
            return false;
        }
        return in_array((int) IPS_GetInstance($parentId)['InstanceStatus'], [IS_ACTIVE, 202, 203], true);
    }

    private function forward(array $payload): array
    {
        $payload['DataID'] = self::EOS_TX_GUID;
        $decoded = json_decode((string) $this->SendDataToParent(json_encode($payload)), true);
        return is_array($decoded) ? $decoded : ['ok' => false, 'error' => 'no response from EOS Server'];
    }
}
