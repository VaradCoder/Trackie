<?php
/**
 * Exercises from the local exercise_library table: the built-in rows
 * (user_id IS NULL) plus the signed-in user's own custom/saved rows.
 */

require_once __DIR__ . '/ExerciseProvider.php';

final class LibraryExerciseProvider implements ExerciseProvider
{
    public function __construct(private int $uid) {}

    public function id(): string { return 'library'; }

    public function available(): bool { return true; }

    public function search(string $query, array $filters, int $limit): array
    {
        $sql = "SELECT * FROM exercise_library WHERE (user_id IS NULL OR user_id=?)";
        $p   = [$this->uid];
        if ($query !== '') {
            $sql .= " AND (name LIKE ? OR target_muscle LIKE ?)";
            $p[] = "%{$query}%";
            $p[] = "%{$query}%";
        }
        if (!empty($filters['body_part'])) { $sql .= " AND muscle_group = ?"; $p[] = $filters['body_part']; }
        if (!empty($filters['equipment'])) { $sql .= " AND equipment = ?";    $p[] = $filters['equipment']; }
        $sql .= " ORDER BY name LIMIT " . max(1, min(200, $limit));
        return array_map([$this, 'normalize'], fetchAll($sql, $p));
    }

    public function find(string $id): ?array
    {
        $row = fetchOne(
            "SELECT * FROM exercise_library WHERE id=? AND (user_id IS NULL OR user_id=?)",
            [(int)$id, $this->uid]
        );
        return $row ? $this->normalize($row) : null;
    }

    /** Library row → normalized exercise. */
    public function normalize(array $r): array
    {
        $instructions = [];
        if (!empty($r['instructions'])) {
            $decoded = json_decode($r['instructions'], true);
            $instructions = is_array($decoded) ? $decoded : preg_split('/\R+/', $r['instructions']);
        }
        return fitnessExercise([
            'source'        => 'library',
            'id'            => $r['id'],
            'library_id'    => $r['id'],
            'custom'        => $r['user_id'] !== null,
            'name'          => $r['name'],
            'body_part'     => $r['muscle_group'],
            'target'        => $r['target_muscle'] ?? null,
            'secondary'     => !empty($r['secondary_muscles']) ? explode(',', $r['secondary_muscles']) : [],
            'equipment'     => $r['equipment'],
            'difficulty'    => $r['difficulty'] ?? null,
            'instructions'  => $instructions,
            'thumbnail_url' => $r['thumbnail_url'] ?? null,
            // A local file wins over a provider URL saved with the row.
            'video_url'     => self::localVideoUrl($r['video_path'] ?? null) ?? ($r['video_url'] ?? null),
        ]);
    }

    /** URL for a video under assets/vids, only if the file really exists. */
    public static function localVideoUrl(?string $path): ?string
    {
        if (!$path || !is_file(ROOT_PATH . '/' . $path)) return null;
        return APP_BASE . '/' . implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}
