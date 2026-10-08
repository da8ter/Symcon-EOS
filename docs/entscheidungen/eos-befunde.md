# EOS im Betrieb: Befunde

Verhalten von EOS 0.4.0rc1, das im Betrieb aufgefallen ist und nicht schon in der README („Bekannte EOS-Eigenheiten“) oder in `../eos-setup.md` („Typische Probleme“) steht. Die Ursachen stammen aus dem EOS-Quelltext; Dateiangaben beziehen sich auf den Tag `v0.4.0rc1`.

## Ein Haushaltsgerät ohne Zyklen-Tageswert stoppt alle Läufe

- **Was:** Fehlt für ein konfiguriertes Haushaltsgerät seit Mitternacht ein gültiger Zählwert erledigter Zyklen, bricht EOS jeden Lauf ab („Invalid completed cycle count“, `configrequest.py`). Das gilt für alle Clients desselben EOS, auch wenn der Eintrag zu keiner Symcon-Instanz mehr gehört, etwa nach dem Deaktivieren einer Test-Instanz.
- **Im Modul:** Seit Build 5 (`54a9526`, PR #6) prüft der EOS Server je Haushaltsgerät den jüngsten Datensatz des Tages wie `configrequest.py` (`applianceCycleProblems()` in `libs/EOSServerConfig.php`) und nennt Gerät und Besitzer im Log und in der Konfigurationsprüfung. Die Geräte-Instanz selbst sendet den Wert alle 15 Minuten, ohne Quellvariable 0.

## Lastprognose: das Anpassungsfenster ist sieben Tage

- **Was:** `LoadAkkudoktorAdjusted` zieht die Abweichung Messung − Profil der letzten sieben Tage ab (`loadakkudoktor.py`). Eine fehlende Lastmessung zählt dabei als 0, das Profil wird also weggerechnet: Beobachtet wurde eine Lastprognose nahe 0 kWh am Tag, nachdem der Lastzähler zwei Tage leer war (nicht am Code prüfbar, EOS-Quelltext).
- **Folge:** Nach dem Einrichten oder Umstellen des Lastzählers die Historie für mindestens 168 h importieren (`EOSMTR_ImportHistory($id, 192)`). Die Vorgabe `HistoryHours` = 48 (`EOSMeter/module.php`) reicht dafür nicht, siehe `../stand.md`.

## Festpreis: Gebühren und Mehrwertsteuer kommen trotzdem dazu

- **Was:** EOS schlägt `elecfee` (Aufschlag und Prozent) auch auf `ElecPriceFixed` auf (`elecpriceabc.py`). Wer Bruttopreise als Festpreis oder Zeitfenster einträgt, muss `consumption_amt_kwh` und `consumption_percent_amt` auf 0 setzen bzw. `elecfee.provider` leer lassen, sonst zählen Netzentgelte und Mehrwertsteuer doppelt (nicht am Code prüfbar).
- **Zeitfenster** setzt das Modul als ganze Liste (`ElecPriceFixedWindows` → `elecprice/elecpricefixed/elecprice_marketprice_amt_kwh`, `putWindows()` in `libs/EOSConfigMapper.php`), unverändert so, wie sie im Formular stehen; Dauern in EOS-Schreibweise („2 hours“). Ein Tarif-Fenster über Mitternacht wurde bisher von Hand als zwei Fenster eingetragen; ob EOS ein Fenster über Mitternacht annimmt, ist nicht geprüft.

## Flacher Preis: zufällige Moduswechsel

- **Was:** Bei einem Preis ohne zeitliche Unterschiede sind Pausen der Batterie (`NON_EXPORT`, `IDLE`) kostenneutral; der genetische Algorithmus streut dann Moduswechsel ohne erkennbaren Grund. Beobachtet am 05.10.2026 (nicht am Code prüfbar). Wer Festpreis nutzt, sieht im Plan deshalb Wechsel, die die Steuerung trotzdem ausführt.

## Messwerte: `/data` nimmt jeden Schlüssel, `/samples` nicht

- `PUT /v1/measurement/data` akzeptiert beliebige Schlüssel; darüber gehen SoC- und Zyklenwerte zusammen mit jedem anderen Messwert (`libs/EOSMeasurementBundle.php`).
- `PUT /v1/measurement/samples` (Historien-Import des Zählers) verlangt registrierte Energiekanäle und antwortet sonst 422; der Zähler registriert seine Schlüssel danach neu (geprüft in `tests/config_test.php`).

## Quellvariablen der Zähler müssen stabil sein

- Eine Symcon-Variable, deren Bedeutung wechselt (etwa ein Cloud-Wert, dessen Index-Reihenfolge sich ändert), verfälscht die Zählerreihe in EOS dauerhaft; EOS hat danach Sprünge in der Messreihe (beobachtet, nicht am Code prüfbar). Solche Quellen nicht als Zähler binden. Eine bereinigte Reihe ersetzt man per `PUT` der ganzen Reihe, nicht durch einzelne Löschungen.

Stand: geprüft gegen den Code am 08.10.2026
