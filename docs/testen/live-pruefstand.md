# Testen: Live-Prüfstand mit echtem Kernel und echtem EOS

Was `tests/run.sh` nicht abdeckt (Nachrichten, Zeitgeber in Echtzeit, Formulare, Optimierung), wird gegen ein Test-Symcon und ein Test-EOS gefahren. Adressen und Instanzen der eigenen Testumgebung stehen nicht hier, sondern in `CLAUDE.local.md`.

## Aufbau

- **Symcon 9.1 in Docker** und **EOS aus `.docker/`** (`../eos-setup.md`). Das Test-Symcon bindet den Modulordner ein und führt den Repo-Code sofort aus.
- **Dummy-Ziele:** eigene Kategorie mit schaltbaren Variablen, deren Custom-Action-Skript `$_IPS` in eine String-Variable schreibt. So sieht man jeden Schreibvorgang samt Kontext (`Reason`, `Changed`, `Heartbeat` …), ohne Hardware.
- **Virtuelle Geräte:** Bibliothek „Virtual Devices“ (`https://github.com/symcon/VirtuelleGeraete`), installiert mit `MC_CreateModule(<ModuleControl>, '<URL>')` (es gibt kein `MC_InstallModule`/`MC_AddModule`; die echten `MC_`-Namen zeigt `IPS_GetFunctionList`):

| Modul | GUID | Für EOS nutzbar |
| --- | --- | --- |
| Virtual Battery Storage | `{92CC7539-5F38-4A34-14F7-F56E39575A9A}` | Ziele `ChargePower`, `DischargePower` (W, schließen sich aus), Quelle `SoCPercentage` (Faktor 0–1); lädt und entlädt real je Sekunde |
| ECar | `{6BF11B43-77F7-8ACE-DF27-E5921360F7ED}` | Wallbox-Modus: `Power` (W), `CurrentL123` (A, dreiphasig), `SoC` (Integer %) |
| Virtual Heater | `{F4F4F985-47AC-8091-8A4A-53180EB38746}` | `Status` als Freigabe eines Haushaltsgeräts |
| PVSystem | `{8CCD3592-047A-FE04-1EF2-2884BD90E575}` | `Production` (W) |
| Virtual Counter | `{CAF42F8B-66EF-0EA3-FF6B-6EAF6B3ACEB7}` | `Counter1…N` (kWh kumuliert, stündlich ins Archiv) als Zählerquellen |

  Die Module nutzen alte Variablenprofile; für Bindungen ist das egal. Die maximale Entladeleistung der EOS Batterie klein halten, dann entlädt der virtuelle Speicher wie eine Hauslast.
- **Mock-EOS** für Fälle, die ein echtes EOS nicht auf Bestellung liefert (Slotgrenze, Plan veraltet, Fallback aus und wieder ein): ein kleiner HTTP-Server, der `/v1/health` und `/v1/energy-management/plan` aus einer `plan.json` beantwortet, an einer zweiten Server-Instanz. Der Mock liegt nicht im Repo (nicht am Code prüfbar).

## Ablauf

Steuerungsänderungen in dieser Reihenfolge fahren: Simulation → Aktiv → Dedupe (unveränderter Plan schreibt nichts) → manuell mit Haltezeit → Hauptschalter aus/an → Steuerungsmodus zurück auf „Nur anzeigen“. Mit dem Mock zusätzlich Slotgrenze sowie Ein- und Austritt aus dem Fallback.

## Fallen

- **Virtual Counter loggt sofort mit den Vorgaben:** `IPS_CreateInstance` führt `ApplyChanges` mit 50 Zählern, 10–30 kWh/h und 30 Tagen rückwirkend ins Archiv aus. Ein späteres `IPS_SetProperty` ändert die Historie nicht. Neu aufsetzen: je Zähler `AC_DeleteVariableData(archiv, var, 0, jetzt + 120)`, dann `VC_AddValues` – ohne vorheriges `SetValue`, sonst gilt der neue Punkt als letzter Wert und es wird nichts ergänzt.
- **Neue Attribute oder Zeitgeber brauchen einen Modul-Reload,** neue Präfix-Funktionen einen Kernelstart; sonst Warnungen und stille Ausfälle (am 23.09.2026 brachen so die Pushes ab). Hintergrund: Plattformwissen `module-lebenszyklus.md`.
- **Keine Reloads bei offener Konsole** eines anderen Nutzers; nur eigene Testwerte zurücksetzen und vorher gegen den gesicherten Wert prüfen.
- **EOS-Konfiguration nur pfadweise zurückdrehen** (`PUT /v1/config/<pfad>`), nie die ganze Konfiguration; eine Sicherung vorher (`GET /v1/config`).
- **Ein deaktiviertes Test-Haushaltsgerät** hinterlässt seinen Eintrag in EOS und stoppt alle Läufe, siehe `../entscheidungen/eos-befunde.md`.

Stand: geprüft gegen den Code am 08.10.2026 (Testumgebung selbst nicht am Code prüfbar)
