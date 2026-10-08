# Steuerung der Geräte

Wie die Geräte-Instanzen (Batterie, E-Auto, Haushaltsgerät) den EOS-Plan an die Hardware geben, und warum so. Bedienung und Beispiele: `../geraete-mapping.md`, Prüfungen: `../../tests/README.md`.

## Entscheidungen

- **Herstellerneutral, ohne Vorlagen im Formular** (21.09.2026, Nutzerentscheid). Drei Wege, kombinierbar: Werte in schaltbare Variablen schreiben (`RequestAction` mit Typumwandlung), je EOS-Modus eine Symcon-Aktion (`IPS_RunActionWait`), bei jeder Änderung Aktion oder Skript (`IPS_RunScriptEx`, Kontext in `$_IPS`). Herstellerbeispiele stehen nur in `geraete-mapping.md`, weil jede eingebaute Vorlage ein fremdes Modul nachbilden und mitpflegen müsste.
- **Fallback nur über den Plan selbst** (`planUsable()` in `libs/EOSPlanDevice.php`): veraltet (`StaleAfterMinutes`), abgelaufen (`valid_until` + 900 s Toleranz) oder keine passende Anweisung. Dass EOS nicht erreichbar ist, löst **keinen** Fallback aus: ein gültiger Plan läuft autark weiter, und ein Fallback an der Erreichbarkeit würde bei jedem kurzen Aussetzer flattern.
- **Nie im Empfangspfad schreiben.** Der Server verteilt den Plan, die Geräte merken sich nur den Sollzustand; geschrieben wird in einem eigenen Durchlauf (`RegisterOnceTimer('Dispatch')` in `libs/EOSControl.php`). So blockiert ein langsames Ziel weder den Server noch die Geschwister.
- **Hauptschalter „Steuerung aktiv“ und manueller Modus mit Haltezeit** (Nutzerentscheid). Die Haltezeit steht im Attribut `ManualUntil` (aus `ManualReturnMinutes`); vor jeder Entscheidung wird eine abgelaufene Haltezeit beendet. Nach manuellem Eingriff ist der Hardwarestand unbekannt, deshalb wird einmal alles neu geschrieben.
- **Beim Verlassen von „Aktiv“ den Fallback einmal schreiben** (`ReleaseOnDisable`, Vorgabe an), aber nur bei eingeschaltetem Hauptschalter: war er aus, ist das Gerät schon freigegeben.
- **Erfolg am Rückgabewert, nicht an Ausnahmen.** Symcon 9.1 wirft bei einer werfenden Variablenaktion nicht, sondern liefert `false` plus Warnung; `IPS_RunActionWait` liefert den PHP-Fehlertext als Ausgabe (Plattformwissen `module-strict-und-php.md`). Fehlschläge werden nach 60 s × Anzahl wiederholt, höchstens 300 s (`MAX_RETRY_BACKOFF`); ein neuer Sollwert geht sofort hinaus.
- **Heartbeat optional, mindestens 5 s** (`HeartbeatSeconds`), für Wechselrichter mit eigenem Watchdog. Bei Fallback „kein Eingriff“ ruhen Heartbeat und Wiederholungen, damit dieser Watchdog greifen kann.
- **Haushaltsgerät startet genau einmal je geplantem Lauf.** Ein unbestätigter Start wird genau einmal wiederholt; ein Gerät, das zwei Starts ignoriert, bleibt dem Nutzer überlassen (`libs/EOSApplianceStart.php`). Manuell „Läuft“ startet nur auf der Flanke.
- **Gesperrte Instanzen schreiben nichts:** Gerätestatus 201/203/205 (ID fehlt, doppelt, EOS hat schon ein Gerät dieser Art) sperren alle Einstiege.

## Herkunft

Steuerung am 21.09.2026 nachgezogen, Review vom 23.09.2026 (Kennungen K…, S…, N1) und Abschlussprüfung mit zwei Prüfagenten (R6-…, R8-…) als PR #4 gemergt (Build 3). Muster aus diesen Runden: Jede Fix-Runde brachte weniger, aber noch echte Funde; Prüfagenten auf den Diff der Runde ansetzen, nicht auf das Ganze.

## Offen

- Fremdänderungs-Erkennung vor dem Heartbeat (Gerät wurde außerhalb von Symcon umgestellt).
- Asynchrone Aktionen: Option 3 startet Skripte per `IPS_RunScriptEx`, „OK“ heißt gestartet, nicht fertig.
- `PhasesSourceVariable` für das E-Auto (Phasenzahl aus einer Variable statt fest).

Stand: geprüft gegen den Code am 08.10.2026
