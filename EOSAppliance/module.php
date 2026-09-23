<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/EOSCommon.php';
require_once __DIR__ . '/../libs/EOSPlanDevice.php';
require_once __DIR__ . '/../libs/EOSControlBindings.php';
require_once __DIR__ . '/../libs/EOSFormHelpers.php';
require_once __DIR__ . '/../libs/EOSControl.php';
require_once __DIR__ . '/../libs/EOSControlDispatch.php';
require_once __DIR__ . '/../libs/EOSDeviceConfigSync.php';
require_once __DIR__ . '/../libs/EOSDeviceConfigForm.php';
require_once __DIR__ . '/../libs/EOSDeviceTimes.php';
require_once __DIR__ . '/../libs/EOSApplianceConfig.php';
require_once __DIR__ . '/../libs/EOSApplianceStart.php';

/**
 * EOS Appliance: a shiftable household appliance (dishwasher, washing machine,
 * dryer) known to EOS. Shows the planned start and the RUN/OFF instruction,
 * reports completed cycles, keeps the appliance parameters in the EOS
 * configuration and - with a control mode enabled - releases/starts the
 * appliance through vendor-neutral bindings (EOSControl).
 *
 * Starting is edge-triggered: a RUN instruction starts the appliance only within
 * the grace period after its planned start, and a start pulse locks further plan
 * starts for the run duration (EOS gives every re-plan new instruction ids, so an
 * id cannot tell "already started"). Manual "run" starts once on the edge.
 * Stopping is off by default (AllowStop).
 */
class EOSAppliance extends IPSModuleStrict
{
    use EOSCommon;
    use EOSPlanDevice;
    use EOSControlBindings;
    use EOSFormHelpers;
    use EOSControl, EOSApplianceStart {
        EOSApplianceStart::onManualModeChanged insteadof EOSControl;
        EOSApplianceStart::rowActionAllowed insteadof EOSControl;
        EOSApplianceStart::onDispatched insteadof EOSControl;
    }
    use EOSControlDispatch;
    use EOSDeviceConfigSync;
    use EOSDeviceConfigForm;
    use EOSDeviceTimes;
    use EOSApplianceConfig;

    private const MODULE_GUID = '{A7E2C4D9-3F61-4B8E-B2D5-6C9F0E1A7B34}';
    /** Device map in the EOS configuration; GENETIC supports only one battery and one vehicle. */
    private const DEVICE_COLLECTION = 'devices/home_appliances';
    private const SINGLE_DEVICE = false;
    /** Configuration properties sent to EOS, with their defaults (also the base of a first sync). */
    private const CONFIG_DEFAULTS = ['ConsumptionWh' => 2000, 'DurationH' => 3, 'NumCycles' => 1, 'ScheduleMode' => 'ONCE', 'TimeWindows' => '[]', 'MinCycleGapH' => 0, 'DeadlinePolicy' => 'BEST_EFFORT'];
    /** Stopped and released when the device id is invalid or not ours (blockDevice()). */
    private const BLOCK_TIMERS = ['CyclesPush', 'SlotTimer', 'Watchdog', 'Retry', 'TimesExpiry'];
    private const SOURCE_ATTRIBUTES = ['RegisteredDeadlineVar', 'RegisteredEarliestVar', 'RegisteredCyclesVar'];
    private const CONTROL_PREFIX = 'EOSHA';
    private const MODE_OFF = 0;
    private const MODE_RUN = 1;
    private const RUN_MODES = ['RUN', 'FORCED_RUN', 'RESUME'];
    private const KNOWN_MODES = ['RUN', 'OFF', 'IDLE', 'DEFER', 'PAUSE', 'RESUME', 'LIMIT_POWER', 'FORCED_RUN', 'FAULT'];

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('DeviceID', 'dishwasher1');
        $this->registerConfigProperties();
        $this->RegisterPropertyInteger('DeadlineSourceVariable', 0);
        $this->RegisterPropertyInteger('EarliestStartSourceVariable', 0);
        $this->RegisterPropertyInteger('CyclesCompletedSourceVariable', 0);
        $this->RegisterPropertyBoolean('SyncTimesToEOS', true);
        $this->RegisterPropertyInteger('StaleAfterMinutes', 180);
        $this->RegisterPropertyInteger('RunningSourceVariable', 0);
        $this->RegisterPropertyInteger('StartGraceMinutes', 30);
        $this->RegisterPropertyBoolean('AllowStop', false);
        $this->registerControlProperties(self::FALLBACK_NONE);

        $this->registerPlanAttributes();
        $this->RegisterAttributeInteger('RegisteredDeadlineVar', 0);
        $this->RegisterAttributeInteger('RegisteredEarliestVar', 0);
        $this->RegisterAttributeInteger('RegisteredCyclesVar', 0);
        $this->RegisterAttributeBoolean('TimesByScript', false);
        $this->RegisterAttributeString('PastDeadlineWarned', '');
        $this->RegisterAttributeString('StartedInstructionIds', '[]'); // before build 3; read once for migration
        $this->RegisterAttributeInteger('StartPulseTs', 0);
        $this->RegisterAttributeInteger('StartConfirmedTs', 0);
        $this->RegisterAttributeBoolean('ManualStartArmed', false);
        $this->RegisterAttributeString('StartRetriedFor', '');
        $this->RegisterAttributeString('MissedStartWarned', '');

        $this->RegisterVariableInteger('Mode', $this->Translate('Instruction'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS'      => json_encode([
                ['Value' => self::MODE_OFF, 'Caption' => $this->Translate('Off'), 'Icon' => 'Power', 'Color' => 0x9A9A94, 'IconActive' => true],
                ['Value' => self::MODE_RUN, 'Caption' => $this->Translate('Run'), 'Icon' => 'Execute', 'Color' => 0x008300, 'IconActive' => true],
                ['Value' => self::EOS_MODE_UNKNOWN, 'Caption' => $this->Translate('Unknown'), 'Icon' => 'Warning', 'Color' => 0x808080, 'IconActive' => true],
            ], JSON_UNESCAPED_UNICODE),
        ], 10);
        $this->RegisterVariableString('ModeRaw', $this->Translate('Instruction (raw)'), $this->eosValuePresentation('Information'), 20);
        $this->RegisterVariableBoolean('Running', $this->Translate('Run now'), $this->eosBoolPresentation('No', 'Yes', 0x808080, 0x008300, 'Execute'), 30);
        $this->RegisterVariableInteger('PlannedStart', $this->Translate('Planned start'), $this->eosDateTimePresentation('Clock'), 40);
        $this->RegisterVariableInteger('PlannedEnd', $this->Translate('Planned end'), $this->eosDateTimePresentation('Clock'), 50);
        $this->RegisterVariableInteger('Deadline', $this->Translate('Deadline'), $this->eosDateTimePresentation('Alert'), 60);
        $this->RegisterVariableInteger('CyclesCompleted', $this->Translate('Cycles completed today'), $this->eosValuePresentation('Repeat'), 70);
        $this->registerPlanVariables(80);
        $this->registerControlVariables(200);

        $this->RegisterTimer('SlotTimer', 0, 'EOSHA_ProcessPlan($_IPS[\'TARGET\']);');
        // EOS needs a completed-cycles value from the current day; keep it fresh.
        $this->RegisterTimer('CyclesPush', 0, 'EOSHA_PushCyclesCompleted($_IPS[\'TARGET\']);');
        // A passed deadline must leave EOS at once (a past STRICT deadline fails the optimization).
        $this->RegisterTimer('TimesExpiry', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'ReconcileTimes\', \'\');');
        $this->registerControlTimers();
        $this->registerFormFillTimer();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        $deviceId = $this->ReadPropertyString('DeviceID');
        // An id that is invalid or used by another instance blocks everything (see EOSBattery).
        if (!$this->validDeviceId($deviceId)) {
            $this->blockDevice(self::STATUS_BAD_DEVICE_ID);
            return;
        }
        if ($this->isDuplicateDeviceId($deviceId)) {
            $this->blockDevice(self::STATUS_DUPLICATE_ID);
            return;
        }
        $this->SetStatus(IS_ACTIVE);
        $this->setupControl();
        $this->registerOptionalSource('DeadlineSourceVariable', 'RegisteredDeadlineVar');
        $this->registerOptionalSource('EarliestStartSourceVariable', 'RegisteredEarliestVar');
        $this->registerOptionalSource('CyclesCompletedSourceVariable', 'RegisteredCyclesVar');
        $this->SetTimerInterval('CyclesPush', 0);

        if (!$this->parentUsable()) {
            $this->SetStatus(self::STATUS_NO_PARENT);
            return;
        }

        $this->SetStatus(IS_ACTIVE);
        $this->SetTimerInterval('CyclesPush', 900 * 1000);
        [$path, $device, $merge] = $this->deviceConfig();
        $this->syncDeviceConfig($path, $device, $merge, false);
        $this->syncTimes();
        $this->sendCyclesCompleted();
        $this->RefreshPlan();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }
        if ($Message !== VM_UPDATE || $SenderID <= 0) {
            return;
        }
        if ($SenderID === $this->ReadPropertyInteger('DeadlineSourceVariable') || $SenderID === $this->ReadPropertyInteger('EarliestStartSourceVariable')) {
            $this->syncTimes();
        } elseif ($SenderID === $this->ReadPropertyInteger('CyclesCompletedSourceVariable') && ($Data === [] || !array_key_exists(1, $Data) || $Data[1] === true)) {
            $this->sendCyclesCompleted(); // only when the count changed; the CyclesPush timer keeps it fresh
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'ReconcileTimes') {
            $this->reconcileTimes();
            return;
        }
        if (!$this->handleControlAction($Ident, $Value)) {
            throw new Exception('Invalid ident: ' . $Ident);
        }
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        $this->fillModeMap($form);
        [$path, $device] = $this->deviceConfig();
        $this->setFormAttribute($form['elements'], 'ConfigInfo', 'caption', $this->eosConfigSummary($path, $device));
        $this->armFormFillIfDiffers($path, $device);
        $this->fillDevicePicker($form);
        return json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ------------------------------------------------------------------ public API (prefix EOSHA_)

    /** Set the deadline (unix timestamp, 0 = none) and write it to EOS. */
    public function SetDeadline(int $Timestamp): bool
    {
        $this->SetValue('Deadline', max(0, $Timestamp));
        $this->WriteAttributeBoolean('TimesByScript', true);
        return $this->reconcileTimes();
    }

    public function PushCyclesCompleted(): bool
    {
        return $this->sendCyclesCompleted();
    }

    public function Dispatch(): void
    {
        $this->runDispatch();
    }

    public function Watchdog(): void
    {
        $this->runWatchdog();
    }

    public function ApplyControl(bool $Force): bool
    {
        return $this->applyControlNow($Force);
    }

    /** Manual override: 0 = off, 1 = run now, 100 = automatic. */
    public function SetManualMode(int $Mode): void
    {
        $this->changeManualMode($Mode);
    }

    public function GetControlState(): string
    {
        return json_encode($this->controlState(), JSON_UNESCAPED_UNICODE);
    }

    // ------------------------------------------------------------------ plan hooks (display)

    protected function showInstruction(array $instruction): void
    {
        $modeId = strtoupper((string) ($instruction['operation_mode_id'] ?? ''));
        $run = in_array($modeId, self::RUN_MODES, true);
        $known = in_array($modeId, self::KNOWN_MODES, true);
        if (!$known && $modeId !== (string) $this->GetValue('ModeRaw')) {
            $this->LogMessage(sprintf($this->Translate('Unknown operation mode %s'), $modeId), KL_WARNING);
        }
        $this->SetValue('Mode', $known ? ($run ? self::MODE_RUN : self::MODE_OFF) : self::EOS_MODE_UNKNOWN);
        $this->SetValue('ModeRaw', $modeId);
        $this->SetValue('Running', $run);
        $this->updatePlannedStart($run ? (int) $instruction['ts'] : null);
    }

    protected function showNoInstruction(): void
    {
        $this->SetValue('Mode', self::EOS_MODE_UNKNOWN);
        $this->SetValue('ModeRaw', '');
        $this->SetValue('Running', false);
        $this->SetValue('PlannedStart', 0);
        $this->SetValue('PlannedEnd', 0);
    }

    protected function onPlanProcessed(?array $active): void
    {
        if ($active === null) {
            $this->updatePlannedStart(null);
        }
        $this->scheduleControl($active, 'plan');
    }

    // ------------------------------------------------------------------ control hooks

    protected function controlTargets(): array
    {
        return ['Enable' => ['property' => 'TargetEnableVariable']];
    }

    /** Something must be able to start the appliance (K17): enable target, RUN action, or action/script on change. */
    protected function validateControlDevice(): string
    {
        $canStart = $this->ReadPropertyInteger('TargetEnableVariable') > 0
            || $this->normalizeActionJson($this->ReadPropertyString('ModeAction_RUN')) !== ''
            || $this->normalizeActionJson($this->ReadPropertyString('ChangeAction')) !== ''
            || $this->ReadPropertyInteger('ControlScript') > 0;
        return $canStart ? '' : $this->Translate('no binding that can start the appliance');
    }

    protected function modeMapRows(): array
    {
        return [['mode' => 'RUN', 'caption' => 'Run'], ['mode' => 'OFF', 'caption' => 'Off']];
    }

    protected function manualModeOptions(): array
    {
        return [
            ['Value' => self::MODE_OFF, 'Caption' => $this->Translate('Off'), 'Icon' => 'Power', 'Color' => 0x9A9A94, 'IconActive' => true],
            ['Value' => self::MODE_RUN, 'Caption' => $this->Translate('Run'), 'Icon' => 'Execute', 'Color' => 0x008300, 'IconActive' => true],
        ];
    }

    protected function desiredFallback(): ?array
    {
        $fallback = $this->ReadPropertyInteger('FallbackMode');
        if ($fallback === self::FALLBACK_NONE) {
            return null;
        }
        // Release = allow to run without triggering a start pulse.
        return $this->desiredAppliance($fallback === self::MODE_RUN, false, '', '', '');
    }

    // ------------------------------------------------------------------ internals

    /** Planned start = current RUN start, otherwise the first future RUN instruction. */
    private function updatePlannedStart(?int $activeRunTs): void
    {
        $start = $activeRunTs;
        if ($start === null) {
            $now = $this->eosNow();
            foreach ($this->instructionList() as $i) {
                if ((int) $i['ts'] > $now && strtoupper((string) ($i['operation_mode_id'] ?? '')) === 'RUN') {
                    $start = (int) $i['ts'];
                    break;
                }
            }
        }
        $this->SetValue('PlannedStart', $start ?? 0);
        $this->SetValue('PlannedEnd', $start !== null ? $start + $this->ReadPropertyInteger('DurationH') * 3600 : 0);
    }

}
