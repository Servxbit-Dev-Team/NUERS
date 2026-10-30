<?php

declare(strict_types=1);

// Read-only inventory of the NUERS deployment. Never reads environment values or databases.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$home = '/var/www/vhosts/nuers.net';
$root = $home.'/httpdocs';
if (!is_dir($root.'/app') || !is_file($root.'/artisan')) {
    throw new RuntimeException('Expected NUERS application is missing.');
}
$inventory = ['generated_at' => gmdate(DATE_ATOM), 'root' => realpath($root), 'candidates' => [], 'links' => [], 'source' => [], 'references' => []];
$preserve = ['nuers-staged-release-20261017', 'nuers-pricing-stage-20261017-v2', 'nuers-release-20261017.zip', 'nuers-google-deployment.zip', 'nuers-timestamp-compatibility.zip'];
foreach (scandir($home) as $name) {
    if (!str_starts_with($name, 'nuers-') || preg_match('/backup|recovery/', $name) || in_array($name, $preserve, true)) {
        continue;
    }
    if (!preg_match('/-stage(?:-|$)|\.zip$/', $name)) {
        continue;
    }
    $path = $home.'/'.$name;
    $entry = ['name' => $name, 'kind' => is_dir($path) ? 'directory' : 'file', 'bytes' => 0, 'files' => 0, 'sensitive_paths' => [], 'links' => []];
    if (is_link($path)) {
        $entry['links'][] = readlink($path);
    } elseif (is_dir($path)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
            $rel = substr($file->getPathname(), strlen($path) + 1);
            if ($file->isLink()) {
                $entry['links'][] = [$rel, readlink($file->getPathname())];
            } elseif ($file->isFile()) {
                $entry['files']++;
                $entry['bytes'] += $file->getSize();
                if (preg_match('~(^|/)(\.env($|\.)|uploads/)|\.(sql|sqlite|db|key|pem)$~i', $rel)) {
                    $entry['sensitive_paths'][] = $rel;
                }
            }
        }
    } else {
        $entry['bytes'] = filesize($path);
        $entry['files'] = 1;
        $entry['sha256'] = hash_file('sha256', $path);
        $zip = new ZipArchive();
        if ($zip->open($path) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $rel = $zip->getNameIndex($i);
                if (preg_match('~(^|/)(\.env($|\.)|uploads/)|\.(sql|sqlite|db|key|pem)$~i', $rel)) {
                    $entry['sensitive_paths'][] = $rel;
                }
            }
            $zip->close();
        } else {
            $entry['error'] = 'Archive unreadable';
        }
    }
    $inventory['candidates'][$name] = $entry;
}

foreach ([$root, $home.'/.nuers-runtime'] as $scanRoot) {
    if (!is_dir($scanRoot)) {
        continue;
    }
    $filter = new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($scanRoot, FilesystemIterator::SKIP_DOTS), function ($file) {
        return !in_array($file->getFilename(), ['.git', 'uploads', 'logs', 'sessions', 'cache', 'testing'], true);
    });
    foreach (new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST) as $file) {
        $path = $file->getPathname();
        if ($file->isLink()) {
            $inventory['links'][] = ['path' => substr($path, strlen($home) + 1), 'target' => readlink($path), 'resolved' => realpath($path)];
            continue;
        }
        if (!$file->isFile() || !str_starts_with($path, $root.'/')) {
            continue;
        }
        $rel = substr($path, strlen($root) + 1);
        if (!preg_match('~^(app/|src/|routes/|config/|database/|resources/|tests/|scripts/|bootstrap/(app|providers)\.php$)~', $rel)) {
            continue;
        }
        if (!preg_match('/\.(php|tsx?|mjs|css)$/', $rel) || $file->getSize() > 8 * 1024 * 1024) {
            continue;
        }
        $inventory['source'][$rel] = hash_file('sha256', $path);
        $content = file_get_contents($path);
        foreach (array_keys($inventory['candidates']) as $name) {
            if (str_contains($content, $name)) {
                $inventory['references'][$name][] = $rel;
            }
        }
    }
}
$target = $home.'/nuers-source-cleanup-inventory-20261029.json';
file_put_contents($target, json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
chmod($target, 0600);
echo json_encode(['result' => basename($target), 'candidates' => count($inventory['candidates']), 'source_files' => count($inventory['source']), 'links' => count($inventory['links']), 'references' => $inventory['references']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
