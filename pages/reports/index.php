<?php
require_once '../../includes/auth_check.php';
require_once '../../config/database.php';
requireRole(['admin', 'official']);

$pageTitle = 'Reports';
$pageDescription = 'Generate operational reports for Barangay Binloc disaster response.';
$basePath = '../../';
$isAdmin = true;
$activeAdmin = 'reports';

$reports = [
    ['resource-stock', 'Resource stock', 'bi-box-seam', 'Current inventory quantities and low-stock conditions.', 'Live', 'Resources', 'Current data', 'resources'],
    ['distribution-summary', 'Distribution summary', 'bi-truck', 'Relief releases grouped by resource and recipient.', 'Live', 'Distributions', 'Current data', 'distribution'],
    ['evacuation-capacity', 'Evacuation capacity', 'bi-buildings', 'Center occupancy, capacity, and available spaces.', 'Live', 'Centers', 'Current data', 'evacuation'],
    ['resident-summary', 'Resident summary', 'bi-people', 'Active resident and vulnerability totals.', 'Live', 'Residents', 'Current data', 'residents'],
];
$reportStats = ['records' => 0, 'resources' => 0, 'low_stock' => 0];
$previewResources = [];
$recentReports = [];
$resourceCategories = [];
$validDate = static function ($value): bool {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return false;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};
$reportFrom = $validDate($_GET['from'] ?? null) ? (string) $_GET['from'] : date('Y-m-01');
$reportTo = $validDate($_GET['to'] ?? null) ? (string) $_GET['to'] : date('Y-m-d');
if ($reportFrom > $reportTo) [$reportFrom, $reportTo] = [$reportTo, $reportFrom];
$reportCoverage = is_string($_GET['coverage'] ?? null) ? trim((string) $_GET['coverage']) : '';
try {
    $resourceCategories = $conn->query("SELECT DISTINCT category FROM resources WHERE status <> 'Inactive' AND category <> '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
    if ($reportCoverage !== '' && !in_array($reportCoverage, $resourceCategories, true)) $reportCoverage = '';
    $periodStart = $reportFrom . ' 00:00:00';
    $periodEnd = (new DateTimeImmutable($reportTo))->modify('+1 day')->format('Y-m-d 00:00:00');
    $categorySql = $reportCoverage !== '' ? ' AND category = ?' : '';
    $resourceParams = [$periodStart, $periodEnd];
    if ($reportCoverage !== '') $resourceParams[] = $reportCoverage;

    $resourceStats = $conn->prepare("SELECT COUNT(*) AS records, COALESCE(SUM(stock), 0) AS resources,
        COALESCE(SUM(stock <= low_stock_threshold), 0) AS low_stock FROM resources
        WHERE status <> 'Inactive' AND updated_at >= ? AND updated_at < ?{$categorySql}");
    $resourceStats->execute($resourceParams);
    $resourceSummary = $resourceStats->fetch() ?: [];

    $distributionCategorySql = $reportCoverage !== '' ? ' AND r.category = ?' : '';
    $distributionParams = [$periodStart, $periodEnd];
    if ($reportCoverage !== '') $distributionParams[] = $reportCoverage;
    $distributionCount = $conn->prepare("SELECT COUNT(*) FROM distributions d JOIN resources r ON r.id = d.resource_id
        WHERE d.distributed_at >= ? AND d.distributed_at < ?{$distributionCategorySql}");
    $distributionCount->execute($distributionParams);
    $centerCount = $conn->prepare('SELECT COUNT(*) FROM evacuation_centers WHERE updated_at >= ? AND updated_at < ?');
    $centerCount->execute([$periodStart, $periodEnd]);
    $residentCount = $conn->prepare("SELECT COUNT(*) FROM residents WHERE status = 'Active' AND updated_at >= ? AND updated_at < ?");
    $residentCount->execute([$periodStart, $periodEnd]);
    $reportStats = [
        'records' => (int) ($resourceSummary['records'] ?? 0) + (int) $distributionCount->fetchColumn() + (int) $centerCount->fetchColumn() + (int) $residentCount->fetchColumn(),
        'resources' => (int) ($resourceSummary['resources'] ?? 0),
        'low_stock' => (int) ($resourceSummary['low_stock'] ?? 0),
    ];

    $previewStatement = $conn->prepare("SELECT name, category, stock, unit, low_stock_threshold,
        CASE WHEN stock = 0 THEN 'Out of stock' WHEN stock <= low_stock_threshold THEN 'Low stock' ELSE 'In stock' END AS stock_status
        FROM resources WHERE status <> 'Inactive' AND updated_at >= ? AND updated_at < ?{$categorySql} ORDER BY name");
    $previewStatement->execute($resourceParams);
    $previewResources = $previewStatement->fetchAll();

    $history = $conn->query("SELECT l.id, l.details, l.created_at, COALESCE(u.full_name, 'System user') AS generated_by
        FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id
        WHERE l.action = 'generate' AND l.entity_type = 'report'
        ORDER BY l.created_at DESC, l.id DESC LIMIT 20")->fetchAll();
    $allowedReports = ['overview', 'resources', 'distribution', 'evacuation', 'residents', 'activity', 'summary', 'custom'];
    foreach ($history as $entry) {
        $details = json_decode((string) $entry['details'], true);
        if (!is_array($details) || !in_array($details['report'] ?? '', $allowedReports, true)) continue;
        $recentReports[] = [
            'title' => (string) ($details['title'] ?? 'SAGIPBRO report'),
            'report' => (string) $details['report'],
            'from' => $validDate($details['from'] ?? null) ? (string) $details['from'] : date('Y-m-01'),
            'to' => $validDate($details['to'] ?? null) ? (string) $details['to'] : date('Y-m-d'),
            'coverage' => is_string($details['coverage'] ?? null) ? (string) $details['coverage'] : '',
            'format' => (string) ($details['format'] ?? 'CSV'),
            'sections' => is_array($details['sections'] ?? null) ? array_values(array_map('strval', $details['sections'])) : [],
            'generated_by' => (string) $entry['generated_by'],
            'created_at' => (string) $entry['created_at'],
        ];
    }
} catch (Throwable $e) {
    error_log('Reports page data unavailable (' . get_class($e) . ').');
}

include '../../includes/header.php';
?>
<div class="admin-shell">
    <?php include '../../includes/sidebar.php'; ?>
    <div class="admin-main">
        <?php include '../../includes/navbar.php'; ?>
        <main class="admin-content" id="main-content">
            <header class="page-header">
                <div>
                    <nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="../../dashboard/admin.php">Dashboard</a></li><li class="breadcrumb-item active" aria-current="page">Reports</li></ol></nav>
                    <h1>Reports center</h1>
                    <p>Turn current relief information into clear, printable operational summaries.</p>
                </div>
                <div class="page-actions"><button class="btn btn-outline-brand" type="button" data-print="#reportCatalog"><i class="bi bi-printer" aria-hidden="true"></i> Print overview</button><button class="btn btn-brand" type="button" data-report-export data-report-url="<?= htmlspecialchars(appUrl('api/reports.php?' . http_build_query(['report' => 'overview', 'export' => 'csv', 'from' => $reportFrom, 'to' => $reportTo, 'coverage' => $reportCoverage])), ENT_QUOTES, 'UTF-8') ?>" data-report-title="Reports overview"><i class="bi bi-file-earmark-arrow-down" aria-hidden="true"></i> Export overview</button></div>
            </header>

            <section class="stat-grid" aria-label="Reporting overview">
                <article class="stat-card"><div class="stat-card-top"><span class="stat-card-label">Reports available</span><span class="stat-card-icon"><i class="bi bi-file-earmark-bar-graph-fill" aria-hidden="true"></i></span></div><strong class="stat-value"><?= count($reports) ?></strong><span class="stat-meta">Live operational report types</span></article>
                <article class="stat-card info"><div class="stat-card-top"><span class="stat-card-label">Records summarized</span><span class="stat-card-icon"><i class="bi bi-database-fill-check" aria-hidden="true"></i></span></div><strong class="stat-value"><?= (int) $reportStats['records'] ?></strong><span class="stat-meta">Records matching the selected filters</span></article>
                <article class="stat-card"><div class="stat-card-top"><span class="stat-card-label">Last data sync</span><span class="stat-card-icon"><i class="bi bi-arrow-repeat" aria-hidden="true"></i></span></div><strong class="stat-value"><?= htmlspecialchars(date('g:i A'), ENT_QUOTES, 'UTF-8') ?></strong><span class="stat-meta">Loaded from the live database</span></article>
                <article class="stat-card warning"><div class="stat-card-top"><span class="stat-card-label">Scheduled reports</span><span class="stat-card-icon"><i class="bi bi-calendar-check-fill" aria-hidden="true"></i></span></div><strong class="stat-value">0</strong><span class="stat-meta">No scheduled reports yet</span></article>
            </section>

            <form class="filter-toolbar" action="index.php" method="get">
                <div class="filter-field"><label for="reportDateFrom">From</label><input class="form-control" id="reportDateFrom" name="from" type="date" value="<?= htmlspecialchars($reportFrom, ENT_QUOTES, 'UTF-8') ?>" required></div>
                <div class="filter-field"><label for="reportDateTo">To</label><input class="form-control" id="reportDateTo" name="to" type="date" value="<?= htmlspecialchars($reportTo, ENT_QUOTES, 'UTF-8') ?>" required></div>
                <div class="filter-field"><label for="reportArea">Resource category</label><select class="form-select" id="reportArea" name="coverage"><option value="">All categories</option><?php foreach ($resourceCategories as $category): ?><option value="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>"<?= $reportCoverage === $category ? ' selected' : '' ?>><?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                <button class="btn btn-brand" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Apply filters</button>
                <span class="filter-results">Reporting period: <?= htmlspecialchars(date('M j, Y', strtotime($reportFrom)), ENT_QUOTES, 'UTF-8') ?>–<?= htmlspecialchars(date('M j, Y', strtotime($reportTo)), ENT_QUOTES, 'UTF-8') ?></span>
            </form>

            <section id="reportCatalog" aria-labelledby="reportCatalogHeading">
                <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3"><div><h2 class="h5 mb-1" id="reportCatalogHeading">Operational report types</h2><p class="small mb-0">Choose a report to preview, print, or export as a CSV file.</p></div><span class="status-badge status-success">Data ready</span></div>
                <div class="report-grid">
                    <?php foreach ($reports as $report): ?>
                        <article class="report-card" id="<?= htmlspecialchars($report[0], ENT_QUOTES, 'UTF-8') ?>">
                            <div class="report-card-top"><span class="report-icon"><i class="bi <?= htmlspecialchars($report[2], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i></span><span class="report-frequency"><?= htmlspecialchars($report[4], ENT_QUOTES, 'UTF-8') ?></span></div>
                            <h2><?= htmlspecialchars($report[1], ENT_QUOTES, 'UTF-8') ?></h2>
                            <p><?= htmlspecialchars($report[3], ENT_QUOTES, 'UTF-8') ?></p>
                            <div class="report-meta"><span><?= htmlspecialchars($report[5], ENT_QUOTES, 'UTF-8') ?></span><span><?= htmlspecialchars($report[6], ENT_QUOTES, 'UTF-8') ?></span></div>
                            <div class="report-actions">
                                <button class="btn btn-brand-soft flex-grow-1" type="button" data-bs-toggle="modal" data-bs-target="#reportPreviewModal"><i class="bi bi-eye" aria-hidden="true"></i> Preview</button>
                                <button class="btn btn-light btn-icon" type="button" title="Print <?= htmlspecialchars(strtolower($report[1]), ENT_QUOTES, 'UTF-8') ?>" aria-label="Print <?= htmlspecialchars($report[1], ENT_QUOTES, 'UTF-8') ?>" data-print="#<?= htmlspecialchars($report[0], ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-printer" aria-hidden="true"></i></button>
                                <button class="btn btn-light btn-icon" type="button" data-report-url="<?= htmlspecialchars(appUrl('api/reports.php?' . http_build_query(['report' => $report[7], 'export' => 'csv', 'from' => $reportFrom, 'to' => $reportTo, 'coverage' => $reportCoverage])), ENT_QUOTES, 'UTF-8') ?>" title="Export <?= htmlspecialchars(strtolower($report[1]), ENT_QUOTES, 'UTF-8') ?>" aria-label="Export <?= htmlspecialchars($report[1], ENT_QUOTES, 'UTF-8') ?>" data-report-export data-report-title="<?= htmlspecialchars($report[1], ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-download" aria-hidden="true"></i></button>
                            </div>
                        </article>
                    <?php endforeach; ?>
                    <article class="report-card">
                        <div class="report-card-top"><span class="report-icon"><i class="bi bi-file-earmark-plus" aria-hidden="true"></i></span><span class="report-frequency">Custom</span></div>
                        <h2>Custom operations summary</h2>
                        <p>Combine selected data points into a briefing prepared for barangay leadership.</p>
                        <div class="report-meta"><span>Choose fields</span><span>On demand</span></div>
                        <div class="report-actions"><button class="btn btn-outline-brand flex-grow-1" type="button" data-bs-toggle="modal" data-bs-target="#customReportModal"><i class="bi bi-sliders" aria-hidden="true"></i> Configure report</button></div>
                    </article>
                </div>
            </section>

            <section class="data-card mt-3" aria-labelledby="recentReportsHeading">
                <div class="data-card-header"><div><h2 id="recentReportsHeading">Recently generated</h2><p>Ready-to-use reports prepared by authorized personnel.</p></div><span class="status-badge status-info" data-report-count aria-live="polite"><?= count($recentReports) ?> <?= count($recentReports) === 1 ? 'file' : 'files' ?></span></div>
                <div class="table-responsive"><table class="table app-table" id="recentReportsTable"><caption class="visually-hidden">Recently generated SAGIPBRO reports</caption><thead><tr><th scope="col">Report</th><th scope="col">Period</th><th scope="col">Generated by</th><th scope="col">Generated</th><th scope="col">Format</th><th scope="col" class="text-end">Actions</th></tr></thead><tbody>
                <?php foreach ($recentReports as $recent):
                    $downloadUrl = appUrl('api/reports.php?' . http_build_query([
                        'report' => $recent['report'], 'export' => 'csv', 'from' => $recent['from'],
                        'to' => $recent['to'], 'coverage' => $recent['coverage'], 'sections' => $recent['sections'],
                        'title' => $recent['title'], 'track' => '0',
                    ]));
                ?>
                    <tr data-report-history><td><span class="table-primary-text"><?= htmlspecialchars($recent['title'], ENT_QUOTES, 'UTF-8') ?></span><span class="table-secondary-text"><?= htmlspecialchars($recent['coverage'] ?: 'All categories', ENT_QUOTES, 'UTF-8') ?></span></td><td><?= htmlspecialchars(date('M j, Y', strtotime($recent['from'])), ENT_QUOTES, 'UTF-8') ?>–<?= htmlspecialchars(date('M j, Y', strtotime($recent['to'])), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($recent['generated_by'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars(date('M j, Y g:i A', strtotime($recent['created_at'])), ENT_QUOTES, 'UTF-8') ?></td><td><span class="status-badge status-info"><?= htmlspecialchars($recent['format'], ENT_QUOTES, 'UTF-8') ?></span></td><td class="text-end"><a class="btn btn-light btn-icon" href="<?= htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8') ?>" title="Download <?= htmlspecialchars($recent['title'], ENT_QUOTES, 'UTF-8') ?>" aria-label="Download <?= htmlspecialchars($recent['title'], ENT_QUOTES, 'UTF-8') ?>" download><i class="bi bi-download" aria-hidden="true"></i></a></td></tr>
                <?php endforeach; ?>
                <?php if (!$recentReports): ?><tr data-report-empty><td colspan="6" class="text-center text-body-secondary py-4">No reports generated yet. Export a report to add it here.</td></tr><?php endif; ?>
                </tbody></table></div>
            </section>
        </main>
    </div>
</div>

<div class="modal fade" id="reportPreviewModal" tabindex="-1" aria-labelledby="reportPreviewModalLabel" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><div><h2 class="modal-title" id="reportPreviewModalLabel">Operational report preview</h2><p class="mb-0 mt-1 small text-body-secondary">Barangay Binloc · <?= htmlspecialchars(date('M j, Y', strtotime($reportFrom)), ENT_QUOTES, 'UTF-8') ?>–<?= htmlspecialchars(date('M j, Y', strtotime($reportTo)), ENT_QUOTES, 'UTF-8') ?></p></div><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body" id="reportPreviewContent">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4"><div><span class="eyebrow mb-2">SAGIPBRO report</span><h3 class="h4 mb-1">Resource Stock Position</h3><p class="small mb-0"><?= htmlspecialchars($reportCoverage ?: 'All categories', ENT_QUOTES, 'UTF-8') ?> within the selected period</p></div><span class="status-badge status-success">Filtered data</span></div>
        <div class="metric-split border rounded-3 mb-4"><div><strong><?= number_format((int) $reportStats['resources']) ?></strong><span>Total available units</span></div><div><strong><?= count(array_unique(array_column($previewResources, 'category'))) ?></strong><span>Resource categories</span></div><div><strong><?= (int) $reportStats['low_stock'] ?></strong><span>Low-stock items</span></div></div>
        <div class="table-responsive"><table class="table app-table"><caption class="visually-hidden">Resource stock report preview</caption><thead><tr><th scope="col">Resource</th><th scope="col">Category</th><th scope="col">Available</th><th scope="col">Threshold</th><th scope="col">Status</th></tr></thead><tbody><?php foreach ($previewResources as $resource): ?><tr><td><?= htmlspecialchars($resource['name'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($resource['category'], ENT_QUOTES, 'UTF-8') ?></td><td><?= number_format((int) $resource['stock']) ?> <?= htmlspecialchars($resource['unit'], ENT_QUOTES, 'UTF-8') ?></td><td><?= number_format((int) $resource['low_stock_threshold']) ?></td><td><span class="status-badge <?= $resource['stock_status'] === 'In stock' ? 'status-success' : ($resource['stock_status'] === 'Low stock' ? 'status-warning' : 'status-danger') ?>"><?= htmlspecialchars($resource['stock_status'], ENT_QUOTES, 'UTF-8') ?></span></td></tr><?php endforeach; ?><?php if (!$previewResources): ?><tr><td colspan="5" class="text-center text-body-secondary">No resource records available.</td></tr><?php endif; ?></tbody></table></div>
    </div>
    <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Close</button><button class="btn btn-outline-brand" type="button" data-print="#reportPreviewContent"><i class="bi bi-printer" aria-hidden="true"></i> Print</button><button class="btn btn-brand" type="button" data-report-export data-report-url="<?= htmlspecialchars(appUrl('api/reports.php?' . http_build_query(['report' => 'resources', 'export' => 'csv', 'from' => $reportFrom, 'to' => $reportTo, 'coverage' => $reportCoverage])), ENT_QUOTES, 'UTF-8') ?>" data-report-title="Resource stock"><i class="bi bi-download" aria-hidden="true"></i> Export</button></div>
</div></div></div>

<div class="modal fade" id="customReportModal" tabindex="-1" aria-labelledby="customReportModalLabel" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
    <form action="<?= htmlspecialchars(appUrl('api/reports.php'), ENT_QUOTES, 'UTF-8') ?>" method="get" data-report-export-form>
        <input type="hidden" name="report" value="custom">
        <input type="hidden" name="export" value="csv">
        <input type="hidden" name="from" value="<?= htmlspecialchars($reportFrom, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="to" value="<?= htmlspecialchars($reportTo, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="coverage" value="<?= htmlspecialchars($reportCoverage, ENT_QUOTES, 'UTF-8') ?>">
        <div class="modal-header"><div><h2 class="modal-title" id="customReportModalLabel">Configure custom report</h2><p class="mb-0 mt-1 small text-body-secondary">Select the operational sections required for your briefing.</p></div><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body"><fieldset><legend class="form-label mb-2">Include sections</legend><div class="row g-2"><div class="col-md-6"><div class="form-check"><input class="form-check-input" id="includeResources" name="sections[]" value="resources" type="checkbox" checked><label class="form-check-label" for="includeResources">Resource inventory</label></div></div><div class="col-md-6"><div class="form-check"><input class="form-check-input" id="includeDistributions" name="sections[]" value="distributions" type="checkbox" checked><label class="form-check-label" for="includeDistributions">Relief distributions</label></div></div><div class="col-md-6"><div class="form-check"><input class="form-check-input" id="includeEvacuation" name="sections[]" value="evacuation" type="checkbox"><label class="form-check-label" for="includeEvacuation">Evacuation centers</label></div></div><div class="col-md-6"><div class="form-check"><input class="form-check-input" id="includePeople" name="sections[]" value="people" type="checkbox"><label class="form-check-label" for="includePeople">Residents</label></div></div></div></fieldset><hr><div class="row g-3"><div class="col-md-6"><label class="form-label" for="customReportFormat">Output format</label><select class="form-select" id="customReportFormat" name="format"><option value="csv">CSV spreadsheet</option></select></div><div class="col-md-6"><label class="form-label" for="customReportTitle">Report title</label><input class="form-control" id="customReportTitle" name="title" value="Barangay Operations Brief" maxlength="180" required></div></div><p class="small text-body-secondary mt-3 mb-0">Once the file is saved, it is added to Recent Reports automatically.</p></div>
        <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-brand" type="submit"><i class="bi bi-file-earmark-arrow-down" aria-hidden="true"></i> Generate and download</button></div>
    </form>
</div></div></div>
<script>
window.sagipbroReports = {
    generatedBy: <?= json_encode((string) ($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'System user')) ?>,
    csrfToken: <?= json_encode(csrfToken()) ?>,
    maxHistory: 20
};
</script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/js/reports.js?v=9" defer></script>
<?php include '../../includes/footer.php'; ?>
