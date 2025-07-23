<?php
if (!isset($_SESSION)) session_start();
require_once __DIR__ . '/../config/database.php';

$user_id = $_SESSION['user_id'] ?? null;
$notifications = [];
$due_count = 0;

if ($user_id) {
    // Due/overdue todos
    $todos = fetchAll("SELECT * FROM todos WHERE user_id = ? AND completed = 0 AND due_date <= CURDATE() AND deleted_at IS NULL ORDER BY due_date ASC", [$user_id]);
    foreach ($todos as $todo) {
        $notifications[] = [
            'type' => 'todo',
            'title' => $todo['title'],
            'due_date' => $todo['due_date'],
            'link' => 'pages/todo_manager.php',
        ];
        $due_count++;
    }
    // Due/overdue study tasks
    $studies = fetchAll("SELECT * FROM study_plan WHERE user_id = ? AND completed = 0 AND due_date <= CURDATE() ORDER BY due_date ASC", [$user_id]);
    foreach ($studies as $task) {
        $notifications[] = [
            'type' => 'study',
            'title' => $task['title'],
            'due_date' => $task['due_date'],
            'link' => 'pages/study_plan.php',
        ];
        $due_count++;
    }
}
?>
<div class="relative inline-block">
    <button id="notifBtn" class="relative p-2 rounded-full hover:bg-gray-100 focus:outline-none">
        <i class="fa fa-bell text-xl text-red-500"></i>
        <?php if ($due_count > 0): ?>
            <span class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full px-2 py-0.5 font-bold animate-pulse"><?= $due_count ?></span>
        <?php endif; ?>
    </button>
    <div id="notifDropdown" class="hidden absolute right-0 mt-2 w-80 bg-white border border-gray-200 rounded shadow-lg z-50">
        <div class="p-3 border-b font-bold text-black">Notifications</div>
        <?php if (empty($notifications)): ?>
            <div class="p-4 text-gray-500 text-sm">No due or overdue tasks.</div>
        <?php else: ?>
            <ul class="max-h-64 overflow-y-auto">
                <?php foreach ($notifications as $n): ?>
                    <li class="px-4 py-2 border-b last:border-0 flex items-center gap-2">
                        <i class="fa fa-exclamation-circle text-red-500"></i>
                        <div class="flex-1">
                            <div class="font-bold text-black text-sm"><?= htmlspecialchars($n['title']) ?></div>
                            <div class="text-xs text-gray-500">Due: <?= htmlspecialchars($n['due_date']) ?></div>
                        </div>
                        <a href="<?= $n['link'] ?>" class="text-blue-600 text-xs underline">View</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var btn = document.getElementById('notifBtn');
    var dropdown = document.getElementById('notifDropdown');
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        dropdown.classList.toggle('hidden');
    });
    document.addEventListener('click', function() {
        dropdown.classList.add('hidden');
    });
});
</script> 