<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap/app.php';

use D2S\repositories\Database;
use D2S\services\ReleaseInfo;
use D2S\services\SiteContent;

try {
    $site=(new SiteContent(Database::fromEnvironment()))->publicData()['site'];
    $release=ReleaseInfo::fromEnvironment()->refresh($site);
    fwrite(STDOUT,'release='.$release['version']."\n");
} catch (Throwable) {
    fwrite(STDERR,"release_refresh_failed\n");
    exit(1);
}
