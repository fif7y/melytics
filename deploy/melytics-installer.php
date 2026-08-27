<?php
/**
 * melytics one-file installer.
 *
 * Upload just this file to the folder your domain serves (e.g. public_html),
 * open it in the browser, and it fetches the latest melytics release, unpacks
 * it here, deletes itself, and sends you to the setup screen.
 */

const RELEASE_URL = 'https://github.com/fif7y/melytics/releases/latest/download/melytics.zip';

error_reporting(E_ALL);
set_time_limit(300);

function fail(string $msg): void
{
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>melytics installer</title>'
        .'<body style="font-family:system-ui;background:#131312;color:#f4f4ef;display:grid;place-items:center;min-height:100vh">'
        .'<div style="max-width:30rem;line-height:1.6"><h1 style="font-size:1.2rem">Couldn&rsquo;t install</h1><p>'
        .htmlspecialchars($msg).'</p><p style="color:#7d7c75">Fallback: download the release zip from GitHub and '
        .'extract it here with your hosting panel&rsquo;s file manager, then open /install.</p></div>';
    exit;
}

// Refuse to clobber an existing install.
if (file_exists(__DIR__.'/artisan') || file_exists(__DIR__.'/melytics/artisan')) {
    header('Location: ./install');
    exit;
}

if (! class_exists('ZipArchive')) {
    fail('The PHP zip extension is missing — enable it in your hosting panel\'s PHP settings and reload.');
}

// 1. Download.
$zipPath = __DIR__.'/melytics-release.zip';
$src = null;
if (ini_get('allow_url_fopen')) {
    $src = @fopen(RELEASE_URL, 'rb', false, stream_context_create(['http' => ['follow_location' => 1, 'timeout' => 120]]));
}
if (! $src && function_exists('curl_init')) {
    $ch = curl_init(RELEASE_URL);
    $out = fopen($zipPath, 'wb');
    curl_setopt_array($ch, [CURLOPT_FILE => $out, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 120]);
    if (! curl_exec($ch) || curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) {
        fail('Download failed ('.(curl_error($ch) ?: 'HTTP '.curl_getinfo($ch, CURLINFO_HTTP_CODE)).').');
    }
    curl_close($ch);
    fclose($out);
} elseif ($src) {
    if (@file_put_contents($zipPath, $src) === false) {
        fail('Could not write the download here — check this folder is writable.');
    }
} else {
    fail('Neither allow_url_fopen nor curl is available to download the release.');
}
if (! is_file($zipPath) || filesize($zipPath) < 1_000_000) {
    fail('The download looks incomplete — try again in a minute.');
}

// 2. Extract (zip contains a top-level melytics/ folder), then hoist its contents here.
$zip = new ZipArchive;
if ($zip->open($zipPath) !== true) {
    fail('Could not open the downloaded zip.');
}
$tmp = __DIR__.'/.melytics-unpack';
@mkdir($tmp);
if (! $zip->extractTo($tmp)) {
    fail('Extraction failed — check disk space and folder permissions.');
}
$zip->close();
unlink($zipPath);

$rootInZip = is_dir($tmp.'/melytics') ? $tmp.'/melytics' : $tmp;
foreach (scandir($rootInZip) as $entry) {
    if ($entry === '.' || $entry === '..') {
        continue;
    }
    if (! rename($rootInZip.'/'.$entry, __DIR__.'/'.$entry)) {
        fail("Could not move '$entry' into place — check permissions.");
    }
}
@rmdir($rootInZip);
@rmdir($tmp);

// 3. Clean up and hand over to the web installer.
@unlink(__FILE__);
header('Location: ./install');
