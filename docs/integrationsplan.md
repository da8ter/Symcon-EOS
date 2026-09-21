# Integrationsplan: Akkudoktor-EOS in IP-Symcon

Stand: 2026-09-19 · Analysierte EOS-Version: **v0.4.0rc1** (Tag `v0.4.0rc1`, Commit `7dae1d2`, 2026-09-18) · Ziel: IP-Symcon ≥ 7.1 (empfohlen 8.x)

---

## 0. Kurzfassung

- **EOS ist ein reiner Planungs-Server** (Python/FastAPI, REST-API auf Port 8503, Dashboard „EOSdash“ auf 8504). Es steuert keine Geräte. Es braucht Messwerte und Prognosen, rechnet per genetischem Algorithmus einen kostenoptimalen Fahrplan (15- oder 60-Minuten-Slots) und liefert diesen als **Energy-Management-Plan** mit Betriebsmodus-Anweisungen pro Gerät (Batterie, E-Auto, Haushaltsgerät) zurück.
- **Empfohlene Architektur:** EOS läuft als eigener Docker-Container neben Symcon. In Symcon entsteht eine **Modulbibliothek „Symcon-EOS“** (PHP) mit einer Splitter-Instanz „EOS Server“ (REST-Client, Polling, Health) und Geräte-Instanzen (Batterie, E-Auto, Haushaltsgerät, Zähler). Symcon **pusht Messwerte** (SoC, Zählerstände) an EOS, **liest den Plan** zyklisch ab, schaltet zu den Slot-Grenzen die Zielvariablen um und überlässt die herstellerspezifische Ansteuerung dem Anwender (Zielvariable/Aktionsskript). Die Optimierung läuft im **automatischen EMS-Modus von EOS** (`ems.mode=OPTIMIZATION`), nicht aus Symcon getriggert.
- **Warum nicht die EOS-Adapter?** Der Home-Assistant-Adapter funktioniert nur als HA-Add-on (Supervisor-API). Der Node-RED-Adapter postet an einen festen Pfad `/eos/control_dispatch`, Symcon-WebHooks liegen aber zwingend unter `/hook/…`. Push von EOS nach Symcon ist daher nur über Reverse-Proxy oder einen späteren Upstream-Beitrag (generischer Webhook-Adapter) möglich → Phase 4.
- **Wichtigste Stolperfallen (0.4.0rc1):** SoC muss als Faktor 0–1 unter `<device_id>-soc-factor` vorliegen und darf max. 300 s alt sein, sonst bricht der Lauf ab. Energie in Wh/Slot, Preise in €/Wh. Geräte sind Maps mit stabilen IDs (`devices/batteries/<id>/…`). Der RC ist „source-only“ (kein Docker-Image, kein HA-Update), die API kann sich bis 0.4.0 final noch ändern → API-Schicht im Modul kapseln.

---

## 1. Was EOS tut und was nicht

| EOS tut | EOS tut nicht |
| --- | --- |
| Prognosen holen (PV, Strompreis, Einspeisevergütung, Last, Wetter) über konfigurierbare Provider oder Import | Geräte steuern (Wechselrichter, Wallbox, Waschmaschine) |
| Geräte simulieren (Wechselrichter, Batterie, EV, Haushaltsgerät) | Messwerte selbst einlesen (außer über HA-Add-on) |
| Kostenoptimalen Fahrplan berechnen (GENETIC, 15/60 min, Horizont 24 h + Tail 48 h) | Sicherheitsfunktionen / Fallback, wenn der Plan veraltet ist |
| Plan als S2-nahe Anweisungen (Betriebsmodus + Faktor je Gerät) und als Zeitreihen/PDF liefern | Mehrere Batterien/Wechselrichter/EVs gleichzeitig (GENETIC: je 1, Haushaltsgeräte mehrere) |

Die Integrationsschicht (Symcon) muss also: **Messwerte liefern → Plan abholen → Plan zeitrichtig ausführen → visualisieren → Fehler abfangen.**

---

## 2. Analyse EOS v0.4.0rc1 – integrationsrelevante Fakten

### 2.1 Betrieb

- Python ≥ 3.11, amd64/aarch64, schwere Abhängigkeiten (numpy, scipy, pandas, deap, pvlib). Genetischer Algorithmus ist CPU-intensiv (Default 400 Individuen × 400 Generationen). → **Nicht auf der SymBox / dem Symcon-Host mitlaufen lassen**, sondern eigener Container/Host (Mini-PC, NAS, Pi 5 mit 64-bit).
- Deployment: Docker (`Dockerfile`/`docker-compose.yaml` im Repo, Image `akkudoktor/eos`), HA-Add-on oder aus dem Quelltext. **Für 0.4.0rc1 gibt es kein veröffentlichtes Docker-Image**, das Image muss lokal aus dem Tag gebaut werden (`docker compose build`).
- Ports: 8503 (API, Swagger unter `/docs`), 8504 (EOSdash). Beide standardmäßig auf 127.0.0.1 → per `EOS_SERVER__HOST=0.0.0.0` freigeben. **Keine Authentifizierung** → nur im LAN oder hinter Reverse-Proxy mit Auth betreiben.
- Konfiguration: `EOS.config.json` im Datenverzeichnis; änderbar per EOSdash oder REST (`PUT /v1/config`, `PUT /v1/config/{path}`), Speichern mit `PUT /v1/config/file`. Laufzeitänderungen haben seit 0.4.0 Vorrang vor Datei/Umgebung.

### 2.2 Drei Wege zur Optimierung

| Weg | Beschreibung | Bewertung für Symcon |
| --- | --- | --- |
| **Automatische Optimierung** (`ems.mode=OPTIMIZATION`, `ems.interval` s) | EOS holt zyklisch Prognosen, liest Messwerte aus seinem Store, optimiert, publiziert Plan + Lösung, ruft Adapter auf | **Empfohlen.** Wenig Logik in PHP; EOS prüft Frische/Vollständigkeit selbst |
| `POST /v1/optimize` (neu, GENETIC) | Konfigurationsgetrieben; Body nur `soc`, `forecasts`, `start_solution`, `start_solution_datetime`; leerer Body = konfigurierte Provider + frische Messwerte | Sinnvoll für „Jetzt neu rechnen“-Button und Tests. Antwort ist `GeneticSolution` |
| `POST /optimize` (legacy, GENETIC0) | Alle Parameter im Body (48 Stundenwerte ab Mitternacht, deutsche Feldnamen), nur Stundenraster | Nur für Bestandsflows. Nicht als Basis für ein neues Modul verwenden |

Ablauf eines EMS-Laufs: `DATA_ACQUISITION` (Adapter lesen) → `FORECAST_RETRIEVAL` → `OPTIMIZATION` → `CONTROL_DISPATCH` (Adapter schreiben) → `IDLE`. Fehlschläge (fehlende Prognose, alter SoC) brechen den Lauf ab; die alte Lösung wird **nicht** als neue ausgegeben (Fix in 0.4.0).

### 2.3 Datenbedarf von EOS

**Messwerte (Symcon → EOS)**

| Messwert | Key in EOS | Einheit | Endpoint | Frequenz |
| --- | --- | --- | --- | --- |
| Batterie-SoC | `<battery_id>-soc-factor` (read-only aus Gerätekonfig ablesbar) | Faktor 0–1 | `PUT /v1/measurement/value?datetime=&key=&value=` | ≤ 300 s (`optimization.genetic.measurement_max_age_seconds`), empfohlen 60–120 s |
| EV-SoC | `<ev_id>-soc-factor` | Faktor 0–1 | wie oben | wie oben, wenn EV konfiguriert |
| Batterieleistung (optional) | `<id>-power-l1-w` … `<id>-power-3-phase-sym-w` | W (Laden negativ) | wie oben oder `PUT /v1/resource/status` | optional |
| Zählerstände Last / Netzbezug / Einspeisung / PV | frei wählbare Keys in `measurement.load_emr_keys`, `grid_import_emr_keys`, `grid_export_emr_keys`, `pv_production_emr_keys` | **kWh, kumulierter Zählerstand** (kein Verbrauch) | `PUT /v1/measurement/data` (Batch mit `start_datetime`, `interval`, Arrays) oder `/value` | 5–15 min; für Lastprognose-Anpassung (`LoadAkkudoktorAdjusted`) |
| Erledigte Gerätezyklen | `<appliance_id>.cycles_completed` | Anzahl | `/value` | bei Änderung |

**Prognosen** – normalerweise holt EOS sie selbst. Verfügbare Provider (0.4.0rc1):

- Strompreis: `ElecPriceAkkudoktor`, `ElecPriceEnergyCharts`, `ElecPriceSMARD`, `ElecPriceTibber`, `ElecPriceFixed`, `ElecPriceImport`
- Gebühren/Steuern: `ElecFeeFixed`, `ElecFeeImport` (ersetzt `elecprice.charges_kwh`/`vat_rate`)
- Einspeisung: `FeedInTariffAkkudoktor`, `FeedInTariffEnergyCharts`, `FeedInTariffFixed`, `FeedInTariffImport`
- PV: `PVForecastAkkudoktor` (remote oder lokal kalibrierbar), `PVForecastForecastSolar`, `PVForecastSolcast`, `PVForecastPVNode`, `PVForecastVrm`, `PVForecastImport`
- Last: `LoadAkkudoktor`, `LoadAkkudoktorAdjusted`, `LoadVrm`, `LoadImport`
- Wetter: `OpenMeteo`, `ClearOutside`, `BrightSky`, `WeatherImport`

Eigene Prognosen aus Symcon (z. B. bestehendes Tibber-/PV-Prognose-Modul) können über `PUT /v1/prediction/import/{ElecPriceImport|PVForecastImport|LoadImport|WeatherImport}` eingespeist werden (Format `{"start_datetime": …, "interval": "15 minutes", "<key>": [...]}`).

**Konfiguration** (einmalig / bei Änderung): Standort (`general/latitude`, `general/longitude` → Zeitzone), Geräte (`devices/inverters/<id>`, `devices/batteries/<id>`, `devices/electric_vehicles/<id>`, `devices/home_appliances/<id>`), Provider, `optimization/algorithm=GENETIC`, `optimization/genetic/interval_sec` (900 oder 3600), `ems/*`, `measurement/*_emr_keys`.

### 2.4 Was EOS liefert (EOS → Symcon)

| Endpoint | Inhalt | Verwendung in Symcon |
| --- | --- | --- |
| `GET /v1/health` | `status`, `version`, `energy-management.last_run_datetime` | Verbindungsstatus, Versionsprüfung, Erkennung „neuer Lauf“ |
| `GET /v1/energy-management/plan` | `EnergyManagementPlan`: `id`, `generated_at`, `valid_from`, `valid_until`, `instructions[]` (404 solange kein Lauf) | **Kern**: Anweisungen je Gerät mit `execution_time` |
| `GET /v1/energy-management/optimization/solution` | `OptimizationSolution`: Kosten/Erlöse, `prediction`- und `solution`-DataFrame je Slot (Last, Netz, SoC, Preise …) | Kacheln, Charts, KPIs |
| `GET /v1/energy-management/optimization/solution/GENETIC` | native `GeneticSolution` (ac_charge, dc_charge, discharge_allowed, ev_charge_hours_float, appliance_starts, start_solution + start_solution_datetime, interval_seconds) | Detailansicht, Warmstart |
| `GET /v1/energy-management/optimization/solution/GENETIC/pdf` | PDF-Report des gespeicherten Ergebnisses | Link/Media-Objekt in Visualisierung |
| `GET /v1/prediction/series?key=…&interval=…` | einzelne Prognosereihen (`pvforecast_ac_power`, `elecprice_marketprice_wh`, `load_mean_adjusted` …) | Charts, Plausibilisierung |
| `GET /v1/logging/log` | EOS-Log | Diagnose im Modul |

### 2.5 Plan-Format und Betriebsmodi (aus dem Code verifiziert)

Anweisungen haben `id = "<resource_id>@<uuid>"`, `resource_id`, `actuator_id`, `execution_time` (ISO mit Zeitzone), `operation_mode_id`, `operation_mode_factor` (0–1). Eine Anweisung gilt, bis die nächste desselben Geräts greift (`get_active_instructions`: jüngste mit `execution_time <= now`). Nur **Wechsel** erzeugen neue Anweisungen, nicht jeder Slot.

**Batterie (`FRBCInstruction`)** – Mapping der GENETIC-Lösung in `_battery_operation_from_solution()`:

| Modus | Bedeutung | Faktor | Umsetzung am Speicher (typisch) |
| --- | --- | --- | --- |
| `SELF_CONSUMPTION` | PV-Laden + Entladen für Hauslast (Normalbetrieb) | DC-Ladefaktor | Automatik / Eigenverbrauch |
| `NON_EXPORT` | Nur PV-Laden, **kein Entladen** | DC-Ladefaktor | Entladesperre setzen |
| `PEAK_SHAVING` | Nur Entladen für Hauslast, kein Laden | 1.0 | Ladesperre / Entladen erlauben |
| `GRID_SUPPORT_IMPORT` | **Netzladen** (AC), kein Entladen | AC-Ladefaktor → P = Faktor × `max_charge_power_w` | Netzladung mit Sollleistung |
| `FORCED_CHARGE` | AC + DC laden, kein Entladen | AC-Ladefaktor | Netzladung + PV-Laden |
| `GRID_SUPPORT_EXPORT` | Entladen ins Netz (nur wenn Export explizit aktiviert) | Exportfaktor × Entladeleistung | Netzeinspeisung aus Speicher |
| `IDLE` | weder Laden noch Entladen | 1.0 | Speicher sperren |

Hinweis: Die Doku (`optimauto.md`) nennt noch `CHARGE/DISCHARGE/ALLOW_DISCHARGE`; der Code emittiert die obigen IDs. Das Modul muss alle `BatteryOperationMode`-Werte tolerant behandeln (unbekannt → Fallback).

**E-Auto (`FRBCInstruction`)**: `IDLE` (kein Laden) oder `GRID_SUPPORT_IMPORT` mit Faktor = relative Ladeleistung (Code nutzt dieselbe Mapping-Funktion; Doku nennt `FORCED_CHARGE`). Regel für Symcon: **Faktor > 0 ⇒ laden mit Faktor × `max_charge_power_w`**, unabhängig vom Modus-Namen. Ladeleistungsstufen kommen aus `charge_rates`.

**Haushaltsgerät (`DDBCInstruction`)**: `RUN` / `OFF`; Faktor ignoriert. Zusätzlich `appliance_starts` (absolute Startzeit je Gerät) in der GeneticSolution.

### 2.6 Adapter in EOS und warum sie für Symcon (noch) nicht passen

| Adapter | Mechanik | Für Symcon |
| --- | --- | --- |
| `HomeAssistant` | liest/schreibt HA-Entities über `http://supervisor/core/api` mit `SUPERVISOR_TOKEN` | **Nicht nutzbar** (nur als HA-Add-on) |
| `NodeRED` | nach jedem Lauf `POST http://<host>:<port>/eos/control_dispatch` mit `{"<id>_op_mode": "...", "<id>_op_factor": 0.5, …}`; `GET /eos/data_aquisition` ist noch nicht funktional | Pfad ist fest, Symcon-WebHooks liegen unter `/hook/<name>` (Port 3777) → nur mit **Reverse-Proxy-Rewrite** nutzbar. Liefert nur die *aktuell aktive* Anweisung, nicht den ganzen Plan |

Konsequenz: **Polling aus Symcon** ist der robuste Weg (Phase 1). Push ist ein Komfort-Add-on (Phase 4) über (a) Proxy-Rewrite `/eos/control_dispatch → /hook/eos` oder (b) Upstream-Beitrag eines generischen „Webhook“-Adapters mit frei konfigurierbarer URL (nützt auch ioBroker/openHAB).

### 2.7 Stolperfallen

1. **Frische:** Ohne aktuellen SoC (≤ 300 s, nicht in der Zukunft, gültig) bricht GENETIC ab. Symcon muss den SoC zuverlässig zyklisch pushen, nicht nur bei Änderung.
2. **Einheiten:** Prognose-Arrays im Request/Ergebnis sind **Wh pro Slot**, Preise **€/Wh** (0,30 €/kWh = 0,00030 €/Wh). Zählerstände in kWh kumuliert. SoC als Faktor (Messwert) bzw. Prozent-Integer (Request-Body `soc`).
3. **Zeit:** 15-min-Slots ⇒ 96 Werte/Tag, an DST-Tagen 92/100. Immer `execution_time`/Timestamps aus der Antwort verwenden, nie Indizes annehmen. EOS nutzt die aus Koordinaten abgeleitete Zeitzone.
4. **Device-IDs:** Maps statt Listen (`devices/batteries/<id>`), `device_id` muss dem Map-Key entsprechen; Wechselrichter muss auf die Batterie-ID verweisen. GENETIC akzeptiert genau 1 Wechselrichter, 1 Batterie, 0–1 EV, n Haushaltsgeräte.
5. **RC-Status:** 0.4.0rc1 ist Release-Kandidat, „source-only“, Doku teils inkonsistent (Betriebsmodi, Tippfehler `DATA_AQUISITION`). Bis 0.4.0 final können sich Endpunkte/Felder ändern. Das Modul sollte die EOS-Version prüfen und die REST-Zugriffe in einer Klasse kapseln.
6. **Fehlerverhalten:** Bei Ausfall von EOS oder veraltetem Plan (`valid_until` überschritten) braucht Symcon einen definierten **Fallback** (Batterie → `SELF_CONSUMPTION`, EV → Standardladen, Gerät → keine Aktion) und eine Benachrichtigung.
7. **Keine Auth an der EOS-API**; Netzsegmentierung oder Proxy.

---

## 3. Integrationsvarianten im Vergleich

| Variante | Aufwand | Robustheit | Wiederverwendbar | Bewertung |
| --- | --- | --- | --- | --- |
| **A – PHP-Skripte in Symcon** (curl gegen REST, Ereignisse/Timer) | gering | mittel (Skript-Wildwuchs, keine Instanzlogik) | gering | Gut für **PoC** (Phase 0), nicht als Endzustand |
| **B – Symcon-Modulbibliothek, Polling** (Splitter + Geräte-Instanzen) | mittel | hoch (Timer, Statusvariablen, Fallback, Formulare) | hoch (Module-Store, Community) | **Empfehlung** |
| **C – Push von EOS (NodeRED-Adapter + Proxy / Upstream-Webhook-Adapter)** | gering (Proxy) bis mittel (Upstream-PR) | abhängig von EOS-Adapter; liefert nur aktive Anweisung | mittel | Ergänzung zu B für geringe Latenz (Phase 4) |
| **D – Symcon triggert `POST /v1/optimize`** und verarbeitet `GeneticSolution` | mittel | mittel (Symcon muss Zyklus, Warmstart, Fehler selbst managen) | mittel | Nur als manueller Button in B; automatische Zyklen EOS überlassen |

**Entscheidung:** B mit automatischer EMS-Optimierung in EOS; A als Einstieg; C und D als optionale Erweiterungen.

---

## 4. Zielarchitektur

### 4.1 Systemaufbau

```
┌──────────────────────────┐        REST (HTTP, LAN)         ┌──────────────────────────────┐
│ EOS (Docker)             │ <────── PUT measurement ─────── │ IP-Symcon                    │
│  :8503 API  :8504 Dash   │ ──────> GET plan/solution ────> │  Modul „EOS Server“ (Splitter)│
│  Provider: Preise, PV,   │ <────── PUT config/{path} ───── │  ├─ EOS Batterie   (Device)   │
│  Wetter, Last            │ ──────> GET health ───────────> │  ├─ EOS E-Auto     (Device)   │
│  GENETIC alle N min      │   (optional) POST /hook/eos     │  ├─ EOS Haushaltsgerät (Dev.) │
└──────────────────────────┘ ──────────────────────────────> │  └─ EOS Zähler     (Device)   │
                                                             │  Zielvariablen / Aktionsskripte│
                                                             │  → Hersteller-Module (E3DC,    │
                                                             │    Victron, SMA, openWB, evcc…)│
                                                             └──────────────────────────────┘
```

### 4.2 Modulstruktur der Bibliothek „Symcon-EOS“

**`EOS Server` (Splitter)** – genau eine Instanz pro EOS.

- Eigenschaften: Host, Port, HTTPS/Basic-Auth (für Proxy), Timeout, Poll-Intervall Plan, Health-Intervall, Fallback-Verhalten, Standort-Sync (Lat/Lon aus Symcon-Standort übernehmen).
- Variablen: Verbindung (Bool), EOS-Version, Letzter Lauf, Plan-ID, Plan gültig bis, Gesamtkosten, Gesamterlös, Fitness, Letzter Fehler.
- Funktionen: `EOS_TestConnection`, `EOS_FetchPlan`, `EOS_Optimize` (POST `/v1/optimize` mit leerem Body), `EOS_GetConfig(path)`, `EOS_SetConfig(path, value)`, `EOS_SaveConfig`, `EOS_PutMeasurement(key, value, ts)`, `EOS_PutMeasurementData(json)`, `EOS_ImportPrediction(provider, json)`, `EOS_GetLog`.
- Interna: REST-Client-Klasse (curl, JSON, Fehlerobjekte `EOSProblem` auswerten), Versionsprüfung, `ForwardData()` für Kinder (Messwert-Push, Konfig-Sync), `SendDataToChildren()` mit dem Plan nach jedem Abruf, WebHook `/hook/eos` (optional, Phase 4), Buttons „EOSdash öffnen“, „Swagger öffnen“, „PDF-Report“.

**`EOS Batterie` (Device)**

- Eigenschaften: `device_id`; Quellvariable SoC (% oder Faktor, Umrechnung wählbar); optional Quellvariable Leistung (W); Push-Intervall; Kapazität Wh, max. Lade-/Entladeleistung, Wirkungsgrade, SoC-Grenzen, LCOS (werden bei „Konfiguration nach EOS schreiben“ per `PUT /v1/config/devices/batteries/<id>/…` übertragen); Zielvariablen (optional): Betriebsmodus-Variable eines Hersteller-Moduls, Sollleistung-Variable; Aktionsskript (optional).
- Variablen: Betriebsmodus (Integer mit Profil `EOS.BatteryMode`), Modus-Rohwert (String), Faktor, Soll-Ladeleistung W, Entladen erlaubt (Bool), Netzladen aktiv (Bool), Nächster Wechsel (Timestamp), Nächster Modus, Geplanter SoC-Verlauf (JSON-String für Chart), Fallback aktiv (Bool).
- Logik: Message-Sink (VM_UPDATE) auf SoC-Quelle + Timer ⇒ `PUT /v1/measurement/value` mit `<id>-soc-factor`; Empfang des Plans ⇒ eigene Anweisungen filtern (`resource_id == device_id`), sortieren, aktive bestimmen, Timer exakt auf nächste `execution_time` setzen; bei Umschalten Zielvariablen per `RequestAction` schreiben bzw. Aktionsskript mit Parametern (`Mode`, `Factor`, `PowerW`, `ExecutionTime`) ausführen.

**`EOS E-Auto` (Device)** – wie Batterie, plus: Quellvariable „angesteckt“, Abfahrtszeit/Ziel-SoC (→ `min_soc_deadline_datetime`, `min_soc_percentage`), Phasen/Spannung zur Umrechnung in Ladestrom (A) für Wallbox-Module (openWB, evcc, go-e, KEBA …). Variablen: Modus, Faktor, Soll-Ladeleistung W, Soll-Ladestrom A, Ladeplan.

**`EOS Haushaltsgerät` (Device)** – `device_id`, Verbrauch Wh, Dauer h, Zeitfenster / Zyklen (→ EOS-Konfig), Quellvariable „Zyklen erledigt“ (→ `<id>.cycles_completed`). Variablen: Modus RUN/OFF, Geplanter Start (Timestamp aus `appliance_starts`), Freigabe (Bool), Deadline verfehlt (Bool).

**`EOS Zähler` (Device)** – Liste von Symcon-Zählervariablen (kWh) je Kategorie Last / Netzbezug / Einspeisung / PV mit frei wählbaren EOS-Keys; schreibt zyklisch per `PUT /v1/measurement/data` Batches; trägt die Keys in `measurement/*_emr_keys` ein; **Historien-Import** aus dem Symcon-Archiv (`AC_GetLoggedValues`) für die ersten 48 h+ Lastprognose-Anpassung.

**`EOS Prognose-Import` (Device, optional)** – mappt vorhandene Symcon-Prognosevariablen/JSON (z. B. Tibber-Preise inkl. eigener Tarifbestandteile, PV-Prognose eines anderen Moduls) auf `ElecPriceImport`/`PVForecastImport`/`LoadImport`.

**Visualisierung** – HTML-SDK-Kachel (Symcon ≥ 7.1) im Server-Modul: Chart mit Preis, PV, Last, SoC-Verlauf, Lade-/Entladebalken je Slot aus `OptimizationSolution.solution`; KPIs; Link auf PDF-Report und EOSdash.

### 4.3 Datenflüsse und Timing

1. **Messwerte**: SoC alle 60–120 s (Timer) und zusätzlich bei Änderung (entprellt); Zählerstände alle 5–15 min als Batch; Zyklen bei Änderung. Zeitstempel immer mit Zeitzone (ISO 8601) senden.
2. **EOS rechnet** im Intervall `ems.interval` (Empfehlung: 900 s bei 15-min-Slots, 3600 s bei Stunden-Slots; Laufzeit muss ins Intervall passen, ggf. Individuen/Generationen reduzieren).
3. **Plan-Abruf**: Server-Modul pollt `/v1/health` (leichtgewichtig) im Poll-Intervall; ändert sich `last_run_datetime`, wird `/v1/energy-management/plan` und `/solution` geladen und an die Kinder verteilt. Zusätzlich fester Abruf kurz nach jeder Slot-Grenze.
4. **Ausführung**: Jedes Gerät hält seine sortierte Anweisungsliste, setzt einen Timer auf die nächste `execution_time` und schaltet exakt zur Slot-Grenze. Zwischen zwei EOS-Läufen läuft der Plan autark weiter.
5. **Fallback**: Wenn `valid_until` überschritten oder EOS länger als X min nicht erreichbar ⇒ Fallback-Modus je Gerät setzen, `Fallback aktiv` = true, Meldung ins Symcon-Meldungsfenster/Benachrichtigung.

### 4.4 Konfigurations-Synchronisation

- Standort: aus Symcon (`IPS_GetLocation`) → `general/latitude`, `general/longitude`.
- Geräte: Formularfelder der Geräte-Instanzen → `PUT /v1/config/devices/<typ>/<id>/<feld>`; Button „Konfiguration nach EOS schreiben“ + „aus EOS lesen“; danach `PUT /v1/config/file`.
- Provider/EMS-Einstellungen bleiben in EOSdash (kein Nachbau der gesamten EOS-Konfiguration in Symcon). Das Server-Modul zeigt die relevanten Werte nur an und validiert (Algorithmus GENETIC, `ems.mode`, `interval_sec`, konfigurierte Provider, Messwert-Keys).

### 4.5 Fehlerbehandlung und Sicherheit

- Jeder REST-Aufruf mit Timeout, Retry (1×), strukturierter Fehlerauswertung (`detail` aus EOSProblem) und Log über `$this->LogMessage`.
- Instanzstatus (IS_ACTIVE / IS_EBASE+n) für: EOS nicht erreichbar, Versions-Mismatch, 404 kein Plan, Konfigurationsfehler (Device-ID unbekannt), Messwert-Push fehlgeschlagen.
- Keine direkte Gerätesteuerung im Modul ohne explizite Zuordnung (Zielvariable/Skript) – „Plan anzeigen“ ist der sichere Default.
- Sicherheits-Grenzen im Modul: min/max SoC, max. Netzladeleistung dürfen die Hersteller-Grenzen nicht überschreiten (Plausibilisierung vor dem Schreiben).

---

## 5. Phasenplan

### Phase 0 – Infrastruktur und Proof of Concept (1–2 Tage)

- EOS aus Tag `v0.4.0rc1` als Docker-Image bauen und neben Symcon starten (`EOS_SERVER__HOST=0.0.0.0`, Volume für `/data`).
- In EOSdash konfigurieren: Standort, 1 Wechselrichter, 1 Batterie (`device_id` z. B. `battery1`), Preis-/PV-/Last-Provider, `optimization.algorithm=GENETIC`, `interval_sec=900`, `ems.mode=OPTIMIZATION`, `ems.interval=900`.
- Drei PHP-Skripte in Symcon: (1) SoC pushen (`PUT /v1/measurement/value`), (2) Health + Plan lesen und in String-Variablen ablegen, (3) aktive Anweisung ermitteln und loggen.
- **Akzeptanz:** EOS liefert regelmäßig einen Plan mit Batterie-Anweisungen, Symcon zeigt Modus/Faktor/nächsten Wechsel korrekt an. Erkenntnisse zu Laufzeit und Einheiten dokumentieren.

### Phase 1 – MVP Modulbibliothek (Batterie) (1–2 Wochen)

- Repo-Grundgerüst (siehe Abschnitt 6), `EOS Server` (Splitter) mit REST-Client, Health/Plan-Polling, Statusvariablen, Formular mit Verbindungstest.
- `EOS Batterie` mit SoC-Push, Plan-Verarbeitung, Slot-Timer, Zielvariablen/Aktionsskript, Fallback.
- Variablenprofile (`EOS.BatteryMode`, `EOS.Factor`, `EOS.PowerW`), Lokalisierung DE/EN, README mit Beispielkonfiguration.
- **Akzeptanz:** Speicher wird über 48 h nach EOS-Plan geschaltet (Testsystem), Ausfall von EOS führt sauber in den Fallback.

### Phase 2 – E-Auto, Haushaltsgerät, Zähler, Konfig-Sync (2–3 Wochen)

- `EOS E-Auto` (inkl. Abfahrtszeit/Ziel-SoC → EOS-Konfig, Ladestrom-Umrechnung), `EOS Haushaltsgerät` (Zeitfenster, Zyklen), `EOS Zähler` (Batch-Push, Archiv-Import).
- Konfigurationsabgleich Symcon ↔ EOS (`PUT /v1/config/{path}`), Validierungs-Checkliste im Server-Formular.
- Manueller „Jetzt optimieren“-Button (`POST /v1/optimize`, leerer Body).
- **Akzeptanz:** EV-Ladung vor Abfahrt erreicht Ziel-SoC im Test; Haushaltsgerät startet im Fenster; Lastprognose nutzt Symcon-Zählerdaten (`load_mean_adjusted` ändert sich).

### Phase 3 – Visualisierung und Diagnose (1–2 Wochen)

- HTML-SDK-Kachel: Plan-Chart (Preis, PV, Last, SoC, Lade-/Entladeaktionen), KPIs (Kosten/Erlös/Bilanz), nächste Wechsel je Gerät.
- PDF-Report-Link, EOS-Log-Ansicht, Diagnose-Export (Plan + Solution als JSON).
- Prognose-Import-Instanz (optional).

### Phase 4 – Push-Kanal, Upstream, Veröffentlichung (parallel/optional)

- Upstream-Beitrag an EOS: generischer **Webhook-Adapter** (konfigurierbare URL, POST des Plans/der aktiven Anweisungen) oder dedizierter `Symcon`-Adapter; zusätzlich Symcon-Abschnitt in `docs/akkudoktoreos/integration.md`.
- Alternativ kurzfristig: Reverse-Proxy-Rewrite `/eos/control_dispatch → http://symcon:3777/hook/eos` mit NodeRED-Adapter.
- Veröffentlichung im Symcon Module-Store, Forumsthread, Beispiel-Setups (E3DC, Victron, SMA, openWB/evcc).
- Nachziehen auf 0.4.0 final (API-Diff prüfen, Versionsprüfung anpassen).

---

## 6. Vorgeschlagene Repo-Struktur

```
Symcon-EOS/
├── library.json                 # Bibliothek „Symcon-EOS“, Autor, Version, Symcon-Mindestversion
├── README.md                    # Installation (Module Control URL), Voraussetzungen, Schnellstart
├── docs/
│   ├── integrationsplan.md      # dieses Dokument
│   ├── eos-setup.md             # EOS-Docker-Setup und Beispiel-EOS.config.json
│   └── geraete-mapping.md       # Beispiele Betriebsmodus → Hersteller-Module
├── libs/
│   └── EOSClient.php            # REST-Client (curl, JSON, Fehlerbehandlung, Versionsprüfung)
├── EOS Server/                  # Splitter
│   ├── module.json  module.php  form.json  locale.json
├── EOS Batterie/                # Device
├── EOS E-Auto/                  # Device
├── EOS Haushaltsgeraet/         # Device
├── EOS Zaehler/                 # Device
├── EOS Prognose-Import/         # Device (optional, Phase 3)
├── imgs/                        # Icons
└── tests/                       # PHPUnit mit Symcon-Stubs, Fixtures aus openapi.json-Beispielen
```

Konventionen: GUIDs je Modul fest vergeben, Datenfluss-GUIDs für Parent/Child; `EOSClient` kapselt alle Endpunkte und Versionsunterschiede; Fixtures (`plan.json`, `solution.json`) aus einer echten 0.4.0rc1-Instanz für Tests.

---

## 7. Offene Entscheidungen

1. **Zielversion:** Auf 0.4.0rc1/0.4.0 (v1-API, GENETIC) setzen – ja. 0.3.0 (Listen-Geräte, GENETIC0) nicht unterstützen, um Doppelpfade zu vermeiden.
2. **Slot-Raster:** Start mit 15 min (`interval_sec=900`)? Erfordert schnellere Hardware für EOS; 3600 als konservativer Default im Modul-Formular anbieten.
3. **Steuerungs-Abstraktion:** Nur Zielvariablen + Aktionsskript (herstellerneutral) oder zusätzlich fertige Profile für gängige Symcon-Module (E3DC, Victron, openWB)? Vorschlag: neutral starten, Profile als Beispiele in `docs/`.
4. **Push-Kanal:** Upstream-PR für generischen Webhook-Adapter anstreben (Nutzen für die gesamte EOS-Community) vs. nur Polling.
5. **Hosting von EOS:** Docker auf demselben Linux-Host wie Symcon (falls Symcon unter Docker/Linux läuft) oder separater Rechner; SymBox scheidet aus.

---

## 8. Anhang: Beispiel-Requests

```bash
# Health / Version
curl -s http://eos:8503/v1/health

# SoC 57 % pushen (Faktor!)
curl -X PUT "http://eos:8503/v1/measurement/value?datetime=2026-09-19T12:00:00%2B02:00&key=battery1-soc-factor&value=0.57"

# Zählerstände als Batch
curl -X PUT http://eos:8503/v1/measurement/data -H 'Content-Type: application/json' -d '{
  "start_datetime": "2026-09-19T11:00:00+02:00", "interval": "15 minutes",
  "load0_emr": [12345.1, 12345.4, 12345.8, 12346.1],
  "grid_import_emr": [8765.0, 8765.1, 8765.3, 8765.3]
}'

# Plan und Lösung lesen
curl -s http://eos:8503/v1/energy-management/plan
curl -s http://eos:8503/v1/energy-management/optimization/solution

# Konfiguration setzen (Pfad mit '/')
curl -X PUT http://eos:8503/v1/config/devices/batteries/battery1/capacity_wh -H 'Content-Type: application/json' -d '26400'
curl -X PUT http://eos:8503/v1/config/file

# Optimierung manuell (konfigurationsgetrieben, leerer Body)
curl -X POST http://eos:8503/v1/optimize -H 'Content-Type: application/json' -d '{}'
```

Minimaler EOS-Konfigurationsausschnitt für den PoC:

```json
{
  "general": {"latitude": 51.0, "longitude": 9.0},
  "ems": {"mode": "OPTIMIZATION", "interval": 900, "startup_delay": 10},
  "optimization": {"algorithm": "GENETIC", "genetic": {"interval_sec": 900, "horizon_hours": 24, "measurement_max_age_seconds": 300}},
  "devices": {
    "inverters": {"inv1": {"device_id": "inv1", "max_power_w": 10000, "battery_id": "battery1", "ac_to_dc_efficiency": 0.95, "dc_to_ac_efficiency": 0.95}},
    "batteries": {"battery1": {"device_id": "battery1", "capacity_wh": 10000, "max_charge_power_w": 5000, "min_soc_percentage": 10, "max_soc_percentage": 95}},
    "home_appliances": {}
  },
  "measurement": {"load_emr_keys": ["load0_emr"], "grid_import_emr_keys": ["grid_import_emr"], "grid_export_emr_keys": ["grid_export_emr"]},
  "elecprice": {"provider": "ElecPriceEnergyCharts"},
  "pvforecast": {"provider": "PVForecastAkkudoktor"},
  "load": {"provider": "LoadAkkudoktorAdjusted", "loadakkudoktor": {"loadakkudoktor_year_energy_kwh": 4500}},
  "weather": {"provider": "OpenMeteo"}
}
```

Symcon-Variablenprofil `EOS.BatteryMode` (Integer): 0 IDLE · 1 SELF_CONSUMPTION · 2 NON_EXPORT · 3 PEAK_SHAVING · 4 GRID_SUPPORT_IMPORT · 5 FORCED_CHARGE · 6 GRID_SUPPORT_EXPORT · 99 UNBEKANNT/FALLBACK.
