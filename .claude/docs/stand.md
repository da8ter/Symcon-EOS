# Stand

Offenes über alle Bereiche. Erledigtes wird hier gestrichen, nicht abgehakt. Veröffentlicht ist Build 5 (`main`, 29.09.2026).

## Offen

- **Steuerung:** Fremdänderungs-Erkennung vor dem Heartbeat, asynchrone Aktionen, `PhasesSourceVariable` für das E-Auto ([steuerung](entscheidungen/steuerung.md)).
- **Zähler:** Vorgabe `HistoryHours` = 48 deckt das Sieben-Tage-Fenster der Lastanpassung nicht ab ([eos-befunde](entscheidungen/eos-befunde.md)); Vorgabe anheben oder im Formular darauf hinweisen.
- **Phase 3 des Integrationsplans:** KPIs, PDF-Report, Diagnose-Export, Prognose-Import-Instanz. **Phase 4:** Push-Kanal von EOS, Veröffentlichung im Module Store, Nachziehen auf EOS 0.4.0 final ([integrationsplan](../../docs/integrationsplan.md), Abschnitt 5).
- **Upstream:** Die SoC-/Zyklensuche in `configrequest.py` nimmt den jüngsten Datensatz auch ohne den gesuchten Schlüssel (`dropna=False`); das Modul umgeht es mit gebündelten Messwerten. Ein Fix in EOS wäre `dropna=True`.

## Zu klären

- `integrationsplan.md` ist ein Planungsstand vom 19.09.2026 mit Nachtrag vom 21.09. Kopf und Abschnitte 4, 5 (Phase 1) und 8 nennen noch Variablenprofile (`EOS.BatteryMode`), Symcon ≥ 7.1 und „Ausfall von EOS führt in den Fallback“; umgesetzt sind Darstellungen, Mindestversion 8.1 und ein Fallback nur über den Plan ([steuerung](entscheidungen/steuerung.md)). Entweder als historisch kennzeichnen oder nachziehen.

Stand: geprüft gegen den Code am 08.10.2026
