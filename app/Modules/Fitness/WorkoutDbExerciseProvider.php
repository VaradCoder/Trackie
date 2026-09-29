<?php
/**
 * Exercises from WorkoutDB (work-out-db.com). HTTP, caching and URL safety
 * live in includes/workoutdb.php; this class only maps the API's Exercise
 * schema onto Trackie's normalized shape.
 */

require_once __DIR__ . '/ExerciseProvider.php';
require_once __DIR__ . '/../../../includes/workoutdb.php';

final class WorkoutDbExerciseProvider implements ExerciseProvider
{
    public function id(): string { return 'workoutdb'; }

    public function available(): bool { return workoutdbConfigured(); }

    public function search(string $query, array $filters, int $limit): array
    {
        // The API needs a query; browsing the whole catalogue page by page is
        // not something the Exercises tab does.
        $raw = $this->available() ? workoutdbSearchExercises($query, $limit) : null;
        $out = array_map([$this, 'normalize'], $raw ?? []);
        // Filters use Trackie's vocabulary ("Chest", "Dumbbell"); the API's
        // slugs aren't guaranteed to match, so filter on the mapped values.
        return array_values(array_filter($out, static function ($e) use ($filters) {
            foreach (['body_part', 'equipment'] as $f) {
                if (!empty($filters[$f]) && stripos((string)$e[$f], $filters[$f]) === false) return false;
            }
            return true;
        }));
    }

    public function find(string $id): ?array
    {
        $raw = $this->available() ? workoutdbGetExercise($id) : null;
        return $raw ? $this->normalize($raw) : null;
    }

    public function normalize(array $ex): array
    {
        $video = $ex['media']['video'] ?? null;
        $mp4   = is_array($video) ? ($video['mp4'] ?? []) : [];
        $cut   = static fn($s, $n) => is_string($s) ? workoutdbCut(trim($s), 0, $n) : null;
        return fitnessExercise([
            'source'        => 'workoutdb',
            'id'            => $cut($ex['id'] ?? ($ex['slug'] ?? ''), 100),
            'name'          => $cut($ex['name'] ?? '', 100) ?? '',
            'body_part'     => $cut($ex['bodyPart'] ?? null, 40),
            'target'        => $cut($ex['target'] ?? null, 60),
            'secondary'     => array_map(fn($s) => $cut($s, 60), array_filter((array)($ex['secondaryMuscles'] ?? []), 'is_string')),
            'equipment'     => $cut($ex['equipment'] ?? null, 40),
            'difficulty'    => $cut($ex['difficulty'] ?? null, 20),
            'instructions'  => array_map(fn($s) => $cut($s, 400), array_filter((array)($ex['instructions'] ?? []), 'is_string')),
            'thumbnail_url' => is_array($video) ? workoutdbSafeUrl($video['poster'] ?? null) : null,
            'video_url'     => workoutdbSafeUrl($mp4['720p'] ?? null) ?? workoutdbSafeUrl($mp4['480p'] ?? null),
            'gif_url'       => workoutdbSafeUrl($ex['media']['gif'] ?? null) ?? workoutdbSafeUrl($ex['gifUrl'] ?? null),
        ]);
    }
}
