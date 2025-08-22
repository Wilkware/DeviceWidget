<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/_traits.php';  // Generell funktions

// CLASS DeviceWidget
class DeviceWidget extends IPSModuleStrict
{
    use DebugHelper;
    use FormatHelper;

    /**
     * @var int Min IPS Object ID
     */
    private const IPS_MIN_ID = 10000;

    /**
     * @var array<string,string[]> Property name to result mapping
     */
    private const TWDW_VARS2RES = [
        'SwitchVariable'    => ['switchstate'],
        'StatusVariable'    => ['statetext', 'statevalue'],
        'ActionVariable'    => ['actiontext'],
        'ProgressVariable'  => ['progresstext', 'progressvalue'],
        'ProgressTerm'      => ['progressterm'],
        'AddVariableFirst'  => ['info1text'],
        'AddVariableSecond' => ['info2text'],
        'AddVariableThird'  => ['info3text'],
    ];

    /**
     * In contrast to Construct, this function is called only once when creating the instance and starting IP-Symcon.
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
        $this->RegisterPropertyString('ActionLabel', 'AKTION');
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
     * The function is not called when exiting IP-Symcon.
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

        // Register all references
        $ids = [
            $this->ReadPropertyInteger('StatusImageOn'),
            $this->ReadPropertyInteger('StatusImageOff'),
        ];
        foreach ($ids as $mid) {
            if ($mid >= self::IPS_MIN_ID) {
                if (IPS_MediaExists($mid)) {
                    $this->RegisterReference($mid);
                } else {
                    $this->LogDebug(__FUNCTION__, 'Media does not exist: ' . $mid);
                    $this->SetStatus(104);
                    return;
                }
            }
        }
        $ids = [
            $this->ReadPropertyInteger('SwitchVariable'),
            $this->ReadPropertyInteger('StatusVariable'),
            $this->ReadPropertyInteger('ActionVariable'),
            $this->ReadPropertyInteger('ProgressVariable'),
            $this->ReadPropertyInteger('ProgressTerm'),
            $this->ReadPropertyInteger('AddVariableFirst'),
            $this->ReadPropertyInteger('AddVariableSecond'),
            $this->ReadPropertyInteger('AddVariableThird')
        ];
        foreach ($ids as $vid) {
            if ($vid >= self::IPS_MIN_ID) {
                if (IPS_VariableExists($vid)) {
                    $this->RegisterReference($vid);
                } else {
                    $this->LogDebug(__FUNCTION__, 'Variable does not exist: ' . $vid);
                    $this->SetStatus(104);
                    return;
                }
            }
        }

        // Register all messages
        foreach ($ids as $vid) {
            $this->RegisterMessage($vid, VM_UPDATE);
        }

        // Send a complete update message to the display, as parameters may have changed
        $this->UpdateVisualizationValue($this->GetFullUpdateMessage());

        // Set status
        $this->SetStatus(102);
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
        // state changes ?
        if ($data[1] != true) {
            return;
        }
        // look for updates
        foreach (self::TWDW_VARS2RES as $name => $id) {
            if ($sender === $this->ReadPropertyInteger($name)) {
                switch ($message) {
                    case VM_UPDATE:
                        $fst = null;
                        $snd = null;
                        if ($name === 'SwitchVariable') {
                            $fst = $this->GetSwitchValue($data[0]);
                        }
                        elseif ($name === 'ProgressTerm') {
                            $fst = GetValue($this->ReadPropertyInteger('ProgressTerm'));
                            if (is_string($fst) && preg_match('/^(\d{2}):(\d{2}):(\d{2})$/', $fst, $matches)) {
                                $h = (int) $matches[1];
                                $m = (int) $matches[2];
                                $s = (int) $matches[3];
                                $fst = $h * 3600 + $m * 60 + $s;
                            }
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
                        if ($fst != null) {
                            $this->UpdateVisualizationValue(json_encode([$id[0] => $fst]));
                        }
                        if ($snd != null) {
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
                // Messages from the HTML representation always send the identifier corresponding to the property and,
                // in the value, the difference to be calculated for the variable.
                $vid = $this->ReadPropertyInteger($ident);
                if (!IPS_VariableExists($vid)) {
                    $this->LogDebug(__FUNCTION__, 'Variable to be updated does not exist!');
                    return;
                }
                // Switching the value of the variable
                $new = GetValue($vid);
                RequestAction($vid, !$new);
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
        // Possible image content
        $imgOn = '';
        $imgOff = '';
        // Check for images
        $media = $this->ReadPropertyInteger('StatusImageOn');
        if ($media >= self::IPS_MIN_ID) {
            if (IPS_MediaExists($media)) {
                $image = IPS_GetMedia($media);
                if ($image['MediaType'] === MEDIATYPE_IMAGE) {
                    $file = explode('.', $image['MediaFile']);
                    $imgOn = $this->GetMediaType(end($file));
                    // Only continue if content has been set. Otherwise, the image is not a supported file type.
                    if ($imgOn) {
                        // Append base64-encoded content of the image
                        $imgOn .= IPS_GetMediaContent($media);
                    }
                }
            }
        }
        $media = $this->ReadPropertyInteger('StatusImageOff');
        if ($media >= self::IPS_MIN_ID) {
            if (IPS_MediaExists($media)) {
                $image = IPS_GetMedia($media);
                if ($image['MediaType'] === MEDIATYPE_IMAGE) {
                    $file = explode('.', $image['MediaFile']);
                    $imgOff = $this->GetMediaType(end($file));
                    // Only continue if content has been set. Otherwise, the image is not a supported file type.
                    if ($imgOff) {
                        // Append base64-encoded content of the image
                        $imgOff .= IPS_GetMediaContent($media);
                    }
                }
            }
        }

        // Pass images as assets
        $assets = '';
        if (!empty($imgOn) || !empty($imgOff)) {
            $assets = '<script>';
            $assets .= 'window.assets = {};' . PHP_EOL;
            $assets .= 'window.assets.img_on  = "' . $imgOn . '";' . PHP_EOL;
            $assets .= 'window.assets.img_off = "' . $imgOff . '";' . PHP_EOL;
            $assets .= '</script>';
        }

        // Read form data and create status mapping array for image and color
        $asso = json_decode($this->ReadPropertyString('StatusProfile'), true);
        $images = [];
        $colors = [];
        $bars = [];
        foreach ($asso as $item) {
            $value = $item['Value'];
            if (gettype($value) === 'boolean') $value = var_export($value, true);
            $images[$value] = $item['Image'];
            $colors[$value] = $item['Color'] === -1 ? '' : $this->GetColorFormatted($item['Color']);
            $bars[$value] = $item['Progress'];
        }

        // Pass mapping as vars
        $mapping = '<script type="text/javascript">';
        $mapping .= 'var imgs = ' . json_encode($images) . ';';
        $mapping .= 'var cols = ' . json_encode($colors) . ';';
        $mapping .= 'var bars = ' . json_encode($bars) . ';';
        $mapping .= '</script>';

        // Add a script to set the values when loading, analogous to changes at runtime
        // Although the return from GetFullUpdateMessage is already JSON-encoded, json_encode is still executed a second time
        // This adds quotation marks to the string and any quotation marks within it are escaped correctly
        $handling = '<script>handleMessage(' . json_encode($this->GetFullUpdateMessage()) . ');</script>';
        // Add static HTML from file
        $module = file_get_contents(__DIR__ . '/module.html');
        // Important: $initialHandling at the end, as the handleMessage function is only defined in the HTML
        return $module . $mapping . $assets . $handling;
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
        $result['switchstate'] = $this->GetSwitchValue(null);
        $result['statehead'] = $this->ReadPropertyString('StatusLabel');
        $result['statetext'] = $this->ReadPropertyFormatted('StatusVariable');
        $result['statevalue'] = $this->ReadPropertyValue('StatusVariable');
        $result['statefont'] = $this->ReadPropertyInteger('StatusFont');
        $result['actionhead'] = $this->ReadPropertyString('ActionLabel');
        $result['actiontext'] = $this->ReadPropertyFormatted('ActionVariable');
        $result['actionfont'] = $this->ReadPropertyInteger('ActionFont');
        $result['progresshead'] = $this->ReadPropertyString('ProgressLabel');
        $result['progresstext'] = $this->ReadPropertyFormatted('ProgressVariable');
        // Progress min/max/val
        $value = $this->ReadPropertyValue('ProgressVariable');
        if ($value != null) {
            $progressMinMax = $this->GetProgressMinMax($this->ReadPropertyInteger('ProgressVariable'));
            $result['progressmin'] = $progressMinMax['min'];
            $result['progressmax'] = $progressMinMax['max'];
            $result['progressvalue'] = $value;
        }
        $result['progressfont'] = $this->ReadPropertyInteger('ProgressFont');
        $result['progressstart'] = $this->GetColorFormatted($this->ReadPropertyInteger('ProgressStart'));
        $result['progressstop'] = $this->GetColorFormatted($this->ReadPropertyInteger('ProgressStop'));
        $result['progressterm'] = $this->ReadPropertyValue('ProgressTerm');
        // Check whether the value is in HH:MM:SS format
        if ($result['progressterm'] != null) {
            if (preg_match('/^(\d{2}):(\d{2}):(\d{2})$/', $result['progressterm'], $matches)) {
                // Value is in HH:MM:SS format, so convert to seconds
                $h = (int) $matches[1];
                $m = (int) $matches[2];
                $s = (int) $matches[3];
                $result['progressterm'] = $h * 3600 + $m * 60 + $s;
            } else {
                $result['progressterm'] = (int) $result['progressterm'];
            }
        }
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
        return json_encode($result);
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

        // Read variable profil
        $variable = IPS_GetVariable($vid);
        $name = $variable['VariableCustomProfile'] ?: $variable['VariableProfile'];
        if ($name != '') {
            $profile = IPS_GetVariableProfile($name);
            // Durchlaufen der Profilassoziationen
            foreach ($profile['Associations'] as $association) {
                $list[] = [
                    'Name'  => $association['Name'],
                    'Value' => $association['Value'],
                    'Image' => 'OFF',
                    'Color' => -1
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
            return;
        }
        $type = $this->ReadPropertyInteger('SwitchType');
        $on = $this->ReadPropertyString('SwitchOn');
        $off = $this->ReadPropertyString('SwitchOff');
        if ($type == 0) {
            RequestAction($vid, $value ? filter_var($on, FILTER_VALIDATE_BOOLEAN) : filter_var($off, FILTER_VALIDATE_BOOLEAN));
        } elseif ($type == 1) {
            RequestAction($vid, $value ? intval($on) : intval($off));
        } elseif ($type == 2) {
            RequestAction($vid, $value ? floatval($on) : floatval($off));
        } else {
            RequestAction($vid, $value ? $on : $off);
        }
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
     * @return string|null The formatted variable value if it exists, otherwise null.
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
     * Returns the value of the switch variable depends on type.
     *
     * @param mixed $data The value of the switch variable.
     * @return string|null The formatted variable value if it exists, otherwise null.
     */
    private function GetSwitchValue(mixed $data): string|null
    {
        if ($data === null) {
            $vid = $this->ReadPropertyInteger('SwitchVariable');
            if (IPS_VariableExists($vid)) {
                $data = GetValue($vid);
            } else {
                return null;
            }
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
     * Get media type
     *
     * @param string $ext File extention
     * @return string Media type data prefix
     */
    private function GetMediaType(string $ext): string
    {
        $type = '';
        switch ($ext) {
            case 'bmp':
                $type = 'data:image/bmp;base64,';
                break;
            case 'jpg':
            case 'jpeg':
                $type = 'data:image/jpeg;base64,';
                break;
            case 'gif':
                $type = 'data:image/gif;base64,';
                break;
            case 'png':
                $type = 'data:image/png;base64,';
                break;
            case 'ico':
                $type = 'data:image/x-icon;base64,';
                break;
            case 'webp':
                $type = 'data:image/webp;base64,';
                break;
        }
        return $type;
    }

    /**
     * Retrieves the minimum and maximum values for a progress bar based on
     * variable profiles, custom presentations, presentations, or object visualizations.
     *
     * Priority order:
     *  1. Variable profile (custom or standard)
     *  2. CustomPresentation with MIN/MAX
     *  3. Presentation GUIDs (PRESENTATION, OPTIONS, TEMPLATE)
     *  4. ObjectVisualization with known min/max fields or value mappings
     *  5. Fallback to default values 0–100
     *
     * @param int $variable  The ID of the Symcon variable to check.
     *
     * @return array{min: float, max: float} Associative array with 'min' and 'max' as floating-point numbers.
     */
    private function GetProgressMinMax($variable): array
    {
        $default = ['min' => 0, 'max' => 100];

        // Helper zum Extrahieren von Min/Max aus decoded JSON
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

        // Hilfsfunktion: Hole Präsentation und extrahiere Min/Max
        $presentation = function ($guid) use ($extract)
        {
            if ($guid && @IPS_PresentationExists($guid)) {
                return $extract(IPS_GetPresentation($guid));
            }
            return null;
        };

        // Variable prüfen
        if (!IPS_VariableExists($variable)) {
            return $default;
        }
        $variable = IPS_GetVariable($variable);

        if (!in_array($variable['VariableType'], [VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT], true)) {
            return $default;
        }

        // Fall 1: Variablenprofil
        $custom = $variable['VariableCustomProfile'] ?: $variable['VariableProfile'];
        if ($custom && IPS_VariableProfileExists($custom)) {
            $profile = IPS_GetVariableProfile($custom);
            if (isset($profile['MinValue'], $profile['MaxValue'])) {
                return [
                    'min' => (float) $profile['MinValue'],
                    'max' => (float) $profile['MaxValue']
                ];
            }
        }

        // Fall 2–5: CustomPresentation
        $custom = $variable['VariableCustomPresentation'] ?? [];
        if (!empty($custom)) {
            if (isset($custom['MIN'], $custom['MAX']) && is_numeric($custom['MIN']) && is_numeric($custom['MAX'])) {
                return ['min' => (float) $custom['MIN'], 'max' => (float) $custom['MAX']];
            }
            foreach (['PRESENTATION', 'OPTIONS', 'TEMPLATE'] as $key) {
                if (!empty($custom[$key])) {
                    $minmax = $presentation($custom[$key]);
                    if ($minmax) {
                        return $minmax;
                    }
                }
            }
        }

        // Fall 6: ObjectVisualization
        if (IPS_ObjectExists($variable)) {
            $object = IPS_GetObject($variable);
            if (!empty($object['ObjectVisualization'])) {
                $visu = json_decode($object['ObjectVisualization'], true);
                if (is_array($visu)) {
                    // mögliche Feldnamen
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
                            return ['min' => min($numericValues), 'max' => max($numericValues)];
                        }
                    }
                }
            }
        }
        return $default;
    }
}