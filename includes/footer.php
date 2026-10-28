<?php $basePath = $basePath ?? ''; $isAdmin = $isAdmin ?? false; $isAuthPage = $isAuthPage ?? false; ?>
<?php if ($isAdmin): ?>
<footer class="admin-footer">
    <span>&copy; <span data-current-year><?= date('Y') ?></span> Barangay Binloc. SAGIPBRO v1.0</span>
    <span class="d-none d-sm-inline">Secure disaster-relief operations console</span>
</footer>
<?php elseif ($isAuthPage): ?>
<footer class="auth-footer">
    <span>&copy; <span data-current-year><?= date('Y') ?></span> Barangay Binloc, Dagupan City</span>
    <span>Disaster Relief Resource Information System</span>
</footer>
<?php else: ?>
<footer class="public-footer">
    <div class="container">
        <div class="footer-grid">
            <div class="footer-about">
                <a class="brand-lockup footer-brand" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>index.php">
                    <img src="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/images/sagipbro-mark.svg" alt="" width="45" height="51">
                    <span><strong>SAGIPBRO</strong><small>Ready. Informed. Together.</small></span>
                </a>
                <p>Bonuan Binloc's central information system for relief resources, evacuation support, and timely community updates.</p>
                <span class="footer-status"><i class="bi bi-shield-check"></i> Information you can trust</span>
            </div>
            <div>
                <h2>Quick links</h2>
                <ul class="footer-links">
                    <li><a href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>about.php#about-barangay">About SAGIPBRO</a></li>
                    <li><a href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>services.php#services-list">Our services</a></li>
                    <li><a href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>announcements.php#announcement-directory">Announcements</a></li>
                    <li><a href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>resources.php#resource-directory">Available resources</a></li>
                    <li><a href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>contact.php#send-message">Contact the barangay</a></li>
                </ul>
            </div>
            <div>
                <h2>Contact</h2>
                <ul class="footer-contact">
                    <li><i class="bi bi-geo-alt"></i><span>Bonuan Binloc<br>Dagupan City, Pangasinan 2400</span></li>
                    <li><i class="bi bi-envelope"></i><span>Barangay email: <a href="mailto:barangaybonuanbinloc@gmail.com">barangaybonuanbinloc@gmail.com</a></span></li>
                    <li><i class="bi bi-telephone"></i><span>Barangay hall hotlines:<br><a href="tel:+639631743346">+63 963 174 3346</a> / <a href="tel:+639632173031">+63 963 217 3031</a></span></li>
                    <li><i class="bi bi-envelope"></i><span>City inquiries: <a href="mailto:dagupanlgu@gmail.com">dagupanlgu@gmail.com</a></span></li>
                    <li><i class="bi bi-telephone"></i><span>City CDRRMO: <a href="tel:+639684449598">+63 968 444 9598</a></span></li>
                </ul>
            </div>
            <div class="footer-emergency">
                <h2>Need urgent help?</h2>
                <p>For urgent disaster and medical response in Dagupan City, contact the CDRRMO immediately.</p>
                <a class="btn btn-light w-100" href="tel:+639684449598"><i class="bi bi-telephone-fill"></i> Call CDRRMO</a>
                <small>Available 24 hours a day</small>
            </div>
        </div>
        <div class="footer-bottom">
            <span>&copy; <span data-current-year><?= date('Y') ?></span> Bonuan Binloc, Dagupan City</span>
            <span>Disaster Relief Resource Information System</span>
        </div>
    </div>
</footer>
<?php endif; ?>
<?php if ($showFloatingAnnouncement ?? false) include __DIR__ . '/floating_announcement.php'; ?>
<?php if (function_exists('isLoggedIn') && isLoggedIn()): ?>
<div class="modal fade logout-confirm-modal" id="logoutConfirmModal" tabindex="-1" aria-labelledby="logoutConfirmTitle" aria-describedby="logoutConfirmDescription" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-body">
                <span class="logout-confirm-icon" aria-hidden="true"><i class="bi bi-box-arrow-right"></i></span>
                <span class="logout-confirm-kicker">Secure sign out</span>
                <h2 id="logoutConfirmTitle">Ready to leave?</h2>
                <p id="logoutConfirmDescription">Are you sure you want to log out of SAGIPBRO? You will need to sign in again to access your dashboard.</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" type="button" data-bs-dismiss="modal">Stay signed in</button>
                <button class="btn btn-danger" type="button" data-confirm-logout-submit><i class="bi bi-box-arrow-left" aria-hidden="true"></i> Yes, log out</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php if (function_exists('isLoggedIn') && isLoggedIn()): ?>
<div class="modal fade" id="sessionExpiryModal" tabindex="-1" aria-labelledby="sessionExpiryTitle" aria-describedby="sessionExpiryMessage" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><h2 id="sessionExpiryTitle" class="modal-title h5">Your session will expire soon</h2></div>
        <div class="modal-body"><p id="sessionExpiryMessage">Stay signed in to continue working.</p><p class="mb-0">Time remaining: <strong data-session-countdown role="timer"></strong></p><p class="small text-danger mt-2 mb-0" data-session-error role="alert" hidden></p></div>
        <div class="modal-footer">
            <form method="post" action="<?= htmlspecialchars(appUrl('actions/auth/logout.php'), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>"><button type="submit" class="btn btn-light">Sign out</button></form>
            <button type="button" class="btn btn-brand" data-session-continue>Stay signed in</button>
        </div>
    </div></div>
</div>
<script>
window.sagipbroSession = <?= json_encode([
    'endpoint' => appUrl('api/session.php'),
    'loginUrl' => appUrl('login.php'),
    'passwordChangeUrl' => appUrl('pages/profile/change-password.php'),
    'csrfToken' => csrfToken(),
    'timing' => $_SESSION['session_timing'],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<?php endif; ?>
<div class="toast-container position-fixed bottom-0 end-0 p-3">
    <div id="appToast" class="toast border-0 shadow" role="status" aria-live="polite" aria-atomic="true">
        <div class="toast-header"><span class="toast-icon"><i class="bi bi-check-lg"></i></span><strong class="me-auto">SAGIPBRO</strong><button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button></div>
        <div class="toast-body">Action completed successfully.</div>
    </div>
</div>
<?php if (function_exists('isLoggedIn') && isLoggedIn() && !passwordChangeRequired()): ?>
<script>
window.sagipbroPresence = {
    endpoint: <?= json_encode(appUrl('api/presence.php')) ?>,
    csrfToken: <?= json_encode(csrfToken()) ?>
};
</script>
<?php endif; ?>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/third-party/bootstrap/bootstrap.bundle.min.js?v=5.3.3"></script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/js/script.js"></script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/js/session-security.js?v=1"></script>
<?php if ($isAdmin): ?><script src="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/js/crud-view.js?v=9"></script><?php endif; ?>
<?php if ($isAdmin): ?><script src="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/js/dashboard.js"></script><?php endif; ?>
<?php if ($isAdmin): ?><script src="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/js/notifications.js?v=4"></script><?php endif; ?>
<script>
window.requestAnimationFrame(() => {
    window.requestAnimationFrame(() => {
        window.sagipbroRestorePagePosition?.();
        document.documentElement.classList.remove('app-loading');
    });
});
</script>
</body>
</html>
