<?php
declare(strict_types=1);

/** php tests/floating_announcement_http.php; optional Chrome: SAGIPBRO_FLOATING_BROWSER=1.
 * Uses a private fixture database and loopback server; never selects application records.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run from the command line.'); }
require_once __DIR__ . '/../includes/public_data.php';
$project = dirname(__DIR__);
$name = 'sagipbro_float_' . bin2hex(random_bytes(8));
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sagipbro-float-' . bin2hex(random_bytes(8));
$db = null; $created = false; $server = null; $checks = 0; $failed = false;
function faCheck(bool $value, string $message): void {
    global $checks; ++$checks; if (!$value) throw new LogicException($message);
}
function faGet(string $route): string {
    global $address;
    $body = file_get_contents($address . $route);
    faCheck(is_string($body) && !preg_match('/SQLSTATE|Fatal error|Warning:/i', $body), 'HTTP response has no technical errors.');
    return $body;
}
function faDom(string $html): DOMXPath {
    $document = new DOMDocument(); $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8">' . $html); libxml_clear_errors(); libxml_use_internal_errors($previous);
    return new DOMXPath($document);
}
try {
    $host = getenv('SAGIPBRO_DB_HOST') ?: '127.0.0.1'; $port = getenv('SAGIPBRO_DB_PORT') ?: '3306';
    $user = getenv('SAGIPBRO_DB_USER'); $password = getenv('SAGIPBRO_DB_PASSWORD');
    faCheck(strpbrk($host, ";\r\n") === false && ctype_digit($port), 'Safe connection settings.');
    $db = new PDO('mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4', $user === false ? 'root' : $user, $password === false ? '' : $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    faCheck((bool) preg_match('/\Asagipbro_float_[a-f0-9]{16}\z/', $name), 'Safe fixture database name.');
    $db->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $created = true;
    $db->exec('USE `' . $name . '`'); $db->exec("SET time_zone = '+08:00'");
    foreach (explode(';', (string) file_get_contents($project . '/database/sagipbro.sql')) as $sql) {
        $sql = trim($sql); if ($sql !== '' && !preg_match('/\A(?:CREATE\s+DATABASE|USE)\b/i', $sql)) $db->exec($sql);
    }
    $db->exec("INSERT INTO users (full_name, username, password_hash, role) VALUES ('Floating fixture', 'floating_fixture', 'NOT-A-LOGIN', 'admin')");
    $staff = (int) $db->lastInsertId();
    $insert = $db->prepare('INSERT INTO announcements (title, body, status, created_by, published_at, expires_at, priority) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $add = static function (string $title, string $status, ?string $date, ?string $expires = null, string $priority = 'Normal') use ($insert, $staff, $db): int {
        $insert->execute([$title, 'FULL_BODY_ONLY_ON_ANNOUNCEMENTS_PAGE', $status, $staff, $date, $expires, $priority]);
        return (int) $db->lastInsertId();
    };
    faCheck(publicFloatingAnnouncement($db) === null, 'Empty database has no floating shortcut.');
    $old = $add('Older urgent', 'Published', '2020-01-01 12:00:00', null, 'Urgent');
    $latest = $add('Latest & <img src=x onerror=window.floatingXss=true> community update with a long title for two lines', 'Published', '2021-01-01 12:00:00');
    foreach ([['Draft', '2022-01-01 00:00:00', null], ['Archived', '2022-01-01 00:00:00', null], ['Published', '2099-01-01 00:00:00', null], ['Published', '2022-01-01 00:00:00', '2023-01-01 00:00:00'], ['Published', null, null]] as [$status, $date, $expires]) {
        $add('PRIVATE_FLOATING_NOTICE', $status, $date, $expires);
    }
    faCheck((int) publicFloatingAnnouncement($db)['id'] === $latest, 'Newest public notice wins over older urgent; private/future/expired notices stay hidden.');
    faCheck(array_keys(publicFloatingAnnouncement($db)) === ['id', 'title'], 'Shortcut query retrieves no body or author data.');
    faCheck(mkdir($directory, 0700), 'Private fixture directory created.');
    $environment = getenv();
    foreach (['SAGIPBRO_DB_HOST' => $host, 'SAGIPBRO_DB_PORT' => $port, 'SAGIPBRO_DB_NAME' => $name, 'SAGIPBRO_DB_USER' => $user === false ? 'root' : $user, 'SAGIPBRO_DB_PASSWORD' => $password === false ? '' : $password, 'SAGIPBRO_SESSION_PATH' => $directory, 'SAGIPBRO_BASE_URL' => ''] as $key => $value) $environment[$key] = $value;
    unset($environment['PHP_CLI_SERVER_WORKERS']);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error); $listen = stream_socket_get_name($socket, false); fclose($socket); $address = 'http://' . $listen;
    $router = '<?php $path=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH); if($path==="/__floating_browser"){readfile(' . var_export($directory . '/browser.html', true) . ');return true;} if(in_array($path,["/__admin","/__auth"],true)){$isAdmin=$path==="/__admin";$isAuthPage=$path==="/__auth";include ' . var_export($project . '/includes/header.php', true) . ';include ' . var_export($project . '/includes/footer.php', true) . ';return true;} if(in_array($path,["/about.php","/announcements.php"],true)){ob_start();require ' . var_export($project, true) . '.$path;header_remove("X-Frame-Options");ob_end_flush();return true;}return false;';
    file_put_contents($directory . '/router.php', $router);
    $server = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $listen, '-t', $project, $directory . '/router.php'], [0 => ['pipe', 'r'], 1 => ['file', $directory . '/server.log', 'ab'], 2 => ['file', $directory . '/server.log', 'ab']], $pipes, $project, $environment, ['bypass_shell' => true, 'create_new_console' => false]);
    faCheck(is_resource($server), 'Private HTTP server starts.'); fclose($pipes[0]);
    $deadline = microtime(true) + 5;
    do { $probe = @stream_socket_client('tcp://' . $listen, $errno, $error, .1); if ($probe) { fclose($probe); break; } usleep(25000); } while (microtime(true) < $deadline);
    $html = faGet('/about.php'); $dom = faDom($html);
    $card = $dom->query('//aside[@data-floating-announcement]')->item(0);
    faCheck($card instanceof DOMElement && (int) $card->getAttribute('data-floating-announcement') === $latest, 'Public page includes exactly the newest shortcut.');
    faCheck(!$card->hasAttribute('hidden'), 'Floating notice is visible even before its JavaScript loads.');
    faCheck($dom->query('//aside[@data-floating-announcement]')->length === 1 && $dom->query('.//a', $card)->length === 1, 'One floating card and one full-card link.');
    faCheck($dom->query('.//img|.//script|.//*[@data-bs-toggle]', $card)->length === 0 && !str_contains($card->textContent, 'FULL_BODY') && !str_contains($html, 'PRIVATE_FLOATING_NOTICE'), 'Card has escaped text, no body, and no modal behavior.');
    faCheck($dom->query('.//a//button', $card)->length === 0 && $dom->query('.//button[@aria-label="Minimize announcement"]', $card)->length === 1, 'Minimize button is accessible and separate from navigation.');
    faCheck($dom->query('//button[@data-floating-announcement-restore and @aria-controls="floatingAnnouncementCard" and @aria-label="Show announcements" and @hidden]')->length === 1 && $dom->query('//*[@data-floating-announcement-close]')->length === 0, 'A side restore button replaces permanent dismissal.');
    $href = $dom->query('.//a', $card)->item(0)->getAttribute('href');
    faCheck($href === '/announcements.php#announcement-directory', 'Shortcut opens all announcements without a selected-record filter.');
    $directoryDom = faDom(faGet($href));
    faCheck($directoryDom->query('//*[@id="announcement-directory"]//article')->length === 2 && $directoryDom->query('//form[@role="search"]')->length === 1, 'Directory includes both public notices and search/filter options.');
    $homeDom = faDom(faGet('/index.php'));
    faCheck($homeDom->query('//*[@id="latest-announcements"]')->length === 0, 'Homepage no longer displays the large Latest Announcements section.');
    faCheck($homeDom->query('//div[contains(@class,"hero-actions")]/a[@href="announcements.php#announcement-directory"]')->length === 1, 'Homepage announcement action opens the full directory.');
    faCheck($homeDom->query('//section[@aria-labelledby="quick-info-title"]')->length === 1 && $homeDom->query('//section[@aria-labelledby="snapshot-title"]')->length === 1, 'Quick Information and live readiness sections remain available.');
    foreach (['/__admin', '/__auth'] as $route) faCheck(!str_contains(faGet($route), 'data-floating-announcement'), 'Shortcut stays off admin/auth screens.');
    if (getenv('SAGIPBRO_FLOATING_BROWSER') === '1') {
        $script = <<<'JS'
const output=document.querySelector('pre'),frame=document.querySelector('iframe');let checks=0;
const check=(value,label)=>{checks++;if(!value)throw new Error(label)};
const settle=()=>new Promise(resolve=>setTimeout(resolve,180));
const load=async route=>{await new Promise(resolve=>{frame.onload=resolve;frame.src=route});await settle()};
(async()=>{
 frame.style.height='900px';await load('/about.php');let doc=frame.contentDocument,win=frame.contentWindow;
 let card=doc.querySelector('[data-floating-announcement]');check(card&&!card.hidden&&!win.floatingXss,'Visible safe notice');
 const key='sagipbro:announcements:minimized';
 for(const width of [1440,390,320]){
  frame.style.width=width+'px';await settle();const rect=card.getBoundingClientRect(),style=win.getComputedStyle(card);
  check(style.position==='fixed'&&rect.right<=width&&rect.left>=0&&rect.bottom<=win.innerHeight,'Fixed card fits '+width+'px');
  check(rect.width<=282&&rect.height<130,'Small footprint at '+width+'px');
  const title=card.querySelector('.floating-announcement-title'),titleStyle=win.getComputedStyle(title);
  check(title.getBoundingClientRect().height<=parseFloat(titleStyle.lineHeight)*2+1,'Title capped at two lines');
  doc.documentElement.style.setProperty('scroll-behavior','auto','important');win.scrollTo(0,500);await settle();
  const scrolled=card.getBoundingClientRect();check(win.scrollY>0&&Math.abs(rect.bottom-scrolled.bottom)<1&&Math.abs(rect.right-scrolled.right)<1,'Position stays fixed while scrolling');
  const location=win.location.href;card.querySelector('[data-floating-announcement-minimize]').click();
  const restore=doc.querySelector('[data-floating-announcement-restore]'),restoreRect=restore.getBoundingClientRect();
  check(card.hidden&&!restore.hidden&&restore.getAttribute('aria-expanded')==='false'&&win.location.href===location,'Minimize hides the card and reveals the side button without navigation');
  check(win.getComputedStyle(restore).position==='fixed'&&restoreRect.width>=44&&restoreRect.height>=44&&restoreRect.left>=0&&restoreRect.right<=width+1&&restoreRect.bottom<=win.innerHeight,'Side button is visible, fixed, and tappable at '+width+'px');
  check(doc.activeElement===restore,'Minimizing moves keyboard focus to the side button');
  restore.click();check(!card.hidden&&restore.hidden&&doc.activeElement===card.querySelector('[data-floating-announcement-minimize]'),'Side button restores the card and keyboard focus');
 }
 const href=card.querySelector('a').href;
 await new Promise(resolve=>{frame.onload=resolve;card.querySelector('a').click()});await settle();
 check(frame.contentWindow.location.href===href&&!new URL(href).searchParams.has('view')&&frame.contentDocument.querySelectorAll('#announcement-directory article').length===2&&frame.contentDocument.querySelector('form[role="search"]'),'Full-card click opens all announcements with search and filter options');
 await load('/about.php');doc=frame.contentDocument;win=frame.contentWindow;card=doc.querySelector('[data-floating-announcement]');const location=win.location.href;
 card.querySelector('[data-floating-announcement-minimize]').click();check(card.hidden&&win.location.href===location&&win.localStorage.getItem(key)==='1','Minimize saves one website-wide preference without navigation');
 await load('/announcements.php');doc=frame.contentDocument;win=frame.contentWindow;card=doc.querySelector('[data-floating-announcement]');
 check(card.hidden&&!doc.querySelector('[data-floating-announcement-restore]').hidden&&doc.documentElement.classList.contains('announcements-minimized'),'Minimized state follows navigation to another public page');
 await new Promise(resolve=>{frame.onload=resolve;win.location.reload()});await settle();
 doc=frame.contentDocument;win=frame.contentWindow;card=doc.querySelector('[data-floating-announcement]');check(card.hidden&&!doc.querySelector('[data-floating-announcement-restore]').hidden,'Refresh keeps the card minimized with a restore button');
 win.dispatchEvent(new win.PageTransitionEvent('pageshow',{persisted:true}));check(card.hidden,'Browser Back restoration respects minimized state');
 doc.querySelector('[data-floating-announcement-restore]').click();check(!card.hidden&&win.localStorage.getItem(key)==='0','Side button saves the expanded preference');
 await load('/about.php');doc=frame.contentDocument;win=frame.contentWindow;card=doc.querySelector('[data-floating-announcement]');check(!card.hidden&&doc.querySelector('[data-floating-announcement-restore]').hidden,'Restored state follows navigation across the website');
 win.localStorage.setItem(key,'1');win.dispatchEvent(new win.StorageEvent('storage',{key,newValue:'1'}));check(card.hidden&&!doc.querySelector('[data-floating-announcement-restore]').hidden,'Preference changes in another tab minimize the card');
 win.localStorage.setItem(key,'0');win.dispatchEvent(new win.StorageEvent('storage',{key,newValue:'0'}));check(!card.hidden,'Preference changes in another tab restore the card');
 // Both controls remain usable when browser storage is blocked.
 Object.defineProperty(win,'localStorage',{get(){throw new Error('Storage disabled')},configurable:true});
 const retry=doc.createElement('script');retry.src='/assets/js/floating-announcement.js?v=3';await new Promise(resolve=>{retry.onload=resolve;doc.body.append(retry)});
 card.querySelector('[data-floating-announcement-minimize]').click();check(card.hidden&&!doc.querySelector('[data-floating-announcement-restore]').hidden,'Minimize works when storage is unavailable');
 doc.querySelector('[data-floating-announcement-restore]').click();check(!card.hidden,'Restore works when storage is unavailable');
 output.textContent=JSON.stringify({ok:true,checks});
})().catch(error=>{output.textContent=JSON.stringify({ok:false,checks,error:error.message})});
JS;
        file_put_contents($directory . '/browser.html', '<!doctype html><meta charset="utf-8"><pre id="result">Running</pre><iframe title="Floating announcement"></iframe><script>' . $script . '</script>');
        $chrome = getenv('SAGIPBRO_CHROME_BINARY') ?: 'C:/Program Files/Google/Chrome/Application/chrome.exe';
        faCheck(is_file($chrome), 'Chrome is available.');
        $process = proc_open([$chrome, '--headless=new', '--disable-gpu', '--no-first-run', '--disable-extensions', '--disable-background-networking', '--user-data-dir=' . $directory . '/chrome-profile', '--virtual-time-budget=20000', '--dump-dom', $address . '/__floating_browser'], [0 => ['pipe', 'r'], 1 => ['file', $directory . '/dom.html', 'wb'], 2 => ['file', $directory . '/chrome.log', 'ab']], $pipes, $project, $environment, ['bypass_shell' => true, 'create_new_console' => false]);
        faCheck(is_resource($process), 'Private Chrome process starts.'); fclose($pipes[0]);
        $deadline = microtime(true) + 45;
        while (proc_get_status($process)['running'] && microtime(true) < $deadline) usleep(50000);
        if (proc_get_status($process)['running']) proc_terminate($process); proc_close($process);
        preg_match('/<pre id="result">(.*?)<\/pre>/s', (string) file_get_contents($directory . '/dom.html'), $match);
        $result = json_decode(html_entity_decode($match[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        faCheck(($result['ok'] ?? false) === true, 'Browser checks: ' . json_encode($result));
        fwrite(STDOUT, 'PASS: ' . $result['checks'] . " desktop/mobile browser checks.\n");
    }
    $tie = $add('New publication with same timestamp', 'Published', '2021-01-01 12:00:00');
    faCheck((int) publicFloatingAnnouncement($db)['id'] === $tie, 'Equal publication times use newest ID.');
    $db->exec("UPDATE announcements SET status='Archived' WHERE id=" . $tie);
    faCheck((int) publicFloatingAnnouncement($db)['id'] === $latest, 'Archiving refreshes the shortcut.');
    $db->exec("UPDATE announcements SET expires_at='2020-01-01 00:00:00' WHERE id=" . $latest);
    faCheck((int) publicFloatingAnnouncement($db)['id'] === $old, 'Expiry refreshes the shortcut.');
    $db->exec('ALTER TABLE announcements CHANGE body content TEXT NOT NULL');
    $legacy = new PDO('mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4', $user === false ? 'root' : $user, $password === false ? '' : $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $legacy->exec("SET time_zone = '+08:00'");
    faCheck((int) publicFloatingAnnouncement($legacy)['id'] === $old, 'Legacy content column is supported.');
    $db->exec("UPDATE announcements SET status='Archived'");
    faCheck(!str_contains(faGet('/about.php'), 'data-floating-announcement'), 'No current notices means no card.');
    $db->exec('DROP TABLE announcements');
    faCheck(!str_contains(faGet('/about.php'), 'data-floating-announcement'), 'Database failure preserves the public page without a fabricated notice.');
} catch (Throwable $error) {
    $failed = true; fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
} finally {
    if (is_resource($server)) { if (proc_get_status($server)['running']) proc_terminate($server); proc_close($server); }
    if ($created && $db instanceof PDO && preg_match('/\Asagipbro_float_[a-f0-9]{16}\z/', $name)) $db->exec('DROP DATABASE `' . $name . '`');
    $resolved = realpath($directory); $temp = realpath(sys_get_temp_dir());
    if ($resolved && $temp && str_starts_with(strtolower($resolved), strtolower($temp . DIRECTORY_SEPARATOR)) && preg_match('/\Asagipbro-float-[a-f0-9]{16}\z/', basename($resolved))) {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) { if ($entry->isDir() && !$entry->isLink()) @rmdir($entry->getPathname()); else @unlink($entry->getPathname()); }
        @rmdir($resolved);
    }
    fwrite(STDOUT, "Cleaned up private fixtures; application records untouched.\n");
}
fwrite($failed ? STDERR : STDOUT, ($failed ? 'FAILED: ' : 'PASSED: ') . $checks . " floating announcement HTTP/data checks.\n");
exit($failed ? 1 : 0);
