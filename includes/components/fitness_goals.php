<?php
/**
 * Fitness → Goals tab. Goal progress is computed server-side from logged
 * workouts (app/Modules/Fitness/FitnessGoals.php) and loaded on first open.
 * Expects $uid from pages/gym.php.
 */
$fitGoalExercises = array_column(fetchAll(
    "SELECT exercise_name FROM workout_logs WHERE user_id=? GROUP BY exercise_name ORDER BY MAX(log_date) DESC LIMIT 100",
    [$uid]
), 'exercise_name');
?>
<div class="fit-section-head">
  <h2 class="fit-h2">Fitness goals</h2>
  <button class="btn btn-primary btn-sm" type="button" id="goalAddToggle" aria-expanded="false" aria-controls="goalForm"><i class="fas fa-plus"></i> New goal</button>
</div>

<form id="goalForm" class="card card-body fit-form hidden" novalidate>
  <div class="form-grid-2">
    <div class="form-group">
      <label for="goalType" class="form-label">Goal</label>
      <select id="goalType" class="form-input">
        <option value="workouts">Complete a number of workouts</option>
        <option value="exercise_weight">Lift a weight on an exercise</option>
        <option value="streak">Reach a training streak</option>
      </select>
    </div>
    <div class="form-group">
      <label for="goalTarget" class="form-label" id="goalTargetLabel">Workouts</label>
      <input id="goalTarget" type="number" class="form-input" min="1" step="1" inputmode="decimal" required>
    </div>
  </div>
  <div class="form-grid-2">
    <div class="form-group hidden" id="goalExerciseWrap">
      <label for="goalExercise" class="form-label">Exercise</label>
      <input id="goalExercise" class="form-input" list="goalExerciseList" maxlength="100" placeholder="e.g. Bench Press">
      <datalist id="goalExerciseList">
        <?php foreach ($fitGoalExercises as $n): ?><option value="<?= h($n) ?>"></option><?php endforeach; ?>
      </datalist>
    </div>
    <div class="form-group">
      <label for="goalDeadline" class="form-label">Deadline <span class="form-hint">(optional)</span></label>
      <input id="goalDeadline" type="date" class="form-input" min="<?= date('Y-m-d') ?>">
    </div>
  </div>
  <div style="display:flex;gap:.5rem;justify-content:flex-end">
    <button type="button" class="btn btn-secondary btn-sm" id="goalCancel">Cancel</button>
    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-bullseye"></i> Save goal</button>
  </div>
</form>

<div id="goalsList" class="fit-goals" aria-live="polite"><p class="form-hint">Loading your goals…</p></div>
<p class="form-hint" style="margin-top:.75rem">Progress is calculated from your logged workouts, so it updates by itself. Reaching a goal earns +50 XP.</p>
