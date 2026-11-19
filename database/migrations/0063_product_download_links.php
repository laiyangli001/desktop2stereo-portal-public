<?php
declare(strict_types=1);

return [
    "ALTER TABLE portal_products
        ADD COLUMN download_url VARCHAR(2048) NOT NULL DEFAULT '',
        ADD COLUMN download_quark VARCHAR(2048) NOT NULL DEFAULT '',
        ADD COLUMN download_baidu VARCHAR(2048) NOT NULL DEFAULT ''",
    "UPDATE portal_products p
        LEFT JOIN portal_settings s ON s.name='site.v1'
        SET p.download_url=COALESCE(NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(s.value_json,'$.download_github')),''),'null'),
                'https://github.com/laiyangli001/desktop2stereo-vulkan/releases/latest'),
            p.download_quark=COALESCE(NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(s.value_json,'$.download_quark')),''),'null'),
                'https://pan.quark.cn/s/9d2bcf039b96'),
            p.download_baidu=COALESCE(NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(s.value_json,'$.download_baidu')),''),'null'),
                'https://pan.baidu.com/s/1JfMpcrdplPTQSNB6rExh6A?pwd=mr64')
        WHERE p.code='desktop2stereo'",
    "UPDATE portal_settings
        SET value_json=JSON_REMOVE(value_json,'$.download_github','$.download_quark','$.download_baidu')
        WHERE name='site.v1'",
];
