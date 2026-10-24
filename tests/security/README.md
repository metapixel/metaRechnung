# Dynamische Sicherheitstests

Entwicklungswerkzeug, das **nur gegen eine eigene Testinstanz** laufen darf (legt Kunden/Rechnungen an, ändert Einstellungen und das Passwort, löscht Daten).

Voraussetzungen: Python 3 mit Pillow, Apache + mod_php (mit `AllowOverride All`, damit die `.htaccess` greift), MariaDB/MySQL, PHP-CLI.

1. Testinstanz aufbauen: `mkinstance.sh` (Pfade am Dateianfang anpassen; Beispielkonfiguration `apache2.conf.example`). Es installiert mit dem Benutzer `kai` / `SehrGeheim12345`
   und `TEST_MODE=1`. Mails fängt `smtp_sink.py` (Port 2525) in einem Ordner ab.
2. Umgebung setzen: `RG_HOST`, `RG_PORT`, `RG_APP` (Installationsordner), `RG_DB` (Datenbankname), `RG_MAILS` (Mail-Ordner des Sink).
3. `python3 sec1.py <label>` und `python3 sec2.py <label>` (jeweils auf frisch installierter Instanz). Ergebnis: Konsole und `results_<label>_partN.json`.

Erwartung: alles `PASS`, außer `O4` (TRACE, nur serverseitig abschaltbar).
