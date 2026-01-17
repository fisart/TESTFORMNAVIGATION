<?php

declare(strict_types=1);

class RemoteSyncManager extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyBoolean("DebugMode", false);
        $this->RegisterPropertyBoolean("AutoCreate", true);
        $this->RegisterPropertyBoolean("ReplicateProfiles", true);
        $this->RegisterPropertyInteger("LocalPasswordModuleID", 0);
        $this->RegisterPropertyString("LocalServerKey", "");
        $this->RegisterPropertyString("Targets", "[]");
        $this->RegisterPropertyString("Roots", "[]");
        $this->RegisterPropertyString("SyncList", "[]");

        $this->RegisterAttributeString("SyncListCache", "[]");
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->WriteAttributeString("SyncListCache", $this->ReadPropertyString("SyncList"));
        $this->SendDebug("RSM_Apply", "Cache reloaded from Property", 0);
    }

    private function GetWorkingSyncList(): array
    {
        $cache = $this->ReadAttributeString("SyncListCache");
        if ($cache === "" || $cache === "[]") {
            $cache = $this->ReadPropertyString("SyncList");
        }
        $data = json_decode($cache, true);
        return is_array($data) ? $data : [];
    }

    /**
     * PROXY OBJECT HANDLER (Blueprint Section 3)
     * Wir nutzen kein Type-Hinting für $ListData, um das Proxy-Objekt zu erlauben.
     */
    public function ProcessUIChange(string $Folder, $ListData): void
    {
        $this->SendDebug("UI_EVENT_START", "Incoming Change for Folder: " . $Folder, 0);
        $this->SendDebug("UI_DATA_TYPE", "Format received: " . gettype($ListData), 0);

        // Schritt 1: Manuelle Extraktion aus dem Proxy-Objekt
        $extractedData = [];
        if (is_iterable($ListData)) {
            foreach ($ListData as $index => $row) {
                $extractedData[] = $row;
                // Detail-Log für jede Zeile
                $this->SendDebug("UI_ROW_" . $index, json_encode($row), 0);
            }
        } else {
            $this->SendDebug("UI_ERROR", "ListData is not iterable!", 0);
            return;
        }

        $this->SendDebug("UI_EXTRACTION", "Successfully extracted " . count($extractedData) . " items", 0);

        // Schritt 2: Bestehenden Speicher laden
        $savedSync = $this->GetWorkingSyncList();
        $this->SendDebug("RAM_BEFORE", "Current items in RAM: " . count($savedSync), 0);

        // Schritt 3: Merging (Folder-spezifisch)
        $map = [];
        foreach ($savedSync as $item) {
            $map[$item['Folder'] . '_' . $item['ObjectID']] = $item;
        }

        foreach ($extractedData as $uiItem) {
            $key = $Folder . '_' . $uiItem['ObjectID'];
            $map[$key] = [
                "Folder"   => $Folder,
                "ObjectID" => $uiItem['ObjectID'],
                "Name"     => $uiItem['Name'],
                "Active"   => $uiItem['Active'],
                "Action"   => $uiItem['Action'],
                "Delete"   => $uiItem['Delete']
            ];
        }

        // Schritt 4: Zurück in das Attribut schreiben
        $finalData = json_encode(array_values($map));
        $this->WriteAttributeString("SyncListCache", $finalData);

        $this->SendDebug("RAM_AFTER", "New items in RAM: " . count($map), 0);
        $this->SendDebug("RAM_CONTENT", "Preview: " . substr($finalData, 0, 100) . "...", 0);
    }

    public function GetConfigurationForm(): string
    {
        // Initialisierung
        $currentCache = $this->ReadAttributeString("SyncListCache");
        if ($currentCache === "" || $currentCache === "[]") {
            $this->WriteAttributeString("SyncListCache", $this->ReadPropertyString("SyncList"));
        }

        $form = json_decode(file_get_contents(__DIR__ . "/form.json"), true);
        $secID = $this->ReadPropertyInteger("LocalPasswordModuleID");
        $targets = json_decode($this->ReadPropertyString("Targets"), true);
        $roots = json_decode($this->ReadPropertyString("Roots"), true);
        $savedSync = $this->GetWorkingSyncList();

        // Dropdowns befüllen
        $serverOptions = [["caption" => "Please select...", "value" => ""]];
        if ($secID > 0 && IPS_InstanceExists($secID)) {
            $keys = json_decode(@SEC_GetKeys($secID), true);
            if (is_array($keys)) foreach ($keys as $k) $serverOptions[] = ["caption" => (string)$k, "value" => (string)$k];
        }
        $folderOptions = [["caption" => "Select Target Folder...", "value" => ""]];
        foreach ($targets as $t) if (!empty($t['Name'])) $folderOptions[] = ["caption" => $t['Name'], "value" => $t['Name']];
        $this->UpdateStaticFormElements($form['elements'], $serverOptions, $folderOptions);

        $stateCache = [];
        foreach ($savedSync as $item) {
            if (isset($item['Folder'], $item['ObjectID'])) {
                $stateCache[$item['Folder'] . '_' . $item['ObjectID']] = $item;
            }
        }

        // Dynamische Generierung
        foreach ($targets as $target) {
            if (empty($target['Name'])) continue;
            $folderName = $target['Name'];
            $syncValues = [];

            foreach ($roots as $root) {
                if (isset($root['TargetFolder']) && $root['TargetFolder'] === $folderName && isset($root['LocalRootID']) && $root['LocalRootID'] > 0 && IPS_ObjectExists($root['LocalRootID'])) {
                    $foundVars = [];
                    $this->GetRecursiveVariables($root['LocalRootID'], $foundVars);
                    foreach ($foundVars as $vID) {
                        $key = $folderName . '_' . $vID;
                        $syncValues[] = [
                            "Folder"   => $folderName,
                            "ObjectID" => $vID,
                            "Name"     => IPS_GetName($vID),
                            "Active"   => $stateCache[$key]['Active'] ?? false,
                            "Action"   => $stateCache[$key]['Action'] ?? false,
                            "Delete"   => $stateCache[$key]['Delete'] ?? false
                        ];
                    }
                }
            }

            $listName = "List_" . md5($folderName);

            // STABILSTE ÜBERGABE: Wir nutzen das $$ Pattern für das Proxy-Objekt
            $onChange = "RSM_ProcessUIChange(\$id, '$folderName', \$$listName);";

            $form['actions'][] = [
                "type"    => "ExpansionPanel",
                "caption" => "TARGET: " . strtoupper($folderName) . " (" . count($syncValues) . " Variables)",
                "items"   => [
                    [
                        "type" => "RowLayout",
                        "items" => [
                            ["type" => "Button", "caption" => "Sync ALL", "onClick" => "RSM_ToggleAll(\$id, 'Active', true, '$folderName');", "width" => "100px"],
                            ["type" => "Button", "caption" => "Sync NONE", "onClick" => "RSM_ToggleAll(\$id, 'Active', false, '$folderName');", "width" => "100px"],
                            ["type" => "Label", "caption" => "|", "width" => "15px"],
                            ["type" => "Button", "caption" => "Action ALL", "onClick" => "RSM_ToggleAll(\$id, 'Action', true, '$folderName');", "width" => "100px"],
                            ["type" => "Button", "caption" => "INSTALL SCRIPTS", "onClick" => "RSM_InstallRemoteScripts(\$id, '$folderName');"]
                        ]
                    ],
                    [
                        "type" => "List",
                        "name" => $listName,
                        "rowCount" => min(count($syncValues) + 1, 10),
                        "add" => false,
                        "delete" => false,
                        "onChange" => $onChange,
                        "columns" => [
                            ["name" => "ObjectID", "caption" => "ID", "width" => "70px"],
                            ["name" => "Name", "caption" => "Variable Name", "width" => "auto"],
                            ["name" => "Active", "caption" => "Sync", "width" => "60px", "edit" => ["type" => "CheckBox"]],
                            ["name" => "Action", "caption" => "R-Action", "width" => "70px", "edit" => ["type" => "CheckBox"]],
                            ["name" => "Delete", "caption" => "Del Rem.", "width" => "80px", "edit" => ["type" => "CheckBox"]]
                        ],
                        "values" => $syncValues
                    ]
                ]
            ];
        }
        return json_encode($form);
    }

    private function UpdateStaticFormElements(&$elements, $serverOptions, $folderOptions): void
    {
        foreach ($elements as &$element) {
            if (isset($element['items'])) $this->UpdateStaticFormElements($element['items'], $serverOptions, $folderOptions);
            if (!isset($element['name'])) continue;
            if ($element['name'] === 'LocalServerKey') $element['options'] = $serverOptions;
            if ($element['name'] === 'Targets') {
                foreach ($element['columns'] as &$col) if ($col['name'] === 'RemoteKey') $col['edit']['options'] = $serverOptions;
            }
            if ($element['name'] === 'Roots') {
                foreach ($element['columns'] as &$col) if ($col['name'] === 'TargetFolder') $col['edit']['options'] = $folderOptions;
            }
        }
    }

    public function ToggleAll(string $Column, bool $State, string $Folder): void
    {
        $this->SendDebug("BATCH_START", "Column: $Column, State: $State, Folder: $Folder", 0);
        $roots = json_decode($this->ReadPropertyString("Roots"), true);
        $currentData = $this->GetWorkingSyncList();

        $map = [];
        foreach ($currentData as $item) $map[$item['Folder'] . '_' . $item['ObjectID']] = $item;

        $uiValues = [];
        foreach ($roots as $root) {
            if (($root['TargetFolder'] ?? '') === $Folder && ($root['LocalRootID'] ?? 0) > 0) {
                $foundVars = [];
                $this->GetRecursiveVariables($root['LocalRootID'], $foundVars);
                foreach ($foundVars as $vID) {
                    $key = $Folder . '_' . $vID;
                    if (!isset($map[$key])) {
                        $map[$key] = ["Folder" => $Folder, "ObjectID" => $vID, "Name" => IPS_GetName($vID), "Active" => false, "Action" => false, "Delete" => false];
                    }
                    $map[$key][$Column] = $State;
                    $uiValues[] = $map[$key];
                }
            }
        }
        $this->WriteAttributeString("SyncListCache", json_encode(array_values($map)));
        $this->UpdateFormField("List_" . md5($Folder), "values", json_encode($uiValues));
        $this->SendDebug("BATCH_FINISH", "Total items in RAM: " . count($map), 0);
    }

    public function SaveSelections(): void
    {
        $data = $this->ReadAttributeString("SyncListCache");
        $this->SendDebug("SAVE_COMMIT", "Payload length: " . strlen($data), 0);

        IPS_SetProperty($this->InstanceID, "SyncList", $data);
        if (IPS_ApplyChanges($this->InstanceID)) {
            $this->SendDebug("SAVE_SUCCESS", "ApplyChanges completed", 0);
            echo "✅ Selections successfully persisted.";
        }
    }

    private function GetRecursiveVariables(int $parentID, array &$result): void
    {
        foreach (IPS_GetChildrenIDs($parentID) as $childID) {
            $obj = IPS_GetObject($childID);
            if ($obj['ObjectType'] === 2) $result[] = $childID;
            if ($obj['HasChildren']) $this->GetRecursiveVariables($childID, $result);
        }
    }

    public function UpdateUI(): void
    {
        $this->ReloadForm();
    }
    public function InstallRemoteScripts(string $Folder): void
    {
        echo "Installer for: $Folder";
    }
}
