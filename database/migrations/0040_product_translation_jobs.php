<?php
declare(strict_types=1);

return [
    "ALTER TABLE portal_translation_jobs
        DROP CHECK portal_translation_jobs_chk_1,
        ADD CONSTRAINT ck_translation_job_resource_type CHECK (resource_type IN ('article','product'))",
    "ALTER TABLE portal_product_translation_revisions
        ADD provider VARCHAR(80) NULL,
        ADD glossary_revision INT NULL",
];
