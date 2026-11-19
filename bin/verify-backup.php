<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap/app.php';

use D2S\services\BackupSetVerifier;

$input = null;
$release = getenv('D2S_RELEASE') ?: '';
foreach (array_slice($argv,1) as $argument) {
    if (str_starts_with($argument,'--input=')) {
        $input = substr($argument,8);
    } elseif (str_starts_with($argument,'--release=')) {
        $release = substr($argument,10);
    }
}
if (!is_string($input) || $input === '' ||
    (!str_starts_with($input,DIRECTORY_SEPARATOR) && !preg_match('/^[A-Za-z]:[\\\\\\/]/D',$input))) {
    fwrite(STDERR,"usage: php bin/verify-backup.php --input=/absolute/private/path [--release=<40-hex-sha>]\n");
    exit(2);
}
try {
    $result = BackupSetVerifier::verify($input,(string)$release);
    fwrite(STDOUT,json_encode(['success'=>true,'data'=>$result],JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
} catch (Throwable $error) {
    fwrite(STDERR,'backup_verification_failed: ' . $error->getMessage() . "\n");
    exit(1);
}
