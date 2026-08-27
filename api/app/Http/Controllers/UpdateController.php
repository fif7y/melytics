<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Version;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\File;
use ZipArchive;

// One-click self-update, WordPress-style: download the latest release zip,
// extract, copy over this install (config and data untouched), migrate.
// Release installs only — git checkouts report "dev" and update via git pull.
class UpdateController extends Controller
{
    // Top-level paths never overwritten by an update: instance config + data,
    // and the root .htaccess (hand-edited on docroot-less hosts).
    private const PRESERVE = ['.env', '.htaccess', 'storage'];

    public function check(Request $request): JsonResponse
    {
        $this->adminOnly($request);

        return response()->json([
            'version' => Version::current(),
            'update' => Version::updateAvailable(fresh: true),
        ]);
    }

    public function run(Request $request): JsonResponse
    {
        $this->adminOnly($request);
        abort_if(Version::current() === 'dev', 422, 'This install runs from git — update with git pull.');
        $update = Version::updateAvailable(fresh: true);
        abort_unless($update, 422, 'Already up to date.');

        @set_time_limit(300);
        $zip = storage_path('app/update.zip');
        $stage = storage_path('app/update-stage');
        File::deleteDirectory($stage);

        try {
            // The bootstrap installer fetches this same stable-name asset.
            $r = Http::timeout(240)->sink($zip)->withUserAgent('melytics-updater')
                ->get('https://github.com/'.Version::REPO.'/releases/latest/download/melytics.zip');
            abort_unless($r->ok(), 502, 'Download failed (HTTP '.$r->status().') — try again, or update manually from GitHub.');

            $archive = new ZipArchive;
            abort_unless($archive->open($zip) === true, 500, 'Downloaded zip is unreadable — try again.');
            $archive->extractTo($stage);
            $archive->close();

            // Flat zip since 0.2.0; tolerate the legacy melytics/-prefixed layout too.
            $src = is_dir($stage.'/melytics') ? $stage.'/melytics' : $stage;
            abort_unless(is_file($src.'/VERSION') && is_file($src.'/artisan'), 500, 'Release zip has an unexpected layout — update manually.');

            // Everything extracted and sane; now overwrite in place.
            $this->copyOver($src, base_path());

            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('config:clear');
            Artisan::call('view:clear');
        } finally {
            File::deleteDirectory($stage);
            @unlink($zip);
        }

        return response()->json(['ok' => true, 'version' => trim((string) file_get_contents(base_path('VERSION')))]);
    }

    private function copyOver(string $src, string $dest): void
    {
        foreach (File::directories($src) as $dir) {
            if (! in_array(basename($dir), self::PRESERVE)) {
                File::copyDirectory($dir, $dest.'/'.basename($dir));
            }
        }
        foreach (File::files($src, hidden: true) as $file) {
            if (! in_array($file->getFilename(), self::PRESERVE)) {
                File::copy($file->getPathname(), $dest.'/'.$file->getFilename());
            }
        }
    }

    private function adminOnly(Request $request): void
    {
        // No roles yet: the first account (created by the installer) is the admin.
        abort_unless($request->user()->id === User::min('id'), 403, 'Only the admin account can update this instance.');
    }
}
