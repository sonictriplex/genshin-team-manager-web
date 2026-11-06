# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

Genshin Impact team & build manager: a single-page web app (German UI) where logged-in users assemble teams of up to 4 characters and store per-character builds (level, constellation, weapon, artifact sets, artifact substats, talents).

There is no build step, package manager, linter or test suite. The app is plain PHP + a single static `index.html`, served by a PHP-capable web server (Apache/nginx + PHP-FPM) with MySQL/MariaDB. For quick local testing: `php -S localhost:8000` from the repo root (needs the MySQL database configured in `api/db.php`). Syntax-check PHP with `php -l api/<file>.php`.

## Architecture

**Frontend — `index.html`** (everything in one file): Tailwind via CDN (theme colors configured inline in `tailwind.config`), custom `GenshinFont` from `assets/zh-cn.ttf`, and one inline `<script>` holding all app logic. Key global state: `availableCharacters/Weapons/Artifacts` (master data), `savedTeams` (object keyed by team name), `currentTeamMembers` (array of `{character, saved}`), `activeDetailIndex`. Rendering is done by building HTML strings and assigning `innerHTML` — every DB/user value inserted that way must go through `escapeHtml()`. Clicks in the character/weapon picker tables use event delegation on `tr[data-name]` rather than inline `onclick` with names.

- Absolute paths are assumed: `API_BASE = '/api'`, `IMAGE_BASE = '/assets/images'` — the app must be served from the web root.
- Character/weapon icons are resolved by name in `getIconUrl()`: lowercase, spaces → `_`, apostrophes/quotes/hyphens stripped, anything from `(` on dropped, `.png` appended (e.g. "Hu Tao" → `hu_tao.png`). New images in `assets/images/` must follow this convention.
- Artifact slots are German: `Blume, Feder, Sanduhr, Kelch, Krone`; substats come from `SUBSTAT_OPTIONS`. Placeholder select values start with `—` (e.g. `"— Keiner —"`).
- Before saving, `syncActiveDetailStats()` pulls the open detail form into state and `normalizeMember()` guarantees `artifact_stats` is an object, never an array.

**Backend — `api/*.php`** (JSON endpoints, no framework, PDO with exceptions and native prepared statements):
- `db.php` — creates `$pdo`; included by every endpoint. Credentials come from env vars `DB_HOST/DB_NAME/DB_USER/DB_PASS/DB_CHARSET` (take precedence) or `api/config.local.php` (git-ignored; template in `api/config.example.php`).
- Error responses never include `$e->getMessage()`; details go to `error_log()`, the client gets a generic German message.
- `auth_check.php` — starts the session (7-day cookie, `Secure` only when HTTPS incl. `X-Forwarded-Proto`) and provides `require_login()` (401 or returns `user_id`).
- `auth.php?action=status|register|login|logout` — session-based auth; `logout` is POST-only; `session_regenerate_id` on login/register.
- `characters.php`, `weapons.php`, `artifacts.php` — read-only master data lists (public, no login required).
- `teams.php` — per-user, requires login. `GET` returns `{teamName: [{character, saved}, ...]}`; `POST {name, members}` upserts the team and replaces its members in one transaction; `DELETE ?name=` deletes by name.
- `transfer.php` — per-user import/export (`GET` = JSON download, `POST {mode: merge|replace, data}` = import in one transaction). The file format (`format: genshin-team-manager`, `version`, `builds`, `teams`) is shared with the desktop app's "Datei" menu — keep both sides in sync. `helpers.php` holds `clamp_int`, `clean_set`, `normalize_artifact_stats` shared by `teams.php` and `transfer.php`.

**Data model** (MySQL, schema not in repo — inferred from queries): `users`, `characters`, `weapons`, `artifacts` (master data), `teams (user_id, name)` with UNIQUE `(user_id, name)`, `team_members (team_id, char_name, slot_order)`, and `user_characters` keyed UNIQUE on `(user_id, char_name)`. Important consequence: **builds are stored per user + character, not per team** — editing a character's build in one team changes it in every team of that user. `artifact_stats_json` holds `{slotName: {..., substats: [{stat, val} ×4]}}`; `normalize_artifact_stats()` in `teams.php` also migrates the legacy `"KT% + 3.9"` string format. Team members are joined to master data by `char_name`, not by id.

## Conventions

- UI text, error messages and code comments are in German; keep it that way.
- API responses use `json_encode(..., JSON_UNESCAPED_UNICODE)` and `{"error": "..."}` with an HTTP status code on failure. Empty PHP arrays that the frontend expects as objects must be emitted as `new stdClass()`.
- Server-side input is clamped/validated in `teams.php` (`clamp_int`, `clean_set`, max 4 members, duplicates skipped) — keep these limits in sync with the frontend inputs.
