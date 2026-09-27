<?php
require_once __DIR__ . '/config/session.php';

if (isLoggedIn()) {
    $target = ($_SESSION['role'] ?? '') === 'resident' ? 'resident.php' : (($_SESSION['role'] ?? '') === 'volunteer' ? 'volunteer.php' : 'admin.php');
    header('Location: dashboard/' . $target);
    exit;
}

$error = trim((string) ($_GET['error'] ?? ''));
$success = trim((string) ($_GET['success'] ?? ''));
$pageTitle = 'Staff sign in';
$pageDescription = 'Secure SAGIPBRO sign in for authorized Barangay Binloc officials and response volunteers.';
$basePath = '';
$useLoginStyles = true;
$isAuthPage = true;
$useRecaptcha = (bool) getenv('SAGIPBRO_RECAPTCHA_SITE_KEY');
require __DIR__ . '/includes/header.php';
?>
<main class="auth-main" id="main-content">
    <section class="auth-visual" aria-label="SAGIPBRO welcome">
        <a class="auth-brand brand-lockup" href="index.php" aria-label="SAGIPBRO home">
            <img src="assets/images/sagipbro-mark.svg" alt="" width="49" height="55">
            <span><strong>SAGIPBRO</strong><small>DISASTER RELIEF INFORMATION SYSTEM</small></span>
        </a>
        <div class="auth-visual-copy">
            <span class="auth-chip"><i class="bi bi-shield-check" aria-hidden="true"></i> Secure staff portal</span>
            <h1>Ready to serve,<br>when it matters most.</h1>
            <p>Manage resources, coordinate evacuation support, and keep the Bonuan Binloc community informed from one dependable workspace.</p>
        </div>
        <div class="auth-visual-status"><span class="status-pulse" aria-hidden="true"></span><span><strong>System operational</strong><small>Authorized personnel only</small></span></div>
    </section>

    <section class="auth-form-side" aria-labelledby="login-title">
        <div class="auth-mobile-brand">
            <a class="brand-lockup" href="index.php"><img src="assets/images/sagipbro-mark.svg" alt="" width="42" height="48"><span><strong>SAGIPBRO</strong><small>Barangay Binloc</small></span></a>
        </div>
        <div class="auth-card">
            <a class="auth-back" href="index.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to public website</a>
            <div class="auth-heading">
                <span class="eyebrow">Authorized access</span>
                <h2 id="login-title">Welcome back</h2>
                <p>Enter your account credentials to continue to the administration dashboard.</p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger app-alert" role="alert"><i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i><div><strong>Sign-in unsuccessful</strong><span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span></div></div>
            <?php endif; ?>
            <?php if ($success !== ''): ?>
                <div class="alert alert-success app-alert" role="status"><i class="bi bi-check-circle-fill" aria-hidden="true"></i><div><strong>Account ready</strong><span><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></span></div></div>
            <?php endif; ?>

            <form method="post" action="actions/auth/login.php" autocomplete="on">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <div class="mb-3">
                    <label class="form-label" for="username">Email or username</label>
                    <div class="auth-input"><i class="bi bi-person" aria-hidden="true"></i><input class="form-control" id="username" name="username" required maxlength="80" autocomplete="username" placeholder="Enter your username"></div>
                </div>
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center gap-2"><label class="form-label" for="password">Password</label><a class="auth-help" href="contact.php#send-message">Forgot password?</a></div>
                    <div class="auth-input"><i class="bi bi-lock" aria-hidden="true"></i><input class="form-control" id="password" type="password" name="password" required autocomplete="current-password" placeholder="Enter your password"><button type="button" data-password-toggle="password" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div>
                </div>
                <?php if (getenv('SAGIPBRO_RECAPTCHA_SITE_KEY')): ?>
                    <div class="g-recaptcha mb-3" data-sitekey="<?= htmlspecialchars(getenv('SAGIPBRO_RECAPTCHA_SITE_KEY'), ENT_QUOTES, 'UTF-8') ?>"></div>
                <?php else: ?>
                    <label class="human-check mb-3"><input class="form-check-input" type="checkbox" name="not_robot" value="1" required><span><strong>Security confirmation</strong><small>I'm not a robot</small></span><i class="bi bi-shield-check" aria-hidden="true"></i></label>
                <?php endif; ?>
                <button class="btn btn-brand w-100 auth-submit" type="submit">Sign in securely <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
            </form>
            <div class="auth-notice"><i class="bi bi-info-circle" aria-hidden="true"></i><p><strong>Need access?</strong> Accounts are issued by an authorized barangay administrator. Contact the barangay office for assistance.</p></div>
        </div>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
