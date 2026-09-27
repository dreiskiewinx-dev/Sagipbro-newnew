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
        'Full' => 'warning', 'Closed' => 'neutral', 'Completed' => 'neutral', 'Cancelled' => 'neutral', 'Normal' => 'info'];
    echo '<span class="status-badge status-' . ($tones[$label] ?? 'neutral') . '">' . publicEscape($label) . '</span>';
}

function publicServiceLinks(): array
{
    return [
        'centers' => ['evacuation-centers.php', 'Evacuation Centers', 'bi-buildings'],
        'resources' => ['resources.php', 'Relief Resources', 'bi-box-seam'],
        'distributions' => ['distributions.php', 'Relief Distribution', 'bi-truck'],
        'announcements' => ['announcements.php', 'Emergency Announcements', 'bi-megaphone'],
        'reports' => ['reports.php', 'Reports', 'bi-file-earmark-bar-graph'],
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
    <section class="page-hero public-service-hero<?= $active === 'resources' ? ' photo-hero resources-hero' : '' ?>" aria-labelledby="public-service-title">
        <div class="container">
            <nav aria-label="Breadcrumb"><ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                <li class="breadcrumb-item"><a href="services.php">Services</a></li>
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
                <p class="mb-0">Try refreshing this page or <a href="contact.php">contact the barangay hall</a> at <a href="tel:+639631743346">+63 963 174 3346</a> / <a href="tel:+639632173031">+63 963 217 3031</a>.</p>
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
