<?php
require_once __DIR__ . '/../../config/session.php';
requireLogin();
$pageTitle = 'Change your password';
$pageDescription = 'Replace your temporary SAGIPBRO password before continuing.';
$basePath = '../../';
$isAuthPage = true;
$useLoginStyles = true;
$error = is_string($_GET['error'] ?? null) ? $_GET['error'] : '';
require __DIR__ . '/../../includes/header.php';
?>
<main id="main-content" class="container py-5" style="max-width:640px">
    <section class="surface-card">
        <div class="surface-card-header"><div><h1 class="h3">Change your password</h1><p>Replace your temporary password to continue to SAGIPBRO.</p></div></div>
        <div class="surface-card-body">
            <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            <p>Use at least 8 characters. Spaces and passphrases are welcome. Choose a password different from your temporary password.</p>
            <form method="post" action="index.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="intent" value="password">
                <div class="mb-3"><label class="form-label" for="temporaryPassword">Current or temporary password</label><input class="form-control" id="temporaryPassword" name="current_password" type="password" autocomplete="current-password" required></div>
                <div class="mb-3"><label class="form-label" for="replacementPassword">New password</label><input class="form-control" id="replacementPassword" name="new_password" type="password" autocomplete="new-password" minlength="8" maxlength="72" required></div>
                <div class="mb-3"><label class="form-label" for="confirmReplacement">Confirm new password</label><input class="form-control" id="confirmReplacement" name="confirm_new_password" type="password" autocomplete="new-password" minlength="8" maxlength="72" required></div>
                <button class="btn btn-brand" type="submit">Update password</button>
            </form>
            <form class="mt-3" method="post" action="../../actions/auth/logout.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <button class="btn btn-light" type="submit">Sign out</button>
            </form>
        </div>
    </section>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
