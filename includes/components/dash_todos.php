<?php
/**
 * Dashboard — Today's Tasks card (column 3)
 * Expects: $todayTodos (array), $todoStats (array with total/done/pending)
 */
$pending = (int)($todoStats['pending'] ?? 0);

// Emoji map based on keywords in title
function todoEmoji(string $title): string {
    $lower = mb_strtolower($title);
    $map   = [
        'study'    => '📚', 'read'     => '📖', 'book'      => '📖',
        'gym'      => '💪', 'exercise' => '💪', 'workout'   => '💪',
        'run'      => '🏃', 'swim'     => '🏊', 'walk'      => '🚶',
        'eat'      => '🥗', 'food'     => '🥗', 'healthy'   => '🥦',
        'cook'     => '🍳', 'lunch'    => '🥗', 'dinner'    => '🍽️',
        'shop'     => '🛒', 'groceri'  => '🛒', 'market'    => '🛒',
        'meditat'  => '🧘', 'yoga'     => '🧘',
        'meeting'  => '💼', 'call'     => '📞', 'email'     => '📧',
        'music'    => '🎵', 'sleep'    => '😴', 'rest'      => '😴',
        'clean'    => '🧹', 'laundry'  => '🧺',
        'doctor'   => '🏥', 'medicine' => '💊',
        'code'     => '💻', 'project'  => '📋', 'report'    => '📄',
    ];
    foreach ($map as $kw => $em) {
        if (str_contains($lower, $kw)) return $em;
    }
    return '📝';
}
?>
<div class="d-card" aria-label="Today's tasks" id="dashTodosCard">
  <div class="d-card-header">
    <span class="d-card-title">
      To-Do
      <?php if ($pending > 0): ?>
        <span class="todo-count-pill"><?= $pending ?> left</span>
      <?php endif; ?>
    </span>
    <a href="<?= APP_BASE ?>/pages/todos.php" class="d-card-link" aria-label="View all todos">
      View Details
    </a>
  </div>

  <div class="d-card-body" style="padding-top:.5rem">

    <!-- Task list -->
    <?php if (empty($todayTodos)): ?>
      <div class="empty-dash">
        <div class="empty-dash-icon" aria-hidden="true"><i class="fas fa-clipboard-check"></i></div>
        <p>You're all caught up! 🎉</p>
        <a href="<?= APP_BASE ?>/pages/todos.php" class="btn-dash-primary"
           style="display:inline-flex;width:auto;margin-top:.625rem;padding:.5rem 1rem;font-size:.8125rem">
          <i class="fas fa-plus" aria-hidden="true"></i> Add Todo
        </a>
      </div>

    <?php else: ?>
      <div role="list" aria-label="Today's todo list">
        <?php foreach ($todayTodos as $t):
          $isDone    = (bool)$t['completed'];
          $emoji     = todoEmoji($t['title']);
          $hasDate   = !empty($t['due_date']);
          $isOverdue = !$isDone && $hasDate && $t['due_date'] < date('Y-m-d');
          $isToday   = $hasDate && $t['due_date'] === date('Y-m-d');
          $dateStr   = $hasDate ? ($isToday ? 'Today' : date('M j', strtotime($t['due_date']))) : '';
        ?>
          <div class="task-item <?= $isDone ? 'done' : '' ?>"
               id="dash-task-<?= $t['id'] ?>"
               role="listitem">
            <!-- Priority pip -->
            <div class="priority-pip <?= h($t['priority']) ?>"
                 aria-label="<?= ucfirst(h($t['priority'])) ?> priority"
                 title="<?= ucfirst(h($t['priority'])) ?> priority"></div>

            <!-- Emoji -->
            <div class="task-emoji" aria-hidden="true"><?= $emoji ?></div>

            <!-- Body -->
            <div class="task-body">
              <div class="task-title"><?= h($t['title']) ?></div>
              <div class="task-meta-row">
                <?php if ($isOverdue): ?>
                  <span style="color:var(--accent);font-weight:600">
                    <i class="fas fa-triangle-exclamation" style="font-size:.65rem" aria-hidden="true"></i> Overdue · <?= $dateStr ?></span>
                <?php elseif ($dateStr): ?>
                  <span><i class="fas fa-calendar-day" style="font-size:.65rem" aria-hidden="true"></i> <?= $dateStr ?></span>
                <?php endif; ?>
                <?php if ($t['location']): ?>
                  <span><i class="fas fa-map-marker-alt" style="font-size:.65rem" aria-hidden="true"></i>
                    <?= h($t['location']) ?></span>
                <?php endif; ?>
              </div>
            </div>

            <!-- Checkbox -->
            <input type="checkbox"
                   class="task-check"
                   <?= $isDone ? 'checked' : '' ?>
                   onchange="dashToggleTask(<?= $t['id'] ?>, this.checked, this)"
                   aria-label="Mark '<?= h($t['title']) ?>' as <?= $isDone ? 'incomplete' : 'complete' ?>">
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  </div>
</div>
