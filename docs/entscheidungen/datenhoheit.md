# Datenhoheit: Geräte-IDs und Gerätekonfiguration

Wer welchen Wert in EOS besitzt, wenn Symcon-Formular und EOSdash dieselbe Gerätekonfiguration ändern können. Bedienung: README, Abschnitt „Wem gehört welcher Wert“.

## Entscheidungen

- **Die Geräte-ID-Hoheit führt der EOS Server** (`ClaimDevice`, Attribut `DeviceOwners`, `libs/EOSServerConfig.php`). Der erste Anspruch auf eine ID gewinnt und bleibt gespeichert; ein Neustart oder eine neu angelegte Instanz mit der Vorgabe-ID ändert daran nichts. Ein Eintrag, dessen Instanz gelöscht, an einen anderen Server gehängt oder umbenannt ist, wird ersetzt.
- **Die Claim-Antwort zählt nicht als „EOS erreichbar“.** Sie kommt von der Server-Instanz, nicht von EOS, und läuft deshalb nicht durch `reply()` (`EOSServer/module.php`).
- **Ohne Antwort der Server-Instanz wird nichts neu entschieden** (keine, deaktiviert oder gerade im Modul-Reload; `deviceIdTaken()` in `libs/EOSPlanDevice.php`, `2673343`): Es gilt die letzte Entscheidung für diese ID (Attribut `IdDecision`), eine Sperre bleibt, ein Besitzer arbeitet weiter. Der Server wird erneut gefragt, im Reload nach 30 s (`ClaimRetry`), sonst sobald er wieder Daten schickt. Die Regel ist auch bei EOS-Ausfall entscheidbar, weil die Besitzliste in der Server-Instanz liegt, nicht in EOS.
- **Drei-Wege-Abgleich mit gemeinsamem Stand** (`libs/EOSConfigCompare.php`, `EOSDeviceConfigSync.php`): Symcon geändert → schreiben, EOS geändert → im Formular anbieten, beide geändert → nichts schreiben. Kernelstart und Reload schreiben nie.
- **Ohne gemeinsamen Stand behält EOS jeden vorhandenen Wert** (`adoptionBase()`): erster Abgleich nach einem Update oder eine eingetippte ID eines vorhandenen Eintrags. Nur Felder, die in EOS leer sind, kommen aus Symcon. Ein ungespeicherter Schreibvorgang wird nie zur Basis.
- **Die Auswahl eines vorhandenen EOS-Geräts gilt nur im offenen Formular** und überschreibt nichts; „Werte aus EOS laden“ füllt das Formular ohne Konfliktfelder.
- **Überschreiben ist ein eigener, ausdrücklich benannter Knopf:** „EOS mit den gespeicherten Symcon-Werten überschreiben“, nur mit der gespeicherten ID.
- **Fristen und Zeitfenster nur mit Besitz**, vergangene Fristen per Pfad-PUT mit `null` löschen (ein Merge ignoriert `null`). Das SoC-Paar (min/max) wird immer zusammen geschrieben; die nicht geschriebene Grenze behält den Wert aus EOS.

## Grenze: ein EOS, zwei Symcon-Systeme

Die ID-Hoheit gilt je Server-Instanz. Zeigen zwei Symcon-Systeme mit eigenen Server-Instanzen auf dasselbe EOS und nutzen dieselben Geräte-IDs, sieht keines das andere. Beobachtet am 22.09.2026: Beide schrieben den SoC derselben Batterie mit verschiedenen Werten, der Wert flatterte, und EOS brach Läufe mit „Fresh SoC missing“ bzw. „Invalid or stale SoC factor“ ab. Ein EOS gehört deshalb genau einem Symcon-System. Abhilfe im Modul (`8bd833b`): SoC- und Zyklenwerte gehen im selben Request wie jeder andere Messwert hinaus (`PUT /v1/measurement/data`), damit EOS nie einen halb geschriebenen Datensatz sieht.

Stand: geprüft gegen den Code am 08.10.2026
