# Symcon-EOS

Integration von [Akkudoktor-EOS](https://github.com/Akkudoktor-EOS/EOS) (Energy Optimization System) in IP-Symcon.

EOS berechnet aus Prognosen (PV, Strompreis, Last) und Messwerten (SoC, Zählerstände) einen kostenoptimalen
Fahrplan für Batteriespeicher, E-Auto und Haushaltsgeräte. Dieses Projekt soll diesen Plan in IP-Symcon
verfügbar machen und ausführen.

## Status

Planungsphase. Der Integrationsplan (Analyse von EOS v0.4.0rc1, Zielarchitektur, Modulstruktur, Phasen)
liegt unter [docs/integrationsplan.md](docs/integrationsplan.md).

## Geplante Struktur

- **EOS Server** (Splitter): REST-Client, Health/Plan-Polling, Konfigurationsabgleich
- **EOS Batterie / E-Auto / Haushaltsgerät** (Devices): Messwert-Push, Plan-Ausführung zu Slot-Grenzen, Fallback
- **EOS Zähler** (Device): Zählerstände inkl. Historien-Import aus dem Symcon-Archiv
- Visualisierung als HTML-SDK-Kachel
