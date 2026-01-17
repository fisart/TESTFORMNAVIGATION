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
    }

    public function RequestAction($Ident, $Value): void
    {
        switch ($Ident) {
            case "UpdateRow":
                $row = json_decode($Value, true);
                if (!$row || !isset($row['Folder'], $row['ObjectID'])) return;

                $currentCache = json_decode($this->ReadAttributeString("SyncListCache"), true);
                if (!is_array($currentCache)) $currentCache = [];

                $map = [];
                foreach ($currentCache as $item) {
                    $map[$item['Folder'] . '_' . $item['ObjectID']] = $item;
                }

                $key = $row['Folder'] . '_' . $row['ObjectID'];
                $map[$key] = [
                    "Folder"   => $row['Folder'],
                    "ObjectID" => $row['ObjectID'],
                    "Name" => $row['Name'],
                    "Active"   => $row['Active'],
                    "Action" => $row['Action'],
                    "Delete" => $row['Delete']
                ];

                $final = json_encode(array_values($map));
                $this->WriteAttributeString("SyncListCache", $final);
                IPS_SetProperty($this->InstanceID, "SyncList", $final);
                break;
        }
    }

    public function GetConfigurationForm(): string
    {
        if ($this->ReadAttributeString("SyncListCache") === "[]") {
            $this->WriteAttributeString("SyncListCache", $this->ReadPropertyString("SyncList"));
        }

        $form = json_decode(file_get_contents(__DIR__ . "/form.json"), true);

        // Statische Footer-Buttons parken
        $staticFooter = $form['actions'];
        $form['actions'] = [];

        $secID = $this->ReadPropertyInteger("LocalPasswordModuleID");
        $targets = json_decode($this->ReadPropertyString("Targets"), true);
        $roots = json_decode($this->ReadPropertyString("Roots"), true);
        $savedSync = json_decode($this->ReadAttributeString("SyncListCache"), true);

        $serverOptions = [["caption" => "Please select...", "value" => ""]];
        if ($secID > 0 && IPS_InstanceExists($secID)) {
            $keys = json_decode(@SEC_GetKeys($secID), true);
            if (is_array($keys)) foreach ($keys as $k) $serverOptions[] = ["caption" => (string)$k, "value" => (string)$k];
        }
        $folderOptions = [["caption" => "Select Target Folder...", "value" => ""]];
        foreach ($targets as $t) if (!empty($t['Name'])) $folderOptions[] = ["caption" => $t['Name'], "value" => $t['Name']];
        $this->UpdateStaticFormElements($form['elements'], $serverOptions, $folderOptions);

        $stateCache = [];
        if (is_array($savedSync)) {
            foreach ($savedSync as $item) $stateCache[$item['Folder'] . '_' . $item['ObjectID']] = $item;
        }

        foreach ($targets as $target) {
            if (empty($target['Name'])) continue;
            $folderName = $target['Name'];
            $syncValues = [];

            foreach ($roots as $root) {
                if (($root['TargetFolder'] ?? '') === $folderName && ($root['LocalRootID'] ?? 0) > 0 && IPS_ObjectExists($root['LocalRootID'])) {
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
            $onEdit = "IPS_RequestAction(\$id, 'UpdateRow', json_encode(\$$listName));";

            $form['actions'][] = [
                "type"    => "ExpansionPanel",
                "caption" => "TARGET: " . strtoupper($folderName) . " (" . count($syncValues) . " Variables)",
                "items"   => [
                    [
                        "type" => "RowLayout",
                        "items" => [
                            ["type" => "Button", "caption" => "Sync ALL", "onClick" => "RSM_ToggleAll(\$id, 'Active', true, '$folderName');", "width" => "90px"],
                            ["type" => "Button", "caption" => "Sync NONE", "onClick" => "RSM_ToggleAll(\$id, 'Active', false, '$folderName');", "width" => "90px"],
                            ["type" => "Label", "caption" => "|", "width" => "15px"],
                            ["type" => "Button", "caption" => "Action ALL", "onClick" => "RSM_ToggleAll(\$id, 'Action', true, '$folderName');", "width" => "90px"],
                            ["type" => "Label", "caption" => "|", "width" => "15px"],
                            // LOKALER SAVE BUTTON
                            ["type" => "Button", "caption" => "💾 SAVE THIS SET", "onClick" => "RSM_SaveSelections(\$id);", "width" => "130px", "confirm" => "Save all pending changes for this and other sets?"],
                            ["type" => "Button", "caption" => "INSTALL REMOTE", "onClick" => "RSM_InstallRemoteScripts(\$id, '$folderName');"]
                        ]
                    ],
                    [
                        "type" => "List",
                        "name" => $listName,
                        "rowCount" => min(count($syncValues) + 1, 15),
                        "add" => false,
                        "delete" => false,
                        "onEdit" => $onEdit,
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

        // Globalen Footer wieder anhängen (als Backup)
        foreach ($staticFooter as $btn) {
            $form['actions'][] = $btn;
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

        $map = [];
        foreach ($data as $item) $map[$item['Folder'] . '_' . $item['ObjectID']] = $item;

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
        $final = json_encode(array_values($map));
        $this->WriteAttributeString("SyncListCache", $final);
        IPS_SetProperty($this->InstanceID, "SyncList", $final);
        $this->UpdateFormField("List_" . md5($Folder), "values", json_encode($uiValues));
    }

    public function SaveSelections(): void
    {
        $data = $this->ReadAttributeString("SyncListCache");
        IPS_SetProperty($this->InstanceID, "SyncList", $data);
        IPS_ApplyChanges($this->InstanceID);
        echo "✅ All selections (all targets) saved.";
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
        echo "Installing scripts for: $Folder";
    }
}
