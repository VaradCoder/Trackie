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

require_once __DIR__ . '/gamification.php';

/** XP per hobby action. Change values here only. */
const HOBBY_XP = [
    'reading_session' => 10,   // once per day with a logged reading session
    'book_finished'   => 30,   // once per book
    'game_completed'  => 30,   // once per game
    'photo_shoot'     => 15,   // once per logged shoot
    'photo_upload_day'=> 5,    // once per day with uploaded photos
];

function hobbyXp(string $action): int {
    return HOBBY_XP[$action] ?? 0;
}

/**
 * Award a hobby's XP once per (action, ref). Returns the awardXpOnce() result
 * (null when already awarded or gamification tables are missing).
 */
function awardHobbyXp(int $uid, string $action, string $refType, int $refId): ?array {
    $xp = hobbyXp($action);
    if ($xp <= 0 || !function_exists('awardXpOnce')) return null;
    return awardXpOnce($uid, $action, $xp, $refType, $refId);
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
