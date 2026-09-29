<?php
/**
 * Fitness goals. A goal stores only WHAT the user is aiming for; progress is
 * computed from logged data on every read, so it can never drift from reality
 * or be typed in by hand.
 *
 *   workouts         Complete N workouts — distinct training days since start_date
 *   exercise_weight  Lift X kg on an exercise — best weight ever logged for it
 *   streak           Reach an N-day streak — current streak (same maths as the badge)
 */

final class FitnessGoals
{
    public const TYPES = ['workouts', 'exercise_weight', 'streak'];
    public const XP_ON_COMPLETE = 50;

    public function __construct(private int $uid) {}

    /** All goals with live progress; newly reached goals are completed + rewarded once. */
    public function all(): array
    {
        $goals = fetchAll(
            "SELECT * FROM fitness_goals WHERE user_id=? ORDER BY completed_at IS NOT NULL, created_at DESC",
            [$this->uid]
        );
        $out = [];
        foreach ($goals as $g) {
            $current = $this->current($g);
            $target  = (float)$g['target_value'];
            if ($g['completed_at'] === null && $current >= $target) {
                $this->complete($g);
                $g['completed_at'] = date('Y-m-d H:i:s');
            }
            $out[] = [
                'id'            => (int)$g['id'],
                'type'          => $g['goal_type'],
                'title'         => self::title($g),
                'exercise_name' => $g['exercise_name'],
                'target'        => $target,
                'current'       => $current,
                'unit'          => $g['goal_type'] === 'exercise_weight' ? 'kg' : ($g['goal_type'] === 'streak' ? 'days' : 'workouts'),
                'pct'           => $target > 0 ? min(100, (int)floor($current / $target * 100)) : 0,
                'start_date'    => $g['start_date'],
                'deadline'      => $g['deadline'],
                'completed_at'  => $g['completed_at'],
                'overdue'       => $g['completed_at'] === null && $g['deadline'] !== null && $g['deadline'] < date('Y-m-d'),
            ];
        }
        return $out;
    }

    /** Validates and creates a goal. Returns [id|null, error|null]. */
    public function create(string $type, $target, ?string $exercise, ?string $deadline): array
    {
        if (!in_array($type, self::TYPES, true)) return [null, 'Pick a goal type.'];
        $t = is_numeric($target) ? (float)$target : 0;
        $limits = ['workouts' => [1, 1000], 'exercise_weight' => [0.5, 999], 'streak' => [2, 365]];
        [$min, $max] = $limits[$type];
        if ($t < $min || $t > $max) return [null, "Target must be between {$min} and {$max}."];
        if ($type !== 'exercise_weight') $t = floor($t);

        $exercise = $exercise !== null ? trim($exercise) : '';
        if ($type === 'exercise_weight' && $exercise === '') return [null, 'Choose the exercise for this goal.'];
        if ($type !== 'exercise_weight') $exercise = '';

        if ($deadline !== null && $deadline !== '') {
            $d = DateTime::createFromFormat('Y-m-d', $deadline);
            if (!$d || $d->format('Y-m-d') !== $deadline) return [null, 'Deadline must be a valid date.'];
            if ($deadline < date('Y-m-d')) return [null, 'Deadline can’t be in the past.'];
        } else {
            $deadline = null;
        }

        $id = (int)insert(
            "INSERT INTO fitness_goals (user_id, goal_type, target_value, exercise_name, start_date, deadline) VALUES (?,?,?,?,CURDATE(),?)",
            [$this->uid, $type, $t, $exercise !== '' ? (function_exists('mb_substr') ? mb_substr($exercise, 0, 100) : substr($exercise, 0, 100)) : null, $deadline]
        );
        return [$id, null];
    }

    public function delete(int $id): bool
    {
        return delete("DELETE FROM fitness_goals WHERE id=? AND user_id=?", [$id, $this->uid]) > 0;
    }

    /** Live value for a goal, from logged data only. */
    private function current(array $g): float
    {
        switch ($g['goal_type']) {
            case 'workouts':
                return (float)fetchOne(
                    "SELECT COUNT(DISTINCT log_date) c FROM workout_logs WHERE user_id=? AND log_date >= ?",
                    [$this->uid, $g['start_date']]
                )['c'];
            case 'exercise_weight':
                return (float)(fetchOne(
                    "SELECT MAX(w) best FROM (
                        SELECT ws.weight_kg w FROM workout_sets ws JOIN workout_logs wl ON wl.id = ws.log_id
                         WHERE wl.user_id=? AND LOWER(wl.exercise_name)=LOWER(?)
                        UNION ALL
                        SELECT wl.weight_kg FROM workout_logs wl
                         WHERE wl.user_id=? AND LOWER(wl.exercise_name)=LOWER(?)
                           AND wl.id NOT IN (SELECT DISTINCT log_id FROM workout_sets)
                     ) x",
                    [$this->uid, $g['exercise_name'], $this->uid, $g['exercise_name']]
                )['best'] ?? 0);
            case 'streak':
                $dates = array_column(
                    fetchAll("SELECT DISTINCT log_date FROM workout_logs WHERE user_id=? ORDER BY log_date", [$this->uid]),
                    'log_date'
                );
                return (float)(calculateStreaks($dates)['current'] ?? 0);
        }
        return 0.0;
    }

    private function complete(array $g): void
    {
        update("UPDATE fitness_goals SET completed_at=NOW() WHERE id=? AND user_id=? AND completed_at IS NULL", [$g['id'], $this->uid]);
        if (function_exists('awardXpOnce')) {
            require_once __DIR__ . '/../../../includes/activity.php';
            recordActivity($this->uid, 'fitness_goal', 'fitness_goal', (int)$g['id'], ['xp' => self::XP_ON_COMPLETE]);
        }
        if (function_exists('createNotification')) {
            createNotification($this->uid, 'goal', '🎯 Fitness goal reached', self::title($g) . ' (+' . self::XP_ON_COMPLETE . ' XP)',
                APP_BASE . '/pages/gym.php');
        }
    }

    public static function title(array $g): string
    {
        $t = (float)$g['target_value'];
        $n = rtrim(rtrim(number_format($t, 1, '.', ''), '0'), '.');
        return match ($g['goal_type']) {
            'workouts'        => "Complete {$n} workout" . ($t == 1 ? '' : 's'),
            'exercise_weight' => "{$g['exercise_name']} → {$n} kg",
            'streak'          => "Reach a {$n}-day streak",
            default           => 'Goal',
        };
    }
}
