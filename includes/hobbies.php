<?php
/**
 * Shared hobby system: one place for hobby XP values and per-hobby streaks,
 * so every hobby module (app/Modules/<Hobby>) awards and counts the same way.
 *
 * XP is always awarded through awardXpOnce() with a stable ref, so a repeated
 * request (double-click, retry, toggling a status back and forth) never pays
 * twice. Session-type XP is keyed per DAY, not per session: logging ten tiny
 * sessions earns the same as one — the reward is for showing up that day.
 */

require_once __DIR__ . '/activity.php';

/** XP values live in ACTIVITY_TYPES (includes/activity.php). */
function hobbyXp(string $action): int {
    return activityXp($action);
}

/**
 * Award a hobby's XP once per (action, ref). Returns the awardXpOnce() result
 * (null when already awarded or gamification tables are missing).
 */
function awardHobbyXp(int $uid, string $action, string $refType, int $refId): ?array {
    // Day refs (20260929) carry the activity date.
    $date = str_ends_with($refType, '_day') && preg_match('/^(\d{4})(\d{2})(\d{2})$/', (string)$refId, $m)
          ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
    return recordActivity($uid, $action, $refType, $refId, $date ? ['date' => $date] : []);
}

/** Undo a hobby completion (e.g. a book moved back off "Finished"). */
function undoHobbyActivity(int $uid, string $action, string $refType, int $refId): void {
    undoActivity($uid, $action, $refType, $refId);
}

/** Ref id for "once per day" awards: 20260925. */
function hobbyDayRef(?string $date = null): int {
    return (int)date('Ymd', $date ? strtotime($date) : time());
}

/**
 * Current/best streak from a list of activity dates (Y-m-d). Today not yet
 * active doesn't break the streak (calculateStreaks handles the grace day).
 */
function hobbyStreak(array $dates): array {
    return calculateStreaks(array_values(array_unique($dates)));
}
