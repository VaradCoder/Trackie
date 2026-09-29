<?php
/**
 * Recovery check-ins: sleep, energy (1–5) and soreness (1–5) as the user
 * entered them, one row per day. There is deliberately NO combined
 * "recovery score" — an invented formula would read as a real measurement.
 * Rest days are derived from workout logs (a past day with no logged workout).
 */

require_once __DIR__ . '/Nutrition.php';

final class Recovery
{
    public function __construct(private int $uid) {}

    /** Last $days days, newest first. Days with nothing logged stay empty. */
    public function recent(int $days = 14): array
    {
        $days = max(1, min(60, $days));
        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' day'));
        $logs = fetchAll(
            "SELECT log_date, sleep_hours, energy, soreness, notes FROM recovery_logs
              WHERE user_id=? AND log_date >= ? ORDER BY log_date DESC",
            [$this->uid, $from]
        );
        $byDate = array_column($logs, null, 'log_date');
        $trained = array_flip(array_column(fetchAll(
            "SELECT DISTINCT log_date FROM workout_logs WHERE user_id=? AND log_date >= ?",
            [$this->uid, $from]
        ), 'log_date'));

        $rows = [];
        for ($i = 0; $i < $days; $i++) {
            $d = date('Y-m-d', strtotime("-{$i} day"));
            $l = $byDate[$d] ?? null;
            $rows[] = [
                'date'        => $d,
                'trained'     => isset($trained[$d]),
                'logged'      => (bool)$l,
                'sleep_hours' => $l && $l['sleep_hours'] !== null ? (float)$l['sleep_hours'] : null,
                'energy'      => $l && $l['energy']      !== null ? (int)$l['energy']        : null,
                'soreness'    => $l && $l['soreness']    !== null ? (int)$l['soreness']      : null,
                'notes'       => $l['notes'] ?? null,
            ];
        }
        return $rows;
    }

    /** Averages over the last 7 days, counting only days that were logged. */
    public function weekSummary(): array
    {
        $rows = $this->recent(7);
        $avg = function (string $k) use ($rows) {
            $v = array_values(array_filter(array_column($rows, $k), fn($x) => $x !== null));
            return $v ? ['avg' => round(array_sum($v) / count($v), 1), 'days' => count($v)] : null;
        };
        $today = date('Y-m-d');
        return [
            'sleep'     => $avg('sleep_hours'),
            'energy'    => $avg('energy'),
            'soreness'  => $avg('soreness'),
            'rest_days' => count(array_filter($rows, fn($r) => !$r['trained'] && $r['date'] < $today)),
            'trained'   => count(array_filter($rows, fn($r) => $r['trained'])),
        ];
    }

    /** Create or replace the check-in for a date. @return string|null error */
    public function save(string $date, $sleep, $energy, $soreness, string $notes): ?string
    {
        if (!Nutrition::validDate($date)) return 'Invalid date.';
        $s = ($sleep === '' || $sleep === null) ? null : (is_numeric($sleep) ? round((float)$sleep * 2) / 2 : false);
        $e = ($energy === '' || $energy === null) ? null : (int)$energy;
        $o = ($soreness === '' || $soreness === null) ? null : (int)$soreness;
        if ($s === false || ($s !== null && ($s < 0 || $s > 24))) return 'Sleep must be between 0 and 24 hours.';
        if ($e !== null && ($e < 1 || $e > 5)) return 'Energy is rated 1–5.';
        if ($o !== null && ($o < 1 || $o > 5)) return 'Soreness is rated 1–5.';
        $notes = trim($notes);
        if ($s === null && $e === null && $o === null && $notes === '') return 'Fill in at least one field.';
        update(
            "INSERT INTO recovery_logs (user_id, log_date, sleep_hours, energy, soreness, notes) VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE sleep_hours=VALUES(sleep_hours), energy=VALUES(energy),
                                     soreness=VALUES(soreness), notes=VALUES(notes)",
            [$this->uid, $date, $s, $e, $o, $notes !== '' ? substr($notes, 0, 255) : null]
        );
        return null;
    }

    public function delete(string $date): bool
    {
        return delete("DELETE FROM recovery_logs WHERE user_id=? AND log_date=?", [$this->uid, $date]) > 0;
    }
}
