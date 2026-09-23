# Prüfstand

Regressionstests ohne Symcon und ohne EOS. Keine Abhängigkeiten außer PHP ≥ 8.1 (für `run.sh` zusätzlich
`python3` und `bash`).

```sh
tests/run.sh
```

`run.sh` prüft zuerst Syntax (`php -l`, `bash -n .docker/setup.sh`) und alle JSON-Dateien und startet dann die sechs
Testdateien. Jede endet mit `Alle N Prüfungen bestanden.`; die erste fehlgeschlagene Prüfung bricht mit `FAIL:` und
Exit-Code 1 ab. Die Kennungen des Reviews vom 23.09.2026 (K…, S…, N1, C1) und seiner Abschlussprüfung (R6-…, R8-…)
stehen im Text der jeweiligen Prüfung.

## Bausteine

- **`bootstrap.php`**: SDK-Double mit einer `IPSModuleStrict`-Basisklasse (Eigenschaften, Attribute, Variablen,
  Timer im Speicher) und den benötigten `IPS_*`-Funktionen über eine kleine „Welt“ aus Zielvariablen, Aktionen,
  Skripten und Instanzen. `Translate` liefert den englischen Schlüssel unverändert.
- **`$GLOBALS['sdkMode']`**: `live` (Standard) verhält sich wie Symcon 9.1, live gemessen am 23.09.2026:
  `RequestAction` liefert bei einem Fehler `false`, `IPS_RunActionWait` die PHP-Fehlerausgabe als Text, beide mit
  Warnung und ohne Ausnahme. `legacy` lässt die Doubles wie früher werfen.
- **Warnungen**: Die Doubles lösen `E_USER_WARNING` aus; das Modul darf sie abfangen, sonst landen sie in
  `$GLOBALS['sdkWarnings']` wie Symcons Ausgabe. Jede andere Warnung oder Notice aus dem Modulcode bricht den Test
  ab.
- **Uhr**: Alle Zeitentscheidungen des Moduls laufen über `eosNow()`. `setClock($ts)` stellt die Uhr,
  `setClock(null)` gibt sie frei; Tests altern die Welt, statt Zeitstempel in Attributen zu fälschen.
- **Zwei Eltern für Geräte-Instanzen**: `connectToServer($m)` hängt sie an `FakeEOS`, einen schnellen Ersatz für
  den ForwardData-Vertrag des EOS Servers (auch `ClaimDevice`; Konflikte nur mit `$GLOBALS['registry']`, wie die
  Instanzsuche; `claimFails` simuliert einen Server ohne Antwort). `connectToRealServer($m)` hängt sie an das echte
  `EOSServer`-Modul über `FakeEOSClient` (`fake_eos_client.php`, wird vor `libs/EOSClient.php` geladen).
- **Prüfstand-Schalter**: `$GLOBALS['unreadable'][$id]` lässt `IPS_GetProperty` wie im Modul-Reload warnen und
  `false` liefern; `worldVar`-Ziele mit `slowS` rücken die gepinnte Uhr beim Schreiben vor; der Fehler-Handler
  respektiert `@`; `attrWrites` zählt Attribut-Schreibzugriffe.
- **`FakeEOSBackend`** (`fake_eos_backend.php`, Messwert-Teil in `fake_eos_measurements.php`) bildet EOS 0.4.0rc1
  nach, wie im Quelltext gelesen und live gemessen: Merge mit
  `exclude_none` (`null` löscht nie) und Listenersatz, Pfad-PUT (`null` löscht), Geräteschlüssel, die nach dem
  Ersetzen der Map beim nächsten Merge zurückkommen, bis gespeichert und zurückgesetzt wird, 404-Problem-Body,
  Validierung des Teil-Bodys mit Standardwerten, Messwertspeicher (unbekannte Schlüssel: `/value` 404, `/data`
  verwirft still, `/samples` 422 ohne Energiekanal), Dauern in EOS-Schreibweise („1 hour 30 minutes“), „Frist nach
  frühestem Start“. `runCheck()` spiegelt die Lauf-Vorprüfung von `configrequest.py` (der jüngste Datensatz
  entscheidet). Fehlerschalter: `failPaths` (GET antwortet 500), `saveFails`, `getConfigStatus`.

## Was geprüft wird

`control_test.php` (Batterie, E-Auto, Haushaltsgerät):

- Steuerungsmodus „Nur anzeigen“ schreibt nie, Simulation führt nichts aus, protokolliert aber.
- Aktiv: genau ein Schreibvorgang je gebundenem Ziel; unveränderter Plan und Wächter schreiben nichts; ein
  Slotwechsel schreibt nur die geänderten Ziele.
- Fehlererkennung über Rückgabewerte (`false`, PHP-Fehlerausgabe), Backoff 60 s × Fehlschläge, der Zeitgeber
  `Retry` weckt zum nächsten fälligen Zeitpunkt; ein anderer Wert wird sofort geschrieben; Heartbeat ≥ 5 s.
- Option 3 bei Änderung, Moduswechsel und Heartbeat, nicht bei reinen Wiederholungen; Kontext `Reason`,
  `ModeChanged`, `Retried`, `Heartbeat`, `Resync`.
- Fehlende oder nicht schaltbare Ziele sperren nicht, Strukturfehler schon; Fallback und Lückentoleranz,
  manueller Modus und Hauptschalter, Freigabe nur bei eingeschaltetem Hauptschalter.
- Batterie-Politik (Netzladen, Einspeisung, Leistung aus EOS), E-Auto (Mindeststrom, Verweilzeit hält den
  zuletzt geschriebenen Zustand), Haushaltsgerät (ein Start je geplantem Lauf, Sperre für die Laufdauer,
  unbestätigter Start gibt die Sperre frei, manueller Start einmal).
- Gerätestatus 201/203/205 sperrt alle Einstiege; doppelte Geräte-IDs über alle drei Gerätearten.

`sync_meter_test.php`:

- Typumwandlung und Normalisierung der Bindungen.
- Konfigurationsvergleich (Zahlen mit Toleranz, Zeitfenster normalisiert, leer = `null`).
- Zähler: Schlüsselregistrierung, später Elternteil.
- SoC-Push: Prozent/Faktor, Push nur bei Änderung (SoC, Stecker), Entprellung mit Backoff, Höchstalter der
  Quelle.
- Kachel: jede Beschriftung kommt übersetzt an, keine ist fest verdrahtet.

`server_test.php` (echter EOS Server über `FakeEOSClient`):

- Health, Status mit Vorrang 201 > 202 > 203 > 102, aufgeschobener Status-Broadcast, Timeouts vs.
  Verbindungsfehler, „Optimierung starten“ mit kurzem Timeout.
- Plan-Verteilung (früher Ausstieg bei gleichem Hash, 404 nach EOS-Neustart), Messwert-Bündel (neuester
  Zeitstempel, Haltewerte, Zyklen nach Mitternacht), Historien-Import in Zeitfenstern.
- Durchreichen der Konfiguration (`{}` bleibt `{}`), Standort aus der Location Control.

`config_test.php` (Gerätekonfiguration und Server-Konfiguration gegen das EOS-Modell):

- Drei-Wege-Abgleich: Symcon geändert → schreiben, EOS geändert → anbieten, Konflikt → nichts schreiben;
  Kernelstart und Reload schreiben nie; Auswahl eines vorhandenen Geräts überschreibt nichts.
- Anlegen, `max_*` anheben, eine Batterie und ein E-Auto je EOS (Status 205), Umbenennen und
  „Alten EOS-Eintrag entfernen“, `battery_id` des Wechselrichters.
- Fristen und Zeitfenster nur mit Besitz, vergangene werden per Pfad-PUT gelöscht; SoC-Paar immer zusammen.
- Server-Konfiguration: Wechselrichter, Konfigurationsprüfung; die Zählerschlüssel bleiben beim Schreiben erhalten,
  nach 422 registriert der Zähler sie neu.
- Frische Messwerte für die Lauf-Vorprüfung: `EOS_PutMeasurement` und der letzte angesteckte SoC eines
  abgesteckten E-Autos.

`control_review_test.php` (Abschlussprüfung Phase 6): kein Abspielen bei „kein Eingriff“, Leerlauf ohne
Schreibzugriffe, Option 3 und Heartbeat ohne Option-1-Ziele, ausgerichtete Heartbeats, Modus-Aktion nur solange
offen, Haushaltsgerät (Freigabe über die Gnadenfrist, eine Wiederholung, manuelle Flanke), abgelaufene
Haltezeit, gesperrte Instanzen, Geräte-ID-Hoheit, Log-Drosselung.

`config_review_test.php` (Abschlussprüfung Phase 8, echter EOS Server): ID-Hoheit im Server, Zeitfelder je Feld,
Zeitfenster vollständig, SoC-Paar, Basis ohne Abgleich, Auswahl nur im offenen Formular, FormFill ohne
Konfliktfelder, Leistung aus EOS, alter Eintrag nach Umbenennen, Fehlerpfade, Formularknöpfe.

## Nicht abgedeckt

Echter Symcon-Kernel (Nachrichten, Zeitgeber in Echtzeit, Formulare) und echtes EOS (Optimierung, Prognosen).
Dafür gibt es den Live-Prüfstand in der Docker-Testinstanz (Symcon 9.1 auf Port 3778, EOS auf Port 8503) mit
virtuellen Geräten, siehe README „Prüfstand mit virtuellen Geräten“.
