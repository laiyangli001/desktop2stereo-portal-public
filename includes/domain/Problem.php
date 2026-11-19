<?php
declare(strict_types=1);

namespace D2S\domain;

use RuntimeException;

final class Problem extends RuntimeException
{
    public function __construct(public readonly string $error, public readonly int $httpStatus = 400)
    {
        parent::__construct($error);
    }
}
