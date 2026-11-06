<?php
// Gemeinsame Hilfsfunktionen für teams.php und transfer.php

/** Begrenzt einen Zahlenwert auf einen Bereich (Fallback bei ungültiger Eingabe). */
function clamp_int($value, int $min, int $max, int $default): int {
    if (!is_numeric($value)) return $default;
    return max($min, min($max, (int)$value));
}

/** Platzhaltertexte wie "— Zweites 2er-Set (optional) —" werden zu einem leeren Wert. */
function clean_set($value): string {
    $v = trim((string)$value);
    if ($v === '' || mb_strpos($v, '—') === 0) return '';
    return $v;
}

/**
 * Bringt artifact_stats in ein sauberes, immer gleiches Format:
 * - Rückgabe ist ein assoziatives Array (Slot => Daten) oder leer
 * - jeder Slot hat genau 4 Substats der Form {stat, val}
 */
function normalize_artifact_stats($stats): array {
    if (!is_array($stats)) return [];

    $clean = [];
    foreach ($stats as $slotName => $slot) {
        if (!is_string($slotName) || !is_array($slot)) continue;

        $subsIn = (isset($slot['substats']) && is_array($slot['substats']))
        ? array_values($slot['substats'])
        : [];

        $subs = [];
        for ($i = 0; $i < 4; $i++) {
            $e = $subsIn[$i] ?? [];

            // Altformat: "KT% + 3.9"
            if (is_string($e)) {
                $parts = explode('+', $e, 2);
                $e = ['stat' => trim($parts[0]), 'val' => trim($parts[1] ?? '')];
            }
            if (!is_array($e)) $e = [];

            $subs[] = [
                'stat' => (string)($e['stat'] ?? ''),
                'val'  => (string)($e['val'] ?? '')
            ];
        }

        $slot['substats'] = $subs;
        $clean[$slotName] = $slot;
    }
    return $clean;
}
