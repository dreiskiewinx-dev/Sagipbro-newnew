<?php
// Rendered by index.php after authentication and loading the current user's account.
if (!isset($profile, $isResidentProfile) || !$isResidentProfile) {
    http_response_code(404);
    exit;
}
$pageTitle = 'Update account';
$isPortalPage = true;
$pageDescription = 'Update your SAGIPBRO resident account information and password.';
$basePath = '../../';
$activePage = 'account';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/navbar.php';
?>
<main id="main-content">
    <section class="page-hero" aria-labelledby="account-title">
        <div class="container">
            <span class="hero-chip"><span aria-hidden="true"></span> Resident portal</span>
            <h1 id="account-title">Update account</h1>
            <p>Keep your personal information current and manage your password.</p>
            <a class="btn btn-ghost-light mt-3" href="../../dashboard/resident.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to resident portal</a>
        </div>
    </section>
    <section class="section-space section-soft">
        <div class="container">
            <?php require __DIR__ . '/../../includes/alerts.php'; ?>
            <div class="row g-4 align-items-start">
                <div class="col-lg-7">
                    <section class="surface-card" aria-labelledby="account-information-title">
                        <div class="surface-card-header"><div><h2 id="account-information-title">Personal information</h2><p>Manage the details saved on your account.</p></div><i class="bi bi-person-vcard" aria-hidden="true"></i></div>
                        <div class="surface-card-body">
                            <form action="index.php" method="post">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="intent" value="profile">
                                <div class="row g-3">
                                    <div class="col-12"><label class="form-label" for="accountFullName">Full name <span class="required-mark">*</span></label><input class="form-control" id="accountFullName" name="full_name" value="<?= htmlspecialchars((string) $profile['full_name'], ENT_QUOTES, 'UTF-8') ?>" maxlength="150" required autocomplete="name"></div>
                                    <div class="col-12"><label class="form-label" for="accountEmail">Email address <span class="required-mark">*</span></label><input class="form-control" id="accountEmail" name="email" type="email" value="<?= htmlspecialchars((string) ($profile['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" maxlength="160" required autocomplete="email"></div>
                                    <div class="col-md-6"><label class="form-label" for="accountContact">Contact number</label><input class="form-control" id="accountContact" name="contact" type="tel" value="<?= htmlspecialchars((string) ($profile['contact'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" maxlength="30" autocomplete="tel" aria-describedby="accountContactHelp"><div class="form-text" id="accountContactHelp">For example, 09171234567 or +639171234567.</div></div>
                                    <div class="col-md-6"><label class="form-label" for="accountUsername">Username</label><input class="form-control" id="accountUsername" value="<?= htmlspecialchars((string) $profile['username'], ENT_QUOTES, 'UTF-8') ?>" readonly autocomplete="username" aria-describedby="accountUsernameHelp"><div class="form-text" id="accountUsernameHelp">Your sign-in username stays the same.</div></div>
                                </div>
                                <p class="small text-secondary mt-4">For corrections to your official household record, <a href="../../contact.php#send-message">contact the barangay office</a>.</p>
                                <button class="btn btn-brand" type="submit"><i class="bi bi-check-lg" aria-hidden="true"></i> Save account changes</button>
                            </form>
                        </div>
                    </section>
                </div>
                <div class="col-lg-5">
                    <section class="surface-card" aria-labelledby="account-password-title">
                        <div class="surface-card-header"><div><h2 id="account-password-title">Change password</h2><p>Use at least 8 characters. Spaces and passphrases are welcome.</p></div><i class="bi bi-shield-lock" aria-hidden="true"></i></div>
                        <div class="surface-card-body">
                            <form action="index.php" method="post">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="intent" value="password">
                                <div class="mb-3"><label class="form-label" for="accountCurrentPassword">Current password <span class="required-mark">*</span></label><div class="input-group"><input class="form-control" id="accountCurrentPassword" name="current_password" type="password" required autocomplete="current-password"><button class="btn btn-outline-secondary" type="button" data-password-toggle="accountCurrentPassword" aria-label="Show current password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div></div>
                                <div class="mb-3"><label class="form-label" for="accountNewPassword">New password <span class="required-mark">*</span></label><div class="input-group"><input class="form-control" id="accountNewPassword" name="new_password" type="password" required minlength="8" maxlength="72" autocomplete="new-password"><button class="btn btn-outline-secondary" type="button" data-password-toggle="accountNewPassword" aria-label="Show new password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div></div>
                                <div class="mb-3"><label class="form-label" for="accountConfirmPassword">Confirm new password <span class="required-mark">*</span></label><div class="input-group"><input class="form-control" id="accountConfirmPassword" name="confirm_new_password" type="password" required minlength="8" maxlength="72" autocomplete="new-password"><button class="btn btn-outline-secondary" type="button" data-password-toggle="accountConfirmPassword" aria-label="Show password confirmation" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div></div>
                                <p class="small text-secondary">After changing your password, sign in again using your new password.</p>
                                <button class="btn btn-brand" type="submit"><i class="bi bi-key" aria-hidden="true"></i> Update password</button>
                            </form>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </section>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
