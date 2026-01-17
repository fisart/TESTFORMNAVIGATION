<?php

declare(strict_types=1);

class RemoteSyncManager extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

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

        // 1. Lade Konfigurationsdaten
        $secID = $this->ReadPropertyInteger("LocalPasswordModuleID");
        $targets = json_decode($this->ReadPropertyString("Targets"), true);
        $roots = json_decode($this->ReadPropertyString("Roots"), true);
        $savedSync = json_decode($this->ReadPropertyString("SyncList"), true);

        // 2. SEC-Keys abrufen (Dropdown für Schritt 1)
        $serverOptions = [["caption" => "Please select...", "value" => ""]];
        if ($secID > 0 && IPS_InstanceExists($secID)) {
            // Wir nutzen die Funktion deines Secrets Managers
            $keysJSON = @SEC_GetKeys($secID);
            $keys = json_decode($keysJSON, true);
            if (is_array($keys)) {
                foreach ($keys as $k) {
                    $serverOptions[] = ["caption" => (string)$k, "value" => (string)$k];
                }
            }
        }

        // 3. Folder-Optionen vorbereiten (Dropdown für Schritt 2)
        $folderOptions = [];
        foreach ($targets as $t) {
            if (!empty($t['Name'])) {
                $folderOptions[] = ["caption" => $t['Name'], "value" => $t['Name']];
            }
        }

        // 4. Dynamische Injektion in die Listen-Spalten
        foreach ($form['elements'] as &$element) {
            if (!isset($element['name'])) continue;

            // Injektion SEC-Keys in Targets-Liste
            if ($element['name'] === 'Targets') {
                foreach ($element['columns'] as &$col) {
                    if ($col['name'] === 'RemoteKey') $col['edit']['options'] = $serverOptions;
                }
            }
            // Injektion Folder-Namen in Roots-Liste
            if ($element['name'] === 'Roots') {
                foreach ($element['columns'] as &$col) {
                    if ($col['name'] === 'TargetFolder') $col['edit']['options'] = $folderOptions;
                }
            }
        }

        // 5. Generierung der SyncList mit Beibehaltung ALLER Zustände
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

        // 6. SyncList in Formular einsetzen
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

    public function UpdateUI(): void
    {
        $this->ReloadForm();
    }
}
