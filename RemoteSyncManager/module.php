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

        // Unser persistenter RAM-Speicher
        $this->RegisterAttributeString("SyncListCache", "[]");
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        // Nach dem Speichern synchronisieren wir den Cache mit der Property
        $this->WriteAttributeString("SyncListCache", $this->ReadPropertyString("SyncList"));
    }

    /**
     * BLUEPRINT: RequestAction fängt Einzelklicks vom UI ab
     */
    public function RequestAction($Ident, $Value): void
    {
        switch ($Ident) {
            case "SaveIndividual":
                $payload = json_decode($Value, true);
                $folder = $payload['Folder'];
                $listData = $payload['Data'];

                // 1. Aktuellen Stand aus dem ATTR laden (RAM-Wahrheit)
                $data = json_decode($this->ReadAttributeString("SyncListCache"), true);
                if (!is_array($data)) $data = [];

                $map = [];
                foreach ($data as $item) $map[$item['Folder'] . '_' . $item['ObjectID']] = $item;

                // 2. Neue Daten aus dieser einen Liste einmischen
                foreach ($listData as $uiItem) {
                    $key = $folder . '_' . $uiItem['ObjectID'];
                    $map[$key] = [
                        "Folder"   => $folder,
                        "ObjectID" => $uiItem['ObjectID'],
                        "Name" => $uiItem['Name'],
                        "Active"   => $uiItem['Active'],
                        "Action" => $uiItem['Action'],
                        "Delete" => $uiItem['Delete']
                    ];
                }
                // 3. Zurück in den RAM schreiben
                $this->WriteAttributeString("SyncListCache", json_encode(array_values($map)));
                break;
        }
    }

    public function GetConfigurationForm(): string
    {
        // Falls Cache leer (Erstes Öffnen), von Property laden
        $currentCache = $this->ReadAttributeString("SyncListCache");
        if ($currentCache === "" || $currentCache === "[]") {
            $currentCache = $this->ReadPropertyString("SyncList");
            $this->WriteAttributeString("SyncListCache", $currentCache);
        }

        $form = json_decode(file_get_contents(__DIR__ . "/form.json"), true);
        $secID = $this->ReadPropertyInteger("LocalPasswordModuleID");
        $targets = json_decode($this->ReadPropertyString("Targets"), true);
        $roots = json_decode($this->ReadPropertyString("Roots"), true);

        // Wir rendern das Formular IMMER aus dem RAM-Cache
        $savedSync = json_decode($currentCache, true);
        if (!is_array($savedSync)) $savedSync = [];

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
                            "Name" => IPS_GetName($vID),
                            "Active"   => $stateCache[$key]['Active'] ?? false,
                            "Action"   => $stateCache[$key]['Action'] ?? false,
                            "Delete"   => $stateCache[$key]['Delete'] ?? false
                        ];
                    }
                }
            }

            $listName = "List_" . md5($folderName);
            // BLUEPRINT: Umwandlung Proxy -> Array -> RequestAction
            $onChangeLogic = "\$D=[]; foreach(\$$listName as \$r){ \$D[]=\$r; } IPS_RequestAction(\$id, 'SaveIndividual', json_encode(['Folder'=>'$folderName', 'Data'=>\$D]));";

            $form['elements'][] = [
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
                        "onChange" => $onChangeLogic,
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
        $roots = json_decode($this->ReadPropertyString("Roots"), true);
        $data = json_decode($this->ReadAttributeString("SyncListCache"), true);
        if (!is_array($data)) $data = [];

        $currentMap = [];
        foreach ($data as $item) $currentMap[$item['Folder'] . '_' . $item['ObjectID']] = $item;

        $uiValues = [];
        foreach ($roots as $root) {
            if (($root['TargetFolder'] ?? '') === $Folder && ($root['LocalRootID'] ?? 0) > 0) {
                $foundVars = [];
                $this->GetRecursiveVariables($root['LocalRootID'], $foundVars);
                foreach ($foundVars as $vID) {
                    $key = $Folder . '_' . $vID;
                    if (!isset($currentMap[$key])) {
                        $currentMap[$key] = ["Folder" => $Folder, "ObjectID" => $vID, "Name" => IPS_GetName($vID), "Active" => false, "Action" => false, "Delete" => false];
                    }
                    $currentMap[$key][$Column] = $State;
                    $uiValues[] = $currentMap[$key];
                }
            }
        }

        $this->WriteAttributeString("SyncListCache", json_encode(array_values($currentMap)));
        $this->UpdateFormField("List_" . md5($Folder), "values", json_encode($uiValues));
    }

    private function GetRecursiveVariables(int $parentID, array &$result): void
    {
        foreach (IPS_GetChildrenIDs($parentID) as $childID) {
            $obj = IPS_GetObject($childID);
            if ($obj['ObjectType'] === 2) $result[] = $childID;
            if ($obj['HasChildren']) $this->GetRecursiveVariables($childID, $result);
        }
    }

    public function SaveSelections(): void
    {
        $data = $this->ReadAttributeString("SyncListCache");
        // RAM-Daten "hart" in die Property schreiben
        IPS_SetProperty($this->InstanceID, "SyncList", $data);
        IPS_ApplyChanges($this->InstanceID);
        echo "✅ All selections (individual & batch) saved successfully.";
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
