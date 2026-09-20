# Symcon-EOS

IP-Symcon-Modulbibliothek für [Akkudoktor-EOS](https://github.com/Akkudoktor-EOS/EOS) (Energy Optimization System).

EOS berechnet aus Prognosen (PV, Strompreis, Last) und Messwerten (SoC, Zählerstände) einen kostenoptimalen
Fahrplan für Batteriespeicher, E-Auto und Haushaltsgeräte. Diese Bibliothek bindet EOS an IP-Symcon an:
Symcon liefert Messwerte an EOS, holt den Plan ab, stellt die Anweisungen als Variablen bereit und
erlaubt die Pflege der EOS-Konfiguration aus der Symcon-Konsole.

## Status

Phase 1 (Anzeige, keine Steuerung). Getestet mit EOS v0.4.0rc1 und IP-Symcon 9.1, Mindestversion 8.1.

| Modul | Typ | Präfix | Aufgabe |
| --- | --- | --- | --- |
| EOS Server | Splitter | `EOS` | Verbindung zu EOS, Health- und Plan-Abruf, Kosten/Erlös, EOS-Konfiguration |
| EOS Batterie | Gerät | `EOSBAT` | SoC an EOS senden, aktive und nächste Anweisung (Modus, Faktor, Sollleistung) anzeigen |

Geplant: EOS E-Auto, EOS Haushaltsgerät, EOS Zähler, HTML-Kachel, Steuerung über Zielvariablen/Aktionsskript.
Details: [docs/integrationsplan.md](docs/integrationsplan.md).

## Voraussetzungen

- Laufende EOS-Instanz ≥ 0.4.0rc1, erreichbar über HTTP (Standard-Port 8503). Setup in Docker auf dem Mac:
  [docs/eos-setup.md](docs/eos-setup.md).
- IP-Symcon ≥ 8.1.
- In EOS konfigurierte Geräte (`devices/batteries/<id>`), wahlweise über EOSdash oder über das Formular des
  Batterie-Moduls.

## Installation

Module Control in der Symcon-Konsole öffnen und die URL hinzufügen:

```
https://github.com/da8ter/Symcon-EOS
```

Für die Entwicklung liegt das Repo direkt im Modulverzeichnis (`/Library/Application Support/Symcon/modules`).

## Einrichtung

1. **EOS Server** anlegen. Host und Port eintragen. Läuft Symcon im Docker-Container und EOS auf demselben Host,
   `host.docker.internal` statt `localhost` verwenden. „Verbindung testen“ zeigt Version und letzten Lauf.
2. Im Bereich **EOS-Konfiguration** „Aus EOS laden“ drücken, dann „Übernehmen“. Die Felder (Standort,
   Energiemanagement, Optimierung, Provider für Strompreis, Gebühren, Einspeisung, PV-Flächen, Last, Wetter,
   Messwert-Keys) spiegeln jetzt die EOS-Konfiguration. Änderungen mit „Nach EOS schreiben“ übertragen und mit
   „In EOS speichern“ dauerhaft sichern. Symcon schreibt nur auf Knopfdruck nach EOS.
3. **EOS Batterie** anlegen, mit dem Server verbinden, Geräte-ID (wie in EOS, z. B. `battery1`) und die
   SoC-Quellvariable wählen (Prozent oder Faktor). Der SoC wird im eingestellten Intervall und bei Wertänderung
   an EOS gesendet. EOS verwirft SoC-Werte, die älter als 300 s sind.
4. Optional unter **Batterieparameter** Kapazität, Leistung, SoC-Grenzen und Wirkungsgrade eintragen und
   „Nach EOS schreiben“.

## Variablen der Batterie

| Variable | Bedeutung |
| --- | --- |
| Betriebsmodus | Aktive EOS-Anweisung als Aufzählung (siehe Tabelle unten) |
| Betriebsmodus (roh) | `operation_mode_id` aus EOS |
| Faktor | `operation_mode_factor` 0 bis 1 |
| Soll-Ladeleistung | Faktor × max. Ladeleistung bei Netzladen, sonst 0 |
| Entladen erlaubt, Netzladen aktiv | Aus dem Modus abgeleitet |
| Nächster Wechsel, Nächster Modus | Zeitpunkt und Modus der nächsten Anweisung |
| Plan veraltet | Kein aktiver Eintrag oder Plan älter als eingestellt |
| SoC gesendet, Letzter Push | Zuletzt an EOS übertragener Faktor |
| Plan (JSON) | Alle Anweisungen dieser Batterie, sortiert |

| Modus (EOS) | Wert | Bedeutung |
| --- | --- | --- |
| IDLE | 0 | Gesperrt, weder Laden noch Entladen |
| SELF_CONSUMPTION | 1 | Eigenverbrauch, PV-Laden und Entladen für Hauslast |
| NON_EXPORT | 2 | Nur PV-Laden, kein Entladen |
| PEAK_SHAVING | 3 | Nur Entladen für Hauslast |
| GRID_SUPPORT_IMPORT | 4 | Netzladen mit Faktor × Ladeleistung |
| FORCED_CHARGE | 5 | Netz- und PV-Laden |
| GRID_SUPPORT_EXPORT | 6 | Entladen ins Netz |
| unbekannt | 99 | Neuer Modus in EOS, wird geloggt |

## Funktionen für Skripte

```php
EOS_TestConnection($id);            // bool
EOS_FetchPlan($id);                 // bool, Plan neu abrufen und an Geräte verteilen
EOS_Optimize($id);                  // Optimierung in EOS anstoßen (asynchron)
EOS_GetConfig($id, 'general');      // JSON-String eines Konfigpfads ('' = alles)
EOS_SetConfig($id, 'ems/interval', '900');
EOS_SaveConfig($id);
EOS_PutMeasurement($id, 'load0_emr', 12345.6, '');   // Zeitstempel '' = jetzt
EOSBAT_PushSoC($id);
EOSBAT_GetActiveInstruction($id);   // JSON der aktiven Anweisung
```

## Entwicklung

- Standards: `declare(strict_types=1)`, `IPSModuleStrict`, Presentation-Arrays statt Variablenprofile.
- Lint: `php -l` über alle PHP-Dateien, JSON mit `python3 -m json.tool`.
- Test gegen Symcon in Docker (`symcon-91-rust`, Port 3778) und EOS in Docker (Port 8503), siehe
  [docs/eos-setup.md](docs/eos-setup.md).
