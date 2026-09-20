# EOS auf dem Mac in Docker einrichten

Anleitung für Docker Desktop auf macOS (Intel und Apple Silicon). Ziel ist eine laufende
Akkudoktor-EOS-Instanz (v0.4.0rc1) im LAN, die Symcon später per REST anspricht.

## Voraussetzungen

- macOS 13 oder neuer, mindestens 8 GB RAM (EOS selbst braucht 1 bis 2 GB, der Build kurzzeitig mehr).
- [Docker Desktop](https://www.docker.com/products/docker-desktop/) installiert und gestartet
  (alternativ `brew install --cask docker`). In den Docker-Desktop-Einstellungen unter *Resources*
  mindestens 2 CPUs und 4 GB RAM freigeben, sonst dauert die Optimierung sehr lange.
- Internetzugang während des Builds (GitHub, Docker Hub, PyPI) und im Betrieb (Prognose-Provider).
- Dieses Repository geklont: `git clone https://github.com/da8ter/Symcon-EOS.git`

## Warum ein eigener Build?

Für den Release-Kandidaten 0.4.0rc1 veröffentlicht das EOS-Projekt kein Docker-Image. Die
Compose-Datei in `docker/` baut das Image deshalb direkt aus dem GitHub-Tag. Ein Checkout des
EOS-Repos ist nicht nötig. Sobald 0.4.0 final erscheint, genügt es, `EOS_GIT_REF` und
`EOS_VERSION` in `docker/.env` zu ändern und `./setup-mac.sh update` auszuführen.

## Schnellstart

```bash
cd Symcon-EOS/docker
./setup-mac.sh
```

Das Skript

1. prüft Docker,
2. legt `.env` aus `.env.example` an und erzeugt einen zufälligen EOSdash-Sitzungsschlüssel,
3. baut das Image (erster Lauf 5 bis 15 Minuten, danach aus dem Cache),
4. startet den Container und wartet, bis `GET /v1/health` antwortet,
5. gibt die URLs aus.

Danach erreichbar:

| Was | URL |
| --- | --- |
| REST-API mit Swagger-UI | http://localhost:8503/docs |
| EOSdash (Dashboard, Konfiguration) | http://localhost:8504 |
| Health | http://localhost:8503/v1/health |
| Aktueller Plan | http://localhost:8503/v1/energy-management/plan |

Der Container startet nach einem Neustart von Docker Desktop automatisch (`restart: unless-stopped`).
Konfiguration, Messwerte und Cache liegen im Docker-Volume `eos_eos-data` und überleben Rebuilds.

## Konfiguration laden

`docker/eos-config-poc.json` ist eine Minimalkonfiguration für den Proof of Concept:
ein Wechselrichter, eine Batterie (`battery1`), GENETIC im 15-Minuten-Raster, automatische
Optimierung alle 15 Minuten, Strompreis von Energy-Charts, PV-Prognose von Akkudoktor,
Lastprofil nach Jahresverbrauch, Wetter von Open-Meteo.

Vor dem Laden anpassen: `general.latitude/longitude`, PV-Fläche (`pvforecast.planes`:
kWp, Azimut, Neigung), Batterie (`capacity_wh`, `max_charge_power_w`, SoC-Grenzen),
Wechselrichterleistung, Jahresverbrauch, Einspeisevergütung und Netzentgelte (`elecfee`).

```bash
./setup-mac.sh config                  # lädt eos-config-poc.json
./setup-mac.sh config meine-anlage.json
```

Eigene Konfigurationen als `docker/eos-config-local.json` ablegen, die Datei ist in `.gitignore`
eingetragen und landet nicht im Repo. Zeitzonentarife (z. B. Octopus Heat mit drei Preiszonen)
werden mit `ElecPriceFixed` und Zeitfenstern abgebildet, Bruttopreise direkt eintragen und
`elecfee.provider` auf `null` lassen.

Das Skript sendet die Datei per `PUT /v1/config` (Teilkonfiguration wird gemerged) und speichert sie
anschließend mit `PUT /v1/config/file` dauerhaft. Alles Weitere lässt sich in EOSdash unter *Config*
nachjustieren.

## Ersten Lauf prüfen

Nach dem Laden der Konfiguration startet EOS innerhalb von `ems.startup_delay` bzw. `ems.interval`
einen Lauf. Solange kein aktueller Batterie-SoC vorliegt, bricht GENETIC den Lauf ab (Messwert darf
höchstens 300 Sekunden alt sein). Für einen Test den SoC von Hand setzen:

```bash
curl -X PUT "http://localhost:8503/v1/measurement/value?datetime=$(date -u +%Y-%m-%dT%H:%M:%SZ)&key=battery1-soc-factor&value=0.55"
curl -X POST http://localhost:8503/v1/optimize -H 'Content-Type: application/json' -d '{}'
curl -s http://localhost:8503/v1/energy-management/plan | python3 -m json.tool | head -60
```

Der erste `POST /v1/optimize` dauert je nach Rechner 1 bis 5 Minuten. Fehlermeldungen kommen als
JSON mit `detail` zurück, zum Beispiel bei fehlender Prognose oder veraltetem SoC.

## Weitere Befehle

```bash
./setup-mac.sh status    # Health, Version, letzter Lauf
./setup-mac.sh logs      # Log verfolgen (Strg+C beendet nur die Anzeige)
./setup-mac.sh stop      # Container stoppen, Daten bleiben
./setup-mac.sh update    # nach Änderung von EOS_GIT_REF neu bauen
./setup-mac.sh reset     # Container und Datenvolume löschen
```

## Zugriff aus Symcon

Symcon spricht EOS über die IP des Mac an, zum Beispiel `http://192.168.1.50:8503`. Dafür

- dem Mac eine feste IP oder DHCP-Reservierung geben,
- die macOS-Firewall für Docker Desktop freigeben, falls aktiv,
- keine Portfreigabe ins Internet einrichten: die EOS-API hat keine Authentifizierung.

Läuft Symcon selbst als Docker-Container auf demselben Mac, lässt sich der Container in dasselbe
Compose-Netz (`eos_default`) hängen und EOS unter `http://akkudoktoreos:8503` ansprechen.

## Typische Probleme

| Symptom | Ursache / Lösung |
| --- | --- |
| `docker info` schlägt fehl | Docker Desktop läuft nicht. Starten und warten, bis das Symbol in der Menüleiste ruhig ist. |
| Build bricht bei `uv sync` ab | Netzwerk/Proxy. Erneut starten, der Build setzt am Cache auf. |
| Port 8503/8504 belegt | Ports in `docker/.env` ändern und `./setup-mac.sh` erneut ausführen. |
| `/v1/energy-management/plan` liefert 404 | Noch kein erfolgreicher Lauf. `./setup-mac.sh logs` prüfen, SoC-Messwert setzen. |
| Lauf bricht mit „stale“ / „missing measurement“ ab | Batterie-SoC älter als 300 s. Symcon (oder Test-curl) muss ihn zyklisch liefern. |
| `POST /v1/optimize` liefert 503 „No new solution was produced“ | Meist fehlt eine Prognose. Log prüfen: `docker compose logs eos \| grep -i "fails on update"`. |
| `PVForecastAkkudoktor fails on update ... 500 Server Error` | Die Akkudoktor-Cloud-API ist nicht erreichbar. Auf das lokale Backend umschalten (siehe unten). |
| Optimierung dauert sehr lange | In Docker Desktop mehr CPUs freigeben oder `individuals`/`generations` in `optimization.genetic` senken. |
| Apple Silicon: Build kompiliert lange | Für einzelne Pakete gibt es keine arm64-Wheels, der Build kompiliert sie (gcc ist im Builder-Image). Einmalig, danach aus dem Cache. |

## PV-Prognose lokal berechnen

Seit 0.4.0 kann `PVForecastAkkudoktor` die Prognose lokal aus Open-Meteo-Wetterdaten berechnen
(pvlib), ohne die Akkudoktor-Cloud-API. Das ist robuster gegen Ausfälle der API:

```bash
curl -X PUT http://localhost:8503/v1/config/pvforecast/akkudoktor/backend -H 'Content-Type: application/json' -d '"local"'
curl -X PUT http://localhost:8503/v1/config/file
curl -X POST "http://localhost:8503/v1/prediction/update?force_update=true"
```

Kontrolle: `GET /v1/prediction/series?key=pvforecast_ac_power&interval=1%20hour`.

## Prognose-Keys in 0.4.0rc1

| Größe | Key |
| --- | --- |
| PV-Leistung | `pvforecast_ac_power` |
| Strompreis | `elecprice_marketprice_wh` (€/Wh) |
| Einspeisevergütung | `feed_in_tariff_wh` (€/Wh), `feed_in_tariff_kwh` |
| Last | `loadforecast_power_w` (W), `loadakkudoktor_mean_power_w` |
| Temperatur | `weather_temp_air` |

Die in älterer Doku genannten Keys `load_mean` und `load_mean_adjusted` existieren nicht mehr.

## Hinweise zum Release-Kandidaten

0.4.0rc1 ist zum Testen gedacht, nicht für den Dauerbetrieb. Konfigurations- und Datenformat
können sich bis 0.4.0 final noch ändern. Vor einem Update das Volume sichern:

```bash
docker run --rm -v eos_eos-data:/data -v "$PWD":/backup alpine tar czf /backup/eos-data-$(date +%F).tgz -C /data .
```
