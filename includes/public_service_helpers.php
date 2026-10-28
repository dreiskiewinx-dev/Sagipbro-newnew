<?php
declare(strict_types=1);

require_once __DIR__ . '/public_data.php';

function publicEscape($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function publicInput(string $name): string
{
    $value = $_GET[$name] ?? '';
    return is_string($value) ? trim(substr($value, 0, 200)) : '';
}

function publicChoice(string $name, array $choices): string
{
    $value = publicInput($name);
    return in_array($value, $choices, true) ? $value : '';
}

function publicDate($value): string
{
    if (!$value) return 'Not recorded';
    try {
        $date = new DateTimeImmutable((string) $value, new DateTimeZone('Asia/Manila'));
        return $date->format(strlen((string) $value) === 10 ? 'M j, Y' : 'M j, Y · g:i A') . ' PHT';
    } catch (Exception $e) {
        return 'Not recorded';
    }
}

function publicQuantity($value): string
{
    return rtrim(rtrim(number_format((float) $value, 2), '0'), '.');
}

function publicExcerpt(string $value, int $limit = 160): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    $limit = min(1000, max(2, $limit));
    preg_match('/\A.{0,' . ($limit - 1) . '}/us', $text, $match);
    $preview = $match[0] ?? '';
    return $preview === $text ? $text : rtrim($preview) . '…';
}

function publicLoad(callable $query): array
{
    header('Cache-Control: no-store, max-age=0');
    $loadedAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
    try {
        $db = sagipbroDatabase();
        // All component queries in one page use the same consistent inventory snapshot.
        $db->beginTransaction();
        try {
            $data = $query($db);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        return ['data' => $data, 'error' => false, 'loaded_at' => $loadedAt];
    } catch (Throwable $e) {
        error_log('SAGIPBRO public data unavailable (' . get_class($e) . '). Check database setup and migrations.');
        http_response_code(503);
        header('Retry-After: 60');
        return ['data' => null, 'error' => true, 'loaded_at' => $loadedAt];
    }
}

function publicStatus(string $label): void
{
    $tones = ['Available' => 'success', 'Active' => 'success', 'Low Stock' => 'warning',
        'Upcoming' => 'info', 'Out of Stock' => 'danger', 'Urgent' => 'danger',
        'Full' => 'warning', 'Closed' => 'neutral', 'Completed' => 'neutral', 'Cancelled' => 'neutral',
        'High' => 'priority-high', 'Medium' => 'priority-medium', 'Low' => 'success', 'Normal' => 'info'];
    echo '<span class="status-badge status-' . ($tones[$label] ?? 'neutral') . '">' . publicEscape($label) . '</span>';
}

function publicServiceLinks(): array
{
    return [
        'centers' => ['evacuation-centers.php#center-directory', 'Evacuation Centers', 'bi-buildings'],
        'resources' => ['resources.php#resource-directory', 'Relief Resources', 'bi-box-seam'],
        'distributions' => ['distributions.php#distribution-directory', 'Relief Distribution', 'bi-truck'],
        'announcements' => ['announcements.php#announcement-directory', 'Announcements', 'bi-megaphone'],
        'reports' => ['reports.php#public-reports', 'Reports', 'bi-file-earmark-bar-graph'],
    ];
}

function publicServiceNavigation(string $active): void
{
    $links = publicServiceLinks();
    ?>
    <nav class="public-service-nav no-print" aria-label="Public disaster services"><div class="container">
        <?php foreach ($links as $key => [$url, $label, $icon]): ?>
            <a href="<?= publicEscape($url) ?>" <?= $key === $active ? 'aria-current="page"' : '' ?>><i class="bi <?= publicEscape($icon) ?>" aria-hidden="true"></i> <?= publicEscape($label) ?></a>
        <?php endforeach; ?>
    </div></nav>
    <?php
}

function publicServiceHero(string $title, string $description, string $active): void
{
    $links = publicServiceLinks();
    ?>
    <section class="page-hero public-service-hero" aria-labelledby="public-service-title">
        <div class="container">
            <nav aria-label="Breadcrumb"><ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                <li class="breadcrumb-item"><a href="services.php#services-list">Services</a></li>
                <li class="breadcrumb-item active" aria-current="page"><?= publicEscape($links[$active][1] ?? $title) ?></li>
            </ol></nav>
            <span class="hero-chip"><span aria-hidden="true"></span> Barangay Bonuan Binloc</span>
            <h1 id="public-service-title"><?= publicEscape($title) ?></h1>
            <p><?= publicEscape($description) ?></p>
        </div>
    </section>
    <?php publicServiceNavigation($active); ?>
    <?php
}

function publicDataNotice(array $result): void
{
    if ($result['error']) {
        ?>
        <div class="alert alert-warning public-data-notice" role="alert">
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <div><h2>Information temporarily unavailable</h2>
                <p>We could not load the barangay records. No availability figures are shown because they cannot be confirmed right now.</p>
                <p class="mb-0">Try refreshing this page or <a href="contact.php#send-message">contact the barangay hall</a> at <a href="tel:+639631743346">+63 963 174 3346</a> / <a href="tel:+639632173031">+63 963 217 3031</a>.</p>
            </div>
        </div>
        <?php
        return;
    }
    ?>
    <div class="public-data-notice public-data-notice--success" role="note">
        <i class="bi bi-database-check" aria-hidden="true"></i>
        <div><strong>Barangay database records</strong>
            <p class="mb-0">Retrieved <?= publicEscape(publicDate($result['loaded_at'])) ?>. Refresh to check for changes; availability may change before you arrive.</p>
        </div>
    </div>
    <?php
}

function publicEmpty(string $title, string $message): void
{
    echo '<div class="empty-state surface-card"><i class="bi bi-inbox" aria-hidden="true"></i><h2>' . publicEscape($title) . '</h2><p>' . publicEscape($message) . '</p></div>';
}

function publicFilterForm(string $action, array $filters, array $statuses, ?array $categories = null, string $statusField = 'status'): void
{
    ?>
    <form class="filter-panel no-print" action="<?= publicEscape($action) ?>" method="get" role="search" aria-label="Search and filter records">
        <div class="filter-search">
            <label for="directory-search">Search</label>
            <input class="form-control" id="directory-search" name="q" type="search" maxlength="200" value="<?= publicEscape($filters['q'] ?? '') ?>" placeholder="Search names, locations or details">
        </div>
        <?php if ($categories !== null): ?>
            <div class="filter-field">
                <label for="directory-category">Category</label>
                <select class="form-select" id="directory-category" name="category">
                    <option value="">All categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= publicEscape($category) ?>" <?= ($filters['category'] ?? '') === $category ? 'selected' : '' ?>><?= publicEscape($category) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <div class="filter-field">
            <label for="directory-status"><?= $statusField === 'priority' ? 'Priority' : 'Status' ?></label>
            <select class="form-select" id="directory-status" name="<?= publicEscape($statusField) ?>">
                <option value="">All <?= $statusField === 'priority' ? 'priorities' : 'statuses' ?></option>
                <?php foreach ($statuses as $status): ?>
                    <option value="<?= publicEscape($status) ?>" <?= ($filters[$statusField] ?? '') === $status ? 'selected' : '' ?>><?= publicEscape($status) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-brand" type="submit"><i class="bi bi-search" aria-hidden="true"></i> Apply filters</button>
        <a class="btn btn-outline-brand" href="<?= publicEscape($action) ?>">Clear filters</a>
    </form>
    <?php
}

function publicPagination(array $metadata, string $action, array $query = [], string $pageKey = 'page', string $sizeKey = 'per_page'): void
{
    $page = (int) $metadata['page'];
    $pages = (int) $metadata['total_pages'];
    unset($query[$sizeKey]);
    $query[$pageKey] = $page;
    $visible = [1, $pages];
    for ($number = max(1, $page - 1); $number <= min($pages, $page + 1); $number++) $visible[] = $number;
    if ($page <= 3) for ($number = 2; $number <= min(4, $pages); $number++) $visible[] = $number;
    if ($page >= $pages - 2) for ($number = max(1, $pages - 3); $number <= $pages; $number++) $visible[] = $number;
    $visible = array_unique($visible);
    sort($visible, SORT_NUMERIC);
    $url = static fn (int $number): string => publicPaginationUrl($action, array_merge($query, [$pageKey => $number]));
    ?>
    <div class="public-pagination no-print" data-public-pagination="<?= publicEscape($pageKey) ?>">
        <p class="public-pagination-summary mb-0" role="status">Showing <?= number_format((int) $metadata['from']) ?>–<?= number_format((int) $metadata['to']) ?> of <?= number_format((int) $metadata['total']) ?> records</p>
        <nav aria-label="<?= publicEscape($pageKey === 'page' ? 'Directory pages' : str_replace('_page', '', $pageKey) . ' pages') ?>">
            <ul class="pagination pagination-sm">
                <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>"><?php if ($page <= 1): ?><span class="page-link" aria-disabled="true">Previous</span><?php else: ?><a class="page-link" href="<?= publicEscape($url($page - 1)) ?>" rel="prev">Previous</a><?php endif; ?></li>
                <?php $previous = 0; foreach ($visible as $number): ?>
                    <?php if ($previous && $number > $previous + 1): ?><li class="page-item disabled"><span class="page-link" aria-hidden="true">…</span></li><?php endif; ?>
                    <li class="page-item<?= $number === $page ? ' active' : '' ?>"><?php if ($number === $page): ?><span class="page-link" aria-current="page"><?= $number ?></span><?php else: ?><a class="page-link" aria-label="Page <?= $number ?>" href="<?= publicEscape($url($number)) ?>"><?= $number ?></a><?php endif; ?></li>
                <?php $previous = $number; endforeach; ?>
                <li class="page-item<?= $page >= $pages ? ' disabled' : '' ?>"><?php if ($page >= $pages): ?><span class="page-link" aria-disabled="true">Next</span><?php else: ?><a class="page-link" href="<?= publicEscape($url($page + 1)) ?>" rel="next">Next</a><?php endif; ?></li>
            </ul>
        </nav>
    </div>
    <?php
}
