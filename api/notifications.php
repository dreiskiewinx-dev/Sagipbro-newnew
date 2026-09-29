<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
requireApiLogin(['admin', 'official', 'volunteer']);
header('Cache-Control: no-store, private');

$userId = (int) currentUserId();
$role = (string) ($_SESSION['role'] ?? '');
session_write_close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = requestData();
    $action = (string) ($data['action'] ?? '');
    if ($action === 'mark_read') {
        $notificationId = trim((string) ($data['notification_id'] ?? ''));
        if (!preg_match('/^(activity|message)-[1-9][0-9]*$/', $notificationId)) {
            jsonResponse(['error' => 'Invalid notification.'], 422);
        }
        $statement = $conn->prepare(
            'INSERT INTO user_notification_reads (user_id, notification_id) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE read_at = read_at'
        );
        $statement->execute([$userId, $notificationId]);
        jsonResponse(['success' => true]);
    }
    if ($action !== 'mark_all_read') {
        jsonResponse(['error' => 'Invalid notification action.'], 422);
    }
    $statement = $conn->prepare(
        'INSERT INTO user_notification_state (user_id, last_seen_at) VALUES (?, NOW())
         ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at)'
    );
    $statement->execute([$userId]);
    $deleteStatement = $conn->prepare('DELETE FROM user_notification_reads WHERE user_id = ?');
    $deleteStatement->execute([$userId]);
    jsonResponse(['success' => true, 'unread' => 0]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    methodNotAllowed(['GET', 'POST']);
}

try {
    $stateStatement = $conn->prepare('SELECT last_seen_at FROM user_notification_state WHERE user_id = ?');
    $stateStatement->execute([$userId]);
    $lastSeenAt = $stateStatement->fetchColumn() ?: '1970-01-01 00:00:00';

    $readStatement = $conn->prepare('SELECT notification_id FROM user_notification_reads WHERE user_id = ?');
    $readStatement->execute([$userId]);
    $readNotifications = array_fill_keys($readStatement->fetchAll(PDO::FETCH_COLUMN), true);

    $activitySql = "SELECT l.id, l.action, l.entity_type, l.entity_id, l.details, l.created_at,
            COALESCE(u.full_name, u.username, 'System') AS actor
        FROM activity_logs l
        LEFT JOIN users u ON u.id = l.user_id";
    $activityParams = [];
    if ($role === 'volunteer') {
        $activitySql .= ' WHERE l.user_id = ?';
        $activityParams[] = $userId;
    }
    $activitySql .= ' ORDER BY l.created_at DESC, l.id DESC LIMIT 40';
    $activityStatement = $conn->prepare($activitySql);
    $activityStatement->execute($activityParams);

    $entityNames = [
        'announcement' => 'announcement', 'distribution' => 'distribution', 'resource' => 'resource',
        'evacuation_center' => 'evacuation center', 'evacuee' => 'evacuee record',
        'resident' => 'resident record', 'volunteer' => 'volunteer record', 'user' => 'user account',
    ];
    $actionNames = [
        'create' => 'Created', 'update' => 'Updated', 'delete' => 'Removed', 'publish' => 'Published',
        'archive' => 'Archived', 'check-in' => 'Checked in', 'check-out' => 'Checked out',
        'reset-password' => 'Reset password for', 'update-profile' => 'Updated',
        'update-notifications' => 'Updated', 'change-password' => 'Changed password for',
    ];
    $icons = [
        'announcement' => 'bi-megaphone', 'distribution' => 'bi-truck', 'resource' => 'bi-box-seam',
        'evacuation_center' => 'bi-buildings', 'evacuee' => 'bi-person-check', 'resident' => 'bi-people',
        'volunteer' => 'bi-person-heart', 'user' => 'bi-person-gear',
    ];
    $notifications = [];
    foreach ($activityStatement->fetchAll() as $activity) {
        $entityType = (string) $activity['entity_type'];
        $action = (string) $activity['action'];
        $entityId = (int) ($activity['entity_id'] ?? 0);
        $entityName = $entityNames[$entityType] ?? str_replace('_', ' ', $entityType);
        $verb = $actionNames[$action] ?? ucwords(str_replace(['-', '_'], ' ', $action));
        $details = json_decode((string) ($activity['details'] ?? ''), true);
        $detailParts = [];
        if (is_array($details)) {
            if (!empty($details['name'])) $detailParts[] = (string) $details['name'];
            if (!empty($details['quantity'])) $detailParts[] = 'Quantity ' . $details['quantity'];
            if (!empty($details['reason'])) $detailParts[] = (string) $details['reason'];
        }
        if (!$detailParts && $entityId > 0) $detailParts[] = 'Record #' . $entityId;
        if (in_array($role, ['admin', 'official'], true)) {
            $activityId = (int) $activity['id'];
            $targetUrl = appUrl('pages/activity/index.php')
                . '?view=' . $activityId
                . '#activity-log-target-' . $activityId;
        } else {
            $targetUrl = $entityType === 'distribution'
                ? appUrl('pages/distribution/index.php')
                : appUrl('dashboard/volunteer.php');
            if ($entityType === 'distribution' && $entityId > 0 && $action !== 'delete') {
                $targetUrl .= '?view=' . $entityId;
            }
        }
        $notifications[] = [
            'id' => 'activity-' . $activity['id'],
            'title' => $verb . ' ' . $entityName,
            'description' => (string) $activity['actor'] . ($detailParts ? ' · ' . implode(' · ', $detailParts) : ''),
            'icon' => $icons[$entityType] ?? 'bi-activity',
            'url' => $targetUrl,
            'created_at' => (string) $activity['created_at'],
            'unread' => (string) $activity['created_at'] > (string) $lastSeenAt
                && !isset($readNotifications['activity-' . $activity['id']]),
        ];
    }

    if (in_array($role, ['admin', 'official'], true)) {
        $messageStatement = $conn->query(
            'SELECT id, name, subject, created_at FROM contact_messages ORDER BY created_at DESC, id DESC LIMIT 40'
        );
        foreach ($messageStatement->fetchAll() as $message) {
            $notifications[] = [
                'id' => 'message-' . $message['id'],
                'title' => 'New contact message',
                'description' => (string) $message['name'] . ' · ' . (string) $message['subject'],
                'icon' => 'bi-envelope',
                'url' => appUrl('pages/messages/index.php') . '?view=' . (int) $message['id'],
                'created_at' => (string) $message['created_at'],
                'unread' => (string) $message['created_at'] > (string) $lastSeenAt
                    && !isset($readNotifications['message-' . $message['id']]),
            ];
        }
    }

    usort($notifications, static fn(array $a, array $b): int => strcmp($b['created_at'], $a['created_at']) ?: strcmp($b['id'], $a['id']));
    $unread = count(array_filter($notifications, static fn(array $notification): bool => $notification['unread']));
    $notifications = array_slice($notifications, 0, 20);
    jsonResponse(['data' => $notifications, 'unread' => $unread, 'last_seen_at' => $lastSeenAt]);
} catch (Throwable $e) {
    error_log('Notifications API unavailable (' . get_class($e) . ').');
    jsonResponse(['error' => 'Notifications temporarily unavailable.'], 503);
}
