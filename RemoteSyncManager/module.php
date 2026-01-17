<?php

declare(strict_types=1);

class RemoteSyncManager extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        // Global Properties
        $this->RegisterPropertyBoolean("DebugMode", false);
        $this->RegisterPropertyBoolean("AutoCreate", true);
        $this->RegisterPropertyBoolean("ReplicateProfiles", true);
        $this->RegisterPropertyInteger("LocalPasswordModuleID", 0);

        // List Properties
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

        // 1. Data Retrieval
        $secID = $this->ReadPropertyInteger("LocalPasswordModuleID");
        $targets = json_decode($this->ReadPropertyString("Targets"), true);
        $roots = json_decode($this->ReadPropertyString("Roots"), true);
        $savedSync = json_decode($this->ReadPropertyString("SyncList"), true);

        // 2. Fetch SEC Keys for Dropdowns
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

        // 3. Prepare Folder Options for Step 2
        $folderOptions = [["caption" => "Select Target Folder...", "value" => ""]];
        foreach ($targets as $t) {
            if (!empty($t['Name'])) {
                $folderOptions[] = ["caption" => $t['Name'], "value" => $t['Name']];
            }
        }

        // 4. Dynamic UI Injection
        foreach ($form['elements'] as &$element) {
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
                    if ($col['name'] === 'TargetFolder') {
                        $col['edit']['options'] = $folderOptions;
                    }
                }
            }
        }

        // 5. Generate SyncList with State Retention
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

        // 6. Inject Values into SyncList
        foreach ($form['elements'] as &$element) {
            if (isset($element['name']) && $element['name'] === 'SyncList') {
                $element['values'] = $syncValues;
            }
        }

        return json_encode($form);
    }

    private function GetRecursiveVariables(int $parentID, array &$result): void
    {
        foreach (IPS_GetChildrenIDs($parentID) as $childID) {
            $obj = IPS_GetObject($childID);
            if ($obj['ObjectType'] === 2) $result[] = $childID;
            if ($obj['HasChildren']) $this->GetRecursiveVariables($childID, $result);
        }
    }

    public function ToggleAll(string $Column, bool $State): void
    {
        $savedSync = json_decode($this->ReadPropertyString("SyncList"), true);
        foreach ($savedSync as &$item) {
            $item[$Column] = $State;
        }
        // Update UI immediately
        $this->UpdateFormField("SyncList", "values", json_encode($savedSync));
        // Save to Property so it persists
        IPS_SetProperty($this->InstanceID, "SyncList", json_encode($savedSync));
        IPS_ApplyChanges($this->InstanceID);
    }

    public function UpdateUI(): void
    {
        $this->ReloadForm();
    }

    public function InstallRemoteScripts(): void
    {
        echo "Installer: This will be implemented in the next phase to deploy scripts to all targets.";
    }
}
