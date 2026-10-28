<?php
declare(strict_types=1);

/**
 * Run: php tests/admin_pagination_http.php
 * Requires PDO MySQL, proc_open, and permission to create a temporary database.
 * Uses SAGIPBRO_DB_HOST/PORT/USER/PASSWORD; never selects SAGIPBRO_DB_NAME.
 * All fixtures and API mutations run in a newly created, randomly named database.
 * Set SAGIPBRO_PAGINATION_BROWSER=1 to include headless Chrome UI/CRUD checks.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run pagination tests from the command line.');
}
require_once __DIR__ . '/../config/config.php';

$project = dirname(__DIR__);
$testDatabase = 'sagipbro_page_test_' . bin2hex(random_bytes(10));
$createdDatabase = false;
$database = null;
$server = null;
$temporaryDirectory = null;
$sessionId = null;
$serverAddress = '';
$sessionCookie = '';
$csrf = '';
$checks = 0;
$failed = false;

function pageCheck(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) throw new LogicException($message);
}

function pageFixture(PDO $db, string $table, array $values): int
{
    $statement = $db->prepare('INSERT INTO `' . $table . '` (`' . implode('`, `', array_keys($values)) . '`) VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')');
    $statement->execute(array_values($values));
    return (int) $db->lastInsertId();
}

function pageRequest(string $endpoint, array $query = [], string $method = 'GET', ?array $data = null, bool $authenticated = true): array
{
    global $serverAddress, $sessionCookie, $csrf;
    $headers = ['Accept: application/json', 'Connection: close'];
    if ($authenticated) $headers[] = 'Cookie: ' . $sessionCookie;
    if ($data !== null) {
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'X-CSRF-Token: ' . $csrf;
    }
    $options = ['method' => $method, 'header' => implode("\r\n", $headers), 'timeout' => 10, 'ignore_errors' => true, 'follow_location' => 0];
    if ($data !== null) $options['content'] = json_encode($data, JSON_THROW_ON_ERROR);
    $url = $serverAddress . '/api/' . $endpoint . '.php' . ($query ? '?' . http_build_query($query) : '');
    $stream = @fopen($url, 'rb', false, stream_context_create(['http' => $options]));
    if ($stream === false) throw new LogicException('The isolated HTTP server did not respond: ' . $endpoint);
    $body = (string) stream_get_contents($stream);
    $meta = stream_get_meta_data($stream);
    fclose($stream);
    if (!empty($meta['timed_out'])) throw new LogicException('HTTP request timed out: ' . $endpoint);
    $responseHeaders = $meta['wrapper_data'] ?? [];
    preg_match('/\AHTTP\/\S+\s+(\d+)/', $responseHeaders[0] ?? '', $status);
    return ['status' => (int) ($status[1] ?? 0), 'body' => $body, 'json' => json_decode($body, true), 'headers' => implode("\n", $responseHeaders)];
}

function pageGet(string $endpoint, array $query = []): array
{
    $response = pageRequest($endpoint, array_merge(['paginate' => '1'], $query));
    pageCheck($response['status'] === 200, $endpoint . ' must return HTTP 200: ' . substr($response['body'], 0, 450));
    pageCheck(is_array($response['json']) && isset($response['json']['pagination'], $response['json']['data']), $endpoint . ' did not return paginated JSON.');
    return $response['json'];
}

function pageMutation(string $endpoint, string $method, array $data, int $expectedStatus = 200): array
{
    $response = pageRequest($endpoint, [], $method, $data);
    pageCheck($response['status'] === $expectedStatus, $endpoint . ' ' . $method . ' failed: ' . substr($response['body'], 0, 450));
    pageCheck(is_array($response['json']), $endpoint . ' ' . $method . ' did not return JSON.');
    return $response['json'];
}

function checkPageSet(string $endpoint, int $total, array $baseQuery = []): void
{
    $first = pageGet($endpoint, $baseQuery);
    pageCheck((int) $first['pagination']['per_page'] === 10 && count($first['data']) === min(10, $total), $endpoint . ' must default to ten rows.');
    pageCheck((int) $first['pagination']['total'] === $total, $endpoint . ' must count all real matching records.');
    foreach ([10, 25, 50, 100] as $size) {
        $result = pageGet($endpoint, array_merge($baseQuery, ['per_page' => $size, 'page' => 2]));
        $expectedPage = min(2, max(1, (int) ceil($total / 10)));
        $expectedFrom = $total ? ($expectedPage - 1) * 10 + 1 : 0;
        $expectedTo = min($expectedPage * 10, $total);
        pageCheck((int) $result['pagination']['page'] === $expectedPage && (int) $result['pagination']['per_page'] === 10, $endpoint . ' must remain fixed at ten records regardless of requested page size.');
        pageCheck((int) $result['pagination']['from'] === $expectedFrom && (int) $result['pagination']['to'] === $expectedTo && count($result['data']) === max(0, $expectedTo - $expectedFrom + ($total ? 1 : 0)), $endpoint . ' has incorrect LIMIT/OFFSET boundaries.');
    }
    $invalid = pageGet($endpoint, array_merge($baseQuery, ['page' => -8, 'per_page' => 17]));
    pageCheck((int) $invalid['pagination']['page'] === 1 && (int) $invalid['pagination']['per_page'] === 10, $endpoint . ' must normalize invalid pagination values.');
    $last = pageGet($endpoint, array_merge($baseQuery, ['page' => 999999]));
    pageCheck((int) $last['pagination']['page'] === max(1, (int) ceil($total / 10)) && (int) $last['pagination']['to'] === $total, $endpoint . ' must clamp pages beyond the end.');
    $seen = [];
    $pages = max(1, (int) ceil($total / 10));
    for ($page = 1; $page <= $pages; ++$page) {
        $result = pageGet($endpoint, array_merge($baseQuery, ['page' => $page]));
        foreach ($result['data'] as $row) {
            pageCheck(isset($row['id']) && !isset($seen[$row['id']]), $endpoint . ' must not duplicate or skip rows between pages when sort values tie.');
            $seen[$row['id']] = true;
        }
    }
    pageCheck(count($seen) === $total, $endpoint . ' must make every matching record accessible.');
    $again = pageGet($endpoint, $baseQuery);
    pageCheck(array_column($again['data'], 'id') === array_column($first['data'], 'id'), $endpoint . ' ordering must remain stable across requests.');
    $empty = pageGet($endpoint, array_merge($baseQuery, ['q' => 'NO_SUCH_PAGINATION_RECORD_71fc', 'page' => 99]));
    pageCheck($empty['data'] === [] && (int) $empty['pagination']['total'] === 0 && (int) $empty['pagination']['page'] === 1 && (int) $empty['pagination']['from'] === 0 && (int) $empty['pagination']['to'] === 0, $endpoint . ' must return an honest empty result.');
    $literal = pageGet($endpoint, array_merge($baseQuery, ['q' => 'PAG_%!', 'page' => 99]));
    pageCheck((int) $literal['pagination']['total'] === 1 && count($literal['data']) === 1 && (int) $literal['pagination']['page'] === 1, $endpoint . ' must treat LIKE wildcards as literal search text.');
    $injection = pageGet($endpoint, array_merge($baseQuery, ['q' => "' OR 1=1 --"]));
    pageCheck((int) $injection['pagination']['total'] === 0, $endpoint . ' must bind search values.');
}

function pageBrowserCheck(string $directory, array $cases, array $environment, PDO $database): void
{
    global $serverAddress;
    foreach ($cases as &$case) {
        if ($case['table'] === 'activityLogsTable' && empty($case['deep'])) {
            $case['statValues'] = array_values($database->query("SELECT SUM(DATE(created_at) = CURDATE()), COUNT(DISTINCT CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN user_id END), SUM(LOWER(action) LIKE '%fail%' OR LOWER(action) LIKE '%warning%') FROM activity_logs")->fetch(PDO::FETCH_NUM));
            $case['statValues'][] = $database->query("SELECT COALESCE(NULLIF(TRIM(u.full_name), ''), u.username) FROM activity_logs l INNER JOIN users u ON u.id = l.user_id WHERE l.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) GROUP BY u.id, u.full_name, u.username ORDER BY COUNT(*) DESC, MAX(l.created_at) DESC, u.id ASC LIMIT 1")->fetchColumn() ?: 'No activity yet';
        }
    }
    unset($case);
    $chrome = getenv('SAGIPBRO_CHROME_BINARY') ?: 'C:/Program Files/Google/Chrome/Application/chrome.exe';
    pageCheck(is_file($chrome), 'Set SAGIPBRO_CHROME_BINARY to run optional browser tests.');
    $script = <<<'JAVASCRIPT'
const cases = __CASES__;
const output = document.getElementById('pagination-browser-result');
const frame = document.getElementById('admin-page');
const results = [];
const cleanupResources = [];
let checks = 0;
const check = (condition, message) => { checks++; if (!condition) throw new Error(message); };
const wait = async (predicate, label) => {
    for (let attempt = 0; attempt < 240; attempt++) {
        if (predicate()) return;
        await new Promise((resolve) => setTimeout(resolve, 25));
    }
    throw new Error('Timed out: ' + label);
};
const change = (element, value, type = 'change') => {
    element.value = value;
    element.dispatchEvent(new Event(type, { bubbles: true }));
};
const statisticLabels = {
    residentsTable: ['Registered residents', 'Households', 'Priority residents', 'Records verified'],
    volunteersTable: ['Registered volunteers', 'Ready for deployment', 'Currently deployed', 'Operationally available'],
    usersTable: ['Total accounts', 'Officials & admins', 'Online now', 'Inactive accounts'],
    recentReportsTable: ['Reports available', 'Records summarized', 'Last data sync', 'Scheduled reports'],
    activityLogsTable: ['Events today', 'Active users', 'Warnings', 'Most active user'],
    volunteerDashboardStats: ["Today's assignments", 'Open centers', 'Households assisted', 'Items distributed'],
    dashboardActivityTable: ['Total resources', 'Low-stock items', 'Evacuation centers', 'Registered residents', 'Active volunteers', 'Recent distributions']
};
(async () => {
    for (const test of cases) {
        output.textContent = JSON.stringify({ running: true, checks, results, current: test.table });
        frame.style.width = '1440px';
        frame.style.height = '1050px';
        await new Promise((resolve) => { frame.onload = resolve; frame.src = test.route; });
        const page = frame.contentWindow;
        const doc = frame.contentDocument;
        if (!test.statsOnly) await wait(() => page.SagipbroPagination?.get('#' + test.table), test.table + ' controller');
        const pager = test.statsOnly ? null : page.SagipbroPagination.get('#' + test.table);
        if (pager) await wait(() => !pager.state.loading && pager.state.total === test.total, test.table + ' initial data');
        const table = doc.getElementById(test.table);
        if (table?.closest('.modal')) {
            const animationStyle = doc.createElement('style'); animationStyle.textContent = '.modal.fade .modal-dialog,.modal-backdrop.fade{transition:none!important}'; doc.head.append(animationStyle);
            const instance = page.bootstrap.Modal.getOrCreateInstance(table.closest('.modal'));
            instance.show();
            await wait(() => table.getBoundingClientRect().width > 0 && !instance._isTransitioning && !pager.state.loading, test.table + ' visible preview');
        }
        const footer = table?.closest('.data-card').querySelector('.server-pagination-footer');
        const button = (label) => footer.querySelector('button[aria-label="' + label + '"]');
        if (statisticLabels[test.table] && !test.deep && !test.crud) {
            const grid = doc.querySelector('.stat-grid');
            const cards = [...grid.querySelectorAll('.stat-card')];
            const liveTotals = [...grid.querySelectorAll('[data-dashboard-stat]')];
            const labels = statisticLabels[test.table];
            const originalValues = cards.map(card => card.querySelector('.stat-value').textContent.trim());
            const keys = ['resources', 'low_stock', 'centers', 'open_centers', 'residents', 'volunteers', 'distributions', 'distributed_quantity'];
            let totals = null, expectedValues = test.statValues;
            const getStatistics = async (route) => {
                const response = await page.fetch(new URL(route, page.location.origin), { headers: { Accept: 'application/json' } });
                check(response.ok, test.table + ' statistics are available from the real API');
                return response.json();
            };
            if (test.table === 'dashboardActivityTable') {
                totals = (await getStatistics('/api/reports.php?report=summary')).data?.[0];
                check(totals && keys.every(key => Object.prototype.hasOwnProperty.call(totals, key)), 'Dashboard summary API retains all eight live metrics');
                check(liveTotals.length === keys.length && keys.every(key => liveTotals.some(node => node.dataset.dashboardStat === key)), 'All dashboard data bindings remain available');
                expectedValues = ['resources', 'low_stock', 'centers', 'residents', 'volunteers', 'distributions'].map(key => totals[key]);
            } else if (['residentsTable', 'volunteersTable', 'usersTable'].includes(test.table)) {
                const endpoint = test.table.replace('Table', '');
                const stats = (await getStatistics('/api/' + endpoint + '.php?paginate=1')).stats;
                check(Boolean(stats), test.table + ' real database summary is retained');
                if (endpoint === 'residents') expectedValues = [stats.total, stats.households, stats.priority, (Number(stats.total) ? Math.round(Number(stats.active) / Number(stats.total) * 100) : 0) + '%'];
                if (endpoint === 'volunteers') expectedValues = [stats.total, stats.active, stats.deployed, (Number(stats.total) ? Math.round((Number(stats.active) + Number(stats.deployed)) / Number(stats.total) * 100) : 0) + '%'];
                if (endpoint === 'users') expectedValues = [stats.total, stats.privileged, stats.online, stats.inactive];
            } else if (test.table === 'recentReportsTable') {
                const query = new URLSearchParams({report: 'overview', from: doc.getElementById('reportDateFrom').value, to: doc.getElementById('reportDateTo').value, coverage: doc.getElementById('reportArea').value});
                const rows = (await getStatistics('/api/reports.php?' + query)).data;
                check(/^(?:[1-9]|1[0-2]):\d{2} [AP]M$/.test(originalValues[2]), 'Reports retains its real Last data sync time string');
                expectedValues = [rows.find(row => row.item === 'Reports available').value, rows.find(row => row.item === 'Records summarized').value, originalValues[2], 0];
            }
            check(cards.length === labels.length, test.table + ' retains all existing summary cards');
            check(expectedValues?.length === cards.length && originalValues.every((value, index) => value.replace(/,/g, '') === String(expectedValues[index])), test.table + ' summary values match the real database or API');
            const intersects = (a, b) => a.left < b.right - 1 && a.right > b.left + 1 && a.top < b.bottom - 1 && a.bottom > b.top + 1;
            for (const width of [1440, 1024, 900, 390, 320]) {
                frame.style.width = width + 'px';
                await new Promise(resolve => setTimeout(resolve, 150));
                const rem = Number.parseFloat(page.getComputedStyle(doc.documentElement).fontSize);
                const columns = page.getComputedStyle(grid).gridTemplateColumns.trim().split(/\s+/).length;
                check(columns === (width >= 992 ? (grid.classList.contains('dashboard-stat-grid') ? 3 : 4) : width >= 576 ? 2 : 1), width + 'px ' + test.table + ' keeps its existing desktop/tablet/mobile column count');
                check(doc.documentElement.scrollWidth <= width + 1 && grid.scrollWidth <= grid.clientWidth + 1, width + 'px dashboard summary grid does not overflow');
                for (const node of liveTotals) {
                    const text = node.textContent.trim();
                    check(/^[\d,]+$/.test(text) && Number(text.replace(/,/g, '')) === Number(totals[node.dataset.dashboardStat]), width + 'px live database total remains unchanged: ' + node.dataset.dashboardStat);
                }
                const tones = {};
                for (let index = 0; index < cards.length; index++) {
                    const card = cards[index], bounds = card.getBoundingClientRect();
                    const label = card.querySelector('.stat-card-label'), number = card.querySelector('.stat-value'), icon = card.querySelector('.stat-card-icon');
                    const labelStyle = page.getComputedStyle(label), numberStyle = page.getComputedStyle(number), iconStyle = page.getComputedStyle(icon);
                    const accent = page.getComputedStyle(card, '::before');
                    const iconBounds = icon.getBoundingClientRect(), labelBounds = label.getBoundingClientRect(), numberBounds = number.getBoundingClientRect();
                    check(label.textContent.trim() === labels[index], width + 'px dashboard label is preserved: ' + labels[index]);
                    check(number.textContent.trim() === originalValues[index], width + 'px ' + test.table + ' preserves its live value/time string: ' + labels[index]);
                    check(bounds.left >= 0 && bounds.right <= width + 1 && numberBounds.left >= bounds.left && numberBounds.right <= bounds.right, width + 'px summary card and value fit the viewport: ' + labels[index]);
                    check(number.scrollWidth <= number.clientWidth + 1 && numberBounds.bottom <= bounds.bottom - 1, width + 'px summary number/time string is not clipped: ' + labels[index]);
                    check(labelBounds.right <= iconBounds.left + 1 && iconBounds.right <= bounds.right - 1, width + 'px label and icon do not overlap: ' + labels[index]);
                    check(Number.parseFloat(accent.height) >= 3.5 && Number.parseFloat(accent.height) <= 4.5 && !['none', 'normal'].includes(accent.content), width + 'px card has its visible four-pixel top accent');
                    check(Number.parseFloat(numberStyle.fontSize) >= rem * 2 - 1 && Number(numberStyle.fontWeight) >= 800 && numberStyle.fontVariantNumeric.includes('tabular-nums'), width + 'px live number is larger, bold and consistently aligned');
                    check(Number.parseFloat(labelStyle.fontSize) >= rem * .73 && Number(labelStyle.fontWeight) >= 750 && labelStyle.color !== 'rgb(110, 131, 123)', width + 'px summary label is larger and darker');
                    check(Math.abs(iconBounds.width - 44) < 1 && Math.abs(iconBounds.height - 44) < 1 && iconStyle.color === 'rgb(255, 255, 255)', width + 'px card icon is solid and readable');
                    check(accent.backgroundColor === iconStyle.backgroundColor && accent.backgroundColor === numberStyle.color, width + 'px accent, icon and number use the same status color');
                    const tone = card.classList.contains('danger') ? 'red' : card.classList.contains('warning') ? 'amber' : card.classList.contains('info') ? 'blue' : 'green';
                    check(!tones[tone] || tones[tone] === accent.backgroundColor, width + 'px repeated status accents stay consistent: ' + tone);
                    tones[tone] = accent.backgroundColor;
                    for (let previous = 0; previous < index; previous++) check(!intersects(bounds, cards[previous].getBoundingClientRect()), width + 'px summary cards remain non-overlapping');
                }
                check(new Set(Object.values(tones)).size === Object.keys(tones).length, width + 'px semantic status accents remain distinct');
            }
            frame.style.width = '1440px';
            await new Promise(resolve => setTimeout(resolve, 100));
            results.push(test.table + ' summary cards retain live values/time and responsive emphasis');
        }
        if (test.statsOnly) continue;
        if (test.crud) {
            button('Next').click();
            await wait(() => pager.state.page === 2 && !pager.state.loading, 'resource CRUD starting page');
            const form = doc.getElementById('addResourceForm');
            page.bootstrap.Modal.getOrCreateInstance(form.closest('.modal')).show();
            for (const [key, value] of Object.entries({ name: 'BROWSER Pagination resource', category: 'Medical', stock: '20', unit: 'packs', low_stock_threshold: '5', location: 'Browser test location', status: 'Available' })) form.elements[key].value = value;
            form.requestSubmit();
            await wait(() => pager.state.total === 106 && !pager.state.loading, 'resource creation refresh');
            check(pager.state.page === 2, 'Adding a resource must refresh totals and preserve the current page');
            const search = doc.querySelector('[data-table-search="#resourcesTable"]');
            change(search, 'BROWSER Pagination', 'input');
            await wait(() => pager.state.total === 1 && !pager.state.loading, 'created resource search');
            const edit = table.querySelector('[data-resource-edit]');
            cleanupResources.push(Number(edit.dataset.resourceEdit));
            edit.click();
            const editForm = doc.getElementById('editResourceForm');
            const editModal = editForm.closest('.modal');
            const editBody = editForm.querySelector('.modal-body');
            const editFooter = editForm.querySelector('.modal-footer');
            const cancelEdit = editFooter.querySelector('[data-bs-dismiss="modal"]');
            const saveEdit = editFooter.querySelector('[type="submit"]');
            const readEditedResource = async () => {
                const url = new URL(page.sagipbroResourceApi.endpoint, page.location.href);
                url.searchParams.set('id', edit.dataset.resourceEdit);
                const response = await page.fetch(url);
                check(response.ok, 'Resource can be checked after a modal action');
                return (await response.json()).data[0];
            };
            const unchangedResource = await readEditedResource();
            // Dismiss the earlier Add confirmation before hit-testing modal actions.
            doc.querySelectorAll('.toast.show').forEach(toast => page.bootstrap.Toast.getOrCreateInstance(toast).hide());
            await wait(() => !doc.querySelector('.toast.show, .toast.showing'), 'Previous resource notification is dismissed');
            for (const [width, height] of [[1440, 900], [1024, 600], [390, 700], [320, 568]]) {
                frame.style.width = width + 'px';
                frame.style.height = height + 'px';
                await wait(() => editModal.classList.contains('show') && !page.bootstrap.Modal.getInstance(editModal)?._isTransitioning, 'Edit resource modal opens');
                await new Promise(resolve => setTimeout(resolve, 180));
                check(editForm.classList.contains('modal-content') && editModal.querySelector('.modal-dialog-scrollable'), 'Edit resource uses Bootstrap scrollable form layout');
                for (const action of [cancelEdit, saveEdit]) {
                    const bounds = action.getBoundingClientRect();
                    check(bounds.width > 0 && bounds.height >= 40 && bounds.top >= 0 && bounds.bottom <= page.innerHeight + 1, width + 'px edit action is visible and tappable: ' + action.textContent.trim());
                    check(bounds.left >= 0 && bounds.right <= page.innerWidth + 1, width + 'px edit action fits horizontally');
                    const hit = doc.elementFromPoint(bounds.left + bounds.width / 2, bounds.top + bounds.height / 2);
                    check(hit && action.contains(hit), width + 'px edit action is not covered: ' + JSON.stringify({button: action.textContent.trim(), hit: hit?.outerHTML.slice(0, 180), top: bounds.top, bottom: bounds.bottom, modal: editModal.className}));
                }
                editBody.scrollTop = editBody.scrollHeight;
                const noteBounds = editForm.elements.reason.getBoundingClientRect();
                check(noteBounds.bottom <= editBody.getBoundingClientRect().bottom + 1, width + 'px adjustment note remains reachable inside scrolling body');
                check(saveEdit.getBoundingClientRect().top >= editBody.getBoundingClientRect().bottom - 1, width + 'px Save stays below the scrolling form body');
                editForm.elements.name.value = 'BROWSER Unsaved resource edit';
                editForm.elements.stock.value = '99';
                cancelEdit.click();
                await wait(() => !editModal.classList.contains('show'), 'Cancel closes the resource modal');
                const afterCancel = await readEditedResource();
                check(afterCancel.name === unchangedResource.name && Number(afterCancel.stock) === Number(unchangedResource.stock), width + 'px Cancel does not change database values');
                await new Promise(resolve => setTimeout(resolve, 350));
                edit.click();
            }
            frame.style.width = '1440px';
            frame.style.height = '900px';
            await wait(() => editModal.classList.contains('show') && !page.bootstrap.Modal.getInstance(editModal)?._isTransitioning, 'Edit resource reopens for save');
            await new Promise(resolve => setTimeout(resolve, 180));
            editForm.elements.name.value = 'BROWSER Updated pagination resource';
            editForm.elements.stock.value = '21';
            editForm.elements.notes.value = 'BROWSER saved inventory note';
            saveEdit.click();
            await wait(() => pager.state.total === 0 && !pager.state.loading, 'resource edit refresh');
            check(search.value === 'BROWSER Pagination', 'Editing must retain the current search and recompute its matches');
            const afterSave = await readEditedResource();
            check(afterSave.name === 'BROWSER Updated pagination resource' && Number(afterSave.stock) === 21 && afterSave.notes === 'BROWSER saved inventory note', 'Clicking Save changes persists edited fields to the database');
            change(search, 'BROWSER Updated', 'input');
            await wait(() => pager.state.total === 1 && !pager.state.loading, 'edited resource search');
            table.querySelector('[data-resource-delete]').click();
            doc.getElementById('deleteResourceForm').requestSubmit();
            await wait(() => pager.state.total === 0 && !pager.state.loading, 'resource archive refresh');
            check(button('Previous').disabled && button('Next').disabled, 'Archiving the last matching row must update navigation');
            const archive = doc.getElementById('resourceArchiveView');
            change(archive, 'archived');
            await wait(() => pager.state.total === 1 && !pager.state.loading, 'archived resource lookup');
            table.querySelector('[data-resource-restore]').click();
            await wait(() => pager.state.total === 0 && !pager.state.loading, 'resource restore refresh');
            change(archive, 'active');
            await wait(() => pager.state.total === 1 && !pager.state.loading, 'restored resource lookup');
            check(table.textContent.includes('BROWSER Updated pagination resource'), 'Restoring must preserve search and return the resource to active inventory');
            results.push('resource forms create/edit/archive/restore pagination');
            continue;
        }
        if (test.deep) {
            check(pager.state.page === test.page, test.table + ' must locate a notification record on a later page');
            await wait(() => doc.querySelector('.modal.show'), test.table + ' deep-link details modal');
            check(doc.querySelector('.modal.show').textContent.includes(test.label), test.table + ' details must show the requested record');
            results.push(test.table + ' deep link');
            continue;
        }
        check(table.tBodies[0].rows.length === 10, test.table + ' must render 10 rows');
        check(button('Previous').disabled && !button('Next').disabled, test.table + ' first-page buttons');
        check(footer.textContent.includes('Showing 1\u201310 of ' + test.total), test.table + ' record summary');
        button('Next').click();
        await wait(() => pager.state.page === 2 && !pager.state.loading, test.table + ' next page');
        check(footer.textContent.includes('Showing 11\u201320'), test.table + ' must render the next database page');
        check(!button('Previous').disabled, test.table + ' previous must enable on page 2');
        check(!footer.querySelector('select') && pager.state.perPage === 10, test.table + ' has ten records per page without a size selector');
        const search = doc.querySelector('[data-table-search="#' + test.table + '"]');
        if (search) {
            change(search, 'PAG_%!', 'input');
            await wait(() => pager.state.total === (test.matches || 1) && !pager.state.loading, test.table + ' search');
            check(pager.state.page === 1 && button('Previous').disabled && button('Next').disabled, test.table + ' search must reset and bound pages');
            change(search, 'NO_SUCH_PAGINATION_RECORD_71fc', 'input');
            await wait(() => pager.state.total === 0 && !pager.state.loading, test.table + ' no matches');
            check(footer.textContent.includes('Showing 0\u20130 of 0 records') && button('Next').disabled && button('Previous').disabled, test.table + ' empty summary and navigation');
            change(search, '', 'input');
            await wait(() => pager.state.total === test.total && !pager.state.loading, test.table + ' cleared search');
        }
        const filter = doc.querySelector('[data-filter-select="#' + test.table + '"]');
        if (filter && filter.options.length > 1) {
            const original = filter.value;
            change(filter, filter.options[1].value);
            await wait(() => pager.state.total < test.total && !pager.state.loading, test.table + ' filtered page count');
            check(pager.state.page === 1 && pager.state.total > 0, test.table + ' filter must reset to matching database records');
            change(filter, original);
            await wait(() => pager.state.total === test.total && !pager.state.loading, test.table + ' cleared filter');
        }
        button('Page ' + pager.state.totalPages).click();
        await wait(() => pager.state.page === pager.state.totalPages && !pager.state.loading, test.table + ' last page');
        check(button('Next').disabled && !button('Previous').disabled, test.table + ' final-page buttons');
        frame.style.width = '390px';
        await new Promise((resolve) => setTimeout(resolve, 80));
        const bounds = footer.getBoundingClientRect();
        check(bounds.width <= page.innerWidth + 1, test.table + ' pagination footer must fit a mobile viewport');
        if (test.table === 'reportResourcesTable') {
            const modal = doc.querySelector('#reportPreviewModal');
            const style = doc.createElement('style'); style.textContent = '.modal.fade .modal-dialog,.modal-backdrop.fade{transition:none!important}'; doc.head.append(style);
            const nativeFetch = page.fetch.bind(page), printCalls = [];
            page.open = (url) => { printCalls.push(String(url)); return null; };
            const closePreview = async () => { modal.querySelector('.modal-footer [data-bs-dismiss="modal"]').click(); await wait(() => !modal.classList.contains('show') && !page.bootstrap.Modal.getInstance(modal)?._isTransitioning, 'preview closed'); };
            const openPreview = async (type) => {
                doc.querySelector(`[data-report-preview="${type}"]`).click();
                try {
                    await wait(() => modal.classList.contains('show') && !page.bootstrap.Modal.getInstance(modal)?._isTransitioning && !pager.state.loading, type + ' preview loaded');
                } catch (error) {
                    throw new Error(error.message + ': ' + JSON.stringify({modal:modal.className,transitioning:page.bootstrap.Modal.getInstance(modal)?._isTransitioning,state:pager.state,status:modal.querySelector('[data-preview-status]').textContent,title:modal.querySelector('.modal-title').textContent}));
                }
            };
            if (modal.classList.contains('show')) await closePreview();
            for (const width of [1440, 390]) {
                frame.style.width = width + 'px';
                for (const [type, title, column, metric] of [
                    ['resources', 'Resource stock', 'Threshold', 'Total available units'],
                    ['distribution', 'Distribution summary', 'Quantity distributed', 'Distribution transactions'],
                    ['evacuation', 'Evacuation capacity', 'Available spaces', 'Current occupants'],
                    ['residents', 'Resident summary', 'With recorded vulnerability', 'Active residents'],
                    ['resources', 'Resource stock', 'Threshold', 'Total available units'],
                ]) {
                    await openPreview(type);
                    check(modal.dataset.report === type && modal.querySelector('.modal-title').textContent.includes(title) && modal.querySelector('[data-preview-heading]').textContent === title, type + ' switches the preview title at ' + width + 'px');
                    check(table.tHead.textContent.includes(column) && (type === 'resources' || !table.tHead.textContent.includes('Threshold')), type + ' displays its own columns rather than stale resource fields');
                    check(modal.querySelector('[data-preview-metrics]').textContent.includes(metric) && (type === 'resources' || !modal.querySelector('[data-preview-metrics]').textContent.includes('Low-stock')), type + ' displays the matching summary metrics');
                    const exportButton = modal.querySelector('[data-report-export]'), exportUrl = new URL(exportButton.dataset.reportUrl);
                    check(!exportButton.disabled && exportUrl.searchParams.get('report') === type && exportButton.dataset.reportTitle === title && exportUrl.searchParams.get('from') === doc.querySelector('#reportDateFrom').value && exportUrl.searchParams.get('to') === doc.querySelector('#reportDateTo').value, type + ' export retains the report type, title, and selected period');
                    modal.querySelector('[data-print]').click();
                    check(printCalls.length > 0, type + ' print opens its filtered report: ' + JSON.stringify({disabled:modal.querySelector('[data-print]').disabled,source:modal.querySelector('[data-print]').dataset.print,controller:!!page.SagipbroPagination.get(table)}));
                    const printUrl = new URL(printCalls.at(-1), page.location.href);
                    check(printUrl.searchParams.get('report') === type && printUrl.searchParams.get('export') === 'print' && !printUrl.searchParams.has('page'), type + ' modal print uses all matching selected report rows');
                    doc.querySelector(`#${{resources:'resource-stock',distribution:'distribution-summary',evacuation:'evacuation-capacity',residents:'resident-summary'}[type]} [data-report-print-url]`).click();
                    check(new URL(printCalls.at(-1), page.location.href).searchParams.get('report') === type, type + ' catalog print uses the selected report');
                    const modalBounds = modal.querySelector('.modal-content').getBoundingClientRect(), action = exportButton.getBoundingClientRect();
                    check(modalBounds.left >= -1 && modalBounds.right <= width + 1 && action.height >= 40 && action.bottom <= page.innerHeight + 1, type + ' preview fits and actions remain reachable at ' + width + 'px');
                    check(pager.state.page === 1, type + ' starts on the first page instead of reusing a previous report page');
                    await closePreview();
                }
            }
            page.fetch = (url, options) => new URL(url, page.location.href).searchParams.get('report') === 'distribution'
                ? Promise.resolve(new page.Response(JSON.stringify({error:'Fixture distribution preview unavailable.'}), {status:503,headers:{'Content-Type':'application/json'}}))
                : nativeFetch(url, options);
            await openPreview('distribution');
            check(modal.querySelector('[data-preview-status]').textContent.includes('Fixture distribution preview unavailable') && !table.tHead.textContent.includes('Threshold') && modal.querySelector('[data-preview-metrics]').children.length === 0 && modal.querySelector('[data-report-export]').disabled, 'A failed request shows its error and clears the previous report instead of displaying stale resource information');
            await closePreview(); page.fetch = nativeFetch;
            await openPreview('residents');
            check(table.tHead.textContent.includes('Active residents') && !modal.querySelector('[data-report-export]').disabled, 'Selecting another report recovers after a failed preview');
            await closePreview();
            results.push('all four report previews, matching print/export, failure recovery and desktop/mobile layout');
        }
        results.push(test.table + ' desktop/mobile controls');
    }
    output.textContent = JSON.stringify({ ok: true, checks, results, cleanupResources });
})().catch((error) => { output.textContent = JSON.stringify({ ok: false, checks, results, error: error.message }); });
JAVASCRIPT;
    $script = str_replace('__CASES__', json_encode($cases, JSON_THROW_ON_ERROR | JSON_HEX_TAG), $script);
    file_put_contents($directory . '/browser.html', '<!doctype html><meta charset="utf-8"><title>Isolated admin pagination test</title><pre id="pagination-browser-result">Running</pre><iframe id="admin-page" title="Admin page"></iframe><script>' . $script . '</script>');
    $process = proc_open([$chrome, '--headless=new', '--disable-gpu', '--no-first-run', '--disable-extensions', '--disable-background-networking', '--user-data-dir=' . $directory . '/chrome-profile', '--virtual-time-budget=60000', '--dump-dom', $serverAddress . '/__pagination_browser'], [0 => ['pipe', 'r'], 1 => ['file', $directory . '/browser-dom.html', 'wb'], 2 => ['file', $directory . '/chrome.log', 'ab']], $pipes, dirname(__DIR__), $environment, ['bypass_shell' => true, 'create_new_console' => false]);
    pageCheck(is_resource($process), 'Cannot start headless Chrome.');
    fclose($pipes[0]);
    $deadline = microtime(true) + 55;
    while (proc_get_status($process)['running'] && microtime(true) < $deadline) usleep(50000);
    if (proc_get_status($process)['running']) proc_terminate($process);
    proc_close($process);
    $dom = (string) file_get_contents($directory . '/browser-dom.html');
    preg_match('/<pre id="pagination-browser-result">(.*?)<\/pre>/s', $dom, $result);
    $details = json_decode(html_entity_decode($result[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
    pageCheck(($details['ok'] ?? false) === true, 'Browser pagination probe failed: ' . json_encode($details));
    foreach ($details['cleanupResources'] ?? [] as $id) {
        $cleanup = $database->prepare("DELETE FROM resources WHERE id = ? AND name = 'BROWSER Updated pagination resource'");
        $cleanup->execute([(int) $id]);
    }
    fwrite(STDOUT, 'PASS: headless Chrome performed ' . $details['checks'] . " desktop/mobile pagination and deep-link assertions.\n");
}

function pageRemoveTemporaryDirectory(string $directory): void
{
    $resolved = realpath($directory);
    $base = realpath(sys_get_temp_dir());
    if ($resolved === false || $base === false || !str_starts_with(strtolower($resolved), strtolower($base . DIRECTORY_SEPARATOR . 'sagipbro-pagination-')) || !preg_match('/\Asagipbro-pagination-[a-f0-9]{16}\z/', basename($resolved))) return;
    $remove = static function (string $target) use (&$remove, $resolved): void {
        $absolute = realpath($target);
        if ($absolute === false || (strcasecmp($absolute, $resolved) !== 0 && !str_starts_with(strtolower($absolute), strtolower($resolved . DIRECTORY_SEPARATOR)))) return;
        if (is_dir($target) && !is_link($target)) {
            foreach (scandir($target) as $entry) if ($entry !== '.' && $entry !== '..') $remove($target . DIRECTORY_SEPARATOR . $entry);
            @rmdir($target);
        } else @unlink($target);
    };
    $remove($resolved);
}

try {
    $host = getenv('SAGIPBRO_DB_HOST') ?: '127.0.0.1';
    $port = getenv('SAGIPBRO_DB_PORT') ?: '3306';
    $user = getenv('SAGIPBRO_DB_USER');
    $password = getenv('SAGIPBRO_DB_PASSWORD');
    pageCheck(strpbrk($host, ";\r\n") === false && ctype_digit($port), 'Invalid test database server settings.');
    $database = new PDO('mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4', $user === false ? 'root' : $user, $password === false ? '' : $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5]);
    $database->exec("SET time_zone = '+08:00'");
    pageCheck((bool) preg_match('/\Asagipbro_page_test_[a-f0-9]{20}\z/', $testDatabase), 'Unsafe test database identifier.');
    $database->exec('CREATE DATABASE `' . $testDatabase . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $createdDatabase = true;
    $database->exec('USE `' . $testDatabase . '`');
    $schema = file_get_contents($project . '/database/sagipbro.sql');
    if ($schema === false) throw new LogicException('Cannot read database schema.');
    foreach (explode(';', $schema) as $statement) {
        $statement = trim($statement);
        if ($statement !== '' && !preg_match('/\A(?:CREATE\s+DATABASE\b|USE\b)/i', $statement)) $database->exec($statement);
    }
    $adminId = pageFixture($database, 'users', ['full_name' => 'Pagination Admin', 'username' => 'pagination_admin', 'password_hash' => 'NOT-A-LOGIN-HASH', 'role' => 'admin']);
    $householdId = pageFixture($database, 'households', ['household_no' => 'PAG-HOUSEHOLD', 'address' => 'Bonuan Binloc', 'barangay' => 'Bonuan Binloc']);
    $clock = $database->query("SELECT CURRENT_DATE() AS today, DATE_SUB(CURRENT_DATE(), INTERVAL 1 DAY) AS yesterday")->fetch();
    $resources = [];
    for ($i = 1; $i <= 105; ++$i) {
        $label = $i === 1 ? 'PAG_%! literal marker' : ($i >= 100 ? 'Pagination duplicate sort tie' : sprintf('Pagination fixture %03d', $i));
        $created = $clock['today'] . ' 08:00:00';
        $resources[$i] = pageFixture($database, 'resources', ['name' => $label, 'category' => $i % 2 ? 'Food packs' : 'Medical', 'unit' => 'packs', 'stock' => $i % 3 === 0 ? 0 : ($i % 3 === 1 ? 5 : 1000), 'low_stock_threshold' => 10, 'location' => 'Bonuan Binloc', 'status' => 'Available', 'created_at' => $created, 'updated_at' => $created]);
        pageFixture($database, 'evacuation_centers', ['name' => $label, 'address' => 'Bonuan Binloc', 'capacity' => 100, 'occupants' => [25, 25, 90, 100][$i % 4], 'status' => $i % 4 === 0 ? 'Closed' : 'Open', 'contact_person' => 'Pagination Contact', 'contact_number' => '09123456789', 'created_at' => $created, 'updated_at' => $created]);
        pageFixture($database, 'residents', ['household_id' => $householdId, 'first_name' => $label, 'last_name' => 'Resident', 'sex' => 'Other', 'status' => $i % 2 ? 'Active' : 'Inactive', 'address' => 'Bonuan Binloc', 'created_at' => $created, 'updated_at' => $created]);
        pageFixture($database, 'volunteers', ['full_name' => $label, 'contact' => '09123456789', 'availability' => 'Weekdays', 'skills' => 'First aid', 'status' => ['Active', 'Deployed', 'Inactive'][$i % 3], 'created_at' => $created, 'updated_at' => $created]);
        pageFixture($database, 'users', ['full_name' => $label, 'username' => 'pagination_user_' . $i, 'email' => 'pagination' . $i . '@example.test', 'password_hash' => 'NOT-A-LOGIN-HASH', 'role' => ['admin', 'official', 'volunteer', 'resident'][$i % 4], 'status' => $i % 5 ? 'Active' : 'Inactive', 'created_at' => $created, 'updated_at' => $created]);
        pageFixture($database, 'announcements', ['title' => $label, 'body' => 'Pagination body', 'category' => $i % 2 ? 'Advisory' : 'Emergency', 'audience' => $i % 2 ? 'All residents' : 'Volunteers', 'status' => ['Draft', 'Published', 'Archived'][$i % 3], 'created_by' => $adminId, 'created_at' => $created, 'updated_at' => $created]);
        pageFixture($database, 'contact_messages', ['name' => $label, 'email' => 'message@example.test', 'subject' => 'Pagination inquiry', 'message' => 'Pagination body', 'status' => ['Unread', 'Read', 'Resolved'][$i % 3], 'created_at' => $created, 'updated_at' => $created]);
        pageFixture($database, 'distributions', ['resource_id' => $resources[$i], 'recipient_name' => $label, 'quantity' => 1, 'distributed_by' => $adminId, 'distributed_at' => ($i % 2 ? $clock['yesterday'] : $clock['today']) . ' 08:00:00', 'location' => 'Bonuan Binloc', 'status' => $i % 2 ? 'Pending review' : 'Completed']);
        pageFixture($database, 'activity_logs', ['user_id' => $adminId, 'action' => 'create', 'entity_type' => 'resource', 'entity_id' => $resources[$i], 'details' => json_encode(['name' => $label]), 'created_at' => $created]);
        pageFixture($database, 'activity_logs', ['user_id' => $adminId, 'action' => 'generate', 'entity_type' => 'report', 'details' => json_encode(['report' => 'resources', 'title' => $label, 'from' => $clock['yesterday'], 'to' => $clock['today'], 'coverage' => '', 'format' => 'CSV']), 'created_at' => $created]);
    }
    $archivedResourceId = pageFixture($database, 'resources', ['name' => 'Archived pagination fixture', 'category' => 'Food packs', 'unit' => 'packs', 'stock' => 1, 'status' => 'Inactive']);
    pageFixture($database, 'user_sessions', ['session_hash' => hash('sha256', 'pagination_presence'), 'user_id' => $adminId, 'last_seen_at' => date('Y-m-d H:i:s')]);

    $temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sagipbro-pagination-' . bin2hex(random_bytes(8));
    if (!mkdir($temporaryDirectory, 0700)) throw new LogicException('Cannot create private session directory.');
    putenv('SAGIPBRO_SESSION_PATH=' . $temporaryDirectory);
    require_once $project . '/config/session.php';
    $_SESSION['user_id'] = $adminId;
    $_SESSION['role'] = 'admin';
    $_SESSION['full_name'] = 'Pagination Admin';
    $_SESSION['username'] = 'pagination_admin';
    $csrf = csrfToken();
    $sessionId = session_id();
    $sessionCookie = session_name() . '=' . $sessionId;
    pageFixture($database, 'user_sessions', ['session_hash' => hash('sha256', $sessionId), 'user_id' => $adminId, 'last_seen_at' => date('Y-m-d H:i:s')]);
    session_write_close();
    $environment = getenv();
    $environment['SAGIPBRO_DB_HOST'] = $host;
    $environment['SAGIPBRO_DB_PORT'] = $port;
    $environment['SAGIPBRO_DB_USER'] = $user === false ? 'root' : $user;
    $environment['SAGIPBRO_DB_PASSWORD'] = $password === false ? '' : $password;
    $environment['SAGIPBRO_DB_NAME'] = $testDatabase;
    $environment['SAGIPBRO_SESSION_PATH'] = $temporaryDirectory;
    $environment['SAGIPBRO_BASE_URL'] = '';
    unset($environment['PHP_CLI_SERVER_WORKERS']);
    $reservation = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorString);
    if ($reservation === false) throw new LogicException('Cannot reserve an isolated test port.');
    $listen = stream_socket_get_name($reservation, false);
    fclose($reservation);
    $serverAddress = 'http://' . $listen;
    $serverCommand = [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-S', $listen, '-t', $project];
    $browser = getenv('SAGIPBRO_PAGINATION_BROWSER') === '1' || getenv('SAGIPBRO_REPORT_PREVIEW_BROWSER') === '1';
    if ($browser) {
        $volunteerIdentity = $database->query("SELECT id, full_name FROM users WHERE role = 'volunteer' ORDER BY id LIMIT 1")->fetch();
        $volunteerStatistics = $database->prepare("SELECT
            (SELECT COUNT(*) FROM distribution_events WHERE publication_status = 'Published' AND DATE(starts_at) = CURDATE()),
            (SELECT COUNT(*) FROM evacuation_centers WHERE status = 'Open'),
            COUNT(DISTINCT COALESCE(recipient_resident_id, recipient_name)), COALESCE(SUM(quantity), 0)
            FROM distributions WHERE distributed_by = ? AND DATE(distributed_at) = CURDATE()");
        $volunteerStatistics->execute([(int) $volunteerIdentity['id']]);
        $volunteerStatValues = $volunteerStatistics->fetch(PDO::FETCH_NUM);
        // Only this isolated loopback test server loads the temporary router.
        $router = '<?php $path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH); if ($path === "/__pagination_browser") { setcookie(' . var_export(session_name(), true) . ', ' . var_export($sessionId, true) . ', ["path" => "/", "httponly" => true, "samesite" => "Lax"]); header("Content-Type: text/html; charset=utf-8"); readfile(' . var_export($temporaryDirectory . '/browser.html', true) . '); return true; } if (preg_match("~^/(?:pages/(?:resources|evacuation|distribution|residents|volunteers|users|announcements|messages|activity|reports)/index|dashboard/admin)\\.php$~", $path)) { $target = ' . var_export($project, true) . ' . $path; chdir(dirname($target)); ob_start(); require $target; header_remove("X-Frame-Options"); ob_end_flush(); return true; } return false;';
        // Render the actual role-restricted template with a private fixture identity;
        // restore the administrator session before this test request is persisted.
        $volunteerRoute = 'if ($path === "/__volunteer_stats") { require_once ' . var_export($project . '/config/session.php', true) . '; $originalSession = $_SESSION; session_write_close(); $_SESSION["user_id"] = ' . (int) $volunteerIdentity['id'] . '; $_SESSION["role"] = "volunteer"; $_SESSION["full_name"] = ' . var_export($volunteerIdentity['full_name'], true) . '; chdir(' . var_export($project . '/dashboard', true) . '); ob_start(); try { require ' . var_export($project . '/dashboard/volunteer.php', true) . '; } finally { $_SESSION = $originalSession; } header_remove("X-Frame-Options"); ob_end_flush(); return true; } ';
        $router = str_replace('return false;', $volunteerRoute . 'return false;', $router);
        file_put_contents($temporaryDirectory . '/router.php', $router);
        $serverCommand[] = $temporaryDirectory . '/router.php';
    }
    $server = proc_open($serverCommand, [0 => ['pipe', 'r'], 1 => ['file', $temporaryDirectory . '/server.log', 'ab'], 2 => ['file', $temporaryDirectory . '/server.log', 'ab']], $pipes, $project, $environment, ['bypass_shell' => true, 'create_new_console' => false]);
    if (!is_resource($server)) throw new LogicException('Cannot start isolated PHP server.');
    fclose($pipes[0]);
    $deadline = microtime(true) + 5;
    do {
        if (!proc_get_status($server)['running']) throw new LogicException('Isolated PHP server exited.');
        $ready = @stream_socket_client('tcp://' . $listen, $errorNumber, $errorString, 0.1);
        if (is_resource($ready)) { fclose($ready); break; }
        usleep(50000);
    } while (microtime(true) < $deadline);

    $endpoints = ['resources' => 105, 'centers' => 105, 'distribution' => 105, 'residents' => 105, 'volunteers' => 105, 'users' => 106, 'announcements' => 105, 'messages' => 105, 'activity' => 210, 'report-history' => 105, 'report-preview' => 105];
    foreach ($endpoints as $endpoint => $total) {
        $unauthorized = pageRequest($endpoint, ['paginate' => '1'], 'GET', null, false);
        pageCheck($unauthorized['status'] === 401, $endpoint . ' must require authentication.');
        $baseQuery = $endpoint === 'report-preview' ? ['from' => $clock['yesterday'], 'to' => $clock['today']] : [];
        // The activity search sees both resource and report labels.
        if ($endpoint === 'activity') {
            checkPageSet($endpoint, 105, ['action' => 'Created']);
            $all = pageGet($endpoint);
            pageCheck((int) $all['pagination']['total'] === $total, 'Activity must include report generation as well as resource logs.');
        } else checkPageSet($endpoint, $total, $baseQuery);
        fwrite(STDOUT, 'PASS: ' . $endpoint . " pagination, sizes, boundaries, search, empty results, and stable ordering.\n");
    }
    $previewPeriod = ['from' => $clock['yesterday'], 'to' => $clock['today']];
    foreach (['distribution', 'evacuation'] as $type) {
        checkPageSet('report-preview', 105, ['report' => $type] + $previewPeriod);
        $previewResult = pageGet('report-preview', ['report' => $type] + $previewPeriod);
        pageCheck($previewResult['report'] === $type && count($previewResult['metrics']) === 3, 'Report preview selects its own type and summary metrics: ' . $type);
        pageCheck(!array_key_exists('stock', $previewResult['columns']) && !array_key_exists('low_stock_threshold', $previewResult['columns']), 'Non-resource preview must not contain resource stock columns.');
        $print = pageRequest('report-preview', ['report' => $type, 'page' => 2, 'export' => 'print'] + $previewPeriod);
        preg_match('/<tbody>(.*?)<\/tbody>/s', $print['body'], $printRows);
        pageCheck($print['status'] === 200 && substr_count($printRows[1] ?? '', '<tr>') === 105 && !str_contains($print['body'], 'Resource stock report'), 'Print uses all rows of the selected report: ' . $type);
    }
    $extraDistribution = pageFixture($database, 'distributions', ['resource_id' => $resources[1], 'recipient_name' => 'Preview recipient', 'quantity' => 4, 'distributed_by' => $adminId, 'distributed_at' => $clock['today'] . ' 09:00:00', 'location' => 'Preview location']);
    $groupedPreview = pageGet('report-preview', ['report' => 'distribution', 'q' => 'PAG_%!'] + $previewPeriod);
    pageCheck(count($groupedPreview['data']) === 1 && (int) $groupedPreview['data'][0]['transactions'] === 2 && (int) $groupedPreview['data'][0]['quantity_distributed'] === 5, 'Distribution preview groups and totals transactions instead of displaying current resource stock.');
    $database->exec('DELETE FROM distributions WHERE id = ' . $extraDistribution);
    $residentsPreview = pageGet('report-preview', ['report' => 'residents'] + $previewPeriod);
    $activeResidents = (int) $database->query("SELECT COUNT(*) FROM residents WHERE status='Active'")->fetchColumn();
    pageCheck($residentsPreview['report'] === 'residents' && count($residentsPreview['data']) === 1 && (int) $residentsPreview['data'][0]['total'] === $activeResidents && array_key_exists('vulnerable', $residentsPreview['columns']), 'Resident preview reports active residents and vulnerability totals.');
    $emptyResidents = pageGet('report-preview', ['report' => 'residents', 'q' => 'NO_SUCH_PREVIEW_RESIDENT'] + $previewPeriod);
    pageCheck((int) $emptyResidents['data'][0]['total'] === 0 && (int) $emptyResidents['stats']['vulnerable'] === 0, 'An empty resident summary reports zero people without reusing resource totals.');
    $coveredDistribution = pageGet('report-preview', ['report' => 'distribution', 'coverage' => 'Medical'] + $previewPeriod);
    pageCheck((int) $coveredDistribution['pagination']['total'] === 52, 'Distribution preview retains resource category filters.');
    pageCheck(pageRequest('report-preview', ['report' => 'not-a-report'])['status'] === 422, 'Unknown preview types are rejected instead of falling back to resources.');
    fwrite(STDOUT, "PASS: each report preview has matching data, summary metrics, filters, pagination and complete print output.\n");
    $deepCases = [];
    foreach (['resources' => ['pages/resources/index.php', 'resourcesTable'], 'centers' => ['pages/evacuation/index.php', 'centersTable'], 'distribution' => ['pages/distribution/index.php', 'distributionsTable'], 'residents' => ['pages/residents/index.php', 'residentsTable'], 'volunteers' => ['pages/volunteers/index.php', 'volunteersTable'], 'users' => ['pages/users/index.php', 'usersTable'], 'announcements' => ['pages/announcements/index.php', 'announcementsTable'], 'messages' => ['pages/messages/index.php', 'messagesTable'], 'activity' => ['pages/activity/index.php', 'activityLogsTable']] as $endpoint => [$route, $table]) {
        $second = pageGet($endpoint, ['page' => 2]);
        $record = $second['data'][0];
        $lookup = pageRequest($endpoint, ['id' => $record['id'], 'per_page' => 10]);
        pageCheck($lookup['status'] === 200 && (int) ($lookup['json']['record_page'] ?? 0) === 2 && (int) $lookup['json']['data'][0]['id'] === (int) $record['id'], $endpoint . ' must locate records outside the initial page.');
        if (in_array($endpoint, ['resources', 'centers', 'distribution', 'residents', 'volunteers', 'users', 'announcements'], true)) {
            $deepCases[] = ['route' => $route . '?view=' . $record['id'], 'table' => $table, 'total' => $endpoints[$endpoint], 'deep' => true, 'page' => 2, 'label' => $record['name'] ?? $record['full_name'] ?? $record['title'] ?? $record['recipient_name'] ?? $record['first_name']];
        }
    }
    if ($browser) {
        $cases = [];
        foreach ([['pages/resources/index.php', 'resourcesTable', 105], ['pages/evacuation/index.php', 'centersTable', 105], ['pages/distribution/index.php', 'distributionsTable', 105], ['pages/residents/index.php', 'residentsTable', 105], ['pages/volunteers/index.php', 'volunteersTable', 105], ['pages/users/index.php', 'usersTable', 106], ['pages/announcements/index.php', 'announcementsTable', 105], ['pages/messages/index.php', 'messagesTable', 105], ['pages/activity/index.php', 'activityLogsTable', 210], ['dashboard/admin.php', 'dashboardActivityTable', 210], ['pages/reports/index.php', 'recentReportsTable', 105], ['pages/reports/index.php', 'reportResourcesTable', 105]] as [$route, $table, $total]) {
            $cases[] = ['route' => $route, 'table' => $table, 'total' => $total, 'matches' => $table === 'activityLogsTable' ? 2 : 1];
        }
        $cases[] = ['route' => '__volunteer_stats', 'table' => 'volunteerDashboardStats', 'statsOnly' => true, 'statValues' => $volunteerStatValues];
        $deepCases[] = ['route' => 'pages/resources/index.php?view=' . $archivedResourceId, 'table' => 'resourcesTable', 'total' => 1, 'deep' => true, 'page' => 1, 'label' => 'Archived pagination fixture'];
        $deepCases[] = ['route' => 'pages/resources/index.php', 'table' => 'resourcesTable', 'total' => 105, 'crud' => true];
        if (getenv('SAGIPBRO_REPORT_PREVIEW_BROWSER') === '1' && getenv('SAGIPBRO_PAGINATION_BROWSER') !== '1') {
            $cases = array_values(array_filter($cases, static fn (array $case): bool => $case['table'] === 'reportResourcesTable'));
            $deepCases = [];
        }
        pageBrowserCheck($temporaryDirectory, array_merge($cases, $deepCases), $environment, $database);
    }

    foreach ([
        ['resources', ['category' => 'Food packs', 'status' => 'Low stock'], 18],
        ['resources', ['status' => 'Out of stock'], 35],
        ['resources', ['view' => 'archived'], 1],
        ['centers', ['status' => 'Near capacity', 'area' => 'Bonuan Binloc'], 26],
        ['centers', ['status' => 'Full'], 26],
        ['centers', ['status' => 'Closed'], 26],
        ['centers', ['area' => 'Different area'], 0],
        ['distribution', ['category' => 'Medical', 'status' => 'Completed', 'period' => 'today'], 52],
        ['distribution', ['period' => 'previous'], 53],
        ['residents', ['status' => 'Active'], 53],
        ['volunteers', ['status' => 'Deployed'], 35],
        ['users', ['role' => 'Volunteer'], 26],
        ['users', ['presence' => 'Online'], 1],
        ['users', ['status' => 'Inactive'], 21],
        ['announcements', ['category' => 'Advisory', 'status' => 'Published', 'audience' => 'All residents'], 18],
        ['messages', ['status' => 'Unread'], 35],
        ['activity', ['action' => 'Exported'], 105],
        ['report-preview', ['coverage' => 'Medical', 'from' => $clock['yesterday'], 'to' => $clock['today']], 52],
    ] as [$endpoint, $query, $total]) {
        $result = pageGet($endpoint, array_merge($query, ['page' => 999]));
        pageCheck((int) $result['pagination']['total'] === $total && (int) $result['pagination']['page'] === max(1, (int) ceil($total / 10)), $endpoint . ' must combine database filters with pagination: ' . json_encode($query));
    }
    $lookup = pageRequest('resources', ['lookup' => 'distribution']);
    pageCheck($lookup['status'] === 200 && count($lookup['json']['data'] ?? []) === 105 && !array_key_exists('notes', $lookup['json']['data'][0]), 'Distribution inventory lookup must retain all active choices using a compact payload.');
    $export = pageRequest('distribution', ['paginate' => '1', 'page' => 2, 'category' => 'Medical', 'export' => 'csv']);
    $csv = fopen('php://temp', 'w+');
    fwrite($csv, $export['body']);
    rewind($csv);
    $rowCount = 0;
    while (fgetcsv($csv, 0, ',', '"', '') !== false) ++$rowCount;
    fclose($csv);
    pageCheck($export['status'] === 200 && str_contains(strtolower($export['headers']), 'text/csv') && $rowCount === 53, 'CSV export must include all 52 filtered distribution rows, not just the current page.');
    $resourceExport = pageRequest('resources', ['paginate' => '1', 'page' => 2, 'category' => 'Medical', 'export' => 'csv']);
    pageCheck($resourceExport['status'] === 200 && str_contains(strtolower($resourceExport['headers']), 'text/csv') && substr_count(trim($resourceExport['body']), "\n") === 52, 'Resource export must include all filtered records rather than the current page.');
    foreach ([
        ['centers', ['status' => 'Full'], 26],
        ['activity', ['action' => 'Exported'], 105],
        ['report-preview', ['coverage' => 'Medical', 'from' => $clock['yesterday'], 'to' => $clock['today']], 52],
    ] as [$endpoint, $query, $expectedRows]) {
        $print = pageRequest($endpoint, array_merge($query, ['paginate' => '1', 'page' => 2, 'export' => 'print']));
        preg_match('/<tbody>(.*?)<\/tbody>/s', $print['body'], $printRows);
        pageCheck($print['status'] === 200 && str_contains(strtolower($print['headers']), 'text/html') && substr_count($printRows[1] ?? '', '<tr>') === $expectedRows, $endpoint . ' print output must include every matching record, not only the selected page.');
    }
    fwrite(STDOUT, "PASS: combined filters, global inventory choices, presence, and complete filtered CSV export.\n");

    // A real archive on the only row of page 11 must clamp a subsequent reload to page 10.
    $lastResource = pageGet('resources', ['page' => 11]);
    pageCheck(count($lastResource['data']) === 5, 'The initial final resource page must contain five rows.');
    foreach (array_slice($lastResource['data'], 0, 4) as $record) pageMutation('resources', 'DELETE', ['id' => $record['id']]);
    $single = pageGet('resources', ['page' => 11]);
    pageCheck(count($single['data']) === 1, 'The final page must stay accessible with one remaining row.');
    $archivedId = (int) $single['data'][0]['id'];
    pageMutation('resources', 'DELETE', ['id' => $archivedId]);
    $clamped = pageGet('resources', ['page' => 11]);
    pageCheck((int) $clamped['pagination']['total'] === 100 && (int) $clamped['pagination']['page'] === 10 && count($clamped['data']) === 10, 'Deleting the last row on the last page must fall back to the new last page.');
    pageMutation('resources', 'PUT', ['id' => $archivedId, 'action' => 'restore']);
    $restored = pageGet('resources', ['page' => 11]);
    pageCheck((int) $restored['pagination']['total'] === 101 && count($restored['data']) === 1, 'Restoring a resource must refresh pagination totals.');

    $resourceData = ['name' => 'CRUD Pagination resource', 'category' => 'Medical', 'unit' => 'packs', 'stock' => 20, 'low_stock_threshold' => 5, 'status' => 'Available'];
    $createdResource = pageMutation('resources', 'POST', $resourceData, 201);
    $resourceId = (int) $createdResource['id'];
    $resourceData['name'] = 'CRUD Updated pagination resource';
    pageMutation('resources', 'PUT', array_merge($resourceData, ['id' => $resourceId]));
    $resourceQuery = pageGet('resources', ['q' => 'CRUD Updated', 'category' => 'Medical', 'status' => 'In stock', 'page' => 11]);
    pageCheck((int) $resourceQuery['pagination']['total'] === 1 && (int) $resourceQuery['data'][0]['id'] === $resourceId, 'Created/edited resources must become searchable in the paginated list.');

    $distributionData = ['resource_id' => $resourceId, 'quantity' => 4, 'recipient_name' => 'CRUD Pagination recipient', 'location' => 'Test location', 'status' => 'Pending review'];
    $createdDistribution = pageMutation('distribution', 'POST', $distributionData, 201);
    $distributionId = (int) $createdDistribution['id'];
    $distribution = pageGet('distribution', ['q' => 'CRUD Pagination recipient', 'status' => 'Pending review']);
    pageCheck((int) $distribution['pagination']['total'] === 1 && (int) $distribution['data'][0]['id'] === $distributionId, 'New distributions must remain filterable.');
    pageCheck((int) $database->query('SELECT stock FROM resources WHERE id = ' . $resourceId)->fetchColumn() === 16, 'Recording a distribution must still deduct inventory.');
    pageMutation('distribution', 'PUT', array_merge($distributionData, ['id' => $distributionId, 'quantity' => 7, 'status' => 'Completed']));
    $changed = pageGet('distribution', ['q' => 'CRUD Pagination recipient', 'status' => 'Pending review']);
    pageCheck((int) $changed['pagination']['total'] === 0 && (int) $database->query('SELECT stock FROM resources WHERE id = ' . $resourceId)->fetchColumn() === 13, 'Distribution edits must refresh filtered totals and reconcile inventory.');
    pageMutation('distribution', 'DELETE', ['id' => $distributionId, 'reason' => 'Pagination test reversal']);
    pageCheck((int) $database->query('SELECT stock FROM resources WHERE id = ' . $resourceId)->fetchColumn() === 20 && (int) pageGet('distribution', ['q' => 'CRUD Pagination recipient'])['pagination']['total'] === 0, 'Reversal must remove the record and restore stock.');

    $centerData = ['name' => 'CRUD Pagination center', 'location' => 'Test address', 'capacity' => 100, 'occupants' => 5, 'contact_person' => 'Test Contact', 'contact_number' => '09123456789', 'status' => 'Open'];
    $center = pageMutation('centers', 'POST', $centerData, 201);
    pageMutation('centers', 'PUT', array_merge($centerData, ['id' => $center['id'], 'occupants' => 90]));
    pageCheck((int) pageGet('centers', ['q' => 'CRUD Pagination center', 'status' => 'Near capacity'])['pagination']['total'] === 1, 'Edited occupancy must update the center status filter.');
    pageMutation('centers', 'DELETE', ['id' => $center['id']]);
    pageCheck((int) pageGet('centers', ['q' => 'CRUD Pagination center', 'status' => 'Open'])['pagination']['total'] === 0, 'Closing a center must remove it from the active filter.');

    $messageId = (int) pageGet('messages', ['q' => 'PAG_%!'])['data'][0]['id'];
    pageMutation('messages', 'PUT', ['id' => $messageId, 'status' => 'Resolved']);
    pageCheck((int) pageGet('messages', ['q' => 'PAG_%!', 'status' => 'Read'])['pagination']['total'] === 0, 'Changing message status must update pagination matches.');
    $announcementData = ['title' => 'CRUD Pagination announcement', 'body' => 'Confirmed test instructions.', 'category' => 'Advisory', 'audience' => 'All residents', 'status' => 'Draft'];
    $announcement = pageMutation('announcements', 'POST', $announcementData, 201);
    pageMutation('announcements', 'PUT', array_merge($announcementData, ['id' => $announcement['id'], 'status' => 'Published']));
    pageCheck((int) pageGet('announcements', ['q' => 'CRUD Pagination announcement', 'status' => 'Published'])['pagination']['total'] === 1, 'Publishing must update announcement pagination matches.');
    pageMutation('announcements', 'DELETE', ['id' => $announcement['id']]);
    pageCheck((int) pageGet('announcements', ['q' => 'CRUD Pagination announcement', 'status' => 'Published'])['pagination']['total'] === 0, 'Archiving must update announcement pagination matches.');

    $residentData = ['first_name' => 'CRUD Pagination', 'last_name' => 'Resident', 'sex' => 'Other', 'household_id' => $householdId, 'address' => 'Test address'];
    $resident = pageMutation('residents', 'POST', $residentData, 201);
    pageMutation('residents', 'PUT', array_merge($residentData, ['id' => $resident['id'], 'status' => 'Inactive']));
    pageCheck((int) pageGet('residents', ['q' => 'CRUD Pagination', 'status' => 'Inactive'])['pagination']['total'] === 1, 'Resident edits must refresh status-filtered totals.');
    $volunteerData = ['full_name' => 'CRUD Pagination volunteer', 'contact' => '09123456789', 'email' => 'volunteer@example.test', 'availability' => 'Weekdays', 'skills' => 'First aid'];
    $volunteer = pageMutation('volunteers', 'POST', $volunteerData, 201);
    pageMutation('volunteers', 'PUT', array_merge($volunteerData, ['id' => $volunteer['id'], 'status' => 'Deployed']));
    pageCheck((int) pageGet('volunteers', ['q' => 'CRUD Pagination volunteer', 'status' => 'Deployed'])['pagination']['total'] === 1, 'Volunteer deployment must update filtered pagination.');
    pageMutation('volunteers', 'DELETE', ['id' => $volunteer['id']]);
    pageCheck((int) pageGet('volunteers', ['q' => 'CRUD Pagination volunteer', 'status' => 'Deployed'])['pagination']['total'] === 0, 'Volunteer deactivation must update filtered pagination.');
    $userData = ['full_name' => 'CRUD Pagination user', 'username' => 'crud_pagination_user', 'email' => 'crud-user@example.test', 'password' => 'Pagination-test-' . bin2hex(random_bytes(16)), 'role' => 'resident'];
    $account = pageMutation('users', 'POST', $userData, 201);
    pageMutation('users', 'PUT', array_merge($userData, ['id' => $account['id'], 'role' => 'official', 'status' => 'Active']));
    pageCheck((int) pageGet('users', ['q' => 'CRUD Pagination user', 'role' => 'Barangay Official'])['pagination']['total'] === 1, 'Changing an account role must update paginated role filters.');
    pageMutation('users', 'DELETE', ['id' => $account['id']]);
    pageCheck((int) pageGet('users', ['q' => 'CRUD Pagination user', 'status' => 'Active'])['pagination']['total'] === 0, 'Account deactivation must update filtered pagination.');
    pageMutation('reports', 'POST', ['action' => 'record_export', 'report' => 'resources', 'title' => 'CRUD Pagination report', 'from' => $clock['yesterday'], 'to' => $clock['today']]);
    pageCheck((int) pageGet('report-history', ['q' => 'CRUD Pagination report'])['pagination']['total'] === 1, 'Generated reports must appear in paginated report history.');
    fwrite(STDOUT, "PASS: create/edit/archive/restore, final-page removal, announcement/message status, and distribution inventory reconciliation.\n");
    fwrite(STDOUT, 'PASS: ' . $checks . " HTTP pagination assertions using an isolated database; live records untouched.\n");
} catch (Throwable $error) {
    $failed = true;
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
} finally {
    if (is_resource($server)) {
        if (proc_get_status($server)['running']) proc_terminate($server);
        proc_close($server);
    }
    if ($createdDatabase && $database instanceof PDO && preg_match('/\Asagipbro_page_test_[a-f0-9]{20}\z/', $testDatabase)) {
        $database->exec('DROP DATABASE `' . $testDatabase . '`');
    }
    if (is_string($temporaryDirectory)) pageRemoveTemporaryDirectory($temporaryDirectory);
}
exit($failed ? 1 : 0);
