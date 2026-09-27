<?php
require_once __DIR__ . '/../config/config.php';

$pageTitle = $pageTitle ?? 'SAGIPBRO';
$pageDescription = $pageDescription ?? 'SAGIPBRO Disaster Relief Resource Information System for Barangay Binloc, Dagupan City.';
$basePath = $basePath ?? '';
$isAdmin = $isAdmin ?? false;
$bodyClass = trim(($bodyClass ?? '') . ($isAdmin ? ' admin-body' : ' public-body'));
$titleSuffix = $pageTitle === 'SAGIPBRO' ? 'Disaster Relief Resource Information System' : 'SAGIPBRO';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="theme-color" content="#0b5d3b">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> | <?= htmlspecialchars($titleSuffix, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/images/sagipbro-mark.svg" type="image/svg+xml">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/css/style.css">
    <?php if ($isAdmin): ?>
        <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/css/dashboard.css">
    <?php endif; ?>
    <?php if (!empty($useLoginStyles)): ?>
        <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/css/login.css">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/css/responsive.css">
    <?php if (!empty($useRecaptcha)): ?><script src="https://www.google.com/recaptcha/api.js" async defer></script><?php endif; ?>
</head>
<body class="<?= htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8') ?>">
<a class="skip-link" href="#main-content">Skip to main content</a>
