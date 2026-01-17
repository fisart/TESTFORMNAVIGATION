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

        // 1. Bestehende Konfigurationen einlesen
        $targets = json_decode($this->ReadPropertyString("Targets"), true);
        $roots = json_decode($this->ReadPropertyString("Roots"), true);
        $savedSync = json_decode($this->ReadPropertyString("SyncList"), true);

        // 2. Ordner-Optionen für die Roots-Liste vorbereiten
        $folderOptions = [];
        foreach ($targets as $t) {
            if (!empty($t['Name'])) {
                $folderOptions[] = ["caption" => $t['Name'], "value" => $t['Name']];
            }
        }

        // 3. Den Dropdown in der Roots-Liste dynamisch aktualisieren
        foreach ($form['elements'] as &$element) {
            // PRÜFUNG: Existiert 'name' und ist es 'Roots'?
            if (isset($element['name']) && $element['name'] === 'Roots') {
                foreach ($element['columns'] as &$col) {
                    if ($col['name'] === 'TargetFolder') {
                        $col['edit']['options'] = $folderOptions;
                    }
                }
            }
        }

        // 4. Die konsolidierte SyncList generieren
        $syncValues = [];
        $activeStates = [];
        foreach ($savedSync as $item) {
            if (isset($item['Folder'], $item['ObjectID'])) {
                $activeStates[$item['Folder'] . '_' . $item['ObjectID']] = $item['Active'] ?? false;
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
                        "Active"   => $activeStates[$key] ?? false
                    ];
                }
            }
        }

        // 5. Die generierten Werte in das Formular injizieren
        foreach ($form['elements'] as &$element) {
            // PRÜFUNG: Existiert 'name' und ist es 'SyncList'?
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
