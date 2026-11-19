<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap/app.php';

use D2S\services\ReleaseManifest;

$options = getopt('', ['root:', 'release:', 'output:']);
$root = (string)($options['root'] ?? D2S_ROOT);
$release = (string)($options['release'] ?? getenv('D2S_RELEASE') ?: '');
$output = (string)($options['output'] ?? '');
if ($output === '' || str_contains($output, "\0")) {
    fwrite(STDERR, "release_manifest_output_required\n");
    exit(1);
}
try {
    $gitHead = ReleaseManifest::gitHead($root);
    $cleanGit = ReleaseManifest::cleanGit($root);
    if ($gitHead !== null && $gitHead !== $release) {
        throw new RuntimeException('release_git_head_mismatch');
    }
    if ($cleanGit === false) {
        throw new RuntimeException('release_git_worktree_dirty');
    }
    $manifest = ReleaseManifest::build($root, $release, time());
    ReleaseManifest::write($output, $manifest);
    echo json_encode([
        'success'=>true,
        'release'=>$manifest['release'],
        'file_count'=>$manifest['file_count'],
        'root_hash'=>$manifest['root_hash'],
        'output'=>$output,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['success'=>false,'error'=>$error->getMessage()], JSON_THROW_ON_ERROR)."\n");
    exit(1);
}
