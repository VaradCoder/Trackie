<?php
/**
 * Fitness exercise catalogue — provider contract.
 *
 * Every source of exercises (the local library, WorkoutDB, a future API)
 * implements this interface and returns exercises in ONE normalized shape
 * (see fitnessExercise()). The UI and api/gym.php only ever talk to
 * ExerciseCatalog, so swapping or adding an API means writing one class.
 */

interface ExerciseProvider
{
    /** Stable short id stored in exercise_library.source, e.g. 'library', 'workoutdb'. */
    public function id(): string;

    /** False when the provider cannot currently answer (no key, outage). */
    public function available(): bool;

    /**
     * @param array{body_part?:string, equipment?:string} $filters
     * @return array<int, array> normalized exercises
     */
    public function search(string $query, array $filters, int $limit): array;

    /** One exercise by this provider's own id, or null. */
    public function find(string $id): ?array;
}

/**
 * The normalized exercise shape. Unknown values are null / empty — never
 * guessed. `key` is globally unique across providers ("library:12").
 */
function fitnessExercise(array $f): array
{
    $list = static fn($v) => array_values(array_filter(array_map(
        static fn($s) => is_string($s) ? trim($s) : '', is_array($v) ? $v : []
    ), 'strlen'));

    return [
        'key'           => ($f['source'] ?? 'library') . ':' . ($f['id'] ?? ''),
        'source'        => $f['source'] ?? 'library',
        'id'            => isset($f['id']) ? (string)$f['id'] : null,
        'library_id'    => isset($f['library_id']) ? (int)$f['library_id'] : null,
        'custom'        => !empty($f['custom']),
        'name'          => (string)($f['name'] ?? ''),
        'body_part'     => $f['body_part'] ?? null,
        'target'        => $f['target'] ?? null,
        'secondary'     => $list($f['secondary'] ?? []),
        'equipment'     => $f['equipment'] ?? null,
        'difficulty'    => $f['difficulty'] ?? null,
        'instructions'  => $list($f['instructions'] ?? []),
        'thumbnail_url' => $f['thumbnail_url'] ?? null,
        'video_url'     => $f['video_url'] ?? null,
        'gif_url'       => $f['gif_url'] ?? null,
    ];
}

/** Case/punctuation-insensitive comparison key for exercise names. */
function fitnessNameKey(string $name): string
{
    return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($name)));
}
