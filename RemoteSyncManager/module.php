<?php

declare(strict_types=1);

class RemoteSyncManager extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        // Register Properties as JSON Strings
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

        // 1. Read existing configurations
        $targets = json_decode($this->ReadPropertyString("Targets"), true);
        $roots = json_decode($this->ReadPropertyString("Roots"), true);
        $savedSync = json_decode($this->ReadPropertyString("SyncList"), true);

        // 2. Prepare Folder Options for the Roots-List
        $folderOptions = [];
        foreach ($targets as $t) {
            if (!empty($t['Name'])) {
                $folderOptions[] = ["caption" => $t['Name'], "value" => $t['Name']];
            }
        }

        // 3. Update the Dropdown in the Roots-List
        foreach ($form['elements'] as &$element) {
            if ($element['name'] === 'Roots') {
                foreach ($element['columns'] as &$col) {
                    if ($col['name'] === 'TargetFolder') {
                        $col['edit']['options'] = $folderOptions;
                    }
                }
            }
        }

        // 4. Generate the consolidated SyncList
        $syncValues = [];
        // Create a map of existing selections to keep them when the list refreshes
        $activeStates = [];
        foreach ($savedSync as $item) {
            $activeStates[$item['Folder'] . '_' . $item['ObjectID']] = $item['Active'] ?? false;
        }

        foreach ($roots as $root) {
            $rootID = $root['LocalRootID'];
            $folderName = $root['TargetFolder'];

            if ($rootID > 0 && IPS_ObjectExists($rootID) && !empty($folderName)) {
                $foundVars = [];
                $this->GetRecursiveVariables($rootID, $foundVars);

                foreach ($foundVars as $vID) {
                    $key = $folderName . '_' . $vID;
                    $syncValues[] = [
                        "Folder"   => $folderName,
                        "ObjectID" => $vID,
                        "Name"     => IPS_GetName($vID),
                        "Active"   => $activeStates[$key] ?? false
                    ];
                }
            }
        }

        // 5. Inject the generated values into the form
        foreach ($form['elements'] as &$element) {
            if ($element['name'] === 'SyncList') {
                $element['values'] = $syncValues;
            }
        }

        return json_encode($form);
    }

    private function GetRecursiveVariables(int $parentID, array &$result): void
    {
        foreach (IPS_GetChildrenIDs($parentID) as $childID) {
            $obj = IPS_GetObject($childID);
            if ($obj['ObjectType'] === 2) { // 2 = Variable
                $result[] = $childID;
            }
            if ($obj['HasChildren']) {
                $this->GetRecursiveVariables($childID, $result);
            }
        }
    }

    public function UpdateUI(): void
    {
        // This triggers a form reload
        $this->ReloadForm();
    }
}
