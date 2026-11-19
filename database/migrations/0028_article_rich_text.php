<?php
declare(strict_types=1);
return [
    "ALTER TABLE portal_articles ADD body_format VARCHAR(16) NOT NULL DEFAULT 'plain',
        ADD CONSTRAINT chk_article_body_format CHECK(body_format IN ('plain','html'))",
];
