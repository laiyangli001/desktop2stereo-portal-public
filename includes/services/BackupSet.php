<?php
declare(strict_types=1);

namespace D2S\services;

use PDO;
use RuntimeException;
use Throwable;

/** Creates a private, hash-verified backup set without embedding deployment secrets. */
final readonly class BackupSet
{
    public function __construct(
        private PDO $db,
        private string $dumpTool,
        private string $database,
        private string $host,
        private int $port,
        private string $dumpUser,
        private string $dumpPassword,
        private string $ticketRoot,
        private string $manifestRoot,
        private string $release,
        private ?string $productImageRoot = null,
    ) {
    }

    public function create(string $output, int $now): array
    {
        $output=PrivateOutputDirectory::resolve($output,D2S_ROOT,'backup_output');
        if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/D',$this->database) ||
            !preg_match('/^[a-f0-9]{40}$/D',$this->release) ||
            !preg_match('/^[A-Za-z0-9._:+-]{1,255}$/D',$this->host) ||
            !preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,79}$/D',$this->dumpUser) ||
            $this->port < 1 || $this->port > 65535) {
            throw new RuntimeException('backup_configuration_invalid');
        }
        if (!is_file($this->dumpTool)) {
            throw new RuntimeException('backup_dump_tool_missing');
        }
        if (file_exists($output)) {
            if (!is_dir($output)) {
                throw new RuntimeException('backup_output_exists');
            }
            $entries = scandir($output);
            if ($entries === false || array_diff($entries,['.','..']) !== []) {
                throw new RuntimeException('backup_output_not_empty');
            }
        } elseif (!mkdir($output,0700,true)) {
            throw new RuntimeException('backup_output_unavailable');
        }
        @chmod($output,0700);
        $marker = $output . DIRECTORY_SEPARATOR . 'backup.incomplete';
        if (file_put_contents($marker,"incomplete\n",LOCK_EX) === false) {
            throw new RuntimeException('backup_output_unavailable');
        }
        $lock = $this->db->query("SELECT GET_LOCK('d2s_support_purge',0)")->fetchColumn();
        if ((int)$lock !== 1) {
            throw new RuntimeException('backup_busy');
        }
        try {
            $imagePaths = $this->db->query("SELECT DISTINCT image_path FROM portal_products WHERE image_path IS NOT NULL AND image_path<>''")
                ->fetchAll(PDO::FETCH_COLUMN);
            $databaseFile = $output . DIRECTORY_SEPARATOR . 'database.sql';
            $this->dump($databaseFile);
            $attachments = $this->copyAttachments($output . DIRECTORY_SEPARATOR . 'attachments');
            $manifests = $this->copyManifests($output . DIRECTORY_SEPARATOR . 'retention-manifests');
            $images = $this->copyProductImages($output . DIRECTORY_SEPARATOR . 'product-images',$imagePaths);
            $files = array_merge([$this->fileRecord($output,$databaseFile,'database')],$attachments,$manifests,$images);
            $payload = [
                'format' => 2,
                'created_at' => $now,
                'release' => $this->release,
                'database' => $this->database,
                'attachment_count' => count(array_filter($files,static fn(array $file): bool => $file['kind'] === 'attachment')),
                'manifest_count' => count(array_filter($files,static fn(array $file): bool => $file['kind'] === 'manifest')),
                'product_image_count' => count($images),
                'files' => $files,
            ];
            $json = json_encode($payload,JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $manifestFile = $output . DIRECTORY_SEPARATOR . 'backup.json';
            if (file_put_contents($manifestFile,$json,LOCK_EX) === false) {
                throw new RuntimeException('backup_manifest_write_failed');
            }
            @chmod($manifestFile,0600);
            if (!unlink($marker)) {
                throw new RuntimeException('backup_finalize_failed');
            }
            return [
                'path' => $output,
                'database_sha256' => $files[0]['sha256'],
                'attachments' => $payload['attachment_count'],
                'manifests' => $payload['manifest_count'],
                'product_images' => $payload['product_image_count'],
                'files' => count($files),
                'bytes' => array_sum(array_column($files,'bytes')),
            ];
        } finally {
            $release = $this->db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute(['d2s_support_purge']);
        }
    }

    private function dump(string $target): void
    {
        $handle = @fopen($target,'xb');
        if ($handle === false) {
            throw new RuntimeException('backup_database_write_failed');
        }
        $environment = [];
        foreach (['SystemRoot','WINDIR','PATH','TEMP','TMP'] as $name) {
            if (getenv($name) !== false) {
                $environment[$name] = getenv($name);
            }
        }
        $environment['MYSQL_PWD'] = $this->dumpPassword;
        $command = [
            $this->dumpTool,
            '--host=' . $this->host,
            '--port=' . $this->port,
            '--user=' . $this->dumpUser,
            '--single-transaction',
            '--no-tablespaces',
            '--set-gtid-purged=OFF',
            '--hex-blob',
            '--skip-comments',
            $this->database,
        ];
        $process = proc_open($command,[0=>['pipe','r'],1=>$handle,2=>['pipe','w']],$pipes,D2S_ROOT,$environment);
        if (!is_resource($process)) {
            fclose($handle);
            @unlink($target);
            throw new RuntimeException('backup_dump_start_failed');
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $status = proc_close($process);
        fclose($handle);
        if ($status !== 0 || !is_file($target) || filesize($target) < 1024) {
            @unlink($target);
            throw new RuntimeException('backup_dump_failed');
        }
        @chmod($target,0600);
    }

    private function copyAttachments(string $directory): array
    {
        if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) {
            throw new RuntimeException('backup_attachment_directory_failed');
        }
        @chmod($directory,0700);
        $query = $this->db->query('SELECT storage_name,created_at,sha256,byte_size FROM portal_support_attachments ORDER BY created_at,storage_name');
        $records = [];
        foreach ($query->fetchAll() as $row) {
            $name = (string)$row['storage_name'];
            if (!preg_match('/^[a-f0-9]{64}$/D',$name)) {
                throw new RuntimeException('backup_attachment_invalid');
            }
            $source = $this->ticketPath($name);
            if (!is_file($source) || is_link($source)) {
                throw new RuntimeException('backup_attachment_missing');
            }
            $targetDirectory = $directory . DIRECTORY_SEPARATOR . substr($name,0,2);
            if (!is_dir($targetDirectory) && !mkdir($targetDirectory,0700,true) && !is_dir($targetDirectory)) {
                throw new RuntimeException('backup_attachment_directory_failed');
            }
            $target = $targetDirectory . DIRECTORY_SEPARATOR . $name;
            if (!copy($source,$target) || !chmod($target,0600)) {
                @unlink($target);
                throw new RuntimeException('backup_attachment_copy_failed');
            }
            $hash = hash_file('sha256',$target);
            if (!is_string($hash) || !hash_equals(strtolower((string)$row['sha256']),$hash) ||
                filesize($target) !== (int)$row['byte_size']) {
                @unlink($target);
                throw new RuntimeException('backup_attachment_hash_mismatch');
            }
            $records[] = $this->fileRecord(dirname($directory),$target,'attachment');
        }
        return $records;
    }

    private function copyManifests(string $directory): array
    {
        if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) {
            throw new RuntimeException('backup_manifest_directory_failed');
        }
        @chmod($directory,0700);
        $sourceDirectory = $this->absolutePath($this->manifestRoot);
        if (!is_dir($sourceDirectory)) {
            return [];
        }
        $sourceFiles = glob($sourceDirectory . DIRECTORY_SEPARATOR . '*.manifest') ?: [];
        sort($sourceFiles,SORT_STRING);
        $records = [];
        foreach ($sourceFiles as $source) {
            $name = basename($source);
            if (!preg_match('/^[a-f0-9]{32}\.manifest$/D',$name) || !is_file($source) || is_link($source)) {
                throw new RuntimeException('backup_manifest_invalid');
            }
            $target = $directory . DIRECTORY_SEPARATOR . $name;
            if (!copy($source,$target) || !chmod($target,0600)) {
                @unlink($target);
                throw new RuntimeException('backup_manifest_copy_failed');
            }
            $records[] = $this->fileRecord(dirname($directory),$target,'manifest');
        }
        return $records;
    }

    /** Preserve immutable historical images too: a concurrent upload never removes snapshot references. */
    private function copyProductImages(string $directory, array $references): array
    {
        $required = [];
        foreach ($references as $path) {
            if (!is_string($path) || !preg_match('#^/product-images/([a-f0-9]{64}\\.png)$#D',$path,$match)) {
                throw new RuntimeException('backup_product_image_reference_invalid');
            }
            $required[$match[1]] = true;
        }
        $sourceDirectory = $this->absolutePath($this->productImageRoot ?? ProductImages::directory());
        if (!is_dir($sourceDirectory)) {
            if ($required !== []) throw new RuntimeException('backup_product_image_missing');
            return [];
        }
        $entries = @scandir($sourceDirectory);
        if ($entries === false) throw new RuntimeException('backup_product_image_directory_unreadable');
        $sources = [];
        foreach ($entries as $entry) {
            if (str_ends_with($entry,'.png')) $sources[] = $sourceDirectory . DIRECTORY_SEPARATOR . $entry;
        }
        sort($sources,SORT_STRING);
        if ($sources === [] && $required === []) return [];
        if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) {
            throw new RuntimeException('backup_product_image_directory_failed');
        }
        @chmod($directory,0700);
        $records = [];
        foreach ($sources as $source) {
            $name = basename($source);
            if (!preg_match('/^([a-f0-9]{64})\\.png$/D',$name,$match) || !is_file($source) || is_link($source)) {
                throw new RuntimeException('backup_product_image_invalid');
            }
            $target = $directory . DIRECTORY_SEPARATOR . $name;
            if (!copy($source,$target) || !chmod($target,0600)) {
                @unlink($target);
                throw new RuntimeException('backup_product_image_copy_failed');
            }
            $hash = hash_file('sha256',$target);
            $size = filesize($target);
            if (!is_string($hash) || !hash_equals($match[1],$hash) || !is_int($size) || $size < 1 || $size > 10*1024*1024) {
                @unlink($target);
                throw new RuntimeException('backup_product_image_hash_mismatch');
            }
            unset($required[$name]);
            $records[] = $this->fileRecord(dirname($directory),$target,'product_image');
        }
        if ($required !== []) throw new RuntimeException('backup_product_image_missing');
        return $records;
    }

    private function fileRecord(string $root, string $path, string $kind): array
    {
        $hash = hash_file('sha256',$path);
        $size = filesize($path);
        if (!is_string($hash) || !is_int($size)) {
            throw new RuntimeException('backup_file_unreadable');
        }
        $relative = str_replace(DIRECTORY_SEPARATOR,'/',substr($path,strlen(rtrim($root,DIRECTORY_SEPARATOR)) + 1));
        return ['kind'=>$kind,'path'=>$relative,'bytes'=>$size,'sha256'=>$hash];
    }

    private function ticketPath(string $storageName): string
    {
        return $this->absolutePath($this->ticketRoot) . DIRECTORY_SEPARATOR . substr($storageName,0,2)
            . DIRECTORY_SEPARATOR . $storageName;
    }

    private function absolutePath(string $root): string
    {
        if (!str_starts_with($root,DIRECTORY_SEPARATOR) && !preg_match('/^[A-Za-z]:[\\\\\\/]/D',$root)) {
            $root = D2S_ROOT . DIRECTORY_SEPARATOR . $root;
        }
        return rtrim($root,DIRECTORY_SEPARATOR);
    }
}
