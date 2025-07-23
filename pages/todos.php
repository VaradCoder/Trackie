<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

require_once '../config/database.php';

$user_id = $_SESSION['user_id'];
$todos = fetchAll("SELECT * FROM todos WHERE user_id = ? AND deleted_at IS NULL ORDER BY priority DESC, due_date ASC, created_at DESC", [$user_id]);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Todos - Trackie.in</title>
    <link rel="icon" type="image/x-icon" href="../assets/images/Logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Bangers&family=Comic+Neue:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="font-comic">
    <?php include '../includes/sidebar.php'; ?>
    <div class="max-w-4xl mx-auto py-10 px-4">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-3xl font-bold text-gold">Your Todos</h1>
            <a href="todo_manager.php" class="btn-primary"><i class="fa fa-plus mr-2"></i>Manage Todos</a>
        </div>
        <div class="luxury-card p-6">
            <ul class="space-y-3">
                <?php if (empty($todos)): ?>
                    <li class="text-silver text-center py-8">No todos found. <span class="block text-gold mt-2">Stay motivated! 🌱</span></li>
                <?php else: ?>
                    <?php foreach ($todos as $todo): ?>
                        <li class="todo-item flex items-center gap-3 <?= $todo['completed'] ? 'completed' : '' ?>" data-todo-id="<?= $todo['id'] ?>">
                            <input type="checkbox" class="todo-checkbox accent-gold w-5 h-5" data-todo-id="<?= $todo['id'] ?>" <?= $todo['completed'] ? 'checked' : '' ?>>
                            <div class="flex-1">
                                <div class="font-bold text-lg text-white flex items-center gap-2">
                                    <?= htmlspecialchars($todo['title']) ?>
                                    <?php if ($todo['due_date']): ?>
                                        <span class="text-xs text-silver ml-2">Due: <?= htmlspecialchars($todo['due_date']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($todo['description']): ?>
                                    <div class="text-sm text-silver mt-1"> <?= htmlspecialchars($todo['description']) ?> </div>
                                <?php endif; ?>
                            </div>
                            <div class="flex gap-2">
                                <button class="text-gold hover:text-silver transition-colors" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="text-gold hover:text-silver transition-colors" title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>
    </div>
    <script src="../assets/js/app.js"></script>
</body>
</html> 