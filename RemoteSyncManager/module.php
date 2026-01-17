<?php

declare(strict_types=1);

class RemoteSyncManager extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        // Properties
        $this->RegisterPropertyBoolean("DebugMode", false);
        $this->RegisterPropertyBoolean("AutoCreate", true);
        $this->RegisterPropertyBoolean("ReplicateProfiles", true);
        $this->RegisterPropertyInteger("LocalPasswordModuleID", 0);
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

        // 1. Fetch SEC Keys
        $serverOptions = [["caption" => "Please select...", "value" => ""]];
        if ($secID > 0 && IPS_InstanceExists($secID)) {
            $keysJSON = @SEC_GetKeys($secID);
            $keys = json_decode($keysJSON, true);
            if (is_array($keys)) {
                foreach ($keys as $k) {
                    $serverOptions[] = ["caption" => (string)$k, "value" => (string)$k];
                }
            }
        }

        // 2. Prepare Folder & Batch Options
        $folderOptions = [["caption" => "Select Target Folder...", "value" => ""]];
        $batchOptions = [["caption" => "All Targets", "value" => "ALL"]];
        foreach ($targets as $t) {
            if (!empty($t['Name'])) {
                $folderOptions[] = ["caption" => $t['Name'], "value" => $t['Name']];
                $batchOptions[] = ["caption" => "Only " . $t['Name'], "value" => $t['Name']];
            }
        }

        // 3. Dynamic Injection (Recursive helper to find elements in ExpansionPanels)
        $this->UpdateFormElements($form['elements'], $serverOptions, $folderOptions, $batchOptions);

        // 4. Generate SyncList
        $syncValues = [];
        $stateCache = [];
        foreach ($savedSync as $item) {
            if (isset($item['Folder'], $item['ObjectID'])) {
                $key = $item['Folder'] . '_' . $item['ObjectID'];
                $stateCache[$key] = [
                    'Active' => $item['Active'] ?? false,
                    'Action' => $item['Action'] ?? false,
                    'Delete' => $item['Delete'] ?? false
                ];
            }
        }

        foreach ($roots as $root) {
            $rootID = $root['LocalRootID'] ?? 0;
            $folderName = $root['TargetFolder'] ?? '';
            if ($rootID > 0 && IPS_ObjectExists($rootID) && !empty($folderName)) {
                $foundVars = [];
                $this->GetRecursiveVariables($rootID, $foundVars);
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

        // 5. Inject SyncList
        foreach ($form['elements'] as &$element) {
            if (isset($element['name']) && $element['name'] === 'SyncList') {
                $element['values'] = $syncValues;
            }
        }

        return json_encode($form);
    }

    private function UpdateFormElements(&$elements, $serverOptions, $folderOptions, $batchOptions): void
    {
        foreach ($elements as &$element) {
            if (isset($element['items'])) {
                $this->UpdateFormElements($element['items'], $serverOptions, $folderOptions, $batchOptions);
            }
            if (!isset($element['name'])) continue;

            if ($element['name'] === 'Targets') {
                foreach ($element['columns'] as &$col) {
                    if ($col['name'] === 'RemoteKey' || $col['name'] === 'LocalServerKey') {
                        $col['edit']['options'] = $serverOptions;
                    }
                }
            }
            if ($element['name'] === 'Roots') {
                foreach ($element['columns'] as &$col) {
                    if ($col['name'] === 'TargetFolder') $col['edit']['options'] = $folderOptions;
                }
            }
            if ($element['name'] === 'BatchFilter') {
                $element['options'] = $batchOptions;
            }
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

    public function ToggleAll(string $Column, bool $State, string $Filter): void
    {
        $savedSync = json_decode($this->ReadPropertyString("SyncList"), true);
        foreach ($savedSync as &$item) {
            if ($Filter === "ALL" || $item['Folder'] === $Filter) {
                $item[$Column] = $State;
            }
        }
        $this->UpdateFormField("SyncList", "values", json_encode($savedSync));
        IPS_SetProperty($this->InstanceID, "SyncList", json_encode($savedSync));
        // No ApplyChanges here to keep UI flow
    }

    public function UpdateUI(): void
    {
        $this->ReloadForm();
    }

    public function InstallRemoteScripts(): void
    {
        echo "Installer: Running deployment for all targets...";
    }
}
