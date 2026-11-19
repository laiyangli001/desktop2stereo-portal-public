<?php
declare(strict_types=1);

namespace D2S\services;

use RuntimeException;

/** Read-only verification of a backup set after storage or transport. */
final class BackupSetVerifier
{
    public static function verify(string $directory, string $expectedRelease): array
    {
        if (!preg_match('/^[a-f0-9]{40}$/D',$expectedRelease)) {
            throw new RuntimeException('backup_release_invalid');
        }
        $root = realpath($directory);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('backup_directory_missing');
        }
        if (file_exists($root . DIRECTORY_SEPARATOR . 'backup.incomplete')) {
            throw new RuntimeException('backup_incomplete');
        }
        $manifestPath = $root . DIRECTORY_SEPARATOR . 'backup.json';
        if (!is_file($manifestPath) || filesize($manifestPath) > 4 * 1024 * 1024) {
            throw new RuntimeException('backup_manifest_missing');
        }
        try {
            $payload = json_decode((string)file_get_contents($manifestPath),true,32,JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException('backup_manifest_invalid',0,$error);
        }
        if (!is_array($payload) || !in_array($payload['format'] ?? null,[1,2],true) ||
            !is_string($payload['release'] ?? null) || !hash_equals($expectedRelease,$payload['release']) ||
            !is_string($payload['database'] ?? null) || !preg_match('/^[a-zA-Z0-9_]{1,64}$/D',$payload['database']) ||
            !is_int($payload['created_at'] ?? null) || !is_array($payload['files'] ?? null) || $payload['files'] === []) {
            throw new RuntimeException('backup_manifest_invalid');
        }
        $withImages = $payload['format'] === 2;
        if ($withImages && (!is_int($payload['product_image_count'] ?? null) || $payload['product_image_count'] < 0)) {
            throw new RuntimeException('backup_manifest_invalid');
        }
        $kinds = $withImages ? ['database','attachment','manifest','product_image'] : ['database','attachment','manifest'];
        $seen = [];
        $counts = ['database'=>0,'attachment'=>0,'manifest'=>0,'product_image'=>0];
        $bytes = 0;
        foreach ($payload['files'] as $record) {
            if (!is_array($record) || !in_array($record['kind'] ?? null,$kinds,true) ||
                !is_int($record['bytes'] ?? null) || $record['bytes'] < 0 ||
                !is_string($record['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D',$record['sha256']) ||
                !is_string($record['path'] ?? null)) {
                throw new RuntimeException('backup_file_record_invalid');
            }
            $relative = $record['path'];
            if ($relative === '' || str_contains($relative,"\0") || str_starts_with($relative,'/') ||
                str_starts_with($relative,'\\') || preg_match('/^[A-Za-z]:/D',$relative) ||
                in_array('..',explode('/',$relative),true)) {
                throw new RuntimeException('backup_file_path_invalid');
            }
            if ($withImages) {
                $pattern = match ($record['kind']) {
                    'database' => '#^database\\.sql$#D',
                    'attachment' => '#^attachments/(?<prefix>[a-f0-9]{2})/(?P=prefix)[a-f0-9]{62}$#D',
                    'manifest' => '#^retention-manifests/[a-f0-9]{32}\\.manifest$#D',
                    'product_image' => '#^product-images/[a-f0-9]{64}\\.png$#D',
                };
                if (!preg_match($pattern,$relative)) throw new RuntimeException('backup_file_path_invalid');
                if ($record['kind'] === 'product_image' &&
                    (!hash_equals(basename($relative,'.png'),$record['sha256']) || $record['bytes'] < 1 || $record['bytes'] > 10*1024*1024)) {
                    throw new RuntimeException('backup_file_record_invalid');
                }
            }
            if (isset($seen[$relative])) {
                throw new RuntimeException('backup_file_path_duplicate');
            }
            $seen[$relative] = true;
            $path = $root . DIRECTORY_SEPARATOR . str_replace('/',DIRECTORY_SEPARATOR,$relative);
            $resolved = realpath($path);
            if ($resolved === false || !is_file($resolved) || is_link($path) ||
                !str_starts_with($resolved,$root . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('backup_file_missing');
            }
            $size = filesize($resolved);
            $hash = hash_file('sha256',$resolved);
            if (!is_int($size) || $size !== $record['bytes'] || !is_string($hash) ||
                !hash_equals($record['sha256'],$hash)) {
                throw new RuntimeException('backup_file_hash_mismatch');
            }
            $counts[$record['kind']]++;
            $bytes += $size;
        }
        if ($counts['database'] !== 1 || !isset($seen['database.sql']) ||
            $counts['attachment'] !== ($payload['attachment_count'] ?? -1) ||
            $counts['manifest'] !== ($payload['manifest_count'] ?? -1) ||
            ($withImages && $counts['product_image'] !== $payload['product_image_count'])) {
            throw new RuntimeException('backup_manifest_count_mismatch');
        }
        return [
            'format' => $payload['format'],
            'release' => $payload['release'],
            'database' => $payload['database'],
            'created_at' => $payload['created_at'],
            'attachments' => $counts['attachment'],
            'manifests' => $counts['manifest'],
            'product_images' => $counts['product_image'],
            'product_images_included' => $withImages,
            'files' => count($payload['files']),
            'bytes' => $bytes,
        ];
    }
}
