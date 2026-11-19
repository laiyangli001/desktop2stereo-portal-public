<?php
declare(strict_types=1);

return [
    "INSERT INTO portal_locales(code,url_prefix,native_name,direction,fallback_locales_json,ui_enabled,public_enabled,admin_enabled,sort_order,updated_at) VALUES
        ('de','de','Deutsch','ltr',JSON_ARRAY('en','zh-Hans'),1,1,1,40,UNIX_TIMESTAMP()),
        ('es','es','Español','ltr',JSON_ARRAY('en','zh-Hans'),1,1,1,60,UNIX_TIMESTAMP()),
        ('pt','pt','Português','ltr',JSON_ARRAY('en','zh-Hans'),1,1,1,70,UNIX_TIMESTAMP()),
        ('ko','ko','한국어','ltr',JSON_ARRAY('en','zh-Hans'),1,1,1,100,UNIX_TIMESTAMP())",
    "INSERT INTO portal_locale_aliases(alias,locale_code) VALUES
        ('de-DE','de'),('es-ES','es'),('pt-PT','pt'),('pt-BR','pt'),('ko-KR','ko')",
    "UPDATE portal_locales SET ui_enabled=0,public_enabled=0,admin_enabled=0,sort_order=1000,revision=revision+1,updated_at=UNIX_TIMESTAMP() WHERE code='vi'",
    "UPDATE portal_locales SET sort_order=10,revision=revision+1,updated_at=UNIX_TIMESTAMP() WHERE code='zh-Hans'",
    "UPDATE portal_locales SET sort_order=20,revision=revision+1,updated_at=UNIX_TIMESTAMP() WHERE code='zh-Hant'",
    "UPDATE portal_locales SET sort_order=30,revision=revision+1,updated_at=UNIX_TIMESTAMP() WHERE code='en'",
    "UPDATE portal_locales SET sort_order=50,revision=revision+1,updated_at=UNIX_TIMESTAMP() WHERE code='fr'",
    "UPDATE portal_locales SET sort_order=80,revision=revision+1,updated_at=UNIX_TIMESTAMP() WHERE code='ru'",
    "UPDATE portal_locales SET sort_order=90,revision=revision+1,updated_at=UNIX_TIMESTAMP() WHERE code='ja'",
    "UPDATE portal_settings
        SET value_json=JSON_SET(value_json,
            '$.target_map.de','DE',
            '$.target_map.es','ES',
            '$.target_map.pt','PT-PT',
            '$.target_map.ko','KO')
        WHERE name='translation.settings.v1' AND JSON_VALID(value_json)",
];
