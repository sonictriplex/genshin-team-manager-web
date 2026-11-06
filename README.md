# Genshin Impact – Team & Build Manager

Web-App zum Zusammenstellen von Genshin-Impact-Teams und Verwalten der Charakter-Builds. Jeder Nutzer hat ein eigenes Konto und eigene Teams.

## Funktionen

- Registrierung und Login (Session-basiert)
- Teams mit bis zu 4 Charakteren anlegen, speichern und löschen
- Pro Charakter: Level, Konstellation, Talente, Waffe (Level, Raffinierung), Artefaktsets (4er oder 2×2er) und die Substats aller 5 Artefakte (Blume, Feder, Sanduhr, Kelch, Krone)
- Suchbare Auswahllisten für Charaktere und Waffen

Builds werden **pro Nutzer und Charakter** gespeichert, nicht pro Team. Ändert man den Build eines Charakters, gilt die Änderung in allen Teams, in denen er vorkommt.

## Technik

- **Frontend:** eine einzelne `index.html` mit Vanilla JavaScript und Tailwind CSS (CDN)
- **Backend:** PHP-Endpunkte in `api/` (PDO), JSON-API
- **Datenbank:** MySQL / MariaDB

Es gibt keinen Build-Schritt und keine Abhängigkeiten, die installiert werden müssen.

```
index.html          Komplettes Frontend
api/
  db.php            Datenbankverbindung
  auth_check.php    Session-Setup, require_login()
  auth.php          ?action=status|register|login|logout
  characters.php    Charakterliste (GET)
  weapons.php       Waffenliste (GET)
  artifacts.php     Artefaktsets (GET)
  teams.php         Teams des Nutzers (GET / POST / DELETE)
assets/
  images/           Charakter- und Waffenbilder (<name>.png)
  zh-cn.ttf         Schriftart
```

## Einrichtung

1. **Webserver mit PHP** (z. B. Apache oder nginx + PHP-FPM) mit der PDO-MySQL-Erweiterung. Das Projekt muss im **Web-Root** liegen, weil das Frontend absolute Pfade (`/api`, `/assets/images`) verwendet.

2. **Datenbank** anlegen. Ein SQL-Schema ist nicht im Repo enthalten; benötigt werden die Tabellen `users`, `characters`, `weapons`, `artifacts`, `teams`, `team_members` und `user_characters`. Die verwendeten Spalten sind aus den Abfragen in `api/*.php` ersichtlich. Wichtig sind die eindeutigen Schlüssel `teams (user_id, name)` und `user_characters (user_id, char_name)`.

3. **Zugangsdaten** konfigurieren – entweder per Datei:

   ```sh
   cp api/config.example.php api/config.local.php
   # Werte in api/config.local.php eintragen
   ```

   oder per Umgebungsvariablen (haben Vorrang): `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_CHARSET`.

   `api/config.local.php` ist in `.gitignore` eingetragen und wird nicht eingecheckt.

4. Für einen schnellen lokalen Test reicht der eingebaute PHP-Server:

   ```sh
   php -S localhost:8000
   ```

   Danach http://localhost:8000 öffnen.

## Bilder

Bilder werden anhand des Namens gefunden: Kleinbuchstaben, Leerzeichen werden zu `_`, Apostrophe und Bindestriche fallen weg, alles ab `(` wird ignoriert. Beispiel: „Hu Tao“ → `assets/images/hu_tao.png`.

## Hinweis

Inoffizielles Fanprojekt, nicht mit HoYoverse verbunden. Genshin Impact sowie zugehörige Namen und Bilder sind Eigentum von HoYoverse.
