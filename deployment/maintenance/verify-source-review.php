<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$home = '/var/www/vhosts/nuers.net';
$root = $home.'/httpdocs';
$bundle = $home.'/nuers-source-review-20261029';
$plan = json_decode(file_get_contents($bundle.'/live-source-plan.json'), true, 512, JSON_THROW_ON_ERROR);
$inventory = json_decode(file_get_contents($home.'/nuers-source-cleanup-inventory-20261029.json'), true, 512, JSON_THROW_ON_ERROR);
$result = json_decode(file_get_contents($home.'/nuers-source-review-result-20261029.json'), true, 512, JSON_THROW_ON_ERROR);
$errors = [];
foreach ($plan['updates'] as $entry) {
    if (hash_file('sha256', $root.'/'.$entry['path']) !== $entry['after']) {
        $errors[] = $entry['path'];
    }
}
foreach ($plan['retire'] as $entry) {
    if (file_exists($root.'/'.$entry['path'])) {
        $errors[] = $entry['path'];
    }
}
if ($errors) {
    throw new RuntimeException('Source verification failed: '.implode(', ', $errors));
}
$recovery = $home.'/private-recovery/source-review-retired-20261029';
if (!is_dir($recovery)) {
    mkdir($recovery, 0700, true);
}
$moved = [];
foreach ($inventory['candidates'] as $name => $entry) {
    if (basename($name) !== $name || !str_starts_with($name, 'nuers-') || preg_match('/backup|recovery/', $name) || $entry['sensitive_paths'] || $entry['links'] || !empty($entry['error']) || !empty($inventory['references'][$name])) {
        throw new RuntimeException('Unsafe cleanup candidate: '.$name);
    }
    $path = $home.'/'.$name;
    if (is_link($path)) {
        throw new RuntimeException('Candidate became a symlink: '.$name);
    }
    if (file_exists($path)) {
        if (file_exists($recovery.'/'.$name) || !rename($path, $recovery.'/'.$name)) {
            throw new RuntimeException('Cannot retain remaining artifact: '.$name);
        }
        $moved[] = $name;
    }
}
$remaining = array_values(array_filter(array_keys($inventory['candidates']), fn ($name) => file_exists($home.'/'.$name)));
$report = ['verified_at' => gmdate(DATE_ATOM), 'source_updates_verified' => count($plan['updates']), 'unused_source_removed' => count($plan['retire']), 'old_artifact_targets' => count($inventory['candidates']), 'remaining_in_domain_home' => $remaining, 'moved_to_private_recovery' => $moved, 'artifact_recovery' => $recovery, 'source_recovery' => $result['backup'], 'preserved_mismatches' => $plan['preserved_mismatches']];
$path = $home.'/nuers-source-review-verification-20261029.json';
file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
chmod($path, 0600);
chown($path, fileowner($root));
chgrp($path, filegroup($root));
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
