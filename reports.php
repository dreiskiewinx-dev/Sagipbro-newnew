<?php
require_once __DIR__ . '/includes/public_service_helpers.php';
$result = publicLoad(static fn(PDO $db): array => publicReport($db));
$pageTitle = 'Public Reports';
$pageDescription = 'Public resource availability, evacuation capacity, and recent relief distribution information for Bonuan Binloc.';
$activePage = 'services';
$basePath = '';
$export = publicChoice('export', ['resources', 'centers', 'distributions']);

if ($export !== '' && !$result['error']) {
    $records = $result['data'][$export];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sagipbro-' . $export . '-' . date('Y-m-d') . '.csv"');
    header('X-Content-Type-Options: nosniff');
    $stream = fopen('php://output', 'wb');
    $write = static function (array $cells) use ($stream): void {
        $cells = array_map(static function ($cell): string {
            $text = (string) ($cell ?? '');
            // Spreadsheet software must not interpret database text as executable formulas.
            return preg_match('/\A[\s]*[=+@-]/u', $text) ? "'" . $text : $text;
        }, $cells);
        fputcsv($stream, $cells, ',', '"', '');
    };
    if ($export === 'resources') {
        $write(['Resource', 'Category', 'Quantity', 'Unit', 'Status', 'Last updated (PHT)']);
        foreach ($records as $r) $write([$r['name'], $r['category'], $r['stock'], $r['unit'], $r['availability'], $r['updated_at']]);
    } elseif ($export === 'centers') {
        $write(['Center', 'Location', 'Capacity', 'Occupants', 'Available spaces', 'Status', 'Last updated (PHT)']);
        foreach ($records as $r) $write([$r['name'], $r['address'], $r['capacity'], $r['occupants'], $r['available_spaces'], $r['availability'], $r['updated_at']]);
    } else {
        $write(['Distribution', 'Location', 'Start (PHT)', 'End (PHT)', 'Status', 'Resource', 'Unit', 'Planned quantity', 'Distributed quantity']);
        foreach ($records as $r) {
            foreach ($r['resources'] ?: [['name' => '', 'unit' => '', 'planned_quantity' => null, 'distributed_quantity' => '']] as $item) {
                $write([$r['title'], $r['location'], $r['starts_at'], $r['ends_at'], $r['status'], $item['name'], $item['unit'], $item['planned_quantity'], $item['distributed_quantity']]);
            }
        }
    }
    fclose($stream);
    exit;
}
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
?>
<main id="main-content">
    <?php publicServiceHero('Public relief reports', 'Review the barangay’s recorded supplies, available evacuation spaces, and recent distributions. Individual resident and recipient details are not published.', 'reports'); ?>
    <section class="section-space section-soft" id="public-reports" data-scroll-target><div class="container">
        <?php publicDataNotice($result); ?>
        <?php if (!$result['error']): $report = $result['data']; $summary = $report['summary']; ?>
            <div class="public-report-actions no-print">
                <p class="mb-0">All times are Philippine time (PHT / UTC+8). Counts are records, not a combined total of different supply units.</p>
                <button class="btn btn-outline-brand" type="button" data-public-print><i class="bi bi-printer" aria-hidden="true"></i> Print report</button>
            </div>
            <div class="public-summary-grid">
                <div class="public-summary-card"><span>Resources listed</span><strong><?= number_format($summary['resources']) ?></strong><small><?= number_format($summary['low_stock_resources']) ?> low stock · <?= number_format($summary['out_of_stock_resources']) ?> out of stock</small></div>
                <div class="public-summary-card"><span>Available centers</span><strong><?= number_format($summary['available_centers']) ?></strong><small>Of <?= number_format($summary['centers']) ?> recorded centers</small></div>
                <div class="public-summary-card"><span>Available evacuation spaces</span><strong><?= number_format($summary['available_spaces']) ?></strong><small>Excludes full and closed centers</small></div>
                <div class="public-summary-card"><span>Active distributions</span><strong><?= number_format($summary['active_distributions']) ?></strong><small><?= number_format($summary['upcoming_distributions']) ?> upcoming</small></div>
            </div>
            <section class="public-report-section" aria-labelledby="report-resources">
                <div class="public-report-heading"><h2 id="report-resources">Resource availability</h2><a class="btn btn-sm btn-outline-brand no-print" href="reports.php?export=resources">Export resources CSV</a></div>
                <?php if (!$report['resources']): publicEmpty('No resources recorded', 'Inventory will appear here once it is entered in the barangay database.'); else: ?>
                    <div class="table-responsive surface-card"><table class="table app-table">
                        <caption class="visually-hidden">Resource availability from the barangay database</caption>
                        <thead><tr><th scope="col">Resource</th><th scope="col">Category</th><th scope="col">Available quantity</th><th scope="col">Status</th></tr></thead>
                        <tbody><?php foreach ($report['resources'] as $r): ?><tr><th scope="row"><?= publicEscape($r['name']) ?></th><td><?= publicEscape($r['category']) ?></td><td><?= publicQuantity($r['stock']) ?> <?= publicEscape($r['unit']) ?></td><td><?php publicStatus($r['availability']); ?></td></tr><?php endforeach; ?></tbody>
                    </table></div>
                <?php endif; ?>
            </section>
            <section class="public-report-section" aria-labelledby="report-centers">
                <div class="public-report-heading"><h2 id="report-centers">Evacuation center availability</h2><a class="btn btn-sm btn-outline-brand no-print" href="reports.php?export=centers">Export centers CSV</a></div>
                <?php if (!$report['centers']): publicEmpty('No evacuation centers recorded', 'Contact the barangay hall for current evacuation guidance.'); else: ?>
                    <div class="table-responsive surface-card"><table class="table app-table">
                        <caption class="visually-hidden">Evacuation center capacities and available spaces</caption>
                        <thead><tr><th scope="col">Center / location</th><th scope="col">Capacity</th><th scope="col">Occupants</th><th scope="col">Available spaces</th><th scope="col">Status</th></tr></thead>
                        <tbody><?php foreach ($report['centers'] as $r): ?><tr><th scope="row"><?= publicEscape($r['name']) ?><span class="table-secondary-text"><?= publicEscape($r['address']) ?></span></th><td><?= number_format($r['capacity']) ?></td><td><?= number_format($r['occupants']) ?></td><td><?= number_format($r['available_spaces']) ?></td><td><?php publicStatus($r['availability']); ?></td></tr><?php endforeach; ?></tbody>
                    </table></div>
                <?php endif; ?>
            </section>
            <section class="public-report-section" aria-labelledby="report-distributions">
                <div class="public-report-heading"><h2 id="report-distributions">Recent completed distributions</h2><a class="btn btn-sm btn-outline-brand no-print" href="reports.php?export=distributions">Export all distributions CSV</a></div>
                <?php $recent = array_values(array_filter($report['distributions'], static fn(array $r): bool => $r['status'] === 'Completed'));
                usort($recent, static fn(array $a, array $b): int => strcmp($b['starts_at'], $a['starts_at']));
                $recent = array_slice($recent, 0, 10);
                if (!$recent): publicEmpty('No completed distributions recorded', 'Published schedules and recorded distributions will appear when available.'); else: ?>
                    <p>Showing the 10 most recent completed distributions, where available.</p>
                    <div class="table-responsive surface-card"><table class="table app-table">
                        <caption class="visually-hidden">Recent completed distributions, without recipient details</caption>
                        <thead><tr><th scope="col">Distribution / location</th><th scope="col">Date and time (PHT)</th><th scope="col">Distributed supplies</th><th scope="col">Status</th></tr></thead>
                        <tbody><?php foreach ($recent as $r): ?><tr><th scope="row"><?= publicEscape($r['title']) ?><span class="table-secondary-text"><?= publicEscape($r['location']) ?></span></th><td><?= publicEscape(publicDate($r['starts_at'])) ?><?= !$r['time_recorded'] ? '<small class="d-block">Time not recorded</small>' : '' ?></td><td><?php foreach ($r['resources'] as $item): ?><span class="d-block"><?= publicEscape($item['name']) ?>: <?= publicQuantity($item['distributed_quantity']) ?> <?= publicEscape($item['unit']) ?></span><?php endforeach; ?><?= !$r['resources'] ? 'No supply details recorded' : '' ?></td><td><?php publicStatus($r['status']); ?></td></tr><?php endforeach; ?></tbody>
                    </table></div>
                <?php endif; ?>
                <a class="btn btn-brand mt-3 no-print" href="distributions.php#distribution-directory">View upcoming and active distributions <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
            </section>
        <?php endif; ?>
    </div></section>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
