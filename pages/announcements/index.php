<?php
require_once '../../includes/auth_check.php';
requireRole(['admin', 'official']);

$pageTitle = 'Announcements';
$pageDescription = 'Create, publish, and maintain trusted community advisories through SAGIPBRO.';
$basePath = '../../';
$isAdmin = true;
$activeAdmin = 'announcements';

$announcements = [];

$statusClasses = ['Published' => 'status-success', 'Draft' => 'status-warning', 'Archived' => 'status-neutral'];
$categoryIcons = ['Emergency' => 'bi-exclamation-triangle-fill', 'Distribution' => 'bi-box-seam-fill', 'Advisory' => 'bi-info-circle-fill', 'Evacuation' => 'bi-buildings-fill', 'Operations' => 'bi-people-fill'];

include '../../includes/header.php';
?>

<div class="admin-shell">
    <?php include '../../includes/sidebar.php'; ?>
    <div class="admin-main">
        <?php include '../../includes/navbar.php'; ?>

        <main id="main-content" class="admin-content">
            <header class="page-header">
                <div>
                    <nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= $basePath ?>dashboard/admin.php">Dashboard</a></li><li class="breadcrumb-item active" aria-current="page">Announcements</li></ol></nav>
                    <h1>Announcements</h1>
                    <p>Prepare clear, verified advisories and control what information is visible to the community.</p>
                </div>
                <div class="page-actions">
                    <button class="btn btn-outline-brand" type="button" data-bs-toggle="modal" data-bs-target="#previewGuidelinesModal"><i class="bi bi-journal-check" aria-hidden="true"></i> Publishing guide</button>
                    <button class="btn btn-brand" type="button" data-bs-toggle="modal" data-bs-target="#addAnnouncementModal"><i class="bi bi-plus-lg" aria-hidden="true"></i> Create announcement</button>
                </div>
            </header>

            <section class="filter-toolbar" aria-label="Announcement table filters">
                <div class="search-field">
                    <label for="announcementSearch">Search announcements</label>
                    <div class="input-group"><span class="input-group-text bg-white border-end-0"><i class="bi bi-search" aria-hidden="true"></i></span><input class="form-control border-start-0" id="announcementSearch" type="search" placeholder="Search title, content, or author" autocomplete="off" data-table-search="#announcementsTable"></div>
                </div>
                <div class="filter-field">
                    <label for="announcementCategory">Category</label>
                    <select class="form-select" id="announcementCategory" data-filter-select="#announcementsTable" data-filter-field="category"><option value="">All categories</option><option>Emergency</option><option>Distribution</option><option>Advisory</option><option>Evacuation</option><option>Operations</option></select>
                </div>
                <div class="filter-field">
                    <label for="announcementStatus">Status</label>
                    <select class="form-select" id="announcementStatus" data-filter-select="#announcementsTable" data-filter-field="status"><option value="">All statuses</option><option>Published</option><option>Draft</option><option>Archived</option></select>
                </div>
                <div class="filter-field">
                    <label for="announcementAudience">Audience</label>
                    <select class="form-select" id="announcementAudience" data-filter-select="#announcementsTable" data-filter-field="audience"><option value="">All audiences</option><option>All residents</option><option>Zones 1–3</option><option>Sitio Korea</option><option>Evacuees</option><option>Volunteers</option><option>Motorists</option></select>
                </div>
                <span class="filter-results" aria-live="polite" data-filter-results><?= count($announcements) ?> announcements</span>
            </section>

            <section class="data-card" aria-labelledby="announcementRegisterHeading">
                <div class="data-card-header">
                    <div><h2 id="announcementRegisterHeading">Community announcement register</h2><p>Published notices appear on the public information feed.</p></div>
                    <span class="status-badge status-info" data-announcement-published>0 currently published</span>
                </div>
                <div class="table-responsive">
                    <table class="table app-table align-middle" id="announcementsTable" data-table>
                        <thead><tr><th scope="col">Title and content</th><th scope="col">Category</th><th scope="col">Audience</th><th scope="col">Status</th><th scope="col">Date</th><th scope="col">Author</th><th scope="col" class="text-end">Actions</th></tr></thead>
                        <tbody>
                            <?php foreach ($announcements as $announcement): ?>
                                <tr data-row data-category="<?= htmlspecialchars($announcement['category'], ENT_QUOTES, 'UTF-8') ?>" data-status="<?= htmlspecialchars($announcement['status'], ENT_QUOTES, 'UTF-8') ?>" data-audience="<?= htmlspecialchars($announcement['audience'], ENT_QUOTES, 'UTF-8') ?>">
                                    <td style="min-width: 280px; max-width: 420px;"><span class="table-primary-text"><?= htmlspecialchars($announcement['title'], ENT_QUOTES, 'UTF-8') ?></span><span class="table-secondary-text text-truncate" style="max-width: 390px;"><?= htmlspecialchars($announcement['content'], ENT_QUOTES, 'UTF-8') ?></span><span class="table-secondary-text"><?= htmlspecialchars($announcement['id'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td><span class="d-inline-flex align-items-center gap-2"><i class="bi <?= htmlspecialchars($categoryIcons[$announcement['category']] ?? 'bi-megaphone-fill', ENT_QUOTES, 'UTF-8') ?> text-success" aria-hidden="true"></i><?= htmlspecialchars($announcement['category'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td><?= htmlspecialchars($announcement['audience'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><span class="status-badge <?= $statusClasses[$announcement['status']] ?>"><?= htmlspecialchars($announcement['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td><span class="table-primary-text"><?= htmlspecialchars($announcement['date'], ENT_QUOTES, 'UTF-8') ?></span><span class="table-secondary-text"><?= htmlspecialchars($announcement['time'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td><?= htmlspecialchars($announcement['author'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="text-end"><div class="table-actions" role="group" aria-label="Actions for <?= htmlspecialchars($announcement['title'], ENT_QUOTES, 'UTF-8') ?>">
                                        <button class="btn btn-light btn-icon" type="button" title="View" aria-label="View <?= htmlspecialchars($announcement['title'], ENT_QUOTES, 'UTF-8') ?>" data-bs-toggle="modal" data-bs-target="#viewAnnouncementModal" data-record-id="<?= htmlspecialchars($announcement['id'], ENT_QUOTES, 'UTF-8') ?>" data-record-name="<?= htmlspecialchars($announcement['title'], ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-eye" aria-hidden="true"></i></button>
                                        <button class="btn btn-light btn-icon" type="button" title="Edit" aria-label="Edit <?= htmlspecialchars($announcement['title'], ENT_QUOTES, 'UTF-8') ?>" data-bs-toggle="modal" data-bs-target="#editAnnouncementModal" data-record-id="<?= htmlspecialchars($announcement['id'], ENT_QUOTES, 'UTF-8') ?>" data-record-name="<?= htmlspecialchars($announcement['title'], ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-pencil" aria-hidden="true"></i></button>
                                        <button class="btn btn-light btn-icon text-danger" type="button" title="Archive" aria-label="Archive <?= htmlspecialchars($announcement['title'], ENT_QUOTES, 'UTF-8') ?>" data-bs-toggle="modal" data-bs-target="#deleteAnnouncementModal" data-record-id="<?= htmlspecialchars($announcement['id'], ENT_QUOTES, 'UTF-8') ?>" data-record-name="<?= htmlspecialchars($announcement['title'], ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-archive" aria-hidden="true"></i></button>
                                    </div></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="data-card-footer record-summary"><span data-announcement-summary>Showing 0 of 0 announcements</span><nav aria-label="Announcement pagination"><ul class="pagination pagination-sm"><li class="page-item disabled"><button class="page-link" type="button" aria-label="Previous page"><i class="bi bi-chevron-left"></i></button></li><li class="page-item active" aria-current="page"><button class="page-link" type="button">1</button></li><li class="page-item"><button class="page-link" type="button" aria-label="Next page"><i class="bi bi-chevron-right"></i></button></li></ul></nav></div>
            </section>

            <div class="modal fade" id="addAnnouncementModal" tabindex="-1" aria-labelledby="addAnnouncementTitle" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><form class="modal-content" id="addAnnouncementForm">
                    <div class="modal-header"><h2 class="modal-title" id="addAnnouncementTitle">Create announcement</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
                    <div class="modal-body"><p class="modal-intro">Use plain language, state the affected area, and include only confirmed instructions.</p><div class="row g-3">
                        <div class="col-12"><label class="form-label" for="addAnnouncementHeading">Title</label><input class="form-control" id="addAnnouncementHeading" name="title" maxlength="180" placeholder="Clear and specific announcement title" required></div>
                        <div class="col-12"><label class="form-label" for="addAnnouncementContent">Content</label><textarea class="form-control" id="addAnnouncementContent" name="content" rows="6" placeholder="What happened, who is affected, what residents should do, and where updates will be posted" required></textarea><div class="form-text">Avoid all caps except for short, urgent labels.</div></div>
                        <div class="col-md-4"><label class="form-label" for="addAnnouncementCategory">Category</label><select class="form-select" id="addAnnouncementCategory" name="category" required><option value="" selected disabled>Select category</option><option>Emergency</option><option>Distribution</option><option>Advisory</option><option>Evacuation</option><option>Operations</option></select></div>
                        <div class="col-md-4"><label class="form-label" for="addAnnouncementAudience">Audience</label><select class="form-select" id="addAnnouncementAudience" name="audience" required><option>All residents</option><option>Selected zones</option><option>Evacuees</option><option>Volunteers</option><option>Barangay officials</option></select></div>
                        <div class="col-md-4"><label class="form-label" for="addAnnouncementStatus">Status</label><select class="form-select" id="addAnnouncementStatus" name="status"><option>Draft</option><option>Published</option></select></div>
                        <div class="col-md-6"><label class="form-label" for="addAnnouncementDate">Publish date and time</label><input class="form-control" id="addAnnouncementDate" name="published_at" type="datetime-local" value="<?= htmlspecialchars(date('Y-m-d\TH:i'), ENT_QUOTES, 'UTF-8') ?>"></div>
                        <div class="col-md-6"><label class="form-label" for="addAnnouncementAuthor">Author</label><input class="form-control" id="addAnnouncementAuthor" name="author" value="<?= htmlspecialchars((string) ($_SESSION['full_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" readonly></div>
                    </div><div class="info-callout mt-3"><i class="bi bi-shield-check"></i><div><strong>Verification reminder</strong><span>Emergency announcements should be approved by the incident lead before publication.</span></div></div></div>
                    <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-brand" type="submit"><i class="bi bi-check-lg" aria-hidden="true"></i> Confirm announcement</button></div>
                </form></div>
            </div>

            <div class="modal fade" id="viewAnnouncementModal" tabindex="-1" aria-labelledby="viewAnnouncementTitle" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
                    <div class="modal-header"><h2 class="modal-title" id="viewAnnouncementTitle">Announcement preview</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
                    <div class="modal-body"><div class="empty-state py-4"><i class="bi bi-megaphone"></i><h3>No announcement selected</h3><p>Announcement details are loaded from the database.</p></div></div>
                    <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Close</button><button class="btn btn-outline-brand" type="button" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#editAnnouncementModal"><i class="bi bi-pencil"></i> Edit announcement</button></div>
                </div></div>
            </div>

            <div class="modal fade" id="editAnnouncementModal" tabindex="-1" aria-labelledby="editAnnouncementTitle" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><form class="modal-content" id="editAnnouncementForm">
                    <div class="modal-header"><h2 class="modal-title" id="editAnnouncementTitle">Edit announcement</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
                    <div class="modal-body"><p class="modal-intro">Editing a published announcement will create a new activity-log entry.</p><div class="row g-3">
                        <div class="col-12"><label class="form-label" for="editAnnouncementHeading">Title</label><input class="form-control" id="editAnnouncementHeading" name="title" maxlength="180" required></div>
                        <div class="col-12"><label class="form-label" for="editAnnouncementContent">Content</label><textarea class="form-control" id="editAnnouncementContent" name="content" rows="6" required></textarea></div>
                        <div class="col-md-4"><label class="form-label" for="editAnnouncementCategory">Category</label><select class="form-select" id="editAnnouncementCategory" name="category"><option>Emergency</option><option>Distribution</option><option>Advisory</option><option>Evacuation</option><option>Operations</option></select></div>
                        <div class="col-md-4"><label class="form-label" for="editAnnouncementAudience">Audience</label><select class="form-select" id="editAnnouncementAudience" name="audience"><option>All residents</option><option>Selected zones</option><option>Evacuees</option><option>Volunteers</option></select></div>
                        <div class="col-md-4"><label class="form-label" for="editAnnouncementStatus">Status</label><select class="form-select" id="editAnnouncementStatus" name="status"><option>Draft</option><option>Published</option></select></div>
                        <div class="col-md-6"><label class="form-label" for="editAnnouncementDate">Publish date and time</label><input class="form-control" id="editAnnouncementDate" name="published_at" type="datetime-local"></div>
                        <div class="col-md-6"><label class="form-label" for="editAnnouncementRevision">Revision note</label><input class="form-control" id="editAnnouncementRevision" name="revision_note" placeholder="Brief reason for this update"></div>
                    </div></div>
                    <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-brand" type="submit"><i class="bi bi-check-lg"></i> Save changes</button></div>
                </form></div>
            </div>

            <div class="modal fade" id="deleteAnnouncementModal" tabindex="-1" aria-labelledby="deleteAnnouncementTitle" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form id="deleteAnnouncementForm">
                    <div class="modal-header"><h2 class="modal-title" id="deleteAnnouncementTitle">Archive announcement?</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
                    <div class="modal-body"><p class="mb-2">Archive the selected announcement?</p><p class="small text-muted mb-0">It will be removed from the public feed but retained in the announcement register and activity log.</p></div>
                    <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Keep published</button><button class="btn btn-danger" type="submit" data-confirm-action="Announcement archived."><i class="bi bi-archive"></i> Archive</button></div>
                </form></div></div>
            </div>

            <div class="modal fade" id="previewGuidelinesModal" tabindex="-1" aria-labelledby="previewGuidelinesTitle" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
                    <div class="modal-header"><h2 class="modal-title" id="previewGuidelinesTitle">Safe publishing checklist</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
                    <div class="modal-body"><ul class="list-group list-group-flush small"><li class="list-group-item px-0"><i class="bi bi-check-circle-fill text-success me-2"></i>Confirm the source and approval authority.</li><li class="list-group-item px-0"><i class="bi bi-check-circle-fill text-success me-2"></i>Name the affected area and effective time.</li><li class="list-group-item px-0"><i class="bi bi-check-circle-fill text-success me-2"></i>Give one clear action residents should take.</li><li class="list-group-item px-0"><i class="bi bi-check-circle-fill text-success me-2"></i>Include a verified hotline for urgent concerns.</li><li class="list-group-item px-0"><i class="bi bi-check-circle-fill text-success me-2"></i>Archive the notice when it is no longer current.</li></ul></div>
                    <div class="modal-footer"><button class="btn btn-brand" type="button" data-bs-dismiss="modal">Understood</button></div>
                </div></div>
            </div>
        </main>
    </div>
</div>

<script>
    window.sagipbroAnnouncementApi = {
        endpoint: <?= json_encode(appUrl('api/announcements.php')) ?>,
        csrfToken: <?= json_encode(csrfToken()) ?>
    };
</script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/js/announcements-api.js"></script>
<?php include '../../includes/footer.php'; ?>
