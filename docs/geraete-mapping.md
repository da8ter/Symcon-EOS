# Geräte-Anbindung: Beispiele für die Steuerung

Die Geräte-Instanzen (EOS Batterie, EOS E-Auto, EOS Haushaltsgerät) enthalten keinen herstellerspezifischen
Code. Was zur Slot-Grenze mit der Hardware passiert, legt der Anwender im Panel **Steuerung** der Instanz
fest. Drei Mechanismen stehen zur Verfügung und lassen sich kombinieren:

| Option im Formular | Felder | Wann sinnvoll |
| --- | --- | --- |
| **Option 1: Bei Moduswechsel Werte in Variablen schreiben** | „Modus-Variable“ mit Tabelle „Wert je EOS-Modus“, „Soll-Ladeleistung“, „Entladen erlaubt“ … (nur schaltbare Variablen wählbar) | Das Hersteller-Modul hat schaltbare Variablen (evcc, openWB, Modbus, MQTT, Shelly …). Der Wert wird auf den Typ der Variable umgewandelt (Bool/Int/Float/String) und per `RequestAction` geschrieben. |
| **Option 2: Je EOS-Modus eine Instanzaktion ausführen** und **Option 3: Bei jeder Änderung, jedem Moduswechsel und jedem Heartbeat Instanzaktion oder Skript ausführen** | ein Auswahlfeld pro Modus (Option 2), „Aktion bei jedem Wechsel“ (Option 3) | Das Modul bietet Aktionen an (z. B. „Ladestrom setzen“), oder ein fertiger Ablauf soll beim Wechsel laufen. Das Feld „Ziel für Aktionen“ (Variable oder Instanz, Standard: die Modus-Variable) öffnet die Auswahl direkt mit den Aktionen dieses Geräts. Kontext (Modus, Leistung …) wird als Parameter mitgegeben, eigene Parameter der Aktion (TARGET, VALUE) gewinnen. |
| **Option 3, Skript** | „Skript bei jedem Wechsel“ | Alles andere. Das Skript bekommt den Kontext in `$_IPS` (siehe unten). |

Reihenfolge je Durchlauf: Option 1 → Option 2 → Option 3 (Aktion, dann Skript).

- **Option 1** schreibt nur, was sich geändert hat; ist ein Heartbeat fällig, gehen alle gesunden Ziele gemeinsam
  erneut hinaus. Erfolg erkennt die
  Instanz am Rückgabewert von `RequestAction`; Symcon wirft dabei keine Ausnahme. Ein fehlgeschlagenes, gelöschtes
  oder nicht schaltbares Ziel wird gemerkt und nach 60 s × Anzahl Fehlschläge (höchstens 300 s) erneut versucht;
  ein anderer Sollwert geht sofort hinaus.
- **Option 2** feuert nur beim Wechsel in den Modus (Flanke), nie beim Heartbeat; fehlgeschlagen wird sie mit
  demselben Abstand wiederholt. Als fehlgeschlagen gilt eine Aktion, deren Ausgabe eine PHP-Fehlermeldung enthält
  (Zeile mit `Fatal error:`, `Warning:` …) oder die Symcon nicht kennt; sonstige Ausgabe gilt als Erfolg und steht
  gekürzt im Ergebnis-Text.
- **Option 3** läuft bei jeder Änderung des Sollzustands (Modus, Faktor und jeder Sollwert, auch ohne
  Option-1-Bindung), jedem Moduswechsel, jedem Heartbeat (auch bei reiner Skript-Anbindung), nach einer gefeuerten
  Modus-Aktion und beim erzwungenen Schreiben (`EOSBAT_ApplyControl($id, true)`), nicht aber für reine
  Wiederholungen von Option 1. Scheitert sie selbst, wiederholt sie denselben Stand mit eigenem Abstand; ein neuer
  Stand läuft sofort. Das Skript startet über `IPS_RunScriptEx` asynchron: „OK“ heißt gestartet, nicht fertig.

## Kontext für Aktionen und Skripte (`$_IPS`)

| Schlüssel | Inhalt |
| --- | --- |
| `InstanceID`, `DeviceID` | Symcon-Instanz und EOS-Geräte-ID |
| `Reason` | Warum jetzt: `plan`, `fallback`, `manual` (Quelle bei Änderung oder Moduswechsel), `force` (erzwungenes Schreiben), `disable` (Steuerung abgeschaltet oder „Aktiv“ verlassen), `heartbeat`, `retry` (nur Wiederholung) |
| `Source` | Quelle des Sollzustands: `plan`, `fallback`, `manual` |
| `ModeRaw`, `Mode` | EOS-Modus als Text und als Zahl der Betriebsmodus-Variable |
| `Factor` | `operation_mode_factor` 0–1 |
| `ExecutionTime` | Beginn der Anweisung (ISO 8601), leer bei Fallback/manuell |
| `Simulation` | `true` im Steuerungsmodus „Simulation“ (dann werden Skripte und Aktionen NICHT ausgeführt) |
| `Changed` | Kommagetrennte Liste der geänderten Werte: gebundene Ziele, die jetzt geschrieben werden, und jeder Sollwert, der sich seit dem letzten Lauf von Aktion/Skript geändert hat |
| `Retried` | Kommagetrennte Liste der Ziele, die nach einem Fehlschlag erneut geschrieben werden |
| `Heartbeat` | `true`, wenn der Durchlauf ein Heartbeat ist |
| `ModeChanged` | `true`, wenn der EOS-Modus seit dem letzten Durchlauf gewechselt hat |
| `Resync` | `true` beim ersten Durchlauf nach Übernehmen oder Symcon-Neustart: der Gerätezustand war unbekannt, der Durchlauf schreibt alles neu |
| `Degraded` | Text, wenn die Politik den Modus abgeschwächt hat (z. B. Netzladen nicht erlaubt) |
| Batterie | `PowerW` (Soll-Ladeleistung), `DischargePowerW`, `DischargeAllowed`, `GridCharge` |
| E-Auto | `ChargeAllowed`, `CurrentA`, `PowerW`, `Plugged` |
| Haushaltsgerät | `Run`, `Start` (echter Startimpuls), `InstructionId` (ändert sich bei jedem EOS-Lauf) |

## Batterie

### Wechselrichter mit Modus-Variable (Modbus, MQTT, Hersteller-Modul)

1. Zielvariable **Betriebsmodus** auf die Modus-Variable des Hersteller-Moduls (Int oder String) legen.
2. In der Tabelle „Wert je EOS-Modus“ je Zeile den Wert eintragen, den das Modul erwartet. Jeder Wert muss zum
   Typ der Modus-Variable passen, sonst sperrt die Instanz die Steuerung mit einem Hinweis. Beispiel für ein
   Modul mit `0 = Automatik, 1 = Laden sperren, 2 = Entladen sperren, 3 = Netzladen`:

   | EOS | Wert |
   | --- | --- |
   | IDLE | 1 |
   | SELF_CONSUMPTION | 0 |
   | NON_EXPORT | 2 |
   | PEAK_SHAVING | 1 |
   | GRID_SUPPORT_IMPORT | 3 |
   | FORCED_CHARGE | 3 |
   | GRID_SUPPORT_EXPORT | 0 |

   Leere Zeile = für diesen Modus wird die Modus-Variable nicht geschrieben.
3. Zusätzlich **Soll-Ladeleistung (W)** auf das Register/die Variable für die Netzladeleistung legen. Bei
   Netzladen steht dort `Faktor × max. Ladeleistung` (Wert aus dem EOS-Geräteeintrag), sonst 0. Der
   Einspeise-Sollwert bei `GRID_SUPPORT_EXPORT` ist derselbe Faktor × max. Ladeleistung, höchstens die max.
   Entladeleistung.
4. **Entladen erlaubt (Bool)** bzw. **Entladeleistungs-Grenze (W)** für Module, die Entladen sperren können.

### evcc (MQTT)

Der Speicher hängt in evcc an der Site: Variable `BatteryDischargeControl` (Bool) als Ziel „Entladen erlaubt“
kann evcc nicht 1:1 abbilden (evcc steuert die Entladesperre selbst). Sinnvoll ist die Netzlade-Grenze:
Skript-Bindung, das bei `GridCharge` den `BatteryGridChargeLimit` auf den aktuellen Preis setzt und sonst
zurücknimmt.

### Skript-Vorlage

```php
<?php
// Aktionsskript der EOS-Batterie: $_IPS enthält den Kontext.
if ($_IPS['Simulation']) { return; }
switch ($_IPS['ModeRaw']) {
    case 'GRID_SUPPORT_IMPORT':
    case 'FORCED_CHARGE':
        MEINWR_SetGridCharge(12345, true, (int) $_IPS['PowerW']);
        break;
    case 'NON_EXPORT':
    case 'IDLE':
        MEINWR_SetGridCharge(12345, false, 0);
        MEINWR_SetDischargeLock(12345, true);
        break;
    default: // SELF_CONSUMPTION, PEAK_SHAVING, GRID_SUPPORT_EXPORT
        MEINWR_SetGridCharge(12345, false, 0);
        MEINWR_SetDischargeLock(12345, false);
}
```

### Sicherheitsschalter der Batterie

- **Netzladen erlauben** (Standard an): aus → `GRID_SUPPORT_IMPORT`/`FORCED_CHARGE` werden zu `NON_EXPORT`.
- **Netzeinspeisung erlauben** (Standard aus): aus → `GRID_SUPPORT_EXPORT` wird zu `SELF_CONSUMPTION`.
- Ein Netzlade-Modus mit 0 W wird nie geschrieben (wird zu `NON_EXPORT`).
- Fallback (Plan veraltet/abgelaufen): Standard `SELF_CONSUMPTION`.

## E-Auto / Wallbox

### evcc (MQTT-Modul `evccLoadPointId`)

| Ziel | evcc-Variable | Hinweis |
| --- | --- | --- |
| Lademodus (String) | `Mode` | Tabelle: IDLE → `off`, SELF_CONSUMPTION → `pv`, GRID_SUPPORT_IMPORT / FORCED_CHARGE → `now`, übrige leer |
| Soll-Ladestrom (A) | `MaxCurrent` | evcc regelt zwischen `MinCurrent` und `MaxCurrent`; alternativ `MinCurrent` = `MaxCurrent` setzen |
| Laden erlaubt (Bool) | `Enabled` | optional |

### go-eCharger (Modul `GOeCharger`)

Das Modul bietet Funktionen statt Variablen: **Skript bei jedem Wechsel**

```php
<?php
if ($_IPS['Simulation']) { return; }
$id = 23456; // go-e Instanz
GOeCharger_setActive($id, (bool) $_IPS['ChargeAllowed']);
if ($_IPS['ChargeAllowed']) {
    GOeCharger_setCurrentChargingAmperage($id, (int) round($_IPS['CurrentA']));
}
```

### Regeln des E-Auto-Moduls

- Laden, wenn EOS einen Faktor > 0 in einem Lademodus plant; Strom = `Faktor × max. Ladeleistung / (Phasen × Spannung)`.
- Unter dem **Mindest-Ladestrom** (Standard 6 A) wird auf den Mindeststrom angehoben, weil das Auto sonst gar nicht lädt.
- **Mindestabstand Laden Ein/Aus** (Standard 300 s) verhindert Schützflattern durch stochastische Neuplanung; in der Wartezeit bleibt der zuletzt erfolgreich geschriebene Ladezustand stehen (ohne einen solchen hält die Instanz nichts zurück), Leistungsstufen wechseln sofort.
- Mit Steckervariable: nicht angesteckt → Sollzustand „aus“, einmal geschrieben; Einstecken wertet sofort neu aus.
- Fallback Standard: Laden erlaubt mit maximaler Leistung (ein leeres Auto ist das größere Risiko als ein voller Tarif).

## Haushaltsgerät

### Shelly / Steckdose (Bool-Variable)

Zielvariable **Freigabe** auf die Schaltvariable der Steckdose. `RUN` → `true`. `OFF` wird nur geschrieben,
wenn **Stoppen erlauben** aktiv ist (Spülmaschinen nicht mitten im Programm ausschalten).

### Gerät mit Start-API (Home Connect, Miele …)

In Option 2 die Aktion für `RUN` auf die Start-Aktion des Moduls legen. Der Startimpuls kommt einmal je
geplantem Lauf, nur innerhalb der **Gnadenfrist** (Standard 30 min nach geplantem Start) und nicht, wenn die
optionale Quellvariable „läuft“ schon wahr ist; ein Gerät, das zum geplanten Start schon läuft (von Hand
gestartet), hat den geplanten Lauf damit erledigt. Weil EOS bei jedem Lauf neue Anweisungs-IDs vergibt, sperrt die
Instanz nach einem Impuls weitere Starts für die **Laufdauer**; die Gnadenfrist begrenzt nur späte Starts, ein
laufendes Gerät behält seine Freigabe. Mit Quellvariable „läuft“ gilt der Start erst als gelungen, wenn sie wahr
wird; bleibt sie 5 Minuten nach dem Impuls falsch, fällt die Sperre mit Warnung, und die Gnadenfrist erlaubt genau
einen neuen Versuch über die Bindung, die den Impuls trägt: die RUN-Aktion feuert erneut, Option 3 läuft erneut mit
`Start = true`. Bleibt auch er ohne Lauf, warnt die Instanz und startet nicht erneut. Trägt nur die Freigabe den
Impuls, ist kein neuer möglich (sie hält schon „an“): Warnung, die Sperre bleibt. Nach einem erfolgten Impuls senden
Heartbeat und Wiederholung `Start = false`.

Den Impuls trägt, in dieser Reihenfolge: die RUN-Aktion aus Option 2, sonst die Freigabe (Wechsel aus → an), sonst
Option 3 mit `Start = true`. Ohne eine dieser Bindungen sperrt die Instanz die Steuerung („keine Bindung, die das
Gerät starten kann“). Manuell „Läuft“ startet genau einmal auf der Flanke in „Läuft“ und umgeht die Sperre bewusst;
erneutes Setzen von „Läuft“ startet nicht noch einmal, und läuft das Gerät auf der Flanke schon, ist sie verbraucht.

## Heartbeat und Geräte-Watchdogs

Viele Wechselrichter (Victron ESS, SMA, Fronius, E3DC, Sungrow) verwerfen Sollwerte, wenn sie nicht regelmäßig
erneuert werden, und fallen dann in ihren Standardbetrieb. Genau dafür gibt es **„Sollwerte erneut senden alle n
s“**: den Wert etwas unter der Timeout-Zeit des Geräts wählen (typisch 30–120 s, mindestens 5 s); das gilt auch für
eine reine Skript- oder Aktionsanbindung (Option 3). Ohne Heartbeat schreibt die Steuerung nur bei Änderung. Bei
Fallback „kein Eingriff“ ruhen Heartbeat und Wiederholungen, damit der geräteseitige Watchdog greifen kann. Ein
eigener Zeitgeber weckt die Instanz genau zum nächsten Heartbeat oder zur nächsten Wiederholung, unabhängig vom
60-s-Wächter. Fällt Symcon aus, greift so der geräteseitige Watchdog.

## Was die Steuerung nie tut

- Nichts schreiben im Modus „Nur anzeigen“, ohne konfigurierte Bindung oder bei Gerätestatus 201/203/205
  (Geräte-ID fehlt, doppelt oder EOS hat schon ein anderes Gerät dieser Art).
- Keine SoC-Grenzen durchsetzen: das macht der Wechselrichter; die Instanz warnt nur bei Widersprüchen.
- Keine Schreibversuche beim Löschen der Instanz: vorher den Steuerungsmodus auf „Nur anzeigen“ stellen
  (schreibt den Fallback einmal, sofern der Hauptschalter „Steuerung aktiv“ an ist; war er aus, ist das Gerät
  schon freigegeben).
