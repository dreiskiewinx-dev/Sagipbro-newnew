<?php
require_once '../../includes/auth_check.php';
requireRole(['admin']);

$pageTitle = 'Users';
$pageDescription = 'Manage access, roles, and account status for SAGIPBRO personnel.';
$basePath = '../../';
$isAdmin = true;
$activeAdmin = 'users';

$users = [];

include '../../includes/header.php';
?>
<div class="admin-shell">
    <?php include '../../includes/sidebar.php'; ?>
    <div class="admin-main">
        <?php include '../../includes/navbar.php'; ?>
        <main class="admin-content" id="main-content">
            <header class="page-header">
                <div>
                    <nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="../../dashboard/admin.php">Dashboard</a></li><li class="breadcrumb-item active" aria-current="page">Users</li></ol></nav>
                    <h1>User access management</h1>
                    <p>Control system access and keep permissions aligned with each person's duties.</p>
                </div>
                <div class="page-actions">
                    <button class="btn btn-outline-brand" type="button" data-export="#usersTable" data-export-name="sagipbro-users"><i class="bi bi-download" aria-hidden="true"></i> Export users</button>
                    <button class="btn btn-brand" type="button" data-bs-toggle="modal" data-bs-target="#addUserModal"><i class="bi bi-person-plus-fill" aria-hidden="true"></i> Add user</button>
                </div>
            </header>

            <section class="stat-grid" aria-label="User account overview">
                <article class="stat-card"><div class="stat-card-top"><span class="stat-card-label">Total accounts</span><span class="stat-card-icon"><i class="bi bi-person-gear" aria-hidden="true"></i></span></div><strong class="stat-value">0</strong><span class="stat-meta">Live account records</span></article>
                <article class="stat-card info"><div class="stat-card-top"><span class="stat-card-label">Officials &amp; admins</span><span class="stat-card-icon"><i class="bi bi-shield-lock-fill" aria-hidden="true"></i></span></div><strong class="stat-value">0</strong><span class="stat-meta">Privileged accounts</span></article>
                <article class="stat-card"><div class="stat-card-top"><span class="stat-card-label">Online now</span><span class="stat-card-icon"><i class="bi bi-person-check-fill" aria-hidden="true"></i></span></div><strong class="stat-value">0</strong><span class="stat-meta">Seen within the last two minutes</span></article>
                <article class="stat-card warning"><div class="stat-card-top"><span class="stat-card-label">Inactive accounts</span><span class="stat-card-icon"><i class="bi bi-person-dash-fill" aria-hidden="true"></i></span></div><strong class="stat-value">0</strong><span class="stat-meta">Current inactive records</span></article>
            </section>

            <div class="info-callout mb-3" role="note"><i class="bi bi-info-circle-fill" aria-hidden="true"></i><div><strong>Principle of least privilege</strong><span>Assign only the role needed for each user's barangay responsibilities. All account changes are recorded in Activity Logs.</span></div></div>

            <section aria-labelledby="userAccountsHeading">
                <div class="filter-toolbar">
                    <div class="search-field"><label for="userSearch">Search accounts</label><div class="input-icon"><i class="bi bi-search" aria-hidden="true"></i><input class="form-control" id="userSearch" type="search" placeholder="Name, username, email or ID" autocomplete="off" data-table-search="#usersTable"></div></div>
                    <div class="filter-field"><label for="userRoleFilter">Access role</label><select class="form-select" id="userRoleFilter" data-filter-select="#usersTable" data-filter-field="role"><option value="">All roles</option><option value="Administrator">Administrator</option><option value="Barangay Official">Barangay Official</option><option value="Volunteer">Volunteer</option><option value="Resident">Resident</option></select></div>
                    <span class="filter-results" aria-live="polite">Loading accounts…</span>
                </div>

                <div class="data-card">
                    <div class="data-card-header"><div><h2 id="userAccountsHeading">System users</h2><p>Account identities, roles, status, and recent access.</p></div><span class="status-badge status-success">Access controls active</span></div>
                    <div class="table-responsive">
                        <table class="table app-table" id="usersTable">
                            <caption class="visually-hidden">SAGIPBRO user accounts</caption>
                            <thead><tr><th scope="col">User</th><th scope="col">Username</th><th scope="col">Role</th><th scope="col">Email</th><th scope="col">Last login</th><th scope="col">Sign-in status</th><th scope="col">Account access</th><th scope="col" class="text-end">Actions</th></tr></thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                    <?php $roleClass = $user[4] === 'Administrator' ? 'status-danger' : ($user[4] === 'Barangay Official' ? 'status-info' : ($user[4] === 'Volunteer' ? 'status-warning' : 'status-neutral')); ?>
                                    <tr data-row data-role="<?= htmlspecialchars($user[4], ENT_QUOTES, 'UTF-8') ?>" data-status="<?= htmlspecialchars($user[7], ENT_QUOTES, 'UTF-8') ?>">
                                        <td><span class="table-avatar" aria-hidden="true"><?= htmlspecialchars($user[2], ENT_QUOTES, 'UTF-8') ?></span><span class="d-inline-block align-middle"><span class="table-primary-text"><?= htmlspecialchars($user[1], ENT_QUOTES, 'UTF-8') ?></span><span class="table-secondary-text"><?= htmlspecialchars($user[0], ENT_QUOTES, 'UTF-8') ?></span></span></td>
                                        <td><span class="table-primary-text"><?= htmlspecialchars($user[3], ENT_QUOTES, 'UTF-8') ?></span></td>
                                        <td><span class="status-badge <?= $roleClass ?>"><?= htmlspecialchars($user[4], ENT_QUOTES, 'UTF-8') ?></span></td>
                                        <td><?= htmlspecialchars($user[5], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars($user[6], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><span class="status-badge <?= $user[7] === 'Active' ? 'status-success' : 'status-neutral' ?>"><?= htmlspecialchars($user[7], ENT_QUOTES, 'UTF-8') ?></span></td>
                                        <td class="text-end"><div class="table-actions" role="group" aria-label="Actions for <?= htmlspecialchars($user[1], ENT_QUOTES, 'UTF-8') ?>"><button class="btn btn-light btn-icon" type="button" title="Edit user" aria-label="Edit <?= htmlspecialchars($user[1], ENT_QUOTES, 'UTF-8') ?>" data-bs-toggle="modal" data-bs-target="#editUserModal"><i class="bi bi-pencil" aria-hidden="true"></i></button><button class="btn btn-light btn-icon" type="button" title="Reset password" aria-label="Reset password for <?= htmlspecialchars($user[1], ENT_QUOTES, 'UTF-8') ?>" data-bs-toggle="modal" data-bs-target="#resetPasswordModal"><i class="bi bi-key" aria-hidden="true"></i></button><button class="btn btn-light btn-icon text-danger" type="button" title="Deactivate user" aria-label="Deactivate <?= htmlspecialchars($user[1], ENT_QUOTES, 'UTF-8') ?>" data-bs-toggle="modal" data-bs-target="#deactivateUserModal"><i class="bi bi-person-x" aria-hidden="true"></i></button></div></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="data-card-footer record-summary"><span>Loading user accounts…</span><span>Live database records</span></div>
                </div>
            </section>
        </main>
    </div>
</div>

<div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
    <form id="addUserForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
        <div class="modal-header"><div><h2 class="modal-title" id="addUserModalLabel">Add system user</h2><p class="mb-0 mt-1 small text-body-secondary">Create secure access for an authorized SAGIPBRO user.</p></div><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-md-6"><label class="form-label" for="newUserName">Full name <span class="required-mark">*</span></label><input class="form-control" id="newUserName" name="full_name" required autocomplete="name"></div>
            <div class="col-md-6"><label class="form-label" for="newUserEmail">Email address <span class="required-mark">*</span></label><input class="form-control" id="newUserEmail" name="email" type="email" required autocomplete="email"></div>
            <div class="col-md-6"><label class="form-label" for="newUsername">Username <span class="required-mark">*</span></label><input class="form-control" id="newUsername" name="username" required autocomplete="username" aria-describedby="usernameHelp"><div class="form-text" id="usernameHelp">Use lowercase letters and a dot between names.</div></div>
            <div class="col-md-6"><label class="form-label" for="newUserRole">Access role <span class="required-mark">*</span></label><select class="form-select" id="newUserRole" name="role" required><option value="">Select role</option><option>Administrator</option><option>Barangay Official</option><option>Volunteer</option><option>Resident</option></select></div>
            <div class="col-md-6"><label class="form-label" for="newUserPassword">Temporary password <span class="required-mark">*</span></label><input class="form-control" id="newUserPassword" name="password" type="password" minlength="8" required autocomplete="new-password"></div>
            <div class="col-md-6"><label class="form-label" for="newUserConfirmPassword">Confirm password <span class="required-mark">*</span></label><input class="form-control" id="newUserConfirmPassword" name="confirm_password" type="password" minlength="8" required autocomplete="new-password"></div>
            <div class="col-12"><div class="form-check"><input class="form-check-input" id="forcePasswordChange" name="force_password_change" type="checkbox" checked><label class="form-check-label small" for="forcePasswordChange">Require a new password on first sign-in</label></div></div>
        </div></div>
        <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-brand" type="submit"><i class="bi bi-person-check" aria-hidden="true"></i> Create account</button></div>
    </form>
</div></div></div>

<div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <form id="editUserForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
        <div class="modal-header"><h2 class="modal-title" id="editUserModalLabel">Edit user access</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body"><p class="modal-intro">Update the selected account's identity, role, and access status.</p><div class="mb-3"><label class="form-label" for="editUserFullName">Full name</label><input class="form-control" id="editUserFullName" name="full_name" required></div><div class="mb-3"><label class="form-label" for="editUserEmail">Email address</label><input class="form-control" id="editUserEmail" name="email" type="email" required></div><div class="mb-3"><label class="form-label" for="editUserRole">Access role</label><select class="form-select" id="editUserRole" name="role"><option>Administrator</option><option>Barangay Official</option><option>Volunteer</option><option>Resident</option></select></div><div><label class="form-label" for="editUserStatus">Account status</label><select class="form-select" id="editUserStatus" name="status"><option>Active</option><option>Inactive</option></select></div></div>
        <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-brand" type="submit">Save permissions</button></div>
    </form>
</div></div></div>

<div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-labelledby="resetPasswordModalLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <form id="resetPasswordForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
        <div class="modal-header"><h2 class="modal-title" id="resetPasswordModalLabel">Reset user password</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body"><div class="info-callout mb-3"><i class="bi bi-shield-lock" aria-hidden="true"></i><div><strong>Secure reset</strong><span>The user will be required to replace this temporary password after signing in.</span></div></div><label class="form-label" for="temporaryPassword">Temporary password</label><input class="form-control" id="temporaryPassword" name="temporary_password" type="password" minlength="8" required autocomplete="new-password"></div>
        <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-brand" type="submit">Issue temporary password</button></div>
    </form>
</div></div></div>

<div class="modal fade" id="deactivateUserModal" tabindex="-1" aria-labelledby="deactivateUserModalLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <form id="deactivateUserForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
        <div class="modal-header"><h2 class="modal-title" id="deactivateUserModalLabel">Deactivate user account?</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body"><p class="mb-2">The selected user will no longer be able to sign in. Historical activity will remain available.</p><p class="small text-body-secondary mb-0">You can reactivate the account later from this page.</p></div>
        <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Keep active</button><button class="btn btn-danger" type="submit"><i class="bi bi-person-x" aria-hidden="true"></i> Deactivate account</button></div>
    </form>
</div></div></div>

<div class="modal fade" id="viewUserModal" tabindex="-1" aria-labelledby="viewUserModalLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h2 class="modal-title" id="viewUserModalLabel">User details</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"></div><div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Close</button></div></div></div></div>
<script>
    window.sagipbroUserApi = {
        endpoint: <?= json_encode(appUrl('api/users.php')) ?>,
        csrfToken: <?= json_encode(csrfToken()) ?>
    };
</script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/js/users-api.js"></script>
<?php include '../../includes/footer.php'; ?>
