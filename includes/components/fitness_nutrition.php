<?php
/**
 * Fitness → Nutrition tab. Only what the user logs: calories eaten, protein,
 * water. Nothing is estimated (no "calories burned"). Data loads on first open.
 */
?>
<div class="fit-section-head">
  <h2 class="fit-h2">Today</h2>
  <button class="btn btn-secondary btn-sm" type="button" id="nutriTargetsBtn"><i class="fas fa-sliders"></i> Daily targets</button>
</div>

<div id="nutriSummary" class="fit-nutri" aria-live="polite"><p class="form-hint">Loading…</p></div>

<form id="nutriForm" class="card card-body fit-form" novalidate>
  <div class="fit-nutri-form">
    <div class="form-group">
      <label for="nutriMeal" class="form-label">Meal</label>
      <select id="nutriMeal" class="form-input">
        <option value="breakfast">Breakfast</option>
        <option value="lunch">Lunch</option>
        <option value="dinner">Dinner</option>
        <option value="snack">Snack</option>
      </select>
    </div>
    <div class="form-group fit-nutri-name">
      <label for="nutriName" class="form-label">Food <span class="form-hint">(optional)</span></label>
      <input id="nutriName" class="form-input" maxlength="120" placeholder="e.g. Chicken rice bowl">
    </div>
    <div class="form-group">
      <label for="nutriCalories" class="form-label">Calories</label>
      <input id="nutriCalories" type="number" class="form-input" min="0" max="10000" step="1" inputmode="numeric" placeholder="kcal">
    </div>
    <div class="form-group">
      <label for="nutriProtein" class="form-label">Protein</label>
      <input id="nutriProtein" type="number" class="form-input" min="0" max="1000" step="0.5" inputmode="decimal" placeholder="g">
    </div>
  </div>
  <div class="fit-nutri-actions">
    <div class="fit-water-btns" role="group" aria-label="Log water">
      <span class="form-hint"><i class="fas fa-droplet" aria-hidden="true"></i> Water</span>
      <button type="button" class="btn btn-secondary btn-sm" data-water="250">+250 ml</button>
      <button type="button" class="btn btn-secondary btn-sm" data-water="500">+500 ml</button>
    </div>
    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Add food</button>
  </div>
</form>

<div id="nutriEntries" class="card" style="margin-bottom:1.5rem"></div>

<div class="fit-section-head"><h2 class="fit-h2">Last 7 days</h2></div>
<div id="nutriRecent" class="card"></div>

<div id="nutriTargetsModal" class="modal-backdrop hidden">
  <div class="modal-box" role="dialog" aria-labelledby="nutriTargetsTitle">
    <div class="modal-header">
      <span class="modal-title" id="nutriTargetsTitle">Daily targets</span>
      <button class="btn btn-icon btn-ghost btn-sm" data-close-modal="nutriTargetsModal" aria-label="Close dialog">&times;</button>
    </div>
    <div class="modal-body">
      <p class="form-hint" style="margin-top:0">Your own targets — Trackie doesn't calculate these for you. Leave any blank to skip it.</p>
      <div class="form-group"><label for="tgtCalories" class="form-label">Calories (kcal)</label><input id="tgtCalories" type="number" class="form-input" min="500" max="10000" step="10"></div>
      <div class="form-group"><label for="tgtProtein" class="form-label">Protein (g)</label><input id="tgtProtein" type="number" class="form-input" min="0" max="1000" step="1"></div>
      <div class="form-group"><label for="tgtWater" class="form-label">Water (ml)</label><input id="tgtWater" type="number" class="form-input" min="250" max="10000" step="250"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary btn-sm" data-close-modal="nutriTargetsModal">Cancel</button>
      <button class="btn btn-primary btn-sm" type="button" id="nutriTargetsSave"><i class="fas fa-save"></i> Save targets</button>
    </div>
  </div>
</div>
