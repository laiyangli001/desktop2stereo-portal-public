<?php
declare(strict_types=1);

return [
    "ALTER TABLE portal_translation_jobs
        DROP CHECK ck_translation_job_resource_type,
        ADD CONSTRAINT ck_translation_job_resource_type CHECK (resource_type IN ('article','product','announcement','policy'))",
];
