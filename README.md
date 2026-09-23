# Symcon-EOS

Symcon-Modulbibliothek für [Akkudoktor-EOS](https://github.com/Akkudoktor-EOS/EOS) (Energy Optimization System).

EOS berechnet aus Prognosen (PV, Strompreis, Last) und Messwerten (SoC, Zählerstände) einen kostenoptimalen
Fahrplan für Batteriespeicher, E-Auto und Haushaltsgeräte. Diese Bibliothek bindet EOS an Symcon an:
Symcon liefert Messwerte an EOS, holt den Plan ab, stellt die Anweisungen als Variablen bereit und
erlaubt die Pflege der EOS-Konfiguration aus der Symcon-Konsole.

## Status

Anzeige und **Steuerung** (herstellerneutral über Zielvariablen, Symcon-Aktionen und Skript). Getestet mit EOS v0.4.0rc1 und Symcon 9.1, Mindestversion 8.1.

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
CPU-intensiv. Geeignet sind ein Linux-Mini-PC, ein NAS mit Docker oder ein Raspberry Pi 5 (64 Bit); ein Mac mit
Docker Desktop eignet sich zum Ausprobieren. Das Repo bringt unter [`.docker/`](.docker/) eine Compose-Datei
und das Skript `setup.sh` mit, das auf macOS, Linux und NAS gleich funktioniert.

**EOS bleibt das offizielle Programm.** Das Skript holt das veröffentlichte Image `akkudoktor/eos:<Version>`
von Docker Hub. Gibt es das nicht (für den Release-Kandidaten 0.4.0rc1 ist das so) oder zeigt `EOS_GIT_REF` auf
einen anderen Branch oder Commit, baut Compose das Image aus `https://github.com/Akkudoktor-EOS/EOS.git#<EOS_GIT_REF>`
mit dem Dockerfile des EOS-Projekts; `EOS_IMAGE_OVERRIDE` in `.docker/.env` erzwingt ein eigenes Image. Es gibt keine
Patches am EOS-Code; die Eigenheiten des Release-Kandidaten (siehe unten) umgeht das Symcon-Modul auf seiner Seite.

```bash
git clone https://github.com/da8ter/Symcon-EOS.git
cd Symcon-EOS/.docker
./setup.sh                # prüft Docker, legt .env an, holt oder baut das Image, startet, wartet auf /v1/health
./setup.sh status         # Version und letzter Lauf
```

Danach: Swagger-UI `http://<rechner>:8503/docs`, EOSdash `http://<rechner>:8504`. Konfiguration und Messwerte
liegen im Volume `eos_eos-data` und überleben Rebuilds. Für ein Update auf eine neue EOS-Version `EOS_VERSION`
(und `EOS_GIT_REF`) in `.docker/.env` ändern und `./setup.sh update` ausführen. Details, Fehlerbilder und die
lokale PV-Prognose ohne Cloud: [docs/eos-setup.md](docs/eos-setup.md).

## Von null zum ersten Plan

1. **EOS starten** wie oben. `./setup.sh status` zeigt Version und „last_run_datetime“ (anfangs `null`).
2. **Modul installieren**: Module Control in der Symcon-Konsole, URL `https://github.com/da8ter/Symcon-EOS`.
3. **EOS Server anlegen**: Host = IP des Docker-Rechners (Symcon im Container auf demselben Mac/Windows:
   `host.docker.internal`; unter Linux die IP), Port 8503. „Verbindung testen“ muss die Version zeigen.
4. **EOS-Grundkonfiguration** im EOS Server: „Aus EOS laden“, dann Standort (Button „Standort aus Symcon“),
   Strompreis (z. B. Energy-Charts, Zone DE-LU), Gebühren (fester Netto-Aufschlag plus 19 %), Einspeisevergütung,
   PV-Flächen, Lastprofil (Jahresverbrauch), Wetter, im Abschnitt „Wechselrichter“ dessen Leistung und
   AC-Ladeleistung. „Nach EOS schreiben“, „In EOS speichern“.
5. **EOS Batterie anlegen**: Geräte-ID (z. B. `battery1`), SoC-Quellvariable, unter „Batterieparameter“
   Kapazität, Leistung und SoC-Grenzen. Übernehmen legt die Batterie in EOS an und trägt sie als `battery_id` des
   Wechselrichters ein, wenn dieser auf keine vorhandene Batterie zeigt. EOS rechnet mit genau einer Batterie.
   Ohne echten Speicher eignet sich die Symcon-Bibliothek „Virtual Devices“ (nächster Abschnitt).
6. **EOS Zähler anlegen**: kumulierte Zählerstände für Hauslast, Netzbezug und Einspeisung, dann „Historie aus
   Archiv importieren“. Ohne Zähler nutzt EOS das Lastprofil aus Schritt 4.
7. **Optional E-Auto und Haushaltsgerät** anlegen; sie legen sich ebenfalls beim Übernehmen in EOS an.
8. **Ersten Lauf abwarten**: EOS rechnet alle 15 Minuten (`ems.interval`), oder „Optimierung starten“ im EOS
   Server (stößt den Lauf nur an; das Ergebnis kommt mit dem nächsten Abruf). Nach dem Lauf zeigt der EOS Server
   Plan-ID und Gültigkeit, die Batterie den Modus und die Kachel den Fahrplan. Läuft nichts: die
   Konfigurationsprüfung im Formular des EOS Servers nennt bekannte Ursachen (Geräteanzahl über dem Maximum,
   fehlender Wechselrichter, falsche `battery_id`, Min-SoC ≥ Max-SoC), `./setup.sh logs` den Rest, meist einen
   fehlenden frischen SoC (max. 300 s alt).
9. **Steuerung anschließen**: im Panel „Steuerung“ der Batterie die Zielvariablen des Wechselrichters binden
   (Option 1) oder Aktionen/Skript (Option 2/3), erst Steuerungsmodus „Simulation“ und den Ergebnis-Text beobachten.
10. **Aktiv schalten**, Fallback-Modus und bei Geräten mit Timeout den Heartbeat setzen. Ab jetzt schaltet Symcon
    den Speicher zu jeder Slot-Grenze nach EOS-Plan.

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
- Symcon ≥ 8.1.
- Geräte in EOS (`devices/batteries/<id>` …) legen die Geräte-Instanzen beim Übernehmen selbst an und gleichen
  sie ab, ohne Änderungen aus EOSdash zu überschreiben (siehe [Wem gehört welcher Wert](#wem-gehört-welcher-wert)).

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
   „In EOS speichern“ dauerhaft sichern. Diese globale EOS-Konfiguration schreibt Symcon nur auf Knopfdruck; die
   Zählerschlüssel der EOS Zähler bleiben dabei erhalten. Automatisch nach EOS gehen dagegen die in Symcon
   geänderten Felder der Geräteeinträge, Abfahrt und Fertig-bis-Zeiten, wenn die Instanz sie besitzt (siehe
   [Wem gehört welcher Wert](#wem-gehört-welcher-wert)), sowie laufend alle Messwerte.
   **Wechselrichter**: GENETIC braucht genau einen. Der EOS Server pflegt ihn im gleichnamigen Abschnitt (ID,
   Leistung, AC-Ladeleistung, Wirkungsgrade) und setzt seine `battery_id` beim Schreiben auf die Batterie-Instanz
   an diesem Server. 0 W heißt: der Wechselrichter wird in EOSdash gepflegt.
   Dynamischer Tarif: Strompreis-Provider `ElecPriceEnergyCharts` (Gebotszone `DE-LU`, 15-Minuten-Raster) und
   unter Gebühren `ElecFeeFixed` mit dem festen Netto-Aufschlag des Tarifs in EUR/kWh (Beschaffung, Netzentgelt,
   Konzession, Stromsteuer, Umlagen) plus 19 % Aufschlag für die Mehrwertsteuer. EOS rechnet dann mit
   (Börsenpreis + Aufschlag) × 1,19 je Viertelstunde.
3. **EOS Batterie** anlegen, mit dem Server verbinden, Geräte-ID (wie in EOS, z. B. `battery1`) und die
   SoC-Quellvariable wählen (Prozent oder Faktor; ein Prozentwert bei Einstellung „Faktor“ wird mit Warnung
   umgerechnet). Der SoC wird im eingestellten Intervall und bei Wertänderung an EOS gesendet. EOS verwirft
   SoC-Werte, die älter als 300 s sind. Optional stoppt „Höchstalter der SoC-Quelle“ den Push mit Warnung, wenn die
   Quellvariable länger nicht aktualisiert wurde, statt einen eingefrorenen Wert als frisch zu melden.
4. Unter **Batterieparameter** Kapazität, Leistung, SoC-Grenzen und Wirkungsgrade eintragen. Beim Übernehmen
   legt die Instanz den Geräteeintrag in EOS an bzw. gleicht ihn nach den Regeln unten ab; das Ergebnis steht
   unter den Parametern.

### Wem gehört welcher Wert

Jede Geräte-Instanz merkt sich den zuletzt abgeglichenen Stand ihres EOS-Eintrags und entscheidet je Feld:

| Symcon | EOS | Ergebnis |
| --- | --- | --- |
| geändert | unverändert | wird nach EOS geschrieben |
| unverändert | geändert (z. B. in EOSdash) | bleibt in EOS; beim Öffnen lädt das Formular den EOS-Wert in das Feld, Übernehmen speichert ihn in Symcon |
| geändert | anders geändert | Konflikt, nichts wird geschrieben; das Feld behält den Symcon-Wert, die Meldung unter den Parametern zeigt beide, entschieden wird mit „EOS mit den gespeicherten Symcon-Werten überschreiben“ oder „Werte aus EOS laden“ |

- Kernelstart, Modul-Reload und verzögertes Übernehmen ändern keine Symcon-Werte und schreiben deshalb nie.
- Ohne gemeinsamen Stand (erster Abgleich nach einem Update, eine eingetippte ID eines vorhandenen Eintrags)
  behält EOS jeden Wert, den es hat; Abweichungen werden angeboten, nur Felder, die EOS leer lässt, werden aus
  Symcon gefüllt.
- „Gerät in EOS“ wählt einen vorhandenen Eintrag und lädt ihn vollständig ins Formular; Übernehmen im selben
  Formular schreibt danach nur, was man selbst geändert hat. Eine Auswahl ohne Übernehmen verfällt mit dem
  Schließen des Formulars. „Werte aus EOS laden“ lädt das Gerät, dessen ID das Formular gerade zeigt;
  „EOS mit den gespeicherten Symcon-Werten überschreiben“ schreibt die gespeicherten Werte und verweigert, solange
  das Formular eine andere ID zeigt.
- Zeitfenster werden vollständig verglichen, auch `day_of_week`, `date` und `locale` aus EOSdash; Dauern in jeder
  Schreibweise („90 minutes“ = „1 hour 30 minutes“, wie EOS sie zurückschreibt).
- Batterie und E-Auto gibt es in EOS nur je einmal (GENETIC rechnet mit genau einer Batterie und höchstens einem
  E-Auto). Hat EOS schon ein anderes, zeigt die Instanz Status 205 und legt kein zweites an. Nach einem Wechsel der
  Geräte-ID (bei allen Gerätearten) bleibt der alte Eintrag stehen, bis „Alten EOS-Eintrag entfernen“ ihn dauerhaft
  löscht (auch aus `EOS.config.json`); der Knopf erscheint, solange der alte Eintrag existiert. Die Batterie zieht
  dabei die `battery_id` des Wechselrichters nach. Scheitert das Entfernen halb, stellt der Server den Eintrag
  wieder her.
- Geräte-IDs beginnen mit einem Buchstaben und sind je EOS Server über Batterien, E-Autos und Haushaltsgeräte
  hinweg eindeutig. Die ID gehört der Instanz, die sie zuerst beansprucht hat; der EOS Server merkt sich das. Eine
  später angelegte Instanz mit derselben ID zeigt Status 203, auch mit kleinerer InstanceID. In 201/203/205 schreibt
  eine Instanz weder in EOS noch an ihre Hardware.
- Widersprüchliche Grenzen (Batterie: Min-SoC ≥ Max-SoC; E-Auto: Max-SoC 0) gehen nicht nach EOS (Status 206).
  Ein Ziel-SoC ≥ Max-SoC geht als Max-SoC − 1 nach EOS, weil EOS das Ziel unter dem Maximum verlangt.
- **Zeiten** (Abfahrt des E-Autos, Fertig-bis und frühester Start des Haushaltsgeräts) schreibt die Instanz je Feld
  nur, wenn der Schalter „… nach EOS schreiben“ an ist und das Feld eine eigene Quelle hat: die Abfahrt ihre
  Quellvariable oder `EOSEV_SetDeparture`, das Fertig-bis seine Quellvariable oder `EOSHA_SetDeadline`, der
  früheste Start seine Quellvariable. Vergangene Zeiten löscht sie in EOS. Felder ohne Quelle bleiben aus EOSdash
  unangetastet; eine vergangene Frist mit Strategie STRICT, an der jeder Lauf scheitert, meldet die Instanz.
- Scheitert nach einem Schreibvorgang das Speichern in EOS, schreibt die Instanz den Wert beim nächsten Übernehmen
  erneut, statt ihn nach einem EOS-Neustart für eine EOSdash-Änderung zu halten.

### E-Auto, Haushaltsgerät, Zähler

- **E-Auto**: Geräte-ID wie in EOS (`devices/electric_vehicles/<id>`), SoC-Quellvariable, optional Variablen für
  „angesteckt“ und Abfahrtszeit (Unix-Zeitstempel). Die Abfahrt geht als `min_soc_deadline_datetime` nach EOS, wenn
  die Instanz sie besitzt (siehe oben); der Ziel-SoC steht als `min_soc_percentage` im Geräteeintrag. Mit „SoC-Quelle
  nur angesteckt verwenden“ wird abgesteckt der letzte angesteckte SoC weitergesendet. Angezeigt werden Laden
  geplant, Soll-Ladeleistung und Soll-Ladestrom (aus Phasen und Spannung). Beim Übernehmen wird das Fahrzeug in EOS
  angelegt bzw. abgeglichen; `max_electric_vehicles` wird bei Bedarf auf 1 angehoben (mehr kann GENETIC nicht).
- **Haushaltsgerät**: Energie je Lauf, Dauer, Zeitfenster, Planungsmodus ONCE/DAILY, optional Frist und frühester
  Start aus Variablen (Besitzregel wie oben) sowie „heute erledigte Läufe“. Angezeigt werden geplanter Start und Ende sowie RUN/OFF.
  Beim Übernehmen wird das Gerät in EOS abgeglichen und `devices/max_home_appliances` bei Bedarf angehoben. Steht
  der Wert unter der Anzahl der Geräte, bricht EOS jeden Lauf ab („home_appliances exceeds configured maximum“).
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
3. **Option 3: Bei jeder Änderung, jedem Moduswechsel und jedem Heartbeat Instanzaktion oder Skript ausführen** –
   eine Aktion und ein Skript, das Skript mit dem Kontext in `$_IPS` (`Reason`, `ModeRaw`, `Factor`, `PowerW`,
   `ChargeAllowed`, `CurrentA`, `Run` …, vollständige Liste in [docs/geraete-mapping.md](docs/geraete-mapping.md)).
   Option 3 folgt dem ganzen Sollzustand, auch ohne Option-1-Ziele: eine neue Leistung im selben Modus erreicht das
   Skript. Reine Wiederholungen fehlgeschlagener Ziele lösen Option 3 nicht aus.

Geschrieben wird nur bei Änderung. Ob ein Ziel geschrieben wurde, entscheidet der Rückgabewert von `RequestAction`
bzw. die Ausgabe der Aktion (Symcon wirft dabei keine Ausnahmen). Ein fehlgeschlagenes Ziel wird mit wachsendem
Abstand wiederholt (60 s je Fehlschlag, höchstens 300 s); die übrigen Ziele laufen weiter. Optional sendet ein
**Heartbeat** die Sollwerte alle n Sekunden erneut (mindestens 5 s, für Wechselrichter mit eigenem Timeout): alle
gesunden Ziele und Option 3 gemeinsam, auch bei reiner Skript-Anbindung. Liefert die Steuerung nichts zu tun
(Fallback „kein Eingriff“, Bindungen nicht bereit), verwirft sie den gemerkten Sollzustand: keine Heartbeats und
Wiederholungen mehr, beim Wiedereinstieg ein vollständiger Abgleich.
Strukturfehler sperren die Steuerung und stehen im Ergebnis-Text: keine Bindung, eine Variable der eigenen
Instanz als Ziel, Modus-Werte, die die Modus-Variable nicht annehmen kann, ein Leistungsziel bei Max-Leistung 0,
beim Haushaltsgerät keine Bindung, die starten kann. Ein gelöschtes oder nicht schaltbares Ziel sperrt nicht, es
wird gemeldet und wiederholt. Der **Fallback-Modus** greift, wenn der Plan veraltet (Einstellung
„Plan als veraltet markieren nach“), abgelaufen (`valid_until`) oder für die Steuerung unbrauchbar ist
(unbekannter Modus); ein kurzer EOS-Ausfall ist kein Auslöser, der gespeicherte Plan läuft weiter. Ein
**Hauptschalter** (Variable „Steuerung aktiv“) und ein **manueller Modus** mit Haltezeit überlagern den
Plan. Beim Abschalten wird der Fallback einmal geschrieben (abschaltbar), danach ruht die Steuerung bis zum
Wiedereinschalten. Schreiben passiert nie im Empfangspfad des Servers, sondern kurz danach im eigenen Zeitgeber der
Instanz; ein 60-s-Wächter prüft Alter des Plans, Heartbeat und Wiederholungen weckt ein eigener Zeitgeber genau zum
nächsten fälligen Zeitpunkt. Eine abgelaufene manuelle Haltezeit endet vor jeder Entscheidung; im Modus „Nur
anzeigen“ bleibt ein laufender manueller Modus mit seiner Haltezeit erhalten. Warnungen kommen einmal je
Störung, ein flatterndes Ziel höchstens einmal je Stunde.

Sicherheitsregeln der Batterie: Netzladen mit 0 W wird nie geschrieben (wird zu `NON_EXPORT`), „Netzladen
erlauben“ und „Netzeinspeisung erlauben“ schwächen die Modi ab, wenn der Anwender sie verbietet. Leistungen
rechnet die Batterie mit der max. Ladeleistung aus EOS; der Einspeise-Sollwert ist höchstens die max.
Entladeleistung.
E-Auto: Mindest-Ladestrom (6 A), Mindestabstand zwischen Laden Ein/Aus (300 s; in der Wartezeit bleibt der zuletzt
geschriebene Ladezustand stehen), nicht angesteckt = aus.
Haushaltsgerät: ein Startimpuls je geplantem Lauf, nur innerhalb der Gnadenfrist nach dem geplanten Start; danach
sperrt die Instanz weitere Starts für die Laufdauer, auch wenn EOS neu plant. Die Gnadenfrist begrenzt nur späte
Starts: ein gestartetes oder laufendes Gerät behält seine Freigabe, auch mit „Stoppen erlauben“. Mit Quellvariable
„läuft“ fällt die Sperre, wenn das Gerät 5 Minuten nach dem Impuls nicht läuft; dann folgt ein neuer Versuch in der
Gnadenfrist, bei erneutem Ausbleiben eine Warnung und kein weiterer. Läuft das Gerät zum geplanten Start schon (von
Hand gestartet), gilt der geplante Lauf als erledigt. Der Impuls geht an die RUN-Aktion (Option 2), sonst an die
Freigabe (Flanke aus → an), sonst an Option 3 mit `Start = true`. Manuell „Läuft“ startet einmal auf der Flanke,
nicht bei erneutem Setzen und nicht, wenn das Gerät schon läuft. Stoppen nur wenn erlaubt.

## Variablen der Batterie

| Variable | Bedeutung |
| --- | --- |
| Betriebsmodus | Aktive EOS-Anweisung als Aufzählung (siehe Tabelle unten) |
| Betriebsmodus (roh) | `operation_mode_id` aus EOS |
| Faktor | `operation_mode_factor` 0 bis 1 |
| Soll-Ladeleistung | Faktor × max. Ladeleistung (Wert aus EOS) bei Netzladen, sonst 0 |
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
Tooltip beim Überfahren und Legende. Hell- und Dunkelmodus werden aus der Textfarbe der Kachel erkannt. Die
Beschriftungen folgen der Sprache von Symcon, Uhrzeiten und Zahlen dem Format des Geräts.

## Funktionen für Skripte

```php
EOS_TestConnection($id);            // bool
EOS_FetchPlan($id);                 // bool, Plan neu abrufen und an Geräte verteilen
EOS_Optimize($id);                  // Optimierung in EOS anstoßen (asynchron)
EOS_GetConfig($id, 'general');      // JSON-String eines Konfigpfads ('' = alles)
EOS_SetConfig($id, 'ems/interval', '900');
EOS_SaveConfig($id);
EOS_PutMeasurement($id, 'load0_emr', 12345.6, '');   // Zeitstempel '' = jetzt; hält SoC und Läufe wie jeder Push frisch
EOSBAT_PushSoC($id);
EOSBAT_GetActiveInstruction($id);   // JSON der aktiven Anweisung
EOSBAT_ApplyControl($id, true);     // Steuerung jetzt anwenden, true = alle Ziele neu schreiben
EOSBAT_SetManualMode($id, 5);       // manuell FORCED_CHARGE; 100 = Automatik (EOSEV_: 0 aus / 5 laden; EOSHA_: 0 / 1)
EOSBAT_GetControlState($id);        // JSON: Sollzustand, zuletzt Geschriebenes, Fallback, manuell
EOSBAT_WriteConfigToEOS($id);       // Geräteeintrag in EOS mit den gespeicherten Symcon-Werten überschreiben (auch EOSEV_, EOSHA_)
EOSBAT_ReadConfigFromEOS($id);      // EOS-Werte ins Formular laden
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
  scheitert die Parametervorbereitung von GENETIC still (nur im Container-Log sichtbar). Die Geräte-Instanzen heben
  den Wert beim Übernehmen an; die Konfigurationsprüfung des EOS Servers zeigt Abweichungen.
- GENETIC rechnet mit genau einer Batterie, höchstens einem E-Auto und genau einem Wechselrichter, dessen
  `battery_id` zur Batterie passen muss.
- `null` in einem Merge (`PUT /v1/config`) löscht nichts. Gelöscht wird nur über den Pfad
  (`PUT /v1/config/<pfad>` mit `null`); so löscht das Modul vergangene Fristen.
- Ein Geräteeintrag kommt nach dem Ersetzen der Geräte-Map beim nächsten Merge zurück. Dauerhaft entfernt ihn nur
  Map ersetzen → speichern → `POST /v1/config/reset`; „Alten EOS-Eintrag entfernen“ macht das in einem Schritt.
- Anweisungs-IDs (`<gerät>@<uuid>`) sind bei jedem Lauf neu, `valid_until` ist immer leer. Das Modul erkennt eine
  Anweisung deshalb an Startzeit und Modus.
- `POST /v1/optimize` antwortet erst nach dem ganzen Lauf; EOS rechnet auch nach einem Abbruch der Verbindung weiter.
  „Optimierung starten“ stößt den Lauf deshalb nur an und blockiert den EOS Server nicht.
- Die EOS-Konfigurationsdatei speichert nur Werte, die vom Standard abweichen. Ein fehlender Schlüssel in
  `EOS.config.json` heißt „Standardwert“, nicht „nicht gesetzt“.
- Prognosen manuell neu laden: `POST /v1/prediction/update` (alle Provider) oder
  `POST /v1/prediction/update/<ProviderId>`.

## Entwicklung

- Standards: `declare(strict_types=1)`, `IPSModuleStrict`, Presentation-Arrays statt Variablenprofile.
- Prüfstand ohne Symcon: `tests/run.sh` prüft Syntax und JSON und führt die Regressionstests der Steuerung,
  des Konfigurationsabgleichs, des Zählers und des EOS Servers gegen ein SDK-Double und ein EOS-Double aus
  ([tests/README.md](tests/README.md)).
- Test gegen Symcon in Docker (`symcon-91-rust`, Port 3778) und EOS in Docker (Port 8503), siehe
  [docs/eos-setup.md](docs/eos-setup.md).
