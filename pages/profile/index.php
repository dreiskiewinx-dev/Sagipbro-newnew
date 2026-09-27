<?php
require_once '../../includes/auth_check.php';
require_once '../../config/database.php';

$profileUserId = currentUserId();
$profileRedirect = static function (string $key, string $message): void {
    header('Location: ' . appUrl('pages/profile/index.php') . '?' . http_build_query([$key => $message]));
    exit;
};
$recordProfileActivity = static function (string $action, array $details = []) use ($conn, $profileUserId): void {
    $stmt = $conn->prepare('INSERT INTO activity_logs (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$profileUserId, $action, 'user', $profileUserId, $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null, $_SERVER['REMOTE_ADDR'] ?? null]);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $intent = (string) ($_POST['intent'] ?? '');
    try {
        if ($intent === 'profile') {
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $email = filter_var(trim((string) ($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
            $position = trim((string) ($_POST['position'] ?? ''));
            $contact = trim((string) ($_POST['contact'] ?? ''));
            $language = in_array($_POST['language'] ?? '', ['English', 'Filipino'], true) ? $_POST['language'] : 'English';
            if ($fullName === '' || strlen($fullName) > 150 || $email === false || strlen($contact) > 30 || strlen($position) > 150) {
                $profileRedirect('error', 'Enter valid profile information.');
            }
            $stmt = $conn->prepare('UPDATE users SET full_name = ?, email = ?, position = ?, contact = ?, language = ? WHERE id = ?');
            $stmt->execute([$fullName, $email, $position ?: null, $contact ?: null, $language, $profileUserId]);
            $_SESSION['full_name'] = $fullName;
            $recordProfileActivity('update-profile', ['fields' => ['full_name', 'email', 'position', 'contact', 'language']]);
            $profileRedirect('success', 'Profile information updated.');
        }
        if ($intent === 'notifications') {
            $stmt = $conn->prepare('UPDATE users SET notify_stock = ?, notify_centers = ?, notify_digest = ? WHERE id = ?');
            $stmt->execute([isset($_POST['notify_stock']) ? 1 : 0, isset($_POST['notify_centers']) ? 1 : 0, isset($_POST['notify_digest']) ? 1 : 0, $profileUserId]);
            $recordProfileActivity('update-notifications');
            $profileRedirect('success', 'Notification preferences saved.');
        }
        if ($intent === 'password') {
            $stmt = $conn->prepare('SELECT password_hash FROM users WHERE id = ?');
            $stmt->execute([$profileUserId]);
            $passwordHash = (string) $stmt->fetchColumn();
            $current = (string) ($_POST['current_password'] ?? '');
            $new = (string) ($_POST['new_password'] ?? '');
            $confirm = (string) ($_POST['confirm_new_password'] ?? '');
            if (!password_verify($current, $passwordHash)) $profileRedirect('error', 'Current password is incorrect.');
            if (strlen($new) < 8 || $new !== $confirm) $profileRedirect('error', 'New passwords must match and contain at least 8 characters.');
            $stmt = $conn->prepare('UPDATE users SET password_hash = ?, force_password_change = 0, password_changed_at = NOW() WHERE id = ?');
            $stmt->execute([password_hash($new, PASSWORD_DEFAULT), $profileUserId]);
            $recordProfileActivity('change-password');
            $profileRedirect('success', 'Password changed successfully.');
        }
        $profileRedirect('error', 'Unknown profile action.');
    } catch (Throwable $e) {
        error_log('Profile update failed (' . get_class($e) . ').');
        $profileRedirect('error', 'Profile update could not be completed.');
    }
}

$profileStatement = $conn->prepare('SELECT * FROM users WHERE id = ?');
$profileStatement->execute([$profileUserId]);
$profile = $profileStatement->fetch();
if (!$profile) {
    header('Location: ' . appUrl('actions/auth/logout.php'));
    exit;
}

$profileActivityStatement = $conn->prepare(
    'SELECT action, entity_type, details, ip_address, created_at FROM activity_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 5'
);
$profileActivityStatement->execute([$profileUserId]);
$profileActivity = $profileActivityStatement->fetchAll();

$pageTitle = 'My Profile';
$pageDescription = 'Manage your SAGIPBRO administrator profile and security preferences.';
$basePath = '../../';
$isAdmin = true;
$activeAdmin = 'profile';
$profileName = trim((string) $profile['full_name']);
$profileRole = ucfirst((string) ($_SESSION['role'] ?? 'Administrator'));
$profileInitials = implode('', array_map(static fn ($part) => strtoupper(substr($part, 0, 1)), array_slice(array_filter(explode(' ', $profileName)), 0, 2)));
$profileDashboard = ($_SESSION['role'] ?? '') === 'volunteer' ? 'volunteer.php' : 'admin.php';

include '../../includes/header.php';
?>
<div class="admin-shell">
    <?php include '../../includes/sidebar.php'; ?>
    <div class="admin-main">
        <?php include '../../includes/navbar.php'; ?>
        <main class="admin-content" id="main-content">
            <?php include '../../includes/alerts.php'; ?>
            <header class="page-header">
                <div>
                    <nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="../../dashboard/<?= htmlspecialchars($profileDashboard, ENT_QUOTES, 'UTF-8') ?>">Dashboard</a></li><li class="breadcrumb-item active" aria-current="page">My profile</li></ol></nav>
                    <h1>My profile</h1>
                    <p>Keep your account details current and review your recent security activity.</p>
                </div>
                <div class="page-actions"><span class="status-badge status-success"><i class="bi bi-shield-check" aria-hidden="true"></i> Account secure</span></div>
            </header>

            <div class="profile-layout">
                <aside class="dashboard-stack" aria-label="Profile summary">
                    <section class="data-card profile-card">
                        <div class="profile-avatar-large" aria-hidden="true"><?= htmlspecialchars($profileInitials ?: 'U', ENT_QUOTES, 'UTF-8') ?></div>
                        <h2><?= htmlspecialchars($profileName, ENT_QUOTES, 'UTF-8') ?></h2>
                        <p><?= htmlspecialchars($profileRole, ENT_QUOTES, 'UTF-8') ?> · Barangay Binloc</p>
                        <span class="status-badge status-success mx-auto">Active account</span>
                        <div class="profile-details">
                            <div><span>User ID</span><strong>USR-<?= str_pad((string) $profile['id'], 4, '0', STR_PAD_LEFT) ?></strong></div>
                            <div><span>Member since</span><strong><?= htmlspecialchars(date('M Y', strtotime((string) $profile['created_at'])), ENT_QUOTES, 'UTF-8') ?></strong></div>
                            <div><span>Last sign-in</span><strong><?= $profile['last_login_at'] ? htmlspecialchars(date('M j, g:i A', strtotime((string) $profile['last_login_at'])), ENT_QUOTES, 'UTF-8') : 'Not recorded' ?></strong></div>
                            <div><span>Role</span><strong><?= htmlspecialchars($profileRole, ENT_QUOTES, 'UTF-8') ?></strong></div>
                        </div>
                    </section>

                    <section class="data-card">
                        <div class="data-card-header"><div><h2>Account health</h2><p>Security recommendations</p></div><span class="status-badge status-success">Good</span></div>
                        <div class="data-card-body">
                            <ul class="stock-warning-list">
                                <li><span class="warning-icon" style="color:#146c43;background:#e0f3e8"><i class="bi bi-check-lg" aria-hidden="true"></i></span><span><strong>Password</strong><small><?= $profile['password_changed_at'] ? 'Changed ' . htmlspecialchars(date('M j, Y', strtotime((string) $profile['password_changed_at'])), ENT_QUOTES, 'UTF-8') : 'Change date not recorded' ?></small></span><span class="stock-count" style="color:#146c43">Set</span></li>
                                <li><span class="warning-icon" style="color:#146c43;background:#e0f3e8"><i class="bi bi-envelope" aria-hidden="true"></i></span><span><strong>Account email</strong><small><?= !empty($profile['email']) ? htmlspecialchars((string) $profile['email'], ENT_QUOTES, 'UTF-8') : 'No email address recorded' ?></small></span><span class="stock-count" style="color:#146c43"><?= !empty($profile['email']) ? 'On file' : 'Missing' ?></span></li>
                                <li><span class="warning-icon"><i class="bi bi-phone" aria-hidden="true"></i></span><span><strong>Contact number</strong><small><?= !empty($profile['contact']) ? htmlspecialchars((string) $profile['contact'], ENT_QUOTES, 'UTF-8') : 'No contact number recorded' ?></small></span><span class="stock-count"><?= !empty($profile['contact']) ? 'On file' : 'Review' ?></span></li>
                            </ul>
                        </div>
                    </section>
                </aside>

                <div class="dashboard-stack">
                    <section class="data-card" aria-labelledby="personalInfoHeading">
                        <div class="data-card-header"><div><h2 id="personalInfoHeading">Personal information</h2><p>Used for identification, alerts, and account recovery.</p></div><i class="bi bi-person-vcard text-success" aria-hidden="true"></i></div>
                        <div class="data-card-body">
                            <form action="index.php" method="post">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="intent" value="profile">
                                <div class="row g-3">
                                    <div class="col-md-6"><label class="form-label" for="profileFullName">Full name <span class="required-mark">*</span></label><input class="form-control" id="profileFullName" name="full_name" value="<?= htmlspecialchars($profileName, ENT_QUOTES, 'UTF-8') ?>" required autocomplete="name"></div>
                                    <div class="col-md-6"><label class="form-label" for="profilePosition">Barangay position</label><input class="form-control" id="profilePosition" name="position" value="<?= htmlspecialchars((string) ($profile['position'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                                    <div class="col-md-6"><label class="form-label" for="profileEmail">Email address <span class="required-mark">*</span></label><input class="form-control" id="profileEmail" name="email" type="email" value="<?= htmlspecialchars((string) ($profile['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required autocomplete="email"></div>
                                    <div class="col-md-6"><label class="form-label" for="profileContact">Contact number</label><input class="form-control" id="profileContact" name="contact" type="tel" value="<?= htmlspecialchars((string) ($profile['contact'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" autocomplete="tel"></div>
                                    <div class="col-md-6"><label class="form-label" for="profileUsername">Username</label><input class="form-control" id="profileUsername" value="<?= htmlspecialchars((string) $profile['username'], ENT_QUOTES, 'UTF-8') ?>" disabled aria-describedby="usernameLockedHelp"><div class="form-text" id="usernameLockedHelp">Contact a system administrator to change your username.</div></div>
                                    <div class="col-md-6"><label class="form-label" for="profileLanguage">Interface language</label><select class="form-select" id="profileLanguage" name="language"><option<?= $profile['language'] === 'English' ? ' selected' : '' ?>>English</option><option<?= $profile['language'] === 'Filipino' ? ' selected' : '' ?>>Filipino</option></select></div>
                                </div>
                                <div class="d-flex justify-content-end mt-4"><button class="btn btn-brand" type="submit"><i class="bi bi-check-lg" aria-hidden="true"></i> Save profile</button></div>
                            </form>
                        </div>
                    </section>

                    <section class="data-card" aria-labelledby="notificationsHeading">
                        <div class="data-card-header"><div><h2 id="notificationsHeading">Notification preferences</h2><p>Choose which urgent operational changes reach you.</p></div><i class="bi bi-bell text-success" aria-hidden="true"></i></div>
                        <div class="data-card-body">
                            <form action="index.php" method="post">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="intent" value="notifications">
                                <div class="d-grid gap-3">
                                    <div class="d-flex justify-content-between align-items-start gap-3 border-bottom pb-3"><div><label class="fw-semibold small" for="notifyStock">Low-stock warnings</label><p class="small mb-0">Notify me when a resource reaches its reorder threshold.</p></div><div class="form-check form-switch"><input class="form-check-input" id="notifyStock" name="notify_stock" type="checkbox" role="switch"<?= $profile['notify_stock'] ? ' checked' : '' ?>></div></div>
                                    <div class="d-flex justify-content-between align-items-start gap-3 border-bottom pb-3"><div><label class="fw-semibold small" for="notifyCenters">Evacuation capacity alerts</label><p class="small mb-0">Notify me when a center passes 80% occupancy.</p></div><div class="form-check form-switch"><input class="form-check-input" id="notifyCenters" name="notify_centers" type="checkbox" role="switch"<?= $profile['notify_centers'] ? ' checked' : '' ?>></div></div>
                                    <div class="d-flex justify-content-between align-items-start gap-3"><div><label class="fw-semibold small" for="notifyDigest">Daily operations digest</label><p class="small mb-0">Receive a summary of distributions and activity at 5:00 PM.</p></div><div class="form-check form-switch"><input class="form-check-input" id="notifyDigest" name="notify_digest" type="checkbox" role="switch"<?= $profile['notify_digest'] ? ' checked' : '' ?>></div></div>
                                </div>
                                <div class="d-flex justify-content-end mt-4"><button class="btn btn-brand" type="submit">Save preferences</button></div>
                            </form>
                        </div>
                    </section>

                    <section class="data-card" aria-labelledby="passwordHeading">
                        <div class="data-card-header"><div><h2 id="passwordHeading">Change password</h2><p>Use at least eight characters and avoid passwords used elsewhere.</p></div><i class="bi bi-shield-lock text-success" aria-hidden="true"></i></div>
                        <div class="data-card-body">
                            <form action="index.php" method="post">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="intent" value="password">
                                <div class="row g-3">
                                    <div class="col-12"><label class="form-label" for="currentPassword">Current password <span class="required-mark">*</span></label><input class="form-control" id="currentPassword" name="current_password" type="password" required autocomplete="current-password"></div>
                                    <div class="col-md-6"><label class="form-label" for="newPassword">New password <span class="required-mark">*</span></label><input class="form-control" id="newPassword" name="new_password" type="password" required minlength="8" autocomplete="new-password"></div>
                                    <div class="col-md-6"><label class="form-label" for="confirmNewPassword">Confirm new password <span class="required-mark">*</span></label><input class="form-control" id="confirmNewPassword" name="confirm_new_password" type="password" required minlength="8" autocomplete="new-password"></div>
                                </div>
                                <div class="d-flex justify-content-end mt-4"><button class="btn btn-brand" type="submit"><i class="bi bi-key" aria-hidden="true"></i> Update password</button></div>
                            </form>
                        </div>
                    </section>

                    <section class="data-card" aria-labelledby="recentSecurityHeading">
                        <div class="data-card-header"><div><h2 id="recentSecurityHeading">Recent account activity</h2><p>Your latest sign-ins and security changes.</p></div><a href="../activity/index.php">View all logs <i class="bi bi-arrow-right" aria-hidden="true"></i></a></div>
                        <div class="data-card-body">
                            <?php if ($profileActivity): ?>
                            <ol class="timeline">
                                <?php foreach ($profileActivity as $activity):
                                    $activityLabel = ucwords(str_replace(['-', '_'], ' ', (string) $activity['action']));
                                    $activityIcon = str_contains((string) $activity['action'], 'password') ? 'bi-key' : (str_contains((string) $activity['action'], 'sign') || str_contains((string) $activity['action'], 'login') ? 'bi-box-arrow-in-right' : 'bi-person-check');
                                    $activitySource = $activity['ip_address'] ? 'Source: ' . $activity['ip_address'] : ucwords(str_replace(['_', '-'], ' ', (string) $activity['entity_type']));
                                ?>
                                <li class="timeline-item"><span class="timeline-dot"><i class="bi <?= $activityIcon ?>" aria-hidden="true"></i></span><span><strong><?= htmlspecialchars($activityLabel, ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($activitySource, ENT_QUOTES, 'UTF-8') ?></small></span><time datetime="<?= htmlspecialchars(date(DATE_ATOM, strtotime((string) $activity['created_at'])), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(date('M j, g:i A', strtotime((string) $activity['created_at'])), ENT_QUOTES, 'UTF-8') ?></time></li>
                                <?php endforeach; ?>
                            </ol>
                            <?php else: ?>
                            <div class="empty-state py-4"><i class="bi bi-clock-history"></i><h3>No account activity yet</h3><p>Your account changes will appear here when they are recorded.</p></div>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>
            </div>
        </main>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>
