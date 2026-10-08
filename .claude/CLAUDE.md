# Symcon-EOS

Symcon-Bibliothek für Akkudoktor-EOS (Energy Optimization System): Symcon liefert Messwerte an EOS, holt den Fahrplan ab und schaltet Batterie, E-Auto und Haushaltsgerät herstellerneutral danach. Öffentliches Repo `da8ter/Symcon-EOS`, Arbeitszweig `main` (Änderungen als Branch + PR). Getestet mit EOS 0.4.0rc1 und Symcon 9.1, Mindestversion 8.1.

Projektwissen (Entscheidungen, EOS-Befunde, Test-Rezepte): **`.claude/docs/README.md`** – vor Änderungen an einem Bereich die passende Datei lesen. Offenes: `.claude/docs/stand.md`. Betriebsdaten dieses Rechners stehen in `CLAUDE.local.md` (nicht eingecheckt).

## Aufbau

- **`EOSServer/`** (`EOS`, Splitter): REST-Client zu EOS, Health/Plan-Abruf, Messwert-Bündel, EOS-Konfiguration, Geräte-ID-Hoheit (`ClaimDevice`).
- **Geräte (Typ 3, Kinder des Servers):** `EOSBattery` (`EOSBAT`, mit HTML-Kachel `module.html`), `EOSVehicle` (`EOSEV`), `EOSAppliance` (`EOSHA`), `EOSMeter` (`EOSMTR`).
- **`libs/`** – Traits: `EOSClient` (alle Endpunkte), `EOSPlanDevice` (Plan je Gerät, `planUsable()`), `EOSControl` + `EOSControlBindings` + `EOSControlDispatch` (Steuerung), `EOSApplianceStart`, `EOSSoCPush`, `EOSMeasurementBundle`, `EOSDeviceConfigSync`/`-Form`, `EOSConfigCompare` (Drei-Wege-Abgleich), `EOSServerConfig`/`-Status`, `EOSConfigMapper`/`-FormValues`.
- **`.docker/`**: Compose und `setup.sh` für EOS; `.env` und `eos-config-local.json` sind ignoriert.
- Alle Module `IPSModuleStrict`, Darstellungen statt Variablenprofilen.

## Prüfen

```bash
tests/run.sh                 # php -l, JSON, bash -n, dann sechs Prüfstände (SDK- und EOS-Attrappe)
php tests/control_test.php   # einzeln; jede Datei endet mit „Alle N Prüfungen bestanden.“
```

Aufbau der Attrappen: `tests/README.md`. Echter Kernel und echtes EOS: Live-Prüfstand, `.claude/docs/testen/live-pruefstand.md`. Ein grüner Prüfstand ohne Fix prüft das Falsche: Gegenprobe machen.

## Regeln

- **Commits:** ein Thema je Commit, deutsche Botschaft, **ohne** Co-Authored-By-Zeile. Prüfungen vorher.
- **Nie** `git checkout`/`git restore` auf Dateien: Arbeitskopien enthalten nicht committete Arbeit.
- **Push, PR und Release nur auf Zuruf.** Release: `build` und `date` in `library.json` hochsetzen („Release: Build N“).
- **Doku nachziehen:** Ändert ein Commit eine Entscheidung aus `docs/`, im selben Commit anpassen und das „Stand“-Datum erneuern. Neues gemessenes Symcon-Verhalten gehört ins Plattformwissen (unten), EOS-Verhalten nach `.claude/docs/entscheidungen/eos-befunde.md`.
- **Öffentliches Repo:** keine IP-Adressen, Ports lokaler Systeme, Instanz-IDs, Token, Pfade unter `/Users/`, Anlagen- oder Tarifdaten des eigenen Haushalts – weder im Code noch in Tests, Doku oder Commit-Botschaften.
- **Am EOS-Code wird nichts geändert**; Umgehungen für EOS-Eigenheiten gehören ins Modul.
- **Live-Proben:** nur eigene Testwerte zurücksetzen, EOS-Konfiguration nur pfadweise zurückdrehen, nie die ganze Konfiguration.

## Plattformwissen

Gemessenes Symcon-Verhalten für alle Module liegt im SymDo-Repo: https://github.com/da8ter/SymDo-Family-Organizer/tree/SymDo-Beta/.claude/docs/plattform (lokal `../List/.claude/docs/plattform/`). Für diese Bibliothek besonders: `module-strict-und-php.md` (RequestAction/IPS_RunActionWait warnen statt zu werfen), `module-lebenszyklus.md` (Reload und neue Präfix-Funktionen), `timer.md`.
