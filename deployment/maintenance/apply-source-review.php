<?php

declare(strict_types=1);

// Apply only reviewed source files whose live hashes match the inventory.
// Removed files remain recoverable under private-recovery, with their original paths.
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--apply') {
    exit("CLI usage: php apply-source-review.php --apply\n");
}
$home = '/var/www/vhosts/nuers.net';
$root = $home.'/httpdocs';
$bundle = __DIR__;
$plan = json_decode(file_get_contents($bundle.'/live-source-plan.json'), true, 512, JSON_THROW_ON_ERROR);
if (($plan['root'] ?? null) !== $root || realpath($root) !== $root) {
    throw new RuntimeException('Application path mismatch.');
}
$lock = fopen($home.'/nuers-source-review.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    throw new RuntimeException('Another source review update is running.');
}
$entries = array_merge($plan['updates'], $plan['retire']);
foreach ($entries as $entry) {
    $rel = $entry['path'];
    if (str_contains($rel, '..') || !preg_match('~^(app/|src/|routes/|config/|database/|resources/|tests/|scripts/|bootstrap/(app|providers)\.php$)~', $rel)) {
        throw new RuntimeException('Invalid source path.');
    }
    $target = $root.'/'.$rel;
    if (!is_file($target) || is_link($target) || !str_starts_with((string) realpath($target), $root.'/') || hash_file('sha256', $target) !== $entry['before']) {
        throw new RuntimeException('Live source changed: '.$rel);
    }
    if (isset($entry['after'])) {
        $payload = $bundle.'/payload/'.$rel;
        if (!is_file($payload) || is_link($payload) || hash_file('sha256', $payload) !== $entry['after']) {
            throw new RuntimeException('Payload mismatch: '.$rel);
        }
        if (str_ends_with($rel, '.php')) {
            exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($payload).' 2>&1', $lint, $status);
            if ($status !== 0) {
                throw new RuntimeException('PHP syntax failure: '.$rel);
            }
            unset($lint);
        }
    }
}
$backup = $home.'/private-recovery/source-review-20261029-'.gmdate('His');
if (!mkdir($backup, 0700, true)) {
    throw new RuntimeException('Unable to create recovery directory.');
}
$metadata = [];
foreach ($entries as $entry) {
    $rel = $entry['path'];
    $target = $root.'/'.$rel;
    $saved = $backup.'/'.$rel;
    if (!is_dir(dirname($saved))) {
        mkdir(dirname($saved), 0700, true);
    }
    $metadata[$rel] = ['mode' => fileperms($target) & 0777, 'uid' => fileowner($target), 'gid' => filegroup($target)];
    if (!copy($target, $saved) || hash_file('sha256', $saved) !== $entry['before']) {
        throw new RuntimeException('Recovery copy failed: '.$rel);
    }
    chmod($saved, 0600);
}
file_put_contents($backup.'/plan.json', json_encode($plan, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
file_put_contents($backup.'/metadata.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$changed = [];
$retired = [];
try {
    foreach ($plan['updates'] as $entry) {
        $rel = $entry['path'];
        $target = $root.'/'.$rel;
        $temp = $target.'.source-review-tmp';
        if (file_exists($temp) || !copy($bundle.'/payload/'.$rel, $temp)) {
            throw new RuntimeException('Cannot stage source: '.$rel);
        }
        chmod($temp, $metadata[$rel]['mode']);
        chown($temp, $metadata[$rel]['uid']);
        chgrp($temp, $metadata[$rel]['gid']);
        if (!rename($temp, $target)) {
            throw new RuntimeException('Cannot replace source: '.$rel);
        }
        $changed[] = $rel;
        if (hash_file('sha256', $target) !== $entry['after']) {
            throw new RuntimeException('Verification failed: '.$rel);
        }
    }
    foreach ($plan['retire'] as $entry) {
        $rel = $entry['path'];
        $destination = $backup.'/retired/'.$rel;
        if (!is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0700, true);
        }
        if (!rename($root.'/'.$rel, $destination)) {
            throw new RuntimeException('Cannot retire unused source: '.$rel);
        }
        $retired[] = $rel;
    }
} catch (Throwable $error) {
    foreach (array_merge($changed, $retired) as $rel) {
        copy($backup.'/'.$rel, $root.'/'.$rel);
        chmod($root.'/'.$rel, $metadata[$rel]['mode']);
        chown($root.'/'.$rel, $metadata[$rel]['uid']);
        chgrp($root.'/'.$rel, $metadata[$rel]['gid']);
    }
    throw $error;
}
$result = ['completed_at' => gmdate(DATE_ATOM), 'updated' => count($changed), 'retired' => count($retired), 'backup' => $backup, 'preserved_mismatches' => $plan['preserved_mismatches']];
$report = $home.'/nuers-source-review-result-20261029.json';
file_put_contents($report, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
chmod($report, 0600);
chown($report, fileowner($root));
chgrp($report, filegroup($root));
echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
