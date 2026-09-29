<?php
/**
 * Fitness → Recovery tab. A daily check-in of sleep, energy and soreness as
 * the user rates them. There is no combined "recovery score": only the raw
 * inputs and simple averages over days that were actually logged.
 */
$fitScale = function (string $name, string $legend, array $labels) {
    ?>
    <fieldset class="fit-scale">
      <legend class="form-label"><?= h($legend) ?></legend>
      <div class="fit-scale-opts">
        <?php foreach ($labels as $v => $lbl): ?>
          <label class="fit-scale-opt">
            <input type="radio" name="<?= h($name) ?>" value="<?= (int)$v ?>">
            <span><?= (int)$v ?></span>
            <small><?= h($lbl) ?></small>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <?php
};
?>
<div class="fit-section-head"><h2 class="fit-h2">Today's check-in</h2></div>

<form id="recoveryForm" class="card card-body fit-form" novalidate>
  <div class="form-group" style="max-width:220px">
    <label for="recSleep" class="form-label">Sleep last night (hours)</label>
    <input id="recSleep" type="number" class="form-input" min="0" max="24" step="0.5" inputmode="decimal">
  </div>
  <?php $fitScale('energy', 'Energy', [1 => 'Drained', 2 => 'Low', 3 => 'OK', 4 => 'Good', 5 => 'Great']); ?>
  <?php $fitScale('soreness', 'Muscle soreness', [1 => 'None', 2 => 'Mild', 3 => 'Moderate', 4 => 'Sore', 5 => 'Very sore']); ?>
  <div class="form-group">
    <label for="recNotes" class="form-label">Notes <span class="form-hint">(optional)</span></label>
    <input id="recNotes" class="form-input" maxlength="255" placeholder="e.g. tight hamstrings, slept badly">
  </div>
  <div style="display:flex;justify-content:flex-end">
    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Save check-in</button>
  </div>
</form>

<div class="fit-section-head"><h2 class="fit-h2">Last 7 days</h2></div>
<div id="recoveryWeek" class="grid-stats" style="margin-bottom:1.25rem" aria-live="polite"></div>

<div class="fit-section-head"><h2 class="fit-h2">Last 14 days</h2></div>
<div class="card" style="overflow-x:auto"><div id="recoveryDays"><p class="form-hint" style="padding:1rem">Loading…</p></div></div>
