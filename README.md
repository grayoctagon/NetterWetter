# NetterWetter

Netter Wetter visualisiert Wetterdaten so nett und übersichtlich wie nie

*mit AI unterstützung entwickelt*

App zum Abrufen täglicher und stündlicher Wetterdaten von Tomorrow.io
API Keys verwalten unter: https://app.tomorrow.io/development/keys

Stündliche Planung per Cron, zum Beispiel:
0 * * * * /usr/bin/php /Pfad/zu/Ihrem_Skript.php


## License: 
Attribution-ShareAlike 4.0 International CC-BY-SA 

(details see LICENSE.txt file)

[![CC-BY-SA](https://i.creativecommons.org/l/by-sa/4.0/88x31.png)](#license)



## Features

- **Einfaches Deployment**: keine Framework‑Abhängigkeiten
- **Automatische Dateiauswahl**: Klickbare Liste aller `weather*_minimized.json`‑Dateien, Fallback auf neuesten Datensatz
- **Skalierbares Layout** für einen oder mehrere Standorte. Speicherung der Antworten und eines minimierten Datensatzes.
- **Farbcodierte Linien**:
  - Gelb = gefühlte Temperatur
  - Orange = Temperatur
  - Hellblau = Luftfeuchte
  - Dunkelblau = Niederschlag (mm/h)
  - Grau = Wind (m/s)
  - Hellgrau = Windböen (m/s)
- **Tooltips & Crosshair**: Live‑Werte inklusive Einheiten und 3‑px‑Intercept‑Dots
- **Responsive**: SVG passt sich der Browser­breite an; horizontales Scrollen bei langen Zeiträumen


## Roadmap / Ideen

- Import mehrerer Standorte (Dropdown)
- Mobile Touch‑Tooltips
- Export als PNG / PDF
- Theme‑Switch (Hell / Dunkel)


## Disclaimer:
 This work is provided "as is" without any warranties or guarantees of any kind.
 In der Produktion sollten Sie eine ordnungsgemäße Fehlerbehandlung,
 Protokollierung und robuste Prüfungen für JSON-/Dateioperationen hinzufügen.