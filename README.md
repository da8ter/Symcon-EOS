# Symcon-EOS

IP-Symcon-Modulbibliothek für [Akkudoktor-EOS](https://github.com/Akkudoktor-EOS/EOS) (Energy Optimization System).

EOS berechnet aus Prognosen (PV, Strompreis, Last) und Messwerten (SoC, Zählerstände) einen kostenoptimalen
Fahrplan für Batteriespeicher, E-Auto und Haushaltsgeräte. Diese Bibliothek bindet EOS an IP-Symcon an:
Symcon liefert Messwerte an EOS, holt den Plan ab, stellt die Anweisungen als Variablen bereit und
erlaubt die Pflege der EOS-Konfiguration aus der Symcon-Konsole.

## Status

Anzeige und **Steuerung** (herstellerneutral über Zielvariablen, Symcon-Aktionen und Skript). Getestet mit EOS v0.4.0rc1 und IP-Symcon 9.1, Mindestversion 8.1.

| Modul | Typ | Präfix | Aufgabe |
| --- | --- | --- | --- |
| EOS Server | Splitter | `EOS` | Verbindung zu EOS, Health- und Plan-Abruf, Kosten/Erlös, EOS-Konfiguration |
| EOS Batterie | Gerät | `EOSBAT` | SoC an EOS senden, aktive und nächste Anweisung (Modus, Faktor, Sollleistung) anzeigen, Wechselrichter zur Slot-Grenze umschalten, HTML-Kachel mit Fahrplan |
| EOS E-Auto | Gerät | `EOSEV` | Fahrzeug-SoC senden, Abfahrtszeit und Ziel-SoC nach EOS, geplante Ladeleistung und Ladestrom anzeigen und an die Wallbox geben |
| EOS Haushaltsgerät | Gerät | `EOSHA` | Spülmaschine, Waschmaschine, Trockner: Zeitfenster, Frist und erledigte Läufe nach EOS, geplanter Start anzeigen, Gerät zum geplanten Start freigeben |
| EOS Zähler | Gerät | `EOSMTR` | Zählerstände (Last, Netzbezug, Einspeisung, PV) zyklisch an EOS, Keys in EOS registrieren, Historie aus dem Symcon-Archiv importieren |

Details: [docs/integrationsplan.md](docs/integrationsplan.md), Beispiele für die Geräte-Anbindung:
[docs/geraete-mapping.md](docs/geraete-mapping.md).

## EOS in Docker installieren

EOS läuft als eigener Container neben Symcon, nicht auf der SymBox: die genetische Optimierung ist
CPU-intensiv. Das Repo bringt unter [`.docker/`](.docker/) eine Compose-Datei und ein Skript für Docker Desktop
auf dem Mac mit; auf einem Linux-Host funktionieren dieselben Dateien mit `docker compose`.

**EOS wird unverändert aus dem offiziellen Tag gebaut.** Für den Release-Kandidaten 0.4.0rc1 gibt es kein
veröffentlichtes Image, deshalb baut Compose direkt aus `https://github.com/Akkudoktor-EOS/EOS.git#v0.4.0rc1`
mit dem Dockerfile des EOS-Projekts. Es gibt keine Patches am EOS-Code; die Eigenheiten des
Release-Kandidaten (siehe unten) umgeht das Symcon-Modul auf seiner Seite.

```bash
git clone https://github.com/da8ter/Symcon-EOS.git
cd Symcon-EOS/.docker
./setup-mac.sh            # prüft Docker, legt .env an, baut das Image (5-15 min), startet, wartet auf /v1/health
./setup-mac.sh config     # lädt die PoC-Konfiguration eos-config-poc.json (vorher Standort, PV, Batterie anpassen)
./setup-mac.sh status     # Version und letzter Lauf
```

Danach: Swagger-UI `http://localhost:8503/docs`, EOSdash `http://localhost:8504`. Konfiguration und Messwerte
liegen im Volume `eos_eos-data` und überleben Rebuilds. Für ein Update auf eine neue EOS-Version `EOS_GIT_REF`
und `EOS_VERSION` in `.docker/.env` ändern und `./setup-mac.sh update` ausführen.

Läuft Symcon ebenfalls als Container auf demselben Rechner, trägt man im EOS Server `host.docker.internal` als
Host ein. Sonst die IP des Docker-Hosts; die EOS-API hat keine Authentifizierung, also nicht ins Internet
freigeben. Details, Fehlerbilder und die lokale PV-Prognose ohne Cloud: [docs/eos-setup.md](docs/eos-setup.md).

## Prüfstand mit virtuellen Geräten

Zum Testen ohne echte Hardware eignet sich die Symcon-Bibliothek „Virtual Devices“
(`https://github.com/symcon/VirtuelleGeraete`, über Module Control installieren): Batteriespeicher (`ChargePower`,
`DischargePower` in W, `SoCPercentage` als Faktor), E-Auto im Wallbox-Modus (`CurrentL123` in A, `SoC` in %),
Heizstab (`Status` als Freigabe) und Virtual Counter (kumulierte kWh mit Stundenhistorie im Archiv). Die Variablen
sind schaltbar und lassen sich direkt als Quellen und Ziele in die EOS-Instanzen eintragen; die max. Entladeleistung
der EOS Batterie klein halten (z. B. 800 W), dann entlädt der virtuelle Speicher wie eine Hauslast.

## Voraussetzungen

- Laufende EOS-Instanz ≥ 0.4.0rc1, erreichbar über HTTP (Standard-Port 8503). Wer noch keine hat:
  Abschnitt [EOS in Docker installieren](#eos-in-docker-installieren).
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
   Dynamischer Tarif: Strompreis-Provider `ElecPriceEnergyCharts` (Gebotszone `DE-LU`, 15-Minuten-Raster) und
   unter Gebühren `ElecFeeFixed` mit dem festen Netto-Aufschlag des Tarifs in EUR/kWh (Beschaffung, Netzentgelt,
   Konzession, Stromsteuer, Umlagen) plus 19 % Aufschlag für die Mehrwertsteuer. EOS rechnet dann mit
   (Börsenpreis + Aufschlag) × 1,19 je Viertelstunde.
3. **EOS Batterie** anlegen, mit dem Server verbinden, Geräte-ID (wie in EOS, z. B. `battery1`) und die
   SoC-Quellvariable wählen (Prozent oder Faktor). Der SoC wird im eingestellten Intervall und bei Wertänderung
   an EOS gesendet. EOS verwirft SoC-Werte, die älter als 300 s sind.
4. Optional unter **Batterieparameter** Kapazität, Leistung, SoC-Grenzen und Wirkungsgrade eintragen und
   „Nach EOS schreiben“.

### E-Auto, Haushaltsgerät, Zähler

- **E-Auto**: Geräte-ID wie in EOS (`devices/electric_vehicles/<id>`), SoC-Quellvariable, optional Variablen für
  „angesteckt“ und Abfahrtszeit (Unix-Zeitstempel). Die Abfahrt wird als `min_soc_deadline_datetime` zusammen mit dem
  Ziel-SoC nach EOS geschrieben. Angezeigt werden Laden geplant, Soll-Ladeleistung und Soll-Ladestrom (aus Phasen und
  Spannung). Mit „Nach EOS schreiben“ wird das Fahrzeug in EOS angelegt (`max_electric_vehicles` wird auf 1 gesetzt).
- **Haushaltsgerät**: Energie je Lauf, Dauer, Zeitfenster, Planungsmodus ONCE/DAILY, optional Frist und frühester
  Start aus Variablen sowie „heute erledigte Läufe“. Angezeigt werden geplanter Start und Ende sowie RUN/OFF.
  „Nach EOS schreiben“ hebt `devices/max_home_appliances` bei Bedarf an. Steht der Wert unter der Anzahl der
  Geräte, bricht EOS jeden Lauf ab („home_appliances exceeds configured maximum“).
- **Zähler**: Liste von Symcon-Variablen mit kumulierten Zählerständen (kWh oder Wh), EOS-Key und Kategorie. Beim
  Übernehmen werden die Keys in `measurement.*_emr_keys` eingetragen. „Historie aus Archiv importieren“ überträgt die
  geloggten Werte der letzten Stunden, damit die Lastprognose sofort auf Messdaten aufsetzt.

## Steuerung

Jede Geräte-Instanz hat ein Panel **Steuerung** mit dem Steuerungsmodus *Nur anzeigen* (Standard), *Simulation*
(protokolliert, was geschrieben würde, schreibt nichts) und *Aktiv*. Die Anbindung an die Hardware ist
herstellerneutral und kombinierbar:

1. **Option 1: Bei Moduswechsel Werte in Variablen schreiben** – schaltbare Variablen anderer Module
   (Betriebsmodus, Soll-Ladeleistung, Entladen erlaubt, Netzladen; E-Auto: Laden erlaubt, Ladestrom,
   Ladeleistung, Lademodus; Gerät: Freigabe). Der Wert wird auf den Variablentyp umgewandelt und per
   `RequestAction` geschrieben. Für die Modus-Variable legt die Tabelle „Wert je EOS-Modus“ fest, welcher Wert
   je EOS-Modus geschrieben wird (z. B. evcc `off`/`pv`/`now`).
2. **Option 2: Je EOS-Modus eine Instanzaktion ausführen** – ein Auswahlfeld pro Modus, feuert einmal beim
   Wechsel in den Modus. „Ziel der Aktionen“ (Variable oder Instanz, Standard: die Modus-Variable) öffnet die
   Auswahl direkt mit den Aktionen dieses Geräts.
3. **Option 3: Bei jedem EOS-Moduswechsel Instanzaktion oder Skript ausführen** – eine Aktion und ein Skript,
   das Skript mit dem Kontext in `$_IPS` (`Reason`, `ModeRaw`, `Factor`, `PowerW`, `ChargeAllowed`,
   `CurrentA`, `Run` …, vollständige Liste in [docs/geraete-mapping.md](docs/geraete-mapping.md)).

Geschrieben wird nur bei Änderung. Optional sendet ein **Heartbeat** die Sollwerte alle n Sekunden erneut
(für Wechselrichter mit eigenem Timeout). Der **Fallback-Modus** greift, wenn der Plan veraltet (Einstellung
„Plan als veraltet markieren nach“), abgelaufen (`valid_until`) oder für die Steuerung unbrauchbar ist
(unbekannter Modus); ein kurzer EOS-Ausfall ist kein Auslöser, der gespeicherte Plan läuft weiter. Ein
**Hauptschalter** (Variable „Steuerung aktiv“) und ein **manueller Modus** mit Haltezeit überlagern den
Plan. Beim Abschalten wird der Fallback einmal geschrieben (abschaltbar). Schreiben passiert nie im
Empfangspfad des Servers, sondern kurz danach im eigenen Zeitgeber der Instanz; ein 60-s-Wächter prüft
Alter des Plans, Rückkehr aus dem manuellen Modus, Heartbeat und wiederholt fehlgeschlagene Ziele mit
wachsendem Abstand.

Sicherheitsregeln der Batterie: Netzladen mit 0 W wird nie geschrieben (wird zu `NON_EXPORT`), „Netzladen
erlauben“ und „Netzeinspeisung erlauben“ schwächen die Modi ab, wenn der Anwender sie verbietet.
E-Auto: Mindest-Ladestrom (6 A), Mindestabstand zwischen Laden Ein/Aus (300 s), nicht angesteckt = aus.
Haushaltsgerät: Start nur einmal je Anweisung und nur innerhalb der Gnadenfrist nach dem geplanten Start,
Stoppen nur wenn erlaubt.

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
| Steuerung aktiv | Hauptschalter der Steuerung (schaltbar); aus → Fallback wird einmal geschrieben, danach nichts mehr |
| Manueller Modus | Automatik oder ein fester EOS-Modus (schaltbar), kehrt nach der eingestellten Haltezeit zurück |
| Fallback aktiv | Plan veraltet, abgelaufen oder unbrauchbar: der Fallback-Modus ist geschrieben |
| Letzte Steuerung, Ergebnis der letzten Steuerung | Zeitpunkt und Text des letzten Schreibvorgangs, z. B. `[plan] Mode→pv OK · ChargePowerW→0 OK` |

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

## Kachel

Die Batterie-Instanz liefert eine HTML-Kachel (Visualisierungstyp „Kachel“): Kopfzeile mit aktivem Modus,
Faktor bzw. Sollleistung und nächstem Wechsel, darunter das Modus-Band über den Planungshorizont, der
geplante SoC-Verlauf aus der EOS-Lösung, bei variablem Tarif zusätzlich die Preiskurve, Jetzt-Marker,
Tooltip beim Überfahren und Legende. Hell- und Dunkelmodus werden aus der Textfarbe der Kachel erkannt.

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
EOSBAT_ApplyControl($id, true);     // Steuerung jetzt anwenden, true = alle Ziele neu schreiben
EOSBAT_SetManualMode($id, 5);       // manuell FORCED_CHARGE; 100 = Automatik (EOSEV_: 0 aus / 5 laden; EOSHA_: 0 / 1)
EOSBAT_GetControlState($id);        // JSON: Sollzustand, zuletzt Geschriebenes, Fallback, manuell
EOSEV_SetDeparture($id, strtotime('tomorrow 07:00'));   // Abfahrt nach EOS
EOSHA_SetDeadline($id, strtotime('today 18:00'));       // Gerät muss bis dann fertig sein
EOSMTR_Push($id);                   // Zählerstände sofort senden
EOSMTR_ImportHistory($id, 48);      // Historie der letzten 48 h importieren
```

## Bekannte EOS-Eigenheiten (0.4.0rc1)

- EOS verwirft Läufe, wenn der Batterie-SoC älter als 300 s ist. Push-Intervall 120 s ist Standard.
- Die Suche nach SoC und erledigten Läufen in EOS betrachtet den jüngsten Messwert-Datensatz, auch wenn er nur einen
  anderen Key enthält. Der EOS Server sendet deshalb bei jedem anderen Messwert die bekannten Werte erneut mit.
- Haushaltsgeräte brauchen jeden Tag mindestens einen Wert für erledigte Läufe. Das Modul sendet ihn alle 15 Minuten,
  ohne Quellvariable den Wert 0.
- Nach einem EOS-Neustart gibt es bis zum ersten erfolgreichen Lauf keinen Plan (Server-Status 203). Die
  Geräte senden trotzdem weiter, damit EOS rechnen kann.
- `devices/max_home_appliances` muss mindestens der Anzahl konfigurierter Haushaltsgeräte entsprechen, sonst
  scheitert die Parametervorbereitung von GENETIC still (nur im Container-Log sichtbar, `LastError` zeigt es nicht).
- Die EOS-Konfigurationsdatei speichert nur Werte, die vom Standard abweichen. Ein fehlender Schlüssel in
  `EOS.config.json` heißt „Standardwert“, nicht „nicht gesetzt“.
- Prognosen manuell neu laden: `POST /v1/prediction/update` (alle Provider) oder
  `POST /v1/prediction/update/<ProviderId>`.

## Entwicklung

- Standards: `declare(strict_types=1)`, `IPSModuleStrict`, Presentation-Arrays statt Variablenprofile.
- Lint: `php -l` über alle PHP-Dateien, JSON mit `python3 -m json.tool`.
- Test gegen Symcon in Docker (`symcon-91-rust`, Port 3778) und EOS in Docker (Port 8503), siehe
  [docs/eos-setup.md](docs/eos-setup.md).
