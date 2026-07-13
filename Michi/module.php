<?php

declare(strict_types=1);

class Michi extends IPSModule
{
    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('UpdateInterval', 60);

        // Attribut für den Empfangspuffer
        $this->RegisterAttributeString('ReceiveBuffer', '');

        // Timer
        $this->RegisterTimer('UpdateTimer', 0, 'MICHI_RequestStatus($_IPS[\'TARGET\']);');
        $this->RegisterTimer('ResponseTimeout', 0, 'MICHI_HandleTimeout($_IPS[\'TARGET\']);');

        // Variablen registrieren
        $this->RegisterVariableBoolean('Power', 'Power', '', 10);
        $this->EnableAction('Power');

        $this->RegisterVariableInteger('Dimmer', 'Display Helligkeit', '', 20);
        $this->EnableAction('Dimmer');

        $this->RegisterVariableString('Model', 'Modell', '', 30);
        $this->RegisterVariableString('Version', 'Software Version', '', 40);
        $this->RegisterVariableString('IP', 'IP-Adresse', '', 50);
        $this->RegisterVariableString('MAC', 'MAC-Adresse', '', 60);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Self-Healing: Reset all corrupted presentations
        if (function_exists('IPS_SetVariableCustomPresentation')) {
            IPS_SetVariableCustomPresentation($this->GetIDForIdent('Power'), []);
            IPS_SetVariableCustomPresentation($this->GetIDForIdent('Dimmer'), []);
            IPS_SetVariableCustomPresentation($this->GetIDForIdent('Model'), []);
            IPS_SetVariableCustomPresentation($this->GetIDForIdent('Version'), []);
            IPS_SetVariableCustomPresentation($this->GetIDForIdent('IP'), []);
            IPS_SetVariableCustomPresentation($this->GetIDForIdent('MAC'), []);
        }

        IPS_SetVariableCustomPresentation($this->GetIDForIdent('Power'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON'         => 'Power'
        ]);

        IPS_SetVariableCustomPresentation($this->GetIDForIdent('Dimmer'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'ICON'         => 'Bulb',
            'MIN'          => 0,
            'MAX'          => 100,
            'STEP'         => 25,
            'SUFFIX'       => '%'
        ]);
        
        // No custom PRESENTATION for strings, let Symcon use default String display

        // Wir erzwingen, dass ein Parent (Client Socket) existiert
        $this->RequireParent("{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}");

        // Timer setzen
        $interval = $this->ReadPropertyInteger('UpdateInterval');
        if ($interval > 0) {
            $this->SetTimerInterval('UpdateTimer', $interval * 1000);
            $this->RequestStatus();
        } else {
            $this->SetTimerInterval('UpdateTimer', 0);
        }

        // Initiale Sichtbarkeit der Variablen setzen
        $this->UpdatePowerState($this->GetValue('Power'));

        // Statische Infos (Version, Modell, IP, MAC) nur einmalig beim Start abfragen
        // Der Michi antwortet darauf praktischerweise auch im Standby!
        $this->SendCommand("version?");
        $this->SendCommand("model?");
        $this->SendCommand("ip?");
        $this->SendCommand("mac?");
    }


    private function GetConnectionID(): int
    {
        $instance = IPS_GetInstance($this->InstanceID);
        return $instance['ConnectionID'];
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Power':
                if ($Value) {
                    $this->SendCommand("power_on");
                } else {
                    $this->SendCommand("power_off");
                }
                $this->UpdatePowerState($Value);
                break;
            case 'Dimmer':
                // Google Home liefert 0-100%. Michi erwartet 0 (am hellsten) bis 4 (am dunkelsten).
                // 100% -> 0, 75% -> 1, 50% -> 2, 25% -> 3, 0% -> 4
                $val = 4 - (int)round((max(0, min(100, (int)$Value))) / 25);
                $this->SendCommand("dimmer_" . $val);
                $this->SetValue($Ident, $Value);
                break;
        }
    }

    public function RequestStatus(): void
    {
        $parentId = $this->GetConnectionID();
        if ($parentId > 0) {
            $status = IPS_GetInstance($parentId)['InstanceStatus'];
            if ($status != 102) { // 102 = IS_ACTIVE
                // Socket ist getrennt (Michi ist vermutlich im Deep Standby oder stromlos)
                if ($this->GetValue('Power')) {
                    $this->SetValue('Power', false);
                }
                return;
            }
        }

        // Starte Timeout-Timer (3 Sekunden). Wenn der Michi aus ist, ignoriert er
        // alle folgenden Befehle. (power? senden wir absichtlich nicht, da er hier lügt)
        $this->SetTimerInterval('ResponseTimeout', 3000);

        $this->SendCommand("dimmer?");
        $this->SendCommand("source?");
    }

    private function SendCommand(string $command): void
    {
        $parentId = $this->GetConnectionID();
        if ($parentId > 0 && IPS_GetInstance($parentId)['InstanceStatus'] == 102) {
            $json = json_encode([
                "DataID" => "{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}",
                "Buffer" => utf8_encode($command . "!")
            ]);
            $this->SendDataToParent($json);
            $this->SendDebug("SEND", $command . "!", 0);
        }
    }

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString);
        $newData = utf8_decode($data->Buffer);
        
        $lowerData = strtolower($newData);
        
        // Wenn wir dimmer oder source empfangen, ist der Michi definitiv an!
        if (strpos($lowerData, 'dimmer=') !== false || strpos($lowerData, 'source=') !== false) {
            $this->SetTimerInterval('ResponseTimeout', 0);
            
            // Wenn er auf unsere Fragen antwortet, ist er definitiv an!
            if (!$this->GetValue('Power')) {
                $this->UpdatePowerState(true);
            }
        }

        $buffer = $this->ReadAttributeString('ReceiveBuffer');
        $buffer .= $newData;
        
        $this->SendDebug("RECV_RAW", $newData, 0);

        // Nachrichten extrahieren (Ende-Zeichen ist $)
        $pos = strpos($buffer, '$');
        while ($pos !== false) {
            $msg = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 1);
            
            $this->ProcessMessage($msg);
            
            $pos = strpos($buffer, '$');
        }

        // Rest im Puffer speichern
        $this->WriteAttributeString('ReceiveBuffer', $buffer);
    }

    private function ProcessMessage(string $msg): void
    {
        $this->SendDebug("MESSAGE", $msg, 0);

        // Die Nachrichten haben das Format: variable=wert
        $parts = explode('=', $msg, 2);
        if (count($parts) != 2) return;

        $key = trim($parts[0]);
        $value = trim($parts[1]);

        switch (strtolower($key)) {
            case 'power':
                if ($value === 'on') {
                    $this->UpdatePowerState(true);
                } elseif ($value === 'standby') {
                    $this->UpdatePowerState(false);
                }
                break;
            case 'dimmer':
                // Michi liefert 0 (am hellsten) bis 4 (am dunkelsten). Umrechnung in 0-100%
                $symconVal = (4 - (int)$value) * 25;
                $this->SetValue('Dimmer', $symconVal);
                break;
            case 'version':
                $this->SetValue('Version', $value);
                break;
            case 'model':
                $this->SetValue('Model', $value);
                break;
            case 'ip':
                $this->SetValue('IP', $value);
                break;
            case 'mac':
                $this->SetValue('MAC', $value);
                break;
        }
    }

    public function HandleTimeout(): void
    {
        // Der Timer hat ausgelöst, was bedeutet, dass der Michi auf unsere Anfrage
        // nicht geantwortet hat. Er ist im Standby und ignoriert Befehle.
        $this->SetTimerInterval('ResponseTimeout', 0);
        
        if ($this->GetValue('Power')) {
            $this->UpdatePowerState(false);
            $this->SendDebug("TIMEOUT", "Keine Antwort erhalten. Setze Power auf Aus.", 0);
        }
    }

    private function UpdatePowerState(bool $state): void
    {
        if ($this->GetValue('Power') !== $state) {
            $this->SetValue('Power', $state);
        }
        
        $hide = !$state;
        $this->SetHiddenSafe('Dimmer', $hide);
        $this->SetHiddenSafe('Model', $hide);
        $this->SetHiddenSafe('Version', $hide);
        $this->SetHiddenSafe('IP', $hide);
        $this->SetHiddenSafe('MAC', $hide);
    }

    private function SetHiddenSafe(string $ident, bool $hidden): void
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id !== false && $id > 0) {
            IPS_SetHidden($id, $hidden);
        }
    }

    protected function LogMessage(string $Message, int $Type): bool
    {
        IPS_LogMessage('SmartVillaKunterbunt', 'Michi: ' . $Message);
        return true;
    }
}

