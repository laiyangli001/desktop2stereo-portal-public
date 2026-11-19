<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use GdImage;

final class BehaviorCaptcha
{
    private const WIDTH = 320;
    private const HEIGHT = 160;
    private const TTL = 300;
    private const CLICK_TOLERANCE = 14;

    public static function generate(string $realm, array $configuration = []): array
    {
        self::realm($realm);
        self::requireGd();
        self::startSession();
        $minimum = self::bounded($configuration['required_clicks_min'] ?? 2,2,4,2);
        $maximum = self::bounded($configuration['required_clicks_max'] ?? 3,2,4,3);
        if ($minimum > $maximum) [$minimum,$maximum] = [2,3];
        $ttl = self::bounded($configuration['ttl_seconds'] ?? self::TTL,60,600,self::TTL);
        $tolerance = self::bounded($configuration['tolerance_px'] ?? self::CLICK_TOLERANCE,8,24,self::CLICK_TOLERANCE);

        $scene = self::drawScene();
        $symbols = [
            ['kind' => 'circle', 'color' => '#8df0b7'],
            ['kind' => 'diamond', 'color' => '#f0ad78'],
            ['kind' => 'gear', 'color' => '#9ed4ef'],
            ['kind' => 'flower', 'color' => '#e783d2'],
            ['kind' => 'triangle', 'color' => '#d7f08a'],
            ['kind' => 'star', 'color' => '#b69ae8'],
        ];
        $positions = self::symbolPositions(count($symbols));
        $order = range(0, count($symbols) - 1);
        self::secureShuffle($order);
        $required = random_int($minimum, $maximum);
        $targetIndexes = array_slice($order, 0, $required);
        $positionByIndex = [];

        foreach ($symbols as $index => $symbol) {
            [$x, $y] = $positions[$index];
            self::drawSymbol($scene, $symbol['kind'], $symbol['color'], $x, $y, 24, '#19366f');
            $positionByIndex[$index] = ['x' => $x, 'y' => $y];
        }
        $targets = [];
        foreach ($targetIndexes as $index) {
            $targets[] = $positionByIndex[$index];
        }

        $thumb = self::drawThumb($symbols, $targetIndexes);
        $id = bin2hex(random_bytes(16));
        $_SESSION['d2s_login_captcha'][$realm] = [
            'id' => $id,
            'targets' => $targets,
            'created_at' => time(),
            'ttl_seconds' => $ttl,
            'tolerance_px' => $tolerance,
        ];
        session_write_close();

        return [
            'id' => $id,
            'master_image' => self::pngDataUri($scene),
            'thumb_image' => self::pngDataUri($thumb),
            'width' => self::WIDTH,
            'height' => self::HEIGHT,
            'required_clicks' => $required,
        ];
    }

    public static function verify(string $realm, string $id, array $clicks): bool
    {
        self::realm($realm);
        self::startSession();

        $challenge = $_SESSION['d2s_login_captcha'][$realm] ?? null;
        unset($_SESSION['d2s_login_captcha'][$realm]);
        session_write_close();

        $ttl = is_array($challenge) ? self::bounded($challenge['ttl_seconds'] ?? self::TTL,60,600,self::TTL) : self::TTL;
        $tolerance = is_array($challenge) ? self::bounded($challenge['tolerance_px'] ?? self::CLICK_TOLERANCE,8,24,self::CLICK_TOLERANCE) : self::CLICK_TOLERANCE;
        if (!is_array($challenge) || !isset($challenge['id'], $challenge['targets'], $challenge['created_at']) ||
            !is_string($challenge['id']) || !hash_equals($challenge['id'], $id) ||
            !is_int($challenge['created_at']) || $challenge['created_at'] > time() || time() - $challenge['created_at'] > $ttl ||
            !is_array($challenge['targets']) || count($clicks) !== count($challenge['targets'])) {
            return false;
        }

        foreach ($challenge['targets'] as $index => $target) {
            $point = $clicks[$index] ?? null;
            if (!is_array($target) || !is_array($point) ||
                !isset($target['x'], $target['y'], $point['x'], $point['y']) ||
                !is_int($point['x']) || !is_int($point['y']) ||
                $point['x'] < 0 || $point['x'] > self::WIDTH ||
                $point['y'] < 0 || $point['y'] > self::HEIGHT ||
                abs($point['x'] - $target['x']) > $tolerance ||
                abs($point['y'] - $target['y']) > $tolerance) {
                return false;
            }
        }

        return true;
    }

    private static function realm(string $realm): void
    {
        if (!in_array($realm, ['user', 'admin'], true)) {
            throw new Problem('captcha_invalid');
        }
    }

    private static function bounded(mixed $value, int $minimum, int $maximum, int $default): int
    {
        if (is_int($value) && $value >= $minimum && $value <= $maximum) return $value;
        if (is_string($value) && preg_match('/^[0-9]{1,4}$/D',$value) === 1) {
            $parsed = (int)$value;
            if ($parsed >= $minimum && $parsed <= $maximum) return $parsed;
        }
        return $default;
    }

    private static function requireGd(): void
    {
        if (!extension_loaded('gd')) {
            throw new Problem('captcha_unavailable', 503);
        }
    }

    private static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (session_status() !== PHP_SESSION_NONE || headers_sent()) {
            throw new Problem('captcha_unavailable', 503);
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('D2S_LOGIN_CAPTCHA');
        $appUrl = getenv('D2S_APP_URL') ?: '';
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            str_starts_with($appUrl, 'https://');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        if (!session_start()) {
            throw new Problem('captcha_unavailable', 503);
        }
    }

    private static function drawScene(): GdImage
    {
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        if (!$image instanceof GdImage) {
            throw new Problem('captcha_unavailable', 503);
        }
        imageantialias($image, true);

        for ($y = 0; $y < self::HEIGHT; $y++) {
            $blend = $y / self::HEIGHT;
            $color = imagecolorallocate(
                $image,
                (int)(20 - 8 * $blend),
                (int)(44 - 20 * $blend),
                (int)(104 - 24 * $blend),
            );
            imageline($image, 0, $y, self::WIDTH, $y, $color);
        }

        $sky = imagecolorallocate($image, 26, 53, 116);
        $star = imagecolorallocate($image, 195, 224, 255);
        for ($i = 0; $i < 42; $i++) {
            imagesetpixel($image, random_int(5, self::WIDTH - 5), random_int(4, 94), $star);
        }

        $moon = imagecolorallocate($image, 105, 160, 238);
        imagefilledellipse($image, 162, 59, 176, 90, $moon);
        imagefilledellipse($image, 189, 44, 154, 82, $sky);

        $farHill = imagecolorallocate($image, 65, 113, 204);
        imagefilledpolygon($image, [0, 111, 44, 88, 91, 109, 139, 82, 190, 111, 244, 91, 320, 108, 320, 160, 0, 160], $farHill);
        $nearHill = imagecolorallocate($image, 36, 76, 155);
        imagefilledpolygon($image, [0, 137, 52, 113, 106, 141, 168, 119, 222, 145, 278, 116, 320, 130, 320, 160, 0, 160], $nearHill);

        $path = imagecolorallocate($image, 84, 137, 225);
        imagesetthickness($image, 10);
        imagearc($image, 112, 161, 215, 80, 188, 352, $path);
        imagesetthickness($image, 1);

        $ladder = imagecolorallocate($image, 128, 181, 239);
        imageline($image, 154, 78, 158, 136, $ladder);
        imageline($image, 174, 78, 171, 136, $ladder);
        for ($y = 87; $y <= 132; $y += 10) {
            imageline($image, 155, $y, 173, $y, $ladder);
        }

        return $image;
    }

    private static function symbolPositions(int $count): array
    {
        $positions = [];
        $attempts = 0;
        while (count($positions) < $count && $attempts < 300) {
            $attempts++;
            $candidate = [random_int(30, 290), random_int(42, 132)];
            foreach ($positions as [$x, $y]) {
                if (($candidate[0] - $x) ** 2 + ($candidate[1] - $y) ** 2 < 46 ** 2) {
                    continue 2;
                }
            }
            $positions[] = $candidate;
        }
        if (count($positions) !== $count) {
            throw new Problem('captcha_unavailable', 503);
        }
        return $positions;
    }

    private static function drawThumb(array $symbols, array $targetIndexes): GdImage
    {
        $image = imagecreatetruecolor(190, 48);
        if (!$image instanceof GdImage) {
            throw new Problem('captcha_unavailable', 503);
        }
        $background = imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle($image, 0, 0, 189, 47, $background);
        foreach ($targetIndexes as $position => $index) {
            $centerX = 22 + $position * 43;
            self::drawSymbol($image, $symbols[$index]['kind'], $symbols[$index]['color'], $centerX, 24, 23, '#ffffff');
        }
        return $image;
    }

    private static function drawSymbol(GdImage $image, string $kind, string $hex, int $x, int $y, int $size, string $cutout): void
    {
        [$red, $green, $blue] = sscanf(ltrim($hex, '#'), '%02x%02x%02x');
        $color = imagecolorallocate($image, $red, $green, $blue);
        $radius = intdiv($size, 2);
        if ($kind === 'circle') {
            imagefilledellipse($image, $x, $y, $size, $size, $color);
            return;
        }
        if ($kind === 'diamond') {
            imagefilledpolygon($image, [$x, $y - $radius, $x + $radius, $y, $x, $y + $radius, $x - $radius, $y], $color);
            return;
        }
        if ($kind === 'triangle') {
            imagefilledpolygon($image, [$x, $y - $radius, $x + $radius, $y + $radius, $x - $radius, $y + $radius], $color);
            return;
        }
        if ($kind === 'square') {
            imagefilledrectangle($image, $x - $radius, $y - $radius, $x + $radius, $y + $radius, $color);
            return;
        }
        if ($kind === 'gear') {
            $points = [];
            for ($index = 0; $index < 16; $index++) {
                $angle = $index * M_PI / 8;
                $distance = $index % 2 === 0 ? $radius : $radius * 0.72;
                $points[] = (int)round($x + cos($angle) * $distance);
                $points[] = (int)round($y + sin($angle) * $distance);
            }
            imagefilledpolygon($image, $points, $color);
            imagefilledellipse($image, $x, $y, (int)($size * 0.34), (int)($size * 0.34), imagecolorallocate($image, ...sscanf(ltrim($cutout, '#'), '%02x%02x%02x')));
            return;
        }
        if ($kind === 'flower') {
            for ($petal = 0; $petal < 5; $petal++) {
                $angle = $petal * 2 * M_PI / 5;
                imagefilledellipse($image, (int)round($x + cos($angle) * $radius * 0.55), (int)round($y + sin($angle) * $radius * 0.55), (int)($size * 0.55), (int)($size * 0.55), $color);
            }
            imagefilledellipse($image, $x, $y, (int)($size * 0.35), (int)($size * 0.35), $color);
            return;
        }

        $points = [];
        for ($point = 0; $point < 10; $point++) {
            $angle = -M_PI / 2 + $point * M_PI / 5;
            $distance = $point % 2 === 0 ? $radius : $radius * 0.45;
            $points[] = (int)round($x + cos($angle) * $distance);
            $points[] = (int)round($y + sin($angle) * $distance);
        }
        imagefilledpolygon($image, $points, $color);
    }

    private static function secureShuffle(array &$values): void
    {
        for ($index = count($values) - 1; $index > 0; $index--) {
            $swap = random_int(0, $index);
            [$values[$index], $values[$swap]] = [$values[$swap], $values[$index]];
        }
    }

    private static function pngDataUri(GdImage $image): string
    {
        ob_start();
        imagepng($image);
        imagedestroy($image);
        $png = ob_get_clean();
        if (!is_string($png) || $png === '') {
            throw new Problem('captcha_unavailable', 503);
        }
        return 'data:image/png;base64,' . base64_encode($png);
    }
}
