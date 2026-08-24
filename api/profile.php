<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

/**
 * Validate + persist the onboarding personalisation answers.
 *
 * Shared by complete_onboarding and save_prefs so the whitelist and the clamps
 * are defined ONCE — two copies would inevitably disagree about what a valid
 * focus or a sane sleep target is.
 *
 * $allowClear: onboarding must never wipe an answer with a skipped step, but
 * the profile editor MUST be able to reset a field back to "not set".
 */
function savePersonalisation(int $uid, array $in, bool $allowClear = false): void {
    $focusAllowed = ['discipline', 'fitness', 'study', 'money', 'wellbeing', 'creativity'];
    $expAllowed   = ['new', 'some', 'experienced'];

    $focus = sanitizeInput((string)($in['primary_focus'] ?? ''));
    $focus = in_array($focus, $focusAllowed, true) ? $focus : null;

    $exp = sanitizeInput((string)($in['experience_level'] ?? ''));
    $exp = in_array($exp, $expAllowed, true) ? $exp : null;

    $remTime = sanitizeInput((string)($in['daily_reminder_time'] ?? ''));
    $remTime = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $remTime) ? $remTime : null;

    $sleep = $in['sleep_goal_hours'] ?? '';
    $sleep = is_numeric($sleep) ? max(3, min(14, round((float)$sleep, 1))) : null;

    $water = $in['water_goal_ml'] ?? '';
    $water = is_numeric($water) ? max(250, min(6000, (int)$water)) : null;

    if ($allowClear) {
        // Explicit overwrite, including back to NULL.
        update(
            "UPDATE users SET primary_focus=?, experience_level=?, daily_reminder_time=?,
                              sleep_goal_hours=?, water_goal_ml=? WHERE id=?",
            [$focus, $exp, $remTime, $sleep, $water, $uid]
        );
    } else {
        // COALESCE so a skipped onboarding step never erases an existing answer.
        update(
            "UPDATE users
                SET primary_focus       = COALESCE(?, primary_focus),
                    experience_level    = COALESCE(?, experience_level),
                    daily_reminder_time = COALESCE(?, daily_reminder_time),
                    sleep_goal_hours    = COALESCE(?, sleep_goal_hours),
                    water_goal_ml       = COALESCE(?, water_goal_ml)
              WHERE id = ?",
            [$focus, $exp, $remTime, $sleep, $water, $uid]
        );
    }
}

requireAuth();
verify_csrf();

$uid    = currentUserId();
$action = sanitizeInput($_POST['action'] ?? '');

switch ($action) {

    case 'update':
        $name  = sanitizeInput($_POST['name']  ?? '');
        $email = sanitizeInput($_POST['email'] ?? '');
        $phone = sanitizeInput($_POST['phone'] ?? '');

        if (!$name || !$email) json_out(['success' => false, 'error' => 'Name and email are required.'], 422);
        if (!validateEmail($email)) json_out(['success' => false, 'error' => 'Invalid email.'], 422);

        // Check email uniqueness (excluding current user)
        $taken = fetchOne("SELECT id FROM users WHERE email=? AND id!=?", [$email, $uid]);
        if ($taken) json_out(['success' => false, 'error' => 'That email is already in use.'], 409);

        $picPath = null;

        // Handle profile picture upload
        if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] !== UPLOAD_ERR_NO_FILE) {
            $errors = validateUpload($_FILES['profile_pic']);
            if ($errors) json_out(['success' => false, 'error' => implode(' ', $errors)], 422);

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($_FILES['profile_pic']['tmp_name']);
            $ext   = mimeToExt($mime);
            $fname = 'pfp_' . $uid . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $dest  = ROOT_PATH . '/assets/images/' . $fname;

            if (!move_uploaded_file($_FILES['profile_pic']['tmp_name'], $dest)) {
                json_out(['success' => false, 'error' => 'Upload failed.'], 500);
            }

            // Downscale server-side too. The browser already shrinks it, but a
            // direct API caller does not run that JS — and production had
            // 2-2.6 MB originals being served as 44px avatars.
            // No-ops safely when GD is unavailable.
            shrinkImageFile($dest, 512, 82);

            // Delete old profile pic
            $old = fetchOne("SELECT profile_pic FROM users WHERE id=?", [$uid]);
            if ($old && $old['profile_pic']) {
                $oldPath = ROOT_PATH . '/' . $old['profile_pic'];
                if (file_exists($oldPath) && strpos($oldPath, '/assets/images/pfp_') !== false) {
                    @unlink($oldPath);
                }
            }

            $picPath = 'assets/images/' . $fname;
            $_SESSION['profile_pic'] = $picPath;
        }

        if ($picPath !== null) {
            update("UPDATE users SET name=?,email=?,phone=?,profile_pic=? WHERE id=?",
                   [$name, $email, $phone, $picPath, $uid]);
        } else {
            update("UPDATE users SET name=?,email=?,phone=? WHERE id=?",
                   [$name, $email, $phone, $uid]);
        }

        $_SESSION['user_name']  = $name;
        $_SESSION['user_email'] = $email;
        json_out(['success' => true, 'message' => 'Profile updated.']);

    case 'save_hobbies':
        $raw = $_POST['hobbies'] ?? '';
        $list = array_filter(array_map('trim', explode(',', is_array($raw) ? implode(',', $raw) : $raw)));
        $list = array_slice(array_unique(array_map('sanitizeInput', $list)), 0, 8);
        $hobbies = implode(',', $list);
        if (strlen($hobbies) > 300) json_out(['success' => false, 'error' => 'Too many hobbies.'], 422);

        update("UPDATE users SET hobbies=? WHERE id=?", [$hobbies, $uid]);
        json_out(['success' => true, 'hobbies' => $list]);

    // Onboarding step 2 → dashboard: saves the chosen hobbies, creates starter
    // habits for each (skipping ones the user already has, same dedup rule as
    // habits.php's own suggestion chips), and adds one starter task so the
    // dashboard isn't empty on first login. Idempotent-ish: safe to call twice
    // (dedup on habit name; onboarding_completed_at only set once).
    case 'complete_onboarding':
        $raw  = $_POST['hobbies'] ?? '';
        $list = array_filter(array_map('trim', explode(',', is_array($raw) ? implode(',', $raw) : $raw)));
        $list = array_slice(array_unique(array_map('sanitizeInput', $list)), 0, 8);
        $hobbies = implode(',', $list);
        if (strlen($hobbies) > 300) json_out(['success' => false, 'error' => 'Too many hobbies.'], 422);

        update("UPDATE users SET hobbies=? WHERE id=?", [$hobbies, $uid]);

        // Whitelisting + clamping lives in savePersonalisation() so the
        // profile editor cannot drift from onboarding's rules.
        savePersonalisation($uid, $_POST, false);
        $remTime = userPrefs($uid, true)['daily_reminder_time'] ?? null;
        $remTime = $remTime ? substr((string)$remTime, 0, 5) : null;

        /* A check-in TIME is useless as a stored preference — it has to become
           an actual reminder or the question was decorative. Creates one
           recurring daily reminder, and only if the user has no onboarding
           reminder already, so re-running onboarding never stacks duplicates. */
        if ($remTime && tableExists('reminders')) {
            $already = fetchOne(
                "SELECT id FROM reminders WHERE user_id=? AND type='recurring' AND title=? LIMIT 1",
                [$uid, 'Log your habits']
            );
            if (!$already) {
                // If today's slot has already passed, start tomorrow rather
                // than firing immediately on the next poll.
                $todayAt = date('Y-m-d') . ' ' . $remTime . ':00';
                $next    = strtotime($todayAt) > time()
                    ? $todayAt
                    : date('Y-m-d', strtotime('+1 day')) . ' ' . $remTime . ':00';

                insert(
                    "INSERT INTO reminders
                       (user_id,title,notes,type,remind_time,repeat_every,repeat_unit,next_fire_at,active)
                     VALUES (?,?,?,'recurring',?,1,'day',?,1)",
                    [$uid, 'Log your habits',
                     'Your daily check-in — set during onboarding.',
                     $remTime, $next]
                );
            }
        }

        $existingNames = array_map('mb_strtolower', array_column(
            fetchAll("SELECT name FROM habits WHERE user_id=?", [$uid]), 'name'
        ));
        $created = [];
        foreach (suggestedHabitsForHobbies($hobbies) as $sg) {
            if (in_array(mb_strtolower($sg['name']), $existingNames, true)) continue;
            insert(
                "INSERT INTO habits (user_id, name, frequency, color) VALUES (?,?,?,?)",
                [$uid, $sg['name'], $sg['freq'], $sg['color']]
            );
            $created[] = $sg['name'];
        }

        // One starter task, only if the user genuinely has none yet — never
        // clutters a returning/re-run of this action with duplicates.
        $hasTodos = (int)fetchOne("SELECT COUNT(*) c FROM todos WHERE user_id=? AND deleted_at IS NULL", [$uid])['c'];
        if ($hasTodos === 0) {
            insert(
                "INSERT INTO todos (user_id, title, priority, due_date, status) VALUES (?,?,?,?,?)",
                [$uid, 'Explore your Trackie dashboard', 'medium', date('Y-m-d'), 'today']
            );
        }

        update("UPDATE users SET onboarding_completed_at=NOW() WHERE id=? AND onboarding_completed_at IS NULL", [$uid]);

        json_out(['success' => true, 'hobbies' => $list, 'habits_created' => $created]);

    // Profile editor for the onboarding answers. allowClear=true because the
    // user must be able to unset a field here, unlike a skipped onboarding step.
    case 'save_prefs':
        savePersonalisation($uid, $_POST, true);
        json_out(['success' => true, 'prefs' => userPrefs($uid, true)]);

    case 'change_password':
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!$current || !$new || !$confirm) json_out(['success' => false, 'error' => 'All password fields are required.'], 422);
        if (strlen($new) < 8) json_out(['success' => false, 'error' => 'New password must be at least 8 characters.'], 422);
        if ($new !== $confirm) json_out(['success' => false, 'error' => 'Passwords do not match.'], 422);

        $user = fetchOne("SELECT password FROM users WHERE id=?", [$uid]);
        if (!verifyPassword($current, $user['password'])) {
            json_out(['success' => false, 'error' => 'Current password is incorrect.'], 401);
        }

        update("UPDATE users SET password=? WHERE id=?", [hashPassword($new), $uid]);
        json_out(['success' => true, 'message' => 'Password changed successfully.']);

    default:
        json_out(['success' => false, 'error' => 'Unknown action.'], 400);
}
