<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

require_once '../config/database.php';
require_once '../includes/functions.php';

$user_id = $_SESSION['user_id'];
$success = '';
$error = '';

// Fetch user info
$user = fetchOne("SELECT * FROM users WHERE id = ?", [$user_id]);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitizeInput($_POST['name'] ?? '');
    $email = sanitizeInput($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $profile_pic = $user['profile_pic'];

    // Handle profile picture upload
    if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION);
        $filename = 'profile_' . $user_id . '_' . time() . '.' . $ext;
        $dest = '../assets/images/' . $filename;
        if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $dest)) {
            $profile_pic = 'assets/images/' . $filename;
        }
    }

    if (!$name || !$email) {
        $error = 'Name and email are required.';
    } else {
        // Update user info
        $params = [$name, $email, $profile_pic, $user_id];
        $sql = "UPDATE users SET name = ?, email = ?, profile_pic = ? WHERE id = ?";
        update($sql, $params);
        if ($password) {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            update("UPDATE users SET password = ? WHERE id = ?", [$hashed, $user_id]);
        }
        $success = 'Profile updated successfully!';
        // Update session
        $_SESSION['user_name'] = $name;
        $_SESSION['profile_pic'] = $profile_pic;
        // Refresh user info
        $user = fetchOne("SELECT * FROM users WHERE id = ?", [$user_id]);
    }
}

$img_src = $user['profile_pic'] && file_exists('../' . $user['profile_pic']) ? '../' . $user['profile_pic'] : '../assets/images/default-user.png';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - Trackie.in</title>
    <link rel="icon" type="image/x-icon" href="../assets/images/Logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="font-sans">
<?php include '../includes/sidebar.php'; ?>
<div class="pt-20 px-4 max-w-xl mx-auto">
    <h1 class="text-3xl font-bold text-black mb-6">My Profile</h1>
    <?php if ($success): ?>
        <div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-800 rounded">✅ <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="mb-4 p-3 bg-red-50 border border-red-200 text-red-800 rounded">❌ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="POST" enctype="multipart/form-data" class="space-y-5 card p-6">
        <div class="flex flex-col items-center mb-4">
            <img src="<?= htmlspecialchars($img_src) ?>" alt="Profile Picture" class="w-24 h-24 rounded-full border-2 border-red-400 mb-2 object-cover">
            <input type="file" name="profile_pic" accept="image/*" class="form-input w-full">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-600 mb-2">Name</label>
            <input type="text" name="name" value="<?= htmlspecialchars($user['name']) ?>" class="form-input w-full" required>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-600 mb-2">Email</label>
            <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" class="form-input w-full" required>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-600 mb-2">New Password <span class="text-xs text-gray-400">(leave blank to keep current)</span></label>
            <input type="password" name="password" class="form-input w-full">
        </div>
        <div class="flex gap-3">
            <button type="submit" class="btn btn-primary flex-1"><i class="fas fa-save mr-2"></i>Save Changes</button>
        </div>
    </form>
</div>
</body>
</html> 