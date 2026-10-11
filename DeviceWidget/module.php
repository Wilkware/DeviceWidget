<?php

declare(strict_types=1);
/** Generell funktions */
require_once __DIR__ . '/../libs/_traits.php';

/** Namespaced traits */
use Wilkware\DeviceWidget\DebugHelper;
use Wilkware\DeviceWidget\FormatHelper;

/**
 *  CLASS DeviceWidget
 */
class DeviceWidget extends IPSModuleStrict
{
    // -------------------------------------------------------------------------
    // Traits
    // -------------------------------------------------------------------------

    use DebugHelper;
    use FormatHelper;

    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    /** @var int Min IPS Object ID */
    private const IPS_MIN_ID = 10000;

    /** @var int Instance status: at least one selected variable or media object does not exist */
    private const STATUS_OBJECT_MISSING = 201;

    /** @var array<string,string[]> Property name to result mapping */
    private const DW_VARS2RES = [
        'OfflineVariable'   => ['offline'],
        'SwitchVariable'    => ['switchstate'],
        'StatusVariable'    => ['statetext', 'statevalue'],
        'ActionVariable'    => ['actiontext'],
        'ProgressVariable'  => ['progresstext', 'progressvalue'],
        'ProgressTerm'      => ['progressterm'],
        'AddVariableFirst'  => ['info1text'],
        'AddVariableSecond' => ['info2text'],
        'AddVariableThird'  => ['info3text'],
    ];

    // -------------------------------------------------------------------------
    // Methods
    // -------------------------------------------------------------------------

    /**
     * In contrast to Construct, this function is called only once when creating the instance and starting Symcon.
     * Therefore, status variables and module properties which the module requires permanently should be created here.
     *
     * @return void
     */
    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        // Tile
        $this->RegisterPropertyInteger('TileColor', -1);
        $this->RegisterPropertyInteger('TileTransparency', 100);
        $this->RegisterPropertyInteger('TileRatio', 40);

        // Image
        $this->RegisterPropertyInteger('StatusImageOn', 1);
        $this->RegisterPropertyInteger('StatusImageOff', 1);

        // Availability
        $this->RegisterPropertyInteger('OfflineVariable', 1);
        $this->RegisterPropertyString('OnlineValue', 'true');
        $this->RegisterPropertyInteger('OfflineColor', $this->GetColorUnformatted('#E53935'));

        // Switch
        $this->RegisterPropertyInteger('SwitchVariable', 1);
        $this->RegisterPropertyInteger('SwitchType', 0);
        $this->RegisterPropertyString('SwitchOn', 'true');
        $this->RegisterPropertyString('SwitchOff', 'false');

        // Info:Status
        $this->RegisterPropertyString('StatusLabel', 'STATUS');
        $this->RegisterPropertyInteger('StatusVariable', 1);
        $this->RegisterPropertyInteger('StatusFont', 14);
        $this->RegisterPropertyString('StatusProfile', '[]');

        // Info:Action
        $this->RegisterPropertyString('ActionLabel', 'ACTION');
        $this->RegisterPropertyInteger('ActionVariable', 1);
        $this->RegisterPropertyInteger('ActionFont', 14);

        // Info:Progress bar
        $this->RegisterPropertyString('ProgressLabel', '');
        $this->RegisterPropertyInteger('ProgressVariable', 1);
        $this->RegisterPropertyInteger('ProgressFont', 14);
        $this->RegisterPropertyInteger('ProgressTerm', 1);
        $this->RegisterPropertyInteger('ProgressStart', $this->GetColorUnformatted('#FFA405'));
        $this->RegisterPropertyInteger('ProgressStop', $this->GetColorUnformatted('#F9722B'));

        // Info:Additional
        $this->RegisterPropertyString('AddSymbolFirst', '');
        $this->RegisterPropertyString('AddPrefixFirst', '');
        $this->RegisterPropertyInteger('AddVariableFirst', 1);
        $this->RegisterPropertyString('AddSuffixFirst', '');
        $this->RegisterPropertyInteger('AddFontFirst', 12);
        $this->RegisterPropertyString('AddSymbolSecond', '');
        $this->RegisterPropertyString('AddPrefixSecond', '');
        $this->RegisterPropertyInteger('AddVariableSecond', 1);
        $this->RegisterPropertyString('AddSuffixSecond', '');
        $this->RegisterPropertyInteger('AddFontSecond', 12);
        $this->RegisterPropertyString('AddSymbolThird', '');
        $this->RegisterPropertyString('AddPrefixThird', '');
        $this->RegisterPropertyInteger('AddVariableThird', 1);
        $this->RegisterPropertyString('AddSuffixThird', '');
        $this->RegisterPropertyInteger('AddFontThird', 12);

        // Set visualization type to 1, as we want to offer HTML
        $this->SetVisualizationType(1);
    }

    /**
     * This function is called when deleting the instance during operation and when updating via "Module Control".
     * The function is not called when exiting Symcon.
     *
     * @return void
     */
    public function Destroy(): void
    {
        parent::Destroy();
    }

    /**
     * Is executed when "Apply" is pressed on the configuration page and immediately after the instance has been created.
     *
     * @return void
     */
    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Other objects may not be available yet, wait until kernel is ready
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        // Delete all references in order to readd them
        foreach ($this->GetReferenceList() as $referenceID) {
            $this->UnregisterReference($referenceID);
        }

        // Delete all registrations in order to readd them
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        // Register all references and messages, missing objects only mark the status
        $missing = false;
        $ids = [
            $this->ReadPropertyInteger('StatusImageOn'),
            $this->ReadPropertyInteger('StatusImageOff'),
        ];
        foreach (array_unique($ids) as $mid) {
            if ($mid < self::IPS_MIN_ID) {
                continue;
            }
            if (IPS_MediaExists($mid)) {
                $this->RegisterReference($mid);
            } else {
                $this->LogDebug(__FUNCTION__, 'Media does not exist: ' . $mid);
                $missing = true;
            }
        }
        // Sender ID => property names, so MessageSink does not need to read all properties
        $senders = [];
        foreach (array_keys(self::DW_VARS2RES) as $name) {
            $vid = $this->ReadPropertyInteger($name);
            if ($vid < self::IPS_MIN_ID) {
                continue;
            }
            if (IPS_VariableExists($vid)) {
                $senders[$vid][] = $name;
            } else {
                $this->LogDebug(__FUNCTION__, 'Variable does not exist: ' . $vid);
                $missing = true;
            }
        }
        foreach (array_keys($senders) as $vid) {
            $this->RegisterReference($vid);
            $this->RegisterMessage($vid, VM_UPDATE);
        }
        $this->SetBuffer('Senders', json_encode($senders));

        // Send a complete update message to the display, as parameters may have changed
        $this->UpdateVisualizationValue($this->GetFullUpdateMessage());

        // Set status
        $this->SetStatus($missing ? self::STATUS_OBJECT_MISSING : IS_ACTIVE);
    }

    /**
     * The content of the function can be overwritten in order to carry out own reactions to certain messages.
     * The function is only called for registered MessageIDs/SenderIDs combinations.
     *
     * data[0] = new value
     * data[1] = value changed?
     * data[2] = old value
     * data[3] = timestamp.
     *
     * @param int   $timestamp Continuous counter timestamp
     * @param int   $sender    Sender ID
     * @param int   $message   ID of the message
     * @param array{0:mixed,1:bool,2:mixed,3:int} $data Data of the message
     * @return void
     */
    public function MessageSink(int $timestamp, int $sender, int $message, array $data): void
    {
        if ($message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }
        // state changes ?
        if ($message !== VM_UPDATE || $data[1] != true) {
            return;
        }
        // look for updates (mapping is built in ApplyChanges)
        $senders = json_decode($this->GetBuffer('Senders'), true);
        $names = is_array($senders) ? ($senders[$sender] ?? []) : [];
        foreach ($names as $name) {
            $id = self::DW_VARS2RES[$name] ?? null;
            if ($id !== null) {
                switch ($message) {
                    case VM_UPDATE:
                        $fst = null;
                        $snd = null;
                        if ($name === 'OfflineVariable') {
                            $fst = $this->GetOfflineState($data[0]);
                        }
                        elseif ($name === 'SwitchVariable') {
                            $fst = $this->GetSwitchValue($data[0]);
                        }
                        elseif ($name === 'ProgressTerm') {
                            $fst = $this->GetProgressTerm();
                        }
                        elseif ($name === 'ProgressVariable') {
                            $fst = $this->ReadPropertyFormatted($name);
                            $snd = $this->GetProgressValue($name);
                        }
                        else {
                            if (str_starts_with($name, 'AddVariable')) {
                                $info = substr($name, strlen('AddVariable'));
                                $fst = $this->ReadPropertyString('AddPrefix' . $info);
                                $fst .= $this->ReadPropertyFormatted($name);
                                $fst .= $this->ReadPropertyString('AddSuffix' . $info);
                            } else {
                                $fst = $this->ReadPropertyFormatted($name);
                                if (isset($id[1])) {
                                    $snd = $this->ReadPropertyValue($name);
                                }
                            }
                        }
                        if ($fst !== null) {
                            $this->UpdateVisualizationValue(json_encode([$id[0] => $fst]));
                        }
                        if ($snd !== null) {
                            $this->UpdateVisualizationValue(json_encode([$id[1] => $snd]));
                        }
                }
            }
        }
    }

    /**
     * Is called when, for example, a button is clicked in the visualization.
     *
     * @param string $ident Ident of the variable
     * @param mixed $value The value to be set
     *
     * @return void
     */
    public function RequestAction(string $ident, mixed $value): void
    {
        // Debug output
        $this->LogDebug(__FUNCTION__, $ident . ' => ' . $value);
        // Ident == OnXxxxxYyyyy
        switch ($ident) {
            case 'ChangeStatus':
                $this->StatusProfile($value);
                break;
            case 'SwitchState':
                $this->SwitchState($value);
                break;
            default:
                $this->LogDebug(__FUNCTION__, 'Unknown ident: ' . $ident);
                break;
        }
        // Send a complete update message to the display, as parameters may have changed
        // $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
        return;
    }

    /**
     * If the HTML-SDK is to be used, this function must be overwritten in order to return the HTML content.
     *
     * @return string Initial display of a representation via HTML SDK
     */
    public function GetVisualizationTile(): string
    {
        // Add a script to set the values when loading, analogous to changes at runtime
        // Although the return from GetFullUpdateMessage is already JSON-encoded, json_encode is still executed a second time
        // This adds quotation marks to the string and any quotation marks within it are escaped correctly
        // Unescaped slashes keep the base64 images small, JSON_HEX_TAG prevents a '</script>' in texts from closing the tag
        $handling = '<script>handleMessage(' . json_encode($this->GetFullUpdateMessage(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ');</script>';
        // Add static HTML from file
        $module = file_get_contents(__DIR__ . '/module.html');
        // Important: $handling at the end, as the handleMessage function is only defined in the HTML
        return $module . $handling;
    }

    /**
     * Builds the status mapping (image, color, progress bar) from the configuration list.
     *
     * @return object Mapping of status value to {image, color, progress}, always encoded as JSON object
     */
    private function GetStateMap(): object
    {
        $map = [];
        $list = json_decode($this->ReadPropertyString('StatusProfile'), true);
        if (!is_array($list)) {
            return (object) $map;
        }
        foreach ($list as $item) {
            if (!is_array($item) || !array_key_exists('Value', $item)) {
                continue;
            }
            // String keys, otherwise float values would be truncated to int
            $value = is_bool($item['Value']) ? var_export($item['Value'], true) : (string) $item['Value'];
            // Entries from older versions or not yet edited rows may lack columns
            $color = $item['Color'] ?? -1;
            $map[$value] = [
                'image'    => $item['Image'] ?? 'OFF',
                'color'    => $color === -1 ? '' : $this->GetColorFormatted($color),
                'progress' => $item['Progress'] ?? true
            ];
        }
        return (object) $map;
    }

    /**
     * Generate a message that updates all elements in the HTML display.
     *
     * @return string JSON encoded message information
     */
    private function GetFullUpdateMessage(): string
    {
        // Fill resultset
        $result = [];
        $result['tilecolor'] = $this->GetColorFormatted($this->ReadPropertyInteger('TileColor'));
        $result['tiletrans'] = $this->ReadPropertyInteger('TileTransparency');
        $result['tileratio'] = $this->ReadPropertyInteger('TileRatio');
        $result['offlinecolor'] = $this->GetColorFormatted($this->ReadPropertyInteger('OfflineColor'));
        $result['offline'] = $this->GetOfflineState(null);
        $result['switchstate'] = $this->GetSwitchValue(null);
        // Images and status mapping must be sent before 'statevalue'
        $result['imageon'] = $this->GetBase64Image($this->ReadPropertyInteger('StatusImageOn'));
        $result['imageoff'] = $this->GetBase64Image($this->ReadPropertyInteger('StatusImageOff'));
        $result['statemap'] = $this->GetStateMap();
        $result['statehead'] = $this->Translate($this->ReadPropertyString('StatusLabel'));
        $result['statetext'] = $this->ReadPropertyFormatted('StatusVariable');
        $result['statevalue'] = $this->ReadPropertyValue('StatusVariable');
        $result['statefont'] = $this->ReadPropertyInteger('StatusFont');
        $result['actionhead'] = $this->Translate($this->ReadPropertyString('ActionLabel'));
        $result['actiontext'] = $this->ReadPropertyFormatted('ActionVariable');
        $result['actionfont'] = $this->ReadPropertyInteger('ActionFont');
        $result['progresshead'] = $this->ReadPropertyString('ProgressLabel');
        $result['progresstext'] = $this->ReadPropertyFormatted('ProgressVariable');
        // Progress min/max/val
        $progressID = $this->ReadPropertyInteger('ProgressVariable');
        if (IPS_VariableExists($progressID)) {
            $value = $this->GetProgressValue('ProgressVariable');
            $progressMinMax = $this->GetProgressMinMax($progressID);
            $this->LogDebug(__FUNCTION__, 'Progress min/max/val: ' . json_encode($progressMinMax) . ' / ' . $value);
            $result['progressmin'] = $progressMinMax['min'];
            $result['progressmax'] = $progressMinMax['max'];
            $result['progressvalue'] = $value;
        }
        $result['progressfont'] = $this->ReadPropertyInteger('ProgressFont');
        $result['progressstart'] = $this->GetColorFormatted($this->ReadPropertyInteger('ProgressStart'));
        $result['progressstop'] = $this->GetColorFormatted($this->ReadPropertyInteger('ProgressStop'));
        $result['progressterm'] = $this->GetProgressTerm();
        // info1
        $result['info1icon'] = $this->ReadPropertyString('AddSymbolFirst');
        $result['info1text'] = $this->ReadPropertyString('AddPrefixFirst');
        $result['info1text'] .= $this->ReadPropertyFormatted('AddVariableFirst');
        $result['info1text'] .= $this->ReadPropertyString('AddSuffixFirst');
        $result['info1font'] = $this->ReadPropertyInteger('AddFontFirst');
        // info2
        $result['info2icon'] = $this->ReadPropertyString('AddSymbolSecond');
        $result['info2text'] = $this->ReadPropertyString('AddPrefixSecond');
        $result['info2text'] .= $this->ReadPropertyFormatted('AddVariableSecond');
        $result['info2text'] .= $this->ReadPropertyString('AddSuffixSecond');
        $result['info2font'] = $this->ReadPropertyInteger('AddFontSecond');
        // info3
        $result['info3icon'] = $this->ReadPropertyString('AddSymbolThird');
        $result['info3text'] = $this->ReadPropertyString('AddPrefixThird');
        $result['info3text'] .= $this->ReadPropertyFormatted('AddVariableThird');
        $result['info3text'] .= $this->ReadPropertyString('AddSuffixThird');
        $result['info3font'] = $this->ReadPropertyInteger('AddFontThird');
        // send it
        return json_encode($result, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Status variable has been changed. update profil assoziations.
     *
     * @param int $vid Status variable ID.
     * @return void
     */
    private function StatusProfile(int $vid): void
    {
        $this->LogDebug(__FUNCTION__, 'Variable: ' . $vid);
        $list = [];

        // Associations from presentation options or variable profile
        $assocInfo = $this->ResolveAssociationMode($vid);
        if ($assocInfo) {
            foreach ($assocInfo['associations'] as $association) {
                $list[] = [
                    'Name'     => $association['Caption'] ?? $association['Name'] ?? (string) $association['Value'],
                    'Value'    => $association['Value'],
                    'Progress' => true,
                    'Image'    => 'OFF',
                    'Color'    => -1
                ];
            }
        }
        // Update form field (List)
        $json = json_encode($list);
        $this->UpdateFormField('StatusProfile', 'values', $json);
    }

    /**
     * SwitchState
     *
     * @param bool $value
     */
    private function SwitchState(bool $value): void
    {
        $vid = $this->ReadPropertyInteger('SwitchVariable');
        if (!IPS_VariableExists($vid)) {
            $this->LogDebug(__FUNCTION__, 'SwitchVariable does not exist!');
            return;
        }
        if ($this->GetOfflineState(null) === true) {
            $this->LogDebug(__FUNCTION__, 'Device is offline, switching ignored!');
            return;
        }
        $type = $this->ReadPropertyInteger('SwitchType');
        $on = $this->ReadPropertyString('SwitchOn');
        $off = $this->ReadPropertyString('SwitchOff');

        // central convert type
        switch ($type) {
            case 0: // Boolean
                $switch = $value ? filter_var($on, FILTER_VALIDATE_BOOLEAN) : filter_var($off, FILTER_VALIDATE_BOOLEAN);
                break;

            case 1: // Integer
                $switch = $value ? intval($on) : intval($off);
                break;

            case 2: // Float
                $switch = $value ? floatval($on) : floatval($off);
                break;

            default: // String
                $switch = $value ? $on : $off;
                break;
        }

        // Only switch via action, foreign variables must not be set directly
        if (!HasAction($vid)) {
            $this->LogDebug(__FUNCTION__, 'SwitchVariable has no action!');
            return;
        }
        RequestAction($vid, $switch);
        $this->LogDebug(__FUNCTION__, 'RequestAction(' . $vid . ', ' . $this->Stringify($switch) . ')');
    }

    /**
     * Returns the formatted value of a variable defined in the module properties.
     *
     * @param string $property The property name that contains a variable ID.
     * @return string|null The formatted variable value if it exists, otherwise null.
     */
    private function ReadPropertyFormatted(string $property): string|null
    {
        $vid = $this->ReadPropertyInteger($property);
        if (IPS_VariableExists($vid)) {
            return GetValueFormatted($vid);
        }
        return null;
    }

    /**
     * Returns the value of a variable defined in the module properties.
     *
     * @param string $property The property name that contains a variable ID.
     * @return string|null The variable value if it exists, otherwise null.
     */
    private function ReadPropertyValue(string $property): string|null
    {
        $vid = $this->ReadPropertyInteger($property);
        if (IPS_VariableExists($vid)) {
            $value = GetValue($vid);
            if (gettype($value) === 'boolean') {
                $value = var_export($value, true);
            }
            return (string) $value;
        }
        return null;
    }

    /**
     * Returns the remaining term in seconds.
     * Values in HH:MM:SS format are converted, all others are cast to int.
     *
     * @return int|null Remaining term in seconds, or null if no variable is set.
     */
    private function GetProgressTerm(): ?int
    {
        $value = $this->ReadPropertyValue('ProgressTerm');
        if ($value === null) {
            return null;
        }
        if (preg_match('/^(\d{2}):(\d{2}):(\d{2})$/', $value, $matches)) {
            return (int) $matches[1] * 3600 + (int) $matches[2] * 60 + (int) $matches[3];
        }
        return (int) $value;
    }

    /**
     * Returns whether the device is offline (not reachable or operable).
     *
     * @param mixed $data The value of the availability variable, null to read it.
     * @return bool|null True if offline, false if online, null if no variable is set.
     */
    private function GetOfflineState(mixed $data): ?bool
    {
        $vid = $this->ReadPropertyInteger('OfflineVariable');
        if (!IPS_VariableExists($vid)) {
            return null;
        }
        if ($data === null) {
            $data = GetValue($vid);
        }
        // Only the online value is unique, every other value means offline.
        // Compare value is a string, convert it according to the type of the variable
        $online = $this->ReadPropertyString('OnlineValue');
        $isOnline = match (IPS_GetVariable($vid)['VariableType']) {
            VARIABLETYPE_BOOLEAN => (bool) $data === filter_var($online, FILTER_VALIDATE_BOOLEAN),
            VARIABLETYPE_INTEGER => (int) $data === intval($online),
            VARIABLETYPE_FLOAT   => (float) $data === floatval($online),
            default              => (string) $data === $online,
        };
        return !$isOnline;
    }

    /**
     * Returns the value of the switch variable depends on type.
     *
     * @param mixed $data The value of the switch variable.
     * @return string|null The formatted variable value if it exists, otherwise null.
     */
    private function GetSwitchValue(mixed $data): string|null
    {
        // Without an action the variable cannot be switched, so the switch is hidden
        $vid = $this->ReadPropertyInteger('SwitchVariable');
        if (!IPS_VariableExists($vid) || !HasAction($vid)) {
            return null;
        }
        if ($data === null) {
            $data = GetValue($vid);
        }
        $type = $this->ReadPropertyInteger('SwitchType');
        $value = $this->ReadPropertyString('SwitchOn');
        if ($type == 0) {
            $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            return $data === $bool ? 'on' : 'off';
        } elseif ($type == 1) {
            return $data === intval($value) ? 'on' : 'off';
        } elseif ($type == 2) {
            return $data === floatval($value) ? 'on' : 'off';
        } else {
            return $data === $value ? 'on' : 'off';
        }
    }

    /**
     * Returns the effective presentation of a variable (Symcon 8+).
     * Uses IPS_GetVariablePresentation() (resolves templates),
     * falls back to the variable's custom presentation if that returns nothing.
     *
     * @param int $variable The ID of the Symcon variable to check.
     *
     * @return array<string,mixed> Presentation parameters or empty array.
     */
    private function GetEffectivePresentation(int $variable): array
    {
        if (!IPS_VariableExists($variable)) {
            return [];
        }
        $presentation = IPS_GetVariablePresentation($variable);
        if (empty($presentation) || !is_array($presentation)) {
            $presentation = IPS_GetVariable($variable)['VariableCustomPresentation'] ?? [];
        }
        return is_array($presentation) ? $presentation : [];
    }

    /**
     * Resolves the association list of a variable, either from the presentation
     * options (Symcon 8+) or from the profile (custom or standard), and determines
     * whether the association values are numeric (real range) or should be
     * treated as an index-based enumeration.
     *
     * @param int $variable The ID of the Symcon variable to check.
     *
     * @return array{associations:array<int,array{Value:mixed,Caption?:string,Name?:string,Icon?:string,Color?:int}>,numeric:bool}|null
     */
    private function ResolveAssociationMode(int $variable): ?array
    {
        if (!IPS_VariableExists($variable)) {
            return null;
        }

        $associations = [];
        $options = $this->GetEffectivePresentation($variable)['OPTIONS'] ?? null;
        if (is_string($options)) {
            $options = json_decode($options, true);
        }
        if (is_array($options)) {
            foreach ($options as $option) {
                if (is_array($option) && array_key_exists('Value', $option)) {
                    $associations[] = $option;
                }
            }
        }

        if (empty($associations)) {
            $var = IPS_GetVariable($variable);
            $custom = $var['VariableCustomProfile'] ?: $var['VariableProfile'];
            if ($custom && IPS_VariableProfileExists($custom)) {
                $profile = IPS_GetVariableProfile($custom);
                if (!empty($profile['Associations']) && is_array($profile['Associations'])) {
                    $associations = $profile['Associations'];
                }
            }
        }

        if (empty($associations)) {
            return null;
        }

        $numericValues = array_filter(array_column($associations, 'Value'), 'is_numeric');

        return [
            'associations' => $associations,
            'numeric'      => count($numericValues) === count($associations)
        ];
    }

    /**
     * Determines min/max for the progress bar; association handling is
     * delegated to ResolveAssociationMode() for consistency with GetProgressValue().
     *
     * Priority order:
     *  1. Presentation with MIN/MAX (Symcon 8+, templates resolved)
     *  2. Associations (presentation options or profile associations)
     *  3. Variable profile MinValue/MaxValue (custom or standard)
     *  4. Presentation GUIDs (PRESENTATION, TEMPLATE)
     *  5. ObjectVisualization with known min/max fields or value mappings
     *  6. Fallback to default values 0–100
     *
     * @param int $variable  The ID of the Symcon variable to check.
     *
     * @return array{min: float, max: float} Associative array with 'min' and 'max' as floating-point numbers.
     */
    private function GetProgressMinMax(int $variable): array
    {
        $default = ['min' => 0.0, 'max' => 100.0];

        $extract = function ($data)
        {
            if (is_string($data)) {
                $data = json_decode($data, true);
            }
            if (is_array($data) && isset($data['MinValue'], $data['MaxValue']) && is_numeric($data['MinValue']) && is_numeric($data['MaxValue'])) {
                return [
                    'min' => (float) $data['MinValue'],
                    'max' => (float) $data['MaxValue']
                ];
            }
            return null;
        };

        $presentation = function ($guid) use ($extract)
        {
            if (is_string($guid) && $guid !== '' && IPS_PresentationExists($guid)) {
                return $extract(IPS_GetPresentation($guid));
            }
            return null;
        };

        if (!IPS_VariableExists($variable)) {
            return $default;
        }
        $variableID = $variable;
        $variable = IPS_GetVariable($variable);

        if (!in_array($variable['VariableType'], [VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT, VARIABLETYPE_STRING], true)) {
            return $default;
        }

        // Case 1: Presentation with MIN/MAX
        $custom = $this->GetEffectivePresentation($variableID);
        $this->LogDebug(__FUNCTION__, $custom, false);
        if (isset($custom['MIN'], $custom['MAX']) && is_numeric($custom['MIN']) && is_numeric($custom['MAX']) && $custom['MIN'] != $custom['MAX']) {
            return ['min' => (float) $custom['MIN'], 'max' => (float) $custom['MAX']];
        }

        // Case 2: Associations (presentation options or profile)
        $assocInfo = $this->ResolveAssociationMode($variableID);
        if ($assocInfo) {
            if ($assocInfo['numeric']) {
                $values = array_column($assocInfo['associations'], 'Value');
                $minmax = ['min' => (float) min($values), 'max' => (float) max($values)];
            } else {
                // Enumeration steps 1..n (see GetProgressValue), so the first step already fills 1/n
                $minmax = ['min' => 1.0, 'max' => (float) count($assocInfo['associations'])];
            }
            // A single association gives no usable range
            if ($minmax['max'] > $minmax['min']) {
                return $minmax;
            }
        }

        // Case 3: Variable profile
        $profileName = $variable['VariableCustomProfile'] ?: $variable['VariableProfile'];
        if ($profileName && IPS_VariableProfileExists($profileName)) {
            $profile = IPS_GetVariableProfile($profileName);
            if (isset($profile['MinValue'], $profile['MaxValue']) && $profile['MinValue'] != $profile['MaxValue']) {
                return [
                    'min' => (float) $profile['MinValue'],
                    'max' => (float) $profile['MaxValue']
                ];
            }
        }

        // Case 4: Presentation GUIDs
        foreach (['PRESENTATION', 'TEMPLATE'] as $key) {
            if (!empty($custom[$key])) {
                $minmax = $presentation($custom[$key]);
                if ($minmax) {
                    return $minmax;
                }
            }
        }

        // Case 5: ObjectVisualization
        if (IPS_ObjectExists($variableID)) {
            $object = IPS_GetObject($variableID);
            if (!empty($object['ObjectVisualization'])) {
                $visu = json_decode($object['ObjectVisualization'], true);
                if (is_array($visu)) {
                    $minFields = ['MinValue', 'MinimalerWert', 'Minimum', 'Min', 'minValue', 'min'];
                    $maxFields = ['MaxValue', 'MaximalerWert', 'Maximum', 'Max', 'maxValue', 'max'];

                    $foundMin = $foundMax = null;
                    foreach ($minFields as $f) {
                        if (isset($visu[$f]) && is_numeric($visu[$f])) {
                            $foundMin = (float) $visu[$f];
                            break;
                        }
                    }
                    foreach ($maxFields as $f) {
                        if (isset($visu[$f]) && is_numeric($visu[$f])) {
                            $foundMax = (float) $visu[$f];
                            break;
                        }
                    }
                    if ($foundMin !== null && $foundMax !== null) {
                        return ['min' => $foundMin, 'max' => $foundMax];
                    }
                    if (!empty($visu['ValueMappings']) && is_array($visu['ValueMappings'])) {
                        $values = array_column($visu['ValueMappings'], 'Value');
                        $numericValues = array_filter($values, 'is_numeric');
                        if ($numericValues) {
                            return ['min' => (float) min($numericValues), 'max' => (float) max($numericValues)];
                        }
                    }
                }
            }
        }
        return $default;
    }

    /**
     * Returns the current value of a variable, expressed in the same
     * coordinate system that GetProgressMinMax() uses for that variable:
     *  - if the associations (presentation options or profile) carry numeric
     *    Values, this is the plain numeric value (already within min/max).
     *  - if the associations are non-numeric (pure enum strings), this is
     *    the one-based step of the matching association (within 1..count,
     *    matching the range from GetProgressMinMax()), 0 if not found.
     *  - otherwise, the raw numeric value of the variable.
     *
     * @param string $property The property name for the Symcon variable to check.
     *
     * @return float Current value, aligned with GetProgressMinMax().
     */
    private function GetProgressValue(string $property): float
    {
        $vid = $this->ReadPropertyInteger($property);
        if (!IPS_VariableExists($vid)) {
            return 0.0;
        }

        $rawValue = GetValue($vid);

        $assocInfo = $this->ResolveAssociationMode($vid);
        if ($assocInfo) {
            if ($assocInfo['numeric']) {
                return is_numeric($rawValue) ? (float) $rawValue : 0.0;
            }

            foreach (array_values($assocInfo['associations']) as $index => $assoc) {
                if ((string) $assoc['Value'] === (string) $rawValue) {
                    return (float) ($index + 1);
                }
            }
            return 0.0; // no matching entry found
        }

        return is_numeric($rawValue) ? (float) $rawValue : 0.0;
    }
}