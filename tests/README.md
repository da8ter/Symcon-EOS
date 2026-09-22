# Prüfstand

Regressionstests ohne Symcon und ohne EOS. `tests/bootstrap.php` stellt ein isoliertes SDK-Double bereit:
eine `IPSModuleStrict`-Basisklasse, die Eigenschaften, Attribute, Variablen und Timer im Speicher hält, die
benötigten `IPS_*`-Funktionen über eine kleine „Welt“ aus Zielvariablen, und `FakeEOS` als Ersatz für den
EOS Server (beantwortet `SendDataToParent`: Plan, Konfiguration, Messwerte).

```sh
php tests/control_test.php
php tests/sync_meter_test.php
```

Beide Läufe enden mit `Alle N Prüfungen bestanden.`; die erste fehlgeschlagene Prüfung bricht mit `FAIL:` und
Exit-Code 1 ab. Keine Abhängigkeiten außer PHP ≥ 8.1.

## Was geprüft wird

`control_test.php` (Batterie, E-Auto, Haushaltsgerät):

- Steuerungsmodus „Nur anzeigen“ schreibt nie, auch nicht beim Ausschalten des Hauptschalters.
- Simulation führt weder Zielvariablen noch Aktionen noch Skripte aus, protokolliert aber.
- Aktiv: genau ein Schreibvorgang je gebundenem Ziel; unveränderter Plan, erneuter `ProcessPlan` und Wächter
  schreiben nichts; ein Slotwechsel schreibt nur die geänderten Ziele.
- Fehlgeschlagener Schreibvorgang: Wert wird gemerkt, derselbe Wert erst nach dem Backoff erneut versucht, ein
  anderer Wert sofort; Erfolg löscht den Fehlerzustand.
- Modus-Aktion: fehlgeschlagen bleibt sie offen (Backoff), erfolgreich gilt sie einmal je Modus; eigene
  Parameter der Aktion (TARGET, VALUE) gewinnen über den Kontext.
- Fallback bei veraltetem Plan (einmal geschrieben, einmal geloggt), Ende mit frischem Plan, Lückentoleranz
  für Pläne, deren erste Anweisung kurz bevorsteht, Fallback bei unbekanntem Modus.
- Manueller Modus überlagert den Plan; Rückkehr über den Wächter nach Ablauf der Haltezeit.
- Hauptschalter aus schreibt den Fallback einmal, danach Stille; ein schreibt den Planzustand einmal;
  Modus 2 → 0 gibt einmal frei und stoppt den Wächter.
- Batterie-Politik: Netzladen verboten oder 0 W → NON_EXPORT, Export verboten → SELF_CONSUMPTION, Anzeige
  behält den EOS-Modus, Degradation im Ergebnistext.
- E-Auto: Mindeststrom 6 A, Verweilzeit hält Ein/Aus zurück, nicht angesteckt = aus.
- Haushaltsgerät: Start genau einmal je Anweisung innerhalb der Gnadenfrist, verpasster Start wird übersprungen,
  fehlgeschlagener Start wird nicht als erledigt gemerkt, OFF nur mit Freigabe.

`sync_meter_test.php`:

- Typumwandlung und Normalisierung der Bindungen (Bool-Schreibweisen, Rundung, Enum-Strings, Aktions-JSON).
- Konfigurationsabgleich: gleiche Werte werden nicht geschrieben, Zeitstempel und Zeitfenster tolerant
  verglichen, `null` erreicht EOS nie (extern gepflegte Abfahrtszeit bleibt), nur das abweichende Feld wird
  geschrieben und geloggt.
- Zähler: Schlüsselregistrierung bricht bei Lesefehler ab und erweitert nur die passende Liste; kommt der
  Server später, holt ein Einmal-Zeitgeber `ApplyChanges` nach.

## Nicht abgedeckt

Der EOS Server selbst (REST-Client, Health/Plan-Abruf, Sticky-Bundle), die HTML-Kachel und alles, was echte
Zeit braucht (Slot-Timer, Heartbeat-Intervalle). Dafür gibt es den Live-Prüfstand mit Mock-EOS und Dummy-Zielen
in der Docker-Testinstanz, siehe README „Prüfstand mit virtuellen Geräten“.
