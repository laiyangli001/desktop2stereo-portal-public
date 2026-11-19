<?php
declare(strict_types=1);

return [
    "UPDATE portal_settings
        SET value_json=JSON_SET(
            value_json,
            '$.privacy_version',IF(JSON_UNQUOTE(JSON_EXTRACT(value_json,'$.privacy_version'))='1.0','1.1',JSON_UNQUOTE(JSON_EXTRACT(value_json,'$.privacy_version'))),
            '$.terms_version',IF(JSON_UNQUOTE(JSON_EXTRACT(value_json,'$.terms_version'))='1.0','1.1',JSON_UNQUOTE(JSON_EXTRACT(value_json,'$.terms_version')))
        ),updated_at=UNIX_TIMESTAMP()
        WHERE name='site.v1' AND JSON_VALID(value_json)",
];
