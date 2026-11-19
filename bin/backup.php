<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap/app.php';

use D2S\repositories\Database;
use D2S\services\BackupSet;

$output = null;
foreach (array_slice($argv,1) as $argument) {
    if (str_starts_with($argument,'--output=')) {
        $output = substr($argument,9);
    }
}
if (!is_string($output) || $output === '' ||
    (!str_starts_with($output,DIRECTORY_SEPARATOR) && !preg_match('/^[A-Za-z]:[\\\\\\/]/D',$output))) {
    fwrite(STDERR,"usage: php bin/backup.php --output=/absolute/private/path\n");
    exit(2);
}
$output = rtrim($output,DIRECTORY_SEPARATOR);
if ($output === '' || preg_match('/^[A-Za-z]:$/D',$output)) {
    fwrite(STDERR,"backup_output_invalid\n");
    exit(2);
}

try {
    $backupDsn = getenv('D2S_BACKUP_DB_DSN');
    $prefix = is_string($backupDsn) && $backupDsn !== '' ? 'D2S_BACKUP_DB_' : 'D2S_DB_';
    $db = Database::fromEnvironment($prefix);
    $dsn = (string)getenv($prefix . 'DSN');
    $user = (string)getenv($prefix . 'USER');
    $password = (string)getenv($prefix . 'PASSWORD');
    if (!preg_match('/(?:^|[:;])host=([^;]+)/',$dsn,$host) ||
        !preg_match('/(?:^|;)dbname=([^;]+)/',$dsn,$database)) {
        throw new RuntimeException('backup_dsn_invalid');
    }
    $port = preg_match('/(?:^|;)port=([0-9]+)/',$dsn,$match) ? (int)$match[1] : 3306;
    $dumpTool = getenv('D2S_MYSQLDUMP') ?: '';
    if ($dumpTool === '') {
        $executable = PHP_OS_FAMILY === 'Windows' ? 'mysqldump.exe' : 'mysqldump';
        foreach (explode(PATH_SEPARATOR,(string)getenv('PATH')) as $directory) {
            $candidate = rtrim($directory,DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $executable;
            if (is_file($candidate)) {
                $dumpTool = $candidate;
                break;
            }
        }
    }
    if ($dumpTool === '' || !is_file($dumpTool)) {
        throw new RuntimeException('backup_dump_tool_missing');
    }
    $release = getenv('D2S_BACKUP_RELEASE') ?: getenv('D2S_RELEASE') ?: '';
    $ticketRoot = getenv('D2S_TICKET_STORAGE') ?: D2S_ROOT . '/storage/tickets';
    $manifestRoot = getenv('D2S_RETENTION_MANIFEST_DIR') ?: D2S_ROOT . '/storage/retention-manifests';
    $backup = new BackupSet($db,$dumpTool,$database[1],$host[1],$port,$user,$password,
        $ticketRoot,$manifestRoot,(string)$release);
    $result = $backup->create($output,time());
    fwrite(STDOUT,json_encode(['success'=>true,'data'=>$result],JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
} catch (Throwable $error) {
    fwrite(STDERR,'backup_failed: ' . $error->getMessage() . "\n");
    exit(1);
}
