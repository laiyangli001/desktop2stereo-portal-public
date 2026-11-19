<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use JsonException;

/** Preserve signed response bytes; reject duplicate names at every object depth. */
final class RawJson
{
    private int $position = 0;
    private array $root = [];

    private function __construct(private string $json) {}

    public static function fields(string $json): array
    {
        if (strlen($json) > 1048576) { throw new Problem('alipay_response_invalid',502); }
        try {
            $decoded = json_decode($json,false,32,JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) { throw new Problem('alipay_response_invalid',502); }
        if (!$decoded instanceof \stdClass) { throw new Problem('alipay_response_invalid',502); }
        $reader = new self($json);
        $reader->value(0);
        return $reader->root;
    }

    private function space(): void
    {
        while (isset($this->json[$this->position]) && str_contains(" \r\n\t",$this->json[$this->position])) { $this->position++; }
    }

    private function string(): string
    {
        $start = $this->position++;
        while (isset($this->json[$this->position])) {
            $character = $this->json[$this->position++];
            if ($character === '"') { return substr($this->json,$start,$this->position-$start); }
            if ($character === '\\') {
                $escaped = $this->json[$this->position++];
                if ($escaped === 'u') { $this->position += 4; }
            }
        }
        throw new Problem('alipay_response_invalid',502);
    }

    private function value(int $depth): void
    {
        $this->space();
        $character = $this->json[$this->position];
        if ($character === '"') { $this->string(); return; }
        if ($character === '{') {
            $this->position++;
            $this->space();
            $names = [];
            if ($this->json[$this->position] === '}') { $this->position++; return; }
            do {
                $this->space();
                $name = json_decode($this->string(),true,2,JSON_THROW_ON_ERROR);
                if (array_key_exists($name,$names)) { throw new Problem('alipay_response_invalid',502); }
                $names[$name] = true;
                $this->space();
                $this->position++; // Colon already validated by json_decode.
                $this->space();
                $start = $this->position;
                $this->value($depth+1);
                if ($depth === 0) { $this->root[$name] = substr($this->json,$start,$this->position-$start); }
                $this->space();
                $next = $this->json[$this->position++];
            } while ($next === ',');
            return;
        }
        if ($character === '[') {
            $this->position++;
            $this->space();
            if ($this->json[$this->position] === ']') { $this->position++; return; }
            do {
                $this->value($depth+1);
                $this->space();
                $next = $this->json[$this->position++];
            } while ($next === ',');
            return;
        }
        while (isset($this->json[$this->position]) && !str_contains(" \r\n\t,}]",$this->json[$this->position])) { $this->position++; }
    }
}
