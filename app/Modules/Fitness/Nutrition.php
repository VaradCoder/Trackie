<?php
/**
 * Nutrition log. Everything here is what the user entered: calories EATEN,
 * protein and water. Trackie never estimates calories burned or fills gaps.
 */

final class Nutrition
{
    public const MEALS = ['breakfast', 'lunch', 'dinner', 'snack', 'water'];

    public function __construct(private int $uid) {}

    /** Entries, totals and targets for one date. */
    public function day(string $date): array
    {
        $entries = fetchAll(
            "SELECT id, meal, name, calories, protein_g, water_ml, created_at
               FROM nutrition_entries WHERE user_id=? AND entry_date=? ORDER BY created_at, id",
            [$this->uid, $date]
        );
        $totals = ['calories' => 0, 'protein_g' => 0.0, 'water_ml' => 0, 'entries' => count($entries)];
        foreach ($entries as &$e) {
            $e['id'] = (int)$e['id'];
            $e['calories']  = $e['calories']  !== null ? (int)$e['calories']    : null;
            $e['protein_g'] = $e['protein_g'] !== null ? (float)$e['protein_g'] : null;
            $e['water_ml']  = $e['water_ml']  !== null ? (int)$e['water_ml']    : null;
            $totals['calories']  += $e['calories']  ?? 0;
            $totals['protein_g'] += $e['protein_g'] ?? 0;
            $totals['water_ml']  += $e['water_ml']  ?? 0;
        }
        unset($e);
        $totals['protein_g'] = round($totals['protein_g'], 1);
        return ['date' => $date, 'entries' => $entries, 'totals' => $totals, 'targets' => $this->targets()];
    }

    /** Daily totals for the last $days days that HAVE entries (no zero-filled fake days). */
    public function recent(int $days = 7): array
    {
        return array_map(fn($r) => [
            'date' => $r['entry_date'], 'calories' => (int)$r['cal'],
            'protein_g' => round((float)$r['pro'], 1), 'water_ml' => (int)$r['wat'],
        ], fetchAll(
            "SELECT entry_date, SUM(COALESCE(calories,0)) cal, SUM(COALESCE(protein_g,0)) pro, SUM(COALESCE(water_ml,0)) wat
               FROM nutrition_entries WHERE user_id=? AND entry_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
              GROUP BY entry_date ORDER BY entry_date DESC",
            [$this->uid, max(1, min(60, $days)) - 1]
        ));
    }

    public function targets(): ?array
    {
        $t = fetchOne("SELECT calories, protein_g, water_ml FROM nutrition_targets WHERE user_id=?", [$this->uid]);
        if (!$t) return null;
        return [
            'calories'  => $t['calories']  !== null ? (int)$t['calories']    : null,
            'protein_g' => $t['protein_g'] !== null ? (float)$t['protein_g'] : null,
            'water_ml'  => $t['water_ml']  !== null ? (int)$t['water_ml']    : null,
        ];
    }

    /** @return string|null error message */
    public function add(string $date, string $meal, string $name, $calories, $protein, $water): ?string
    {
        if (!self::validDate($date)) return 'Invalid date.';
        if (!in_array($meal, self::MEALS, true)) return 'Pick a meal.';
        $cal = self::num($calories, 0, 10000, true);
        $pro = self::num($protein, 0, 1000, false);
        $wat = self::num($water, 0, 10000, true);
        if ($cal === false || $pro === false || $wat === false) return 'One of the numbers is out of range.';
        if ($cal === null && $pro === null && $wat === null) return 'Enter calories, protein or water.';
        $name = trim($name);
        insert(
            "INSERT INTO nutrition_entries (user_id, entry_date, meal, name, calories, protein_g, water_ml) VALUES (?,?,?,?,?,?,?)",
            [$this->uid, $date, $meal, $name !== '' ? substr($name, 0, 120) : null, $cal, $pro, $wat]
        );
        return null;
    }

    public function delete(int $id): bool
    {
        return delete("DELETE FROM nutrition_entries WHERE id=? AND user_id=?", [$id, $this->uid]) > 0;
    }

    /** @return string|null error message */
    public function saveTargets($calories, $protein, $water): ?string
    {
        $cal = self::num($calories, 500, 10000, true);
        $pro = self::num($protein, 0, 1000, false);
        $wat = self::num($water, 250, 10000, true);
        if ($cal === false || $pro === false || $wat === false) {
            return 'Targets: calories 500–10000, protein 0–1000 g, water 250–10000 ml.';
        }
        update(
            "INSERT INTO nutrition_targets (user_id, calories, protein_g, water_ml) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE calories=VALUES(calories), protein_g=VALUES(protein_g), water_ml=VALUES(water_ml)",
            [$this->uid, $cal, $pro, $wat]
        );
        return null;
    }

    /** '' → null; out of range / not numeric → false. */
    private static function num($v, float $min, float $max, bool $int)
    {
        if ($v === null || $v === '') return null;
        if (!is_numeric($v)) return false;
        $n = $int ? (int)round((float)$v) : round((float)$v, 1);
        return ($n < $min || $n > $max) ? false : $n;
    }

    public static function validDate(string $d): bool
    {
        $dt = DateTime::createFromFormat('Y-m-d', $d);
        return $dt && $dt->format('Y-m-d') === $d && $d <= date('Y-m-d');
    }
}
