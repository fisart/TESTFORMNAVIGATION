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
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . "/form.json"), true);
        $secID = $this->ReadPropertyInteger("LocalPasswordModuleID");
        $targets = json_decode($this->ReadPropertyString("Targets"), true);
        $roots = json_decode($this->ReadPropertyString("Roots"), true);
        $savedSync = json_decode($this->ReadPropertyString("SyncList"), true);

        $serverOptions = [["caption" => "Please select...", "value" => ""]];
        if ($secID > 0 && IPS_InstanceExists($secID)) {
            $keys = json_decode(@SEC_GetKeys($secID), true);
            if (is_array($keys)) {
                foreach ($keys as $k) $serverOptions[] = ["caption" => (string)$k, "value" => (string)$k];
            }
        }

        $folderOptions = [["caption" => "Select Target Folder...", "value" => ""]];
        foreach ($targets as $t) {
            if (!empty($t['Name'])) $folderOptions[] = ["caption" => $t['Name'], "value" => $t['Name']];
        }

        $this->UpdateStaticFormElements($form['elements'], $serverOptions, $folderOptions);

        $stateCache = [];
        if (is_array($savedSync)) {
            foreach ($savedSync as $item) {
                if (isset($item['Folder'], $item['ObjectID'])) {
                    $stateCache[$item['Folder'] . '_' . $item['ObjectID']] = [
                        'Active' => $item['Active'] ?? false,
                        'Action' => $item['Action'] ?? false,
                        'Delete' => $item['Delete'] ?? false
                    ];
                }
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
                            "Name"     => IPS_GetName($vID),
                            "Active"   => $stateCache[$key]['Active'] ?? false,
                            "Action"   => $stateCache[$key]['Action'] ?? false,
                            "Delete"   => $stateCache[$key]['Delete'] ?? false
                        ];
                    }
                }
            }

            $panel = [
                "type"    => "ExpansionPanel",
                "caption" => "TARGET: " . strtoupper($folderName) . " (" . count($syncValues) . " Variables)",
                "items"   => [
                    [
                        "type" => "RowLayout",
                        "items" => [
                            ["type" => "Label", "caption" => "Batch Tools:", "bold" => true, "width" => "100px"],
                            ["type" => "Button", "caption" => "Sync ALL", "onClick" => "RSM_ToggleAll(\$id, 'Active', true, '$folderName');", "width" => "100px"],
                            ["type" => "Button", "caption" => "Sync NONE", "onClick" => "RSM_ToggleAll(\$id, 'Active', false, '$folderName');", "width" => "100px"],
                            ["type" => "Label", "caption" => " | ", "width" => "20px"],
                            ["type" => "Button", "caption" => "Action ALL", "onClick" => "RSM_ToggleAll(\$id, 'Action', true, '$folderName');", "width" => "100px"],
                            ["type" => "Button", "caption" => "INSTALL SCRIPTS", "onClick" => "RSM_InstallRemoteScripts(\$id, '$folderName');"]
                        ]
                    ],
                    [
                        "type" => "List",
                        "name" => "List_" . md5($folderName),
                        "rowCount" => min(count($syncValues) + 1, 10),
                        "add" => false,
                        "delete" => false,
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
            $form['elements'][] = $panel;
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
                foreach ($element['columns'] as &$col) {
                    if ($col['name'] === 'RemoteKey') $col['edit']['options'] = $serverOptions;
                }
            }
            if ($element['name'] === 'Roots') {
                foreach ($element['columns'] as &$col) {
                    if ($col['name'] === 'TargetFolder') $col['edit']['options'] = $folderOptions;
                }
            }
        }
    }

    public function ToggleAll(string $Column, bool $State, string $Folder): void
    {
        $savedSync = json_decode($this->ReadPropertyString("SyncList"), true);
        if (!is_array($savedSync)) $savedSync = [];

        foreach ($savedSync as &$item) {
            if (isset($item['Folder']) && $item['Folder'] === $Folder) {
                $item[$Column] = $State;
            }
        }
        IPS_SetProperty($this->InstanceID, "SyncList", json_encode($savedSync));
        IPS_ApplyChanges($this->InstanceID);
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
        echo "Installing scripts for target: $Folder";
    }
}
