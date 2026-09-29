<?php
/**
 * The single entry point the Fitness UI/API uses for exercises. Combines the
 * local library with any remote provider; the library always wins on name
 * clashes (it may carry the user's own video), and remote results are only
 * added when a remote provider is actually available.
 */

require_once __DIR__ . '/LibraryExerciseProvider.php';
require_once __DIR__ . '/WorkoutDbExerciseProvider.php';

final class ExerciseCatalog
{
    private LibraryExerciseProvider $library;
    /** @var ExerciseProvider[] keyed by id() */
    private array $remote = [];

    public function __construct(private int $uid)
    {
        $this->library = new LibraryExerciseProvider($uid);
        foreach ([new WorkoutDbExerciseProvider()] as $p) {
            $this->remote[$p->id()] = $p;
        }
    }

    /** @return array{exercises: array, remote: array<string,bool>} */
    public function search(string $query, array $filters = [], int $limit = 60): array
    {
        $results = $this->library->search($query, $filters, $limit);
        $seen = array_flip(array_map(fn($e) => fitnessNameKey($e['name']), $results));

        $status = [];
        foreach ($this->remote as $id => $provider) {
            $status[$id] = $provider->available();
            if (!$status[$id] || trim($query) === '') continue;
            foreach ($provider->search($query, $filters, 12) as $e) {
                $k = fitnessNameKey($e['name']);
                if ($k === '' || isset($seen[$k])) continue;
                $seen[$k] = true;
                $results[] = $e;
            }
        }
        return ['exercises' => $results, 'remote' => $status];
    }

    public function find(string $source, string $id): ?array
    {
        if ($source === 'library') return $this->library->find($id);
        return isset($this->remote[$source]) ? $this->remote[$source]->find($id) : null;
    }

    /**
     * Save a remote exercise into the user's library (so it can be planned,
     * logged and matched offline). Idempotent: returns the existing row id if
     * this user already saved it. The data is re-fetched server-side from the
     * provider — the client never supplies exercise fields.
     */
    public function saveToLibrary(string $source, string $id): ?int
    {
        if ($source === 'library' || !isset($this->remote[$source])) return null;
        $existing = fetchOne(
            "SELECT id FROM exercise_library WHERE user_id=? AND source=? AND external_id=?",
            [$this->uid, $source, $id]
        );
        if ($existing) return (int)$existing['id'];

        $e = $this->remote[$source]->find($id);
        if (!$e || $e['name'] === '') return null;
        return (int)insert(
            "INSERT INTO exercise_library
               (user_id, name, muscle_group, target_muscle, secondary_muscles, equipment, category,
                difficulty, instructions, video_url, thumbnail_url, source, external_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [
                $this->uid, $e['name'],
                self::bodyPartToGroup($e['body_part']), $e['target'],
                $e['secondary'] ? implode(',', $e['secondary']) : null,
                $e['equipment'] ? ucfirst($e['equipment']) : null, 'Strength',
                $e['difficulty'],
                $e['instructions'] ? json_encode($e['instructions'], JSON_UNESCAPED_UNICODE) : null,
                $e['video_url'], $e['thumbnail_url'], $source, $id,
            ]
        );
    }

    /** Map a provider body part onto the library's muscle groups; unknown stays as given. */
    private static function bodyPartToGroup(?string $bp): ?string
    {
        if (!$bp) return null;
        $map = [
            'chest' => 'Chest', 'back' => 'Back', 'shoulders' => 'Shoulders',
            'upper arms' => 'Arms', 'lower arms' => 'Arms', 'arms' => 'Arms',
            'upper legs' => 'Legs', 'lower legs' => 'Legs', 'legs' => 'Legs',
            'waist' => 'Core', 'core' => 'Core', 'abs' => 'Core', 'cardio' => 'Cardio',
        ];
        return $map[strtolower(trim($bp))] ?? ucfirst($bp);
    }
}
