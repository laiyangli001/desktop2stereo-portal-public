<?php
declare(strict_types=1);

namespace D2S\services;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use FilesystemIterator;

/** Builds and verifies a deterministic manifest for deployable runtime files. */
final class ReleaseManifest
{
    private const FORMAT = 'd2s-release-manifest-v1';
    private const MAX_MANIFEST_BYTES = 5 * 1024 * 1024;
    private const MAX_FILES = 5000;
    private const ROOT_FILES = ['composer.json', 'composer.lock'];
    private const RUNTIME_DIRECTORIES = ['api', 'bin', 'config', 'database', 'includes', 'public', 'resources', 'template', 'vendor'];

    public static function build(string $root, string $release, int $now): array
    {
        self::release($release);
        $files = [];
        foreach (self::runtimePaths($root) as $path) {
            $absolute = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
            if (!is_file($absolute) || is_link($absolute)) {
                throw new RuntimeException('release_file_invalid');
            }
            $hash = hash_file('sha256', $absolute);
            $size = filesize($absolute);
            if (!is_string($hash) || !is_int($size)) {
                throw new RuntimeException('release_file_unreadable');
            }
            $files[] = ['path'=>$path, 'sha256'=>$hash, 'size'=>$size];
        }
        return [
            'format'=>self::FORMAT,
            'release'=>$release,
            'generated_at'=>$now,
            'file_count'=>count($files),
            'root_hash'=>self::rootHash($files),
            'files'=>$files,
        ];
    }

    public static function write(string $path, array $manifest): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('release_manifest_directory_unavailable');
        }
        $temporary = $path.'.tmp.'.bin2hex(random_bytes(6));
        $json = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($temporary, $json, LOCK_EX) === false) {
            throw new RuntimeException('release_manifest_write_failed');
        }
        try {
            if (!rename($temporary, $path)) {
                if (!copy($temporary, $path)) {
                    throw new RuntimeException('release_manifest_write_failed');
                }
                @unlink($temporary);
            }
            chmod($path, 0644);
        } catch (\Throwable $error) {
            @unlink($temporary);
            throw $error;
        }
    }

    public static function verify(string $root, string $manifestPath, string $expectedRelease): array
    {
        $errors = [];
        $manifest = null;
        try {
            self::release($expectedRelease);
            $gitHead = self::gitHead($root);
            if ($gitHead !== null && $gitHead !== $expectedRelease) {
                $errors[] = 'release_git_head_mismatch';
            }
            $raw = @file_get_contents($manifestPath, false, null, 0, self::MAX_MANIFEST_BYTES + 1);
            if (!is_string($raw) || $raw === '') {
                throw new RuntimeException('release_manifest_unreadable');
            }
            if (strlen($raw) > self::MAX_MANIFEST_BYTES) {
                throw new RuntimeException('release_manifest_too_large');
            }
            $manifest = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($manifest) || ($manifest['format'] ?? null) !== self::FORMAT) {
                throw new RuntimeException('release_manifest_invalid');
            }
            if (($manifest['release'] ?? null) !== $expectedRelease) {
                $errors[] = 'release_mismatch';
            }
            $files = $manifest['files'] ?? null;
            if (!is_array($files) || count($files) > self::MAX_FILES) {
                throw new RuntimeException('release_manifest_files_invalid');
            }
            $seen = [];
            $normalized = [];
            foreach ($files as $entry) {
                if (!is_array($entry)) {
                    $errors[] = 'release_manifest_entry_invalid';
                    continue;
                }
                $path = $entry['path'] ?? null;
                $hash = $entry['sha256'] ?? null;
                $size = $entry['size'] ?? null;
                if (!is_string($path) || !self::safeRelativePath($path) || isset($seen[$path]) ||
                    !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash) ||
                    !is_int($size) || $size < 0) {
                    $errors[] = 'release_manifest_entry_invalid';
                    continue;
                }
                $seen[$path] = true;
                $normalized[] = ['path'=>$path, 'sha256'=>$hash, 'size'=>$size];
            }
            if (($manifest['file_count'] ?? null) !== count($normalized) ||
                ($manifest['root_hash'] ?? null) !== self::rootHash($normalized)) {
                $errors[] = 'release_root_hash_mismatch';
            }
            $actualPaths = self::runtimePaths($root);
            $expectedPaths = array_column($normalized, 'path');
            sort($expectedPaths);
            foreach ($actualPaths as $path) {
                if (!isset($seen[$path])) {
                    $errors[] = 'release_file_unlisted:'.$path;
                }
            }
            foreach ($normalized as $entry) {
                if (!in_array($entry['path'], $actualPaths, true)) {
                    $errors[] = 'release_path_unexpected:'.$entry['path'];
                    continue;
                }
                $absolute = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $entry['path']);
                if (!is_file($absolute) || is_link($absolute)) {
                    $errors[] = 'release_file_missing:'.$entry['path'];
                    continue;
                }
                if (filesize($absolute) !== $entry['size']) {
                    $errors[] = 'release_file_size_mismatch:'.$entry['path'];
                }
                if (hash_file('sha256', $absolute) !== $entry['sha256']) {
                    $errors[] = 'release_file_hash_mismatch:'.$entry['path'];
                }
            }
        } catch (\Throwable $error) {
            $errors[] = $error->getMessage();
        }
        return [
            'ok'=>$errors === [],
            'release'=>$manifest['release'] ?? null,
            'file_count'=>is_array($manifest['files'] ?? null) ? count($manifest['files']) : 0,
            'root_hash'=>$manifest['root_hash'] ?? null,
            'errors'=>array_values(array_unique($errors)),
        ];
    }

    public static function runtimePaths(string $root): array
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        $paths = [];
        foreach (self::ROOT_FILES as $file) {
            if (!is_file($root.DIRECTORY_SEPARATOR.$file) || is_link($root.DIRECTORY_SEPARATOR.$file)) {
                throw new RuntimeException('release_required_file_missing');
            }
            $paths[] = $file;
        }
        foreach (self::RUNTIME_DIRECTORIES as $directory) {
            $base = $root.DIRECTORY_SEPARATOR.$directory;
            if (!is_dir($base) || is_link($base)) {
                throw new RuntimeException('release_runtime_directory_missing');
            }
            $items = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
            );
            foreach ($items as $item) {
                if ($item->isLink() || !$item->isFile()) {
                    throw new RuntimeException('release_file_invalid');
                }
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($item->getPathname(), strlen($root) + 1));
                if (!self::safeRelativePath($relative)) {
                    throw new RuntimeException('release_path_invalid');
                }
                $paths[] = $relative;
            }
        }
        sort($paths, SORT_STRING);
        return $paths;
    }

    public static function cleanGit(string $root): ?bool
    {
        $metadata=$root.DIRECTORY_SEPARATOR.'.git';
        if (!file_exists($metadata) && !is_link($metadata)) {
            return null;
        }
        $process = proc_open(['git', '-C', $root, 'status', '--porcelain', '--untracked-files=all'],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, $root);
        if (!is_resource($process)) {
            throw new RuntimeException('release_git_unavailable');
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || !is_string($output)) {
            throw new RuntimeException('release_git_unavailable');
        }
        return trim($output) === '';
    }

    public static function gitHead(string $root): ?string
    {
        $metadata=$root.DIRECTORY_SEPARATOR.'.git';
        if (!file_exists($metadata) && !is_link($metadata)) {
            return null;
        }
        $process = proc_open(['git', '-C', $root, 'rev-parse', 'HEAD'],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, $root);
        if (!is_resource($process)) {
            throw new RuntimeException('release_git_unavailable');
        }
        fclose($pipes[0]);
        $output = trim((string)stream_get_contents($pipes[1]));
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || !preg_match('/^[a-f0-9]{40}$/D', $output)) {
            throw new RuntimeException('release_git_unavailable');
        }
        return $output;
    }

    private static function release(string $release): void
    {
        if (!preg_match('/^[a-f0-9]{40}$/D', $release)) {
            throw new RuntimeException('release_sha_invalid');
        }
    }

    public static function isRuntimePath(string $path): bool
    {
        return self::safeRelativePath($path);
    }

    private static function safeRelativePath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0") ||
            str_contains($path, '\\') || preg_match('#(^|/)\.\.(/|$)#D', $path)) {
            return false;
        }
        $top = explode('/', $path, 2)[0];
        return in_array($path, self::ROOT_FILES, true) ||
            (in_array($top, self::RUNTIME_DIRECTORIES, true) && str_contains($path, '/'));
    }

    private static function rootHash(array $files): string
    {
        usort($files, static fn(array $left, array $right): int => strcmp($left['path'], $right['path']));
        $lines = '';
        foreach ($files as $file) {
            $lines .= $file['path']."\0".$file['sha256']."\0".$file['size']."\n";
        }
        return hash('sha256', $lines);
    }
}
