<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/session.php';

$pageTitle = $pageTitle ?? 'SAGIPBRO';
$pageDescription = $pageDescription ?? 'SAGIPBRO Disaster Relief Resource Information System for Barangay Binloc, Dagupan City.';
$basePath = $basePath ?? '';
$isAdmin = $isAdmin ?? false;
$bodyClass = trim(($bodyClass ?? '') . ($isAdmin ? ' admin-body' : ' public-body'));
$titleSuffix = $pageTitle === 'SAGIPBRO' ? 'Disaster Relief Resource Information System' : 'SAGIPBRO';
?>
<!doctype html>
<html lang="en" class="app-loading">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="theme-color" content="#0b5d3b">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> | <?= htmlspecialchars($titleSuffix, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        html, body { background: <?= $isAdmin ? 'linear-gradient(90deg, #073e2a 0 268px, #f2f6f3 268px)' : '#ffffff' ?>; }
        <?php if ($isAdmin): ?>
        @media (max-width: 991.98px) { html, body { background: #f2f6f3; } }
        <?php endif; ?>
        html.app-loading *, html.app-loading *::before, html.app-loading *::after {
            animation: none !important;
            transition: none !important;
        }
    </style>
    <link rel="icon" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/images/sagipbro-mark.svg" type="image/svg+xml">
    <link rel="preload" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/third-party/bootstrap-icons/fonts/bootstrap-icons.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/third-party/bootstrap/bootstrap.min.css?v=5.3.3">
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/third-party/bootstrap-icons/bootstrap-icons.min.css?v=1.11.3">
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/css/style.css?v=2">
    <?php if ($isAdmin): ?>
        <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/css/dashboard.css?v=6">
    <?php endif; ?>
    <?php if (!empty($useLoginStyles)): ?>
        <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/css/login.css">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/css/responsive.css?v=2">
    <?php if (!empty($useRecaptcha)): ?><script src="https://www.google.com/recaptcha/api.js" async defer></script><?php endif; ?>
</head>
<body class="<?= htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8') ?>">
<a class="skip-link" href="#main-content">Skip to main content</a>
