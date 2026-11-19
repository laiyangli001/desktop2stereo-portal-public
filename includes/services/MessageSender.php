<?php
declare(strict_types=1);

namespace D2S\services;

interface MessageSender
{
    public function send(array $message): void;
}
