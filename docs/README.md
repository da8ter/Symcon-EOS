# Symcon-EOS — Projektdoku

Versioniertes Projektwissen: **warum** etwas so gebaut ist, was an EOS und Symcon gemessen wurde und wie man es prüft. Was der Code selbst zeigt, steht hier nicht; Bedienung steht in der README.

Neue Dateien enden mit „Stand: geprüft gegen den Code am …“. Ändert ein Commit eine hier beschriebene Entscheidung, wird die Datei im selben Commit nachgezogen.

## Anleitungen und Planung

- [eos-setup](eos-setup.md) – EOS in Docker einrichten, typische Probleme, Prognose-Keys
- [geraete-mapping](geraete-mapping.md) – Beispiele für die Steuerung je Hersteller, Kontext in `$_IPS`
- [integrationsplan](integrationsplan.md) – Analyse von EOS 0.4.0rc1 und Phasenplan (Planungsstand, siehe [stand](stand.md))

## Entscheidungen (`entscheidungen/`)

- [steuerung](entscheidungen/steuerung.md) – herstellerneutral, Fallback nur über den Plan, Schreiben außerhalb des Empfangspfads
- [datenhoheit](entscheidungen/datenhoheit.md) – Geräte-ID-Hoheit am EOS Server, Drei-Wege-Abgleich, ein EOS je Symcon
- [eos-befunde](entscheidungen/eos-befunde.md) – Zyklen-Tageswert, Lastfenster, Festpreis, Messwert-Endpunkte

## Testen

- [`../tests/README.md`](../tests/README.md) – Prüfstand ohne Symcon: SDK- und EOS-Attrappe, was geprüft wird
- [live-pruefstand](testen/live-pruefstand.md) – echter Kernel, virtuelle Geräte, Mock-EOS

## Symcon-Plattform

Gemessenes Symcon-Verhalten für alle Module: https://github.com/da8ter/SymDo-Family-Organizer/tree/SymDo-Beta/docs/plattform (lokal `../../List/docs/plattform/`).

## Stand

[stand.md](stand.md) – offene Punkte und Widersprüche zwischen Planung und Code.

Stand: geprüft gegen den Code am 08.10.2026
