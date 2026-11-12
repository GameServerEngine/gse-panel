<?php require_once __DIR__ . '/includes/brand.php'; ?>
<?php
// Start a separate session for the website
session_name("gameserverpanel_web");
session_start();

// Include database configuration
require_once(__DIR__ . '/bootstrap.php');

// Variables from config.inc.php (helps IDEs understand scope)
/** @var string $db_host Database host */
/** @var string $db_user Database user */
/** @var string $db_pass Database password */
/** @var string $db_name Database name */
/** @var string $table_prefix Table prefix for database tables */

// Logger function
function logger($logtext){
    file_put_contents(__DIR__ . "/logfile.txt", $logtext . PHP_EOL, FILE_APPEND);
}

$message = '';
$error = '';
$token_valid = false;
$user_id = null;

// Create database connection. Public rendering must remain nonfatal when missing.
$db = billing_get_db();
if (!$db) {
    $error = 'Password reset is temporarily unavailable because the storefront database is not configured in this environment.';
}

// Get token from URL
$token = isset($_GET['token']) ? trim($_GET['token']) : '';

if (!$db) {
    $error = 'Password reset is temporarily unavailable because the storefront database is not configured in this environment.';
} elseif (empty($token)) {
    $error = 'Invalid or missing reset token.';
} else {
    // Sanitize token
    $token = mysqli_real_escape_string($db, $token);

    // Verify token
    $query = "SELECT user_id, expires, used FROM {$table_prefix}password_reset_tokens
              WHERE token = '$token' LIMIT 1";
    $result = mysqli_query($db, $query);

    if ($result && mysqli_num_rows($result) === 1) {
        $token_data = mysqli_fetch_assoc($result);

        // Check if token is expired or used
        if ($token_data['used'] == 1) {
            $error = 'This password reset link has already been used.';
        } elseif (strtotime($token_data['expires']) < time()) {
            $error = 'This password reset link has expired. Please request a new one.';
        } else {
            $token_valid = true;
            $user_id = $token_data['user_id'];
        }
    } else {
        $error = 'Invalid password reset token.';
    }
}

// Process password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password']) && $token_valid) {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($new_password) || empty($confirm_password)) {
        $error = 'Please enter and confirm your new password.';
    } elseif ($new_password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif (strlen($new_password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } else {
        // Hash the password (MD5 for panel compatibility, with modern hash shadow if column exists)
        $md5_password = md5($new_password);

        // Check if shadow column exists
        $has_shadow = false;
        $res_cols = mysqli_query($db, "SHOW COLUMNS FROM {$table_prefix}users LIKE 'users_pass_hash'");
        if ($res_cols && mysqli_num_rows($res_cols) > 0) {
            $has_shadow = true;
        }

        // Update password
        if ($has_shadow) {
            $modern_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE {$table_prefix}users SET users_passwd = ?, users_pass_hash = ? WHERE user_id = ?");
            $stmt->bind_param('ssi', $md5_password, $modern_hash, $user_id);
        } else {
            $stmt = $db->prepare("UPDATE {$table_prefix}users SET users_passwd = ? WHERE user_id = ?");
            $stmt->bind_param('si', $md5_password, $user_id);
        }

        if ($stmt->execute()) {
            // Mark token as used
            $stmt2 = $db->prepare("UPDATE {$table_prefix}password_reset_tokens SET used = 1 WHERE token = ?");
            $stmt2->bind_param('s', $token);
            $stmt2->execute();
            $stmt2->close();

            logger("Password reset completed for user_id: $user_id");

            $message = 'Password has been reset successfully. You can now login with your new password.';
            $token_valid = false; // Prevent form from showing again
        } else {
            $error = 'Failed to reset password. Please try again.';
        }

        $stmt->close();
    }
}

// Close database connection
            billing_maybe_close_db($db);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - <?php echo billing_brand_e('name'); ?></title>
<?php include(__DIR__ . '/includes/theme_head.php'); ?>
</head>
<body>
    <?php include(__DIR__ . '/includes/top.php'); ?>
    <?php include(__DIR__ . '/includes/menu.php'); ?>
    <div class="content">
    <div class="reset-container">
        <div class="reset-header">
            <h1>Reset Password</h1>
            <p>Enter your new password</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (!empty($message)): ?>
            <div class="alert alert-success"><?php echo $message; ?></div>
        <?php endif; ?>

        <?php if ($token_valid): ?>
        <form method="POST" action="<?php echo htmlspecialchars(billing_url('reset_password.php?token=' . urlencode($token)), ENT_QUOTES, 'UTF-8'); ?>">
            <div class="form-group">
                <label for="new_password">New Password</label>
                <input type="password" id="new_password" name="new_password" required autofocus>
                <div class="password-requirements">Must be at least 8 characters long</div>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
            </div>

            <button type="submit" name="reset_password" class="btn-submit">Reset Password</button>
        </form>
        <?php endif; ?>

        <div class="footer-links">
            <a href="<?php echo htmlspecialchars(billing_url('login.php'), ENT_QUOTES, 'UTF-8'); ?>">Back to Login</a> |
            <a href="<?php echo htmlspecialchars(billing_url('index.php'), ENT_QUOTES, 'UTF-8'); ?>">Home</a>
        </div>
    </div>
    </div>
<?php include(__DIR__ . '/includes/footer.php'); ?>
</body>
</html>
