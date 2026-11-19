<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use DOMDocument;
use DOMNode;

final class GlossaryProtector
{
    public static function protectText(string $text, array $terms): array
    {
        $tokens = [];
        $text = preg_replace_callback(self::specialTokenPattern(), static function (array $match) use (&$tokens): string {
            return self::storeToken($tokens, $match[0]);
        }, $text) ?? $text;
        foreach (self::orderedTerms($terms) as $term) {
            $tokenPattern = $tokens === [] ? null : '~('.implode('|', array_map(
                static fn(string $token): string => preg_quote($token, '~'), array_keys($tokens)
            )).')~';
            $parts = $tokenPattern === null ? [$text] : preg_split($tokenPattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
            if ($parts === false) $parts = [$text];
            foreach ($parts as $index => $part) {
                if (isset($tokens[$part])) continue;
                $pattern = '/'.preg_quote($term['source_term'], '/').'/iu';
                $parts[$index] = preg_replace_callback($pattern, static function () use (&$tokens, $term): string {
                    return self::storeToken($tokens, $term['mode'] === 'preserve' ? $term['source_term'] : $term['target_term']);
                }, $part) ?? $part;
            }
            $text = implode('', $parts);
        }
        return [$text, $tokens];
    }

    public static function protectHtml(string $html, array $terms): array
    {
        $tokens = [];
        [$protected] = self::mapHtmlTextNodes($html, static function (string $text) use ($terms, &$tokens): array {
            [$mapped, $found] = self::protectText($text, $terms);
            $tokens += $found;
            return [$mapped, []];
        }, true);
        return [$protected, $tokens];
    }

    public static function restoreText(string $text, array $tokens): string
    {
        self::assertAllTokens($text, $tokens);
        return strtr($text, $tokens);
    }

    public static function restoreHtml(string $html, array $tokens): string
    {
        [$restored, $found] = self::mapHtmlTextNodes($html, static function (string $text) use ($tokens): array {
            $counts = [];
            foreach ($tokens as $token => $_) {
                $count = substr_count($text, $token);
                if ($count > 0) $counts[$token] = $count;
            }
            return [strtr($text, $tokens), $counts];
        }, false);
        self::assertTokenCounts($tokens, $found);
        return $restored;
    }

    private static function specialTokenPattern(): string
    {
        return '~(?:https?://|ftp://|mailto:|www\\.)[^\\s<>"\\\']+|\\{\\{[^{}\\r\\n]{1,200}\\}\\}|\\$\\{[^{}\\r\\n]{1,200}\\}|\\{[A-Za-z_][A-Za-z0-9_.-]*\\}|%\\([A-Za-z_][A-Za-z0-9_]*\\)[-+0-9.# ]*[bcdeEfFgGosuxX]|%(?:\\d+\\$)?[-+0-9.# ]*[bcdeEfFgGosuxX]|(?<![\\pL\\pN_]):[A-Za-z_][A-Za-z0-9_.-]*~u';
    }

    private static function storeToken(array &$tokens, string $value): string
    {
        do {
            $token = 'D2SKEEP'.bin2hex(random_bytes(12)).'X';
        } while (isset($tokens[$token]));
        $tokens[$token] = $value;
        return $token;
    }

    private static function orderedTerms(array $terms): array
    {
        $valid = array_values(array_filter($terms, static fn(array $term): bool =>
            isset($term['source_term'], $term['target_term'], $term['mode']) &&
            is_string($term['source_term']) && $term['source_term'] !== '' &&
            is_string($term['target_term']) && $term['target_term'] !== '' &&
            in_array($term['mode'], ['translate_as', 'preserve'], true)
        ));
        usort($valid, static fn(array $a, array $b): int => mb_strlen($b['source_term'], 'UTF-8') <=> mb_strlen($a['source_term'], 'UTF-8'));
        return $valid;
    }

    private static function assertAllTokens(string $text, array $tokens): void
    {
        $counts = [];
        foreach ($tokens as $token => $_) $counts[$token] = substr_count($text, $token);
        self::assertTokenCounts($tokens, $counts);
    }

    private static function assertTokenCounts(array $tokens, array $counts): void
    {
        foreach ($tokens as $token => $_) {
            if (($counts[$token] ?? 0) !== 1) throw new Problem('translation_glossary_placeholder_invalid', 422);
        }
    }

    private static function mapHtmlTextNodes(string $html, callable $map, bool $protectCode): array
    {
        $found = [];
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument('1.0', 'UTF-8');
            if (!$document->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>', LIBXML_NONET)) {
                throw new Problem('translation_source_invalid');
            }
            $body = $document->getElementsByTagName('body')->item(0);
            if (!$body) throw new Problem('translation_source_invalid');
            $walk = static function (DOMNode $node, bool $insideCode = false) use (&$walk, $map, $protectCode, &$found): void {
                $isCode = $insideCode || ($node->nodeType === XML_ELEMENT_NODE && in_array(strtolower($node->nodeName), ['pre', 'code'], true));
                if ($node->nodeType === XML_TEXT_NODE && (!$protectCode || !$isCode)) {
                    [$mapped, $counts] = $map((string)$node->nodeValue);
                    $node->nodeValue = $mapped;
                    foreach ($counts as $token => $count) $found[$token] = ($found[$token] ?? 0) + (int)$count;
                    return;
                }
                foreach (iterator_to_array($node->childNodes) as $child) $walk($child, $isCode);
            };
            $walk($body);
            $result = '';
            foreach ($body->childNodes as $node) $result .= $document->saveHTML($node);
            return [$result, $found];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
