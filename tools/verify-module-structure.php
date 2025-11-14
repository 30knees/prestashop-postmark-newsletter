#!/usr/bin/env php
<?php

$baseDir = dirname(__DIR__);
$errors = [];
$warnings = [];

function checkCondition(bool $condition, string $successMessage, string $failureMessage, array &$errors, array &$warnings, bool $warning = false): void {
    if ($condition) {
        echo "[OK] $successMessage" . PHP_EOL;
        return;
    }

    if ($warning) {
        $target =& $warnings;
    } else {
        $target =& $errors;
    }
    $target[] = $failureMessage;
    $level = $warning ? 'WARNING' : 'ERROR';
    echo "[$level] $failureMessage" . PHP_EOL;
}

function loadComposerJson(string $path): ?array
{
    if (!file_exists($path)) {
        return null;
    }

    $json = file_get_contents($path);
    if ($json === false) {
        return null;
    }

    $data = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }

    return $data;
}

function loadConfigXml(string $path): ?SimpleXMLElement
{
    if (!file_exists($path)) {
        return null;
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($path);
    if ($xml === false) {
        return null;
    }

    return $xml;
}

$requiredFiles = [
    'postmarknewsletter.php',
    'config.xml',
    'composer.json',
    'index.php',
    'logo.png',
];

$requiredDirectories = [
    'classes',
    'controllers',
    'src',
    'views',
    'translations',
    'sql',
];

echo 'Verifying PrestaShop module structure for version 8.2 compatibility' . PHP_EOL;
echo str_repeat('-', 72) . PHP_EOL;

foreach ($requiredFiles as $file) {
    $path = $baseDir . DIRECTORY_SEPARATOR . $file;
    checkCondition(file_exists($path), "$file is present", "$file is missing", $errors, $warnings);
}

foreach ($requiredDirectories as $directory) {
    $path = $baseDir . DIRECTORY_SEPARATOR . $directory;
    checkCondition(is_dir($path), "$directory directory is present", "$directory directory is missing", $errors, $warnings);
}

$composer = loadComposerJson($baseDir . '/composer.json');
checkCondition($composer !== null, 'composer.json can be parsed', 'composer.json cannot be parsed', $errors, $warnings);

if ($composer !== null) {
    $type = $composer['type'] ?? null;
    checkCondition($type === 'prestashop-module', 'composer type is prestashop-module', 'composer type must be prestashop-module', $errors, $warnings);

    $compatibility = $composer['compatibility']['prestashop'] ?? null;
    $supports8x = is_string($compatibility) && preg_match('/\^8\\.0/', $compatibility);
    checkCondition($supports8x, 'composer compatibility covers PrestaShop 8.x', 'composer compatibility must target PrestaShop 8.x (e.g. ^8.0)', $errors, $warnings);

    $psr4 = $composer['autoload']['psr-4'] ?? [];
    $hasNamespace = array_key_exists('PostmarkNewsletter\\', $psr4);
    checkCondition($hasNamespace, 'PSR-4 namespace defined for PostmarkNewsletter', 'Missing PSR-4 namespace definition for PostmarkNewsletter', $errors, $warnings);

    $minimumPhp = $composer['require']['php'] ?? null;
    $phpConstraintOk = is_string($minimumPhp) && version_compare(preg_replace('/[^0-9.]/', '', $minimumPhp), '7.2.5', '>=');
    checkCondition($phpConstraintOk, 'PHP constraint is 7.2.5 or higher', 'PHP constraint should be at least 7.2.5 for PrestaShop 8.2', $errors, $warnings, true);
}

$moduleFile = $baseDir . '/postmarknewsletter.php';
$moduleSource = file_exists($moduleFile) ? file_get_contents($moduleFile) : false;
if ($moduleSource !== false) {
    $matchesClass = preg_match('/class\s+PostmarkNewsletter\s+extends\s+Module/i', $moduleSource) === 1;
    checkCondition($matchesClass, 'Module main class extends Module', 'Module main class must extend Module', $errors, $warnings);

    $hasPsCompliancy = preg_match('/\$this->ps_versions_compliancy\s*=\s*\[[^]]*\]/', $moduleSource) === 1;
    checkCondition($hasPsCompliancy, 'ps_versions_compliancy property defined', 'ps_versions_compliancy property is missing in module file', $errors, $warnings);
}

$configXml = loadConfigXml($baseDir . '/config.xml');
checkCondition($configXml !== null, 'config.xml can be parsed', 'config.xml cannot be parsed', $errors, $warnings);

if ($configXml !== null) {
    $name = trim((string) ($configXml->name ?? ''));
    $displayName = trim((string) ($configXml->displayName ?? ''));
    checkCondition($name === 'postmarknewsletter', 'config.xml module name matches directory', 'config.xml module name does not match expected name', $errors, $warnings);
    checkCondition($displayName !== '', 'config.xml display name is set', 'config.xml display name should not be empty', $errors, $warnings);
}

echo str_repeat('-', 72) . PHP_EOL;

if (!empty($errors)) {
    echo 'Module structure verification failed:' . PHP_EOL;
    foreach ($errors as $error) {
        echo ' - ' . $error . PHP_EOL;
    }
    if (!empty($warnings)) {
        echo PHP_EOL . 'Warnings:' . PHP_EOL;
        foreach ($warnings as $warning) {
            echo ' - ' . $warning . PHP_EOL;
        }
    }
    exit(1);
}

if (!empty($warnings)) {
    echo 'Module structure verified with warnings:' . PHP_EOL;
    foreach ($warnings as $warning) {
        echo ' - ' . $warning . PHP_EOL;
    }
    exit(0);
}

echo 'Module structure verification successful.' . PHP_EOL;
exit(0);
