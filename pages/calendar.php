<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
require_once '../config/database.php';
$user_id = $_SESSION['user_id'];

// Get month/year from query or default to current
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$first_day = mktime(0, 0, 0, $month, 1, $year);
$days_in_month = date('t', $first_day);
$start_day = date('w', $first_day);

// Get all todos and study tasks for this month
$start_date = date('Y-m-01', $first_day);
$end_date = date('Y-m-t', $first_day);
$todos = fetchAll("SELECT title, due_date FROM todos WHERE user_id = ? AND due_date BETWEEN ? AND ? AND deleted_at IS NULL", [$user_id, $start_date, $end_date]);
$studies = fetchAll("SELECT title, due_date FROM study_plan WHERE user_id = ? AND due_date BETWEEN ? AND ?", [$user_id, $start_date, $end_date]);
$tasks_by_date = [];
foreach (array_merge($todos, $studies) as $task) {
    $tasks_by_date[$task['due_date']][] = $task['title'];
}

// Navigation
$prev_month = $month - 1;
$prev_year = $year;
if ($prev_month < 1) { $prev_month = 12; $prev_year--; }
$next_month = $month + 1;
$next_year = $year;
if ($next_month > 12) { $next_month = 1; $next_year++; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendar - Trackie.in</title>
    <link rel="icon" type="image/x-icon" href="../assets/images/Logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .calendar-day { min-width: 2.5rem; min-height: 3.5rem; }
        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 0.5rem; }
    </style>
</head>
<body class="font-sans">
<?php include '../includes/sidebar.php'; ?>
<div class="pt-20 px-4 max-w-3xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <a href="?month=<?= $prev_month ?>&year=<?= $prev_year ?>" class="btn btn-secondary"><i class="fa fa-chevron-left"></i></a>
        <h1 class="text-2xl font-bold text-black">Calendar - <?= date('F Y', $first_day) ?></h1>
        <a href="?month=<?= $next_month ?>&year=<?= $next_year ?>" class="btn btn-secondary"><i class="fa fa-chevron-right"></i></a>
    </div>
    <div class="calendar-grid bg-white rounded-lg p-4 shadow mb-8">
        <?php
        $days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
        foreach ($days as $d) {
            echo '<div class="text-center font-bold text-gray-600">'.$d.'</div>';
        }
        for ($i = 0; $i < $start_day; $i++) {
            echo '<div></div>';
        }
        for ($day = 1; $day <= $days_in_month; $day++) {
            $date = date('Y-m-d', mktime(0,0,0,$month,$day,$year));
            $has_tasks = isset($tasks_by_date[$date]);
            echo '<div class="calendar-day flex flex-col items-center justify-center rounded-lg '.($has_tasks?'bg-red-50 border border-red-200':'bg-gray-50').'">';
            echo '<div class="font-bold text-black">'.$day.'</div>';
            if ($has_tasks) {
                echo '<span class="badge mt-1">'.count($tasks_by_date[$date]).'</span>';
                echo '<div class="text-xs text-gray-600 mt-1">';
                foreach ($tasks_by_date[$date] as $title) {
                    echo '<div title="'.htmlspecialchars($title).'">'.htmlspecialchars($title).'</div>';
                }
                echo '</div>';
            }
            echo '</div>';
        }
        ?>
    </div>
    <div class="text-xs text-gray-500">Click arrows to navigate months. Tasks are shown as badges and tooltips.</div>
</div>
</body>
</html> 