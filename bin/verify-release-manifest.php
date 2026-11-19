<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap/app.php';

use D2S\services\ReleaseManifest;

$options = getopt('', ['root:', 'manifest:', 'release:']);
$root = (string)($options['root'] ?? D2S_ROOT);
$manifest = (string)($options['manifest'] ?? getenv('D2S_RELEASE_MANIFEST') ?: '');
$release = (string)($options['release'] ?? getenv('D2S_RELEASE') ?: '');
if ($manifest === '' || str_contains($manifest, "\0")) {
    fwrite(STDERR, "release_manifest_path_required\n");
    exit(1);
}
$result = ReleaseManifest::verify($root, $manifest, $release);
echo json_encode(['success'=>$result['ok'],'data'=>$result], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
exit($result['ok'] ? 0 : 1);
