<?php
require __DIR__ . '/config.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Only allow a local (same-app) redirect target, to avoid open-redirect abuse.
function safe_next(string $next): string
{
    $next = trim($next);
    if ($next === '' || $next[0] !== '/' && strpos($next, '://') !== false) {
        return 'index.php';
    }
    if (strpos($next, '//') === 0) {
        return 'index.php';
    }
    // Disallow anything with a scheme or backslashes.
    if (preg_match('#^[a-z]+:#i', $next) || strpos($next, "\\") !== false) {
        return 'index.php';
    }
    return $next;
}

$next = safe_next($_GET['next'] ?? ($_POST['next'] ?? 'index.php'));

// If auth is off, or already authed, there's nothing to do here.
if (!auth_enabled($pdo) || is_authed()) {
    header('Location: ' . $next);
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw = (string)($_POST['password'] ?? '');
    if (hash_equals(AUTH_PASSWORD, $pw)) {
        auth_set_cookie();
        header('Location: ' . $next);
        exit;
    }
    $error = 'Incorrect password. Please try again.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Office Presence — sign in</title>
<script src="<?= asset_url('assets/theme.js') ?>"></script>
<link rel="stylesheet" href="<?= asset_url('assets/style.css') ?>">
</head>
<body>
<div class="login-wrap">
    <form class="login-card" method="post">
        <h1>Office Presence</h1>
        <p class="login-sub">Enter the shared password to continue.</p>
        <input type="hidden" name="next" value="<?= h($next) ?>">
        <input type="password" name="password" placeholder="Password" autofocus required>
        <?php if ($error): ?><div class="login-error"><?= h($error) ?></div><?php endif; ?>
        <button type="submit" class="btn login-btn">Sign in</button>
    </form>
</div>
</body>
</html>
