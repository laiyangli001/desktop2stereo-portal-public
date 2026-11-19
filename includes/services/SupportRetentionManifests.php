<?php
declare(strict_types=1);

namespace D2S\services;

use RuntimeException;
use Throwable;

/** Immutable encrypted manifests used to replay support-retention deletions after a restore. */
final readonly class SupportRetentionManifests
{
    private const SCHEMA = 1;
    private const MAX_ENTRIES = 500;

    public function __construct(private string $root, private SecretBox $secrets)
    {
        if ($root === '') {
            throw new RuntimeException('retention_manifest_root_required');
        }
    }

    public function persist(array $tickets, array $files, int $now): string
    {
        $tickets = $this->ticketRows($tickets);
        $files = $this->fileRows($files);
        if ($tickets === []) {
            throw new RuntimeException('retention_manifest_empty');
        }
        $this->assertFileTickets($tickets,$files);
        $id = bin2hex(random_bytes(16));
        $ticketTimes = array_column($tickets,'closed_at');
        $payload = json_encode([
            'schema' => self::SCHEMA,
            'created_at' => $now,
            'range_start' => (int)min($ticketTimes),
            'range_end' => (int)max($ticketTimes),
            'tickets' => $tickets,
            'files' => $files,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $encoded = $this->secrets->encrypt($payload,$this->context($id));
        $directory = $this->directory(true);
        $path = $directory . DIRECTORY_SEPARATOR . $id . '.manifest';
        $temporary = $directory . DIRECTORY_SEPARATOR . '.' . $id . '.tmp-' . bin2hex(random_bytes(8));
        $handle = @fopen($temporary,'xb');
        if ($handle === false) {
            throw new RuntimeException('retention_manifest_storage_unavailable');
        }
        try {
            $written = fwrite($handle,$encoded . "\n");
            if ($written === false || $written !== strlen($encoded) + 1 || !fflush($handle)) {
                throw new RuntimeException('retention_manifest_write_failed');
            }
            if (function_exists('fsync') && !fsync($handle)) {
                throw new RuntimeException('retention_manifest_write_failed');
            }
        } catch (Throwable $error) {
            fclose($handle);
            @unlink($temporary);
            throw $error;
        }
        fclose($handle);
        @chmod($temporary,UpdateSharedPermissions::fileMode());
        if (!@rename($temporary,$path)) {
            @unlink($temporary);
            throw new RuntimeException('retention_manifest_publish_failed');
        }
        @chmod($path,UpdateSharedPermissions::fileMode());
        return $id;
    }

    public function all(): array
    {
        $directory = $this->directory(false);
        if ($directory === null) {
            return [];
        }
        $paths = glob($directory . DIRECTORY_SEPARATOR . '*.manifest');
        if ($paths === false || $paths === []) {
            return [];
        }
        sort($paths,SORT_STRING);
        $manifests = [];
        foreach ($paths as $path) {
            $name = basename($path);
            if (!preg_match('/^([a-f0-9]{32})\\.manifest$/D',$name,$match)) {
                continue;
            }
            $id = $match[1];
            $raw = @file_get_contents($path);
            if (!is_string($raw)) {
                throw new RuntimeException('retention_manifest_unreadable');
            }
            try {
                $plaintext = $this->secrets->decrypt(trim($raw),$this->context($id));
                $payload = json_decode($plaintext,true,32,JSON_THROW_ON_ERROR);
            } catch (Throwable $error) {
                throw new RuntimeException('retention_manifest_invalid',0,$error);
            }
            if (!is_array($payload) || ($payload['schema'] ?? null) !== self::SCHEMA ||
                !is_int($payload['created_at'] ?? null) || !is_array($payload['tickets'] ?? null) ||
                !is_array($payload['files'] ?? null)) {
                throw new RuntimeException('retention_manifest_invalid');
            }
            $tickets = $this->ticketRows($payload['tickets']);
            $files = $this->fileRows($payload['files']);
            if ($tickets === []) {
                throw new RuntimeException('retention_manifest_invalid');
            }
            $this->assertFileTickets($tickets,$files);
            $ticketTimes = array_column($tickets,'closed_at');
            $manifests[] = [
                'id' => $id,
                'created_at' => $payload['created_at'],
                'range_start' => (int)min($ticketTimes),
                'range_end' => (int)max($ticketTimes),
                'tickets' => $tickets,
                'files' => $files,
            ];
        }
        return $manifests;
    }

    private function directory(bool $create): ?string
    {
        $root = $this->root;
        if (!str_starts_with($root,DIRECTORY_SEPARATOR) && !preg_match('/^[A-Za-z]:[\\\\\\/]/D',$root)) {
            $root = D2S_ROOT . DIRECTORY_SEPARATOR . $root;
        }
        $root = rtrim($root,DIRECTORY_SEPARATOR);
        if (!is_dir($root)) {
            if (!$create) {
                return null;
            }
            if (!mkdir($root,UpdateSharedPermissions::directoryMode(),true) && !is_dir($root)) {
                throw new RuntimeException('retention_manifest_storage_unavailable');
            }
        }
        @chmod($root,UpdateSharedPermissions::directoryMode());
        $resolved = realpath($root);
        if ($resolved === false || !is_dir($resolved)) {
            throw new RuntimeException('retention_manifest_storage_unavailable');
        }
        $public = realpath(D2S_ROOT . DIRECTORY_SEPARATOR . 'public');
        if ($public !== false && ($resolved === $public || str_starts_with($resolved,$public . DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('retention_manifest_public_directory');
        }
        return $resolved;
    }

    private function context(string $id): string
    {
        return 'support-retention:v'.self::SCHEMA.':' . $id;
    }

    private function assertFileTickets(array $tickets, array $files): void
    {
        $ticketIds = array_fill_keys(array_column($tickets,'id'),true);
        foreach ($files as $file) {
            if (!isset($ticketIds[$file['ticket_id']])) {
                throw new RuntimeException('retention_manifest_file_ticket_invalid');
            }
        }
    }

    private function ticketRows(array $rows): array
    {
        if (count($rows) < 1 || count($rows) > self::MAX_ENTRIES) {
            throw new RuntimeException('retention_manifest_ticket_count_invalid');
        }
        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['id'] ?? null) ||
                !preg_match('/^[a-f0-9]{32}$/D',$row['id']) || !is_int($row['closed_at'] ?? null) ||
                $row['closed_at'] < 1) {
                throw new RuntimeException('retention_manifest_ticket_invalid');
            }
            $normalized[$row['id']] = ['id'=>$row['id'],'closed_at'=>$row['closed_at']];
        }
        ksort($normalized,SORT_STRING);
        return array_values($normalized);
    }

    private function fileRows(array $rows): array
    {
        if (count($rows) > self::MAX_ENTRIES * 5) {
            throw new RuntimeException('retention_manifest_file_count_invalid');
        }
        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['ticket_id'] ?? null) ||
                !preg_match('/^[a-f0-9]{32}$/D',$row['ticket_id']) ||
                !is_string($row['storage_name'] ?? null) ||
                !preg_match('/^[a-f0-9]{64}$/D',$row['storage_name']) || !is_int($row['created_at'] ?? null) ||
                $row['created_at'] < 1) {
                throw new RuntimeException('retention_manifest_file_invalid');
            }
            $normalized[$row['storage_name']] = [
                'ticket_id'=>$row['ticket_id'],
                'storage_name'=>$row['storage_name'],
                'created_at'=>$row['created_at'],
            ];
        }
        ksort($normalized,SORT_STRING);
        return array_values($normalized);
    }
}
