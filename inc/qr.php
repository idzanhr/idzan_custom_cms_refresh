<?php
declare(strict_types=1);

/**
 * Minimal QR code encoder (byte mode, error correction level M, versions 1–14, up to ~330 bytes)
 * that renders an SVG. Used for the 2FA setup screen so the secret never leaves
 * this server — no QR web service, no JavaScript library.
 * Algorithm follows ISO/IEC 18004; structure modelled on Project Nayuki's reference encoder.
 */
final class QrSvg
{
    // version => [ec codewords per block, group1 blocks, group1 data cw, group2 blocks, group2 data cw]  (level M)
    private const BLOCKS = [
        1 => [10, 1, 16, 0, 0],   2 => [16, 1, 28, 0, 0],   3 => [26, 1, 44, 0, 0],   4 => [18, 2, 32, 0, 0],
        5 => [24, 2, 43, 0, 0],   6 => [16, 4, 27, 0, 0],   7 => [18, 4, 31, 0, 0],   8 => [22, 2, 38, 2, 39],
        9 => [22, 3, 36, 2, 37], 10 => [26, 4, 43, 1, 44], 11 => [30, 1, 50, 4, 51], 12 => [22, 6, 36, 2, 37],
        13 => [22, 8, 37, 1, 38], 14 => [24, 4, 40, 5, 41],
    ];
    private const ALIGN = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42],
        9 => [6, 26, 46], 10 => [6, 28, 50], 11 => [6, 30, 54], 12 => [6, 32, 58], 13 => [6, 34, 62],
        14 => [6, 26, 46, 66],
    ];

    private int $size;
    /** @var bool[][] [row][col] */
    private array $m = [];
    /** @var bool[][] */
    private array $fn = [];

    public static function svg(string $text, int $px = 240, string $dark = '#000', string $light = '#fff'): string
    {
        $q = new self();
        $q->encode($text);
        return $q->toSvg($px, $dark, $light);
    }

    /** @return bool[][] module matrix, for tests */
    public static function matrix(string $text): array
    {
        $q = new self();
        $q->encode($text);
        return $q->m;
    }

    private function encode(string $data): void
    {
        $len = strlen($data);
        $version = 0;
        foreach (self::BLOCKS as $v => $b) {
            $capBits = ($b[1] * $b[2] + $b[3] * $b[4]) * 8;
            if (4 + ($v < 10 ? 8 : 16) + 8 * $len <= $capBits) { $version = $v; break; }
        }
        if (!$version) throw new InvalidArgumentException('Text too long for QR code.');
        [$ecLen, $g1, $d1, $g2, $d2] = self::BLOCKS[$version];
        $capacity = $g1 * $d1 + $g2 * $d2;

        // Bit stream
        $bits = [];
        $put = function (int $val, int $n) use (&$bits) { for ($i = $n - 1; $i >= 0; $i--) $bits[] = ($val >> $i) & 1; };
        $put(0b0100, 4);
        $put($len, $version < 10 ? 8 : 16);
        for ($i = 0; $i < $len; $i++) $put(ord($data[$i]), 8);
        $put(0, min(4, $capacity * 8 - count($bits)));
        while (count($bits) % 8) $bits[] = 0;
        $cw = [];
        foreach (array_chunk($bits, 8) as $byte) $cw[] = bindec(implode('', $byte));
        for ($pad = 0xEC; count($cw) < $capacity; $pad ^= 0xEC ^ 0x11) $cw[] = $pad;

        // Blocks + Reed–Solomon
        $div = self::rsDivisor($ecLen);
        $blocks = [];
        $ecs = [];
        $k = 0;
        foreach ([[$g1, $d1], [$g2, $d2]] as [$n, $d]) {
            for ($b = 0; $b < $n; $b++) {
                $blk = array_slice($cw, $k, $d);
                $k += $d;
                $blocks[] = $blk;
                $ecs[] = self::rsRemainder($blk, $div);
            }
        }
        $final = [];
        $maxD = max($d1, $d2);
        for ($i = 0; $i < $maxD; $i++) foreach ($blocks as $blk) if ($i < count($blk)) $final[] = $blk[$i];
        for ($i = 0; $i < $ecLen; $i++) foreach ($ecs as $ec) $final[] = $ec[$i];

        // Matrix
        $this->size = $version * 4 + 17;
        $row = array_fill(0, $this->size, false);
        $this->m = array_fill(0, $this->size, $row);
        $this->fn = array_fill(0, $this->size, $row);
        $this->functionPatterns($version);
        $this->placeData($final);

        // Pick the mask with the lowest penalty.
        $best = 0;
        $bestScore = PHP_INT_MAX;
        $base = $this->m;
        for ($mask = 0; $mask < 8; $mask++) {
            $this->m = $base;
            $this->applyMask($mask);
            $this->formatBits($mask);
            $score = $this->penalty();
            if ($score < $bestScore) { $bestScore = $score; $best = $mask; }
        }
        $this->m = $base;
        $this->applyMask($best);
        $this->formatBits($best);
    }

    private function set(int $x, int $y, bool $dark): void
    {
        $this->m[$y][$x] = $dark;
        $this->fn[$y][$x] = true;
    }

    private function functionPatterns(int $version): void
    {
        $s = $this->size;
        for ($i = 0; $i < $s; $i++) { $this->set(6, $i, $i % 2 === 0); $this->set($i, 6, $i % 2 === 0); }
        foreach ([[3, 3], [$s - 4, 3], [3, $s - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) for ($dx = -4; $dx <= 4; $dx++) {
                $d = max(abs($dx), abs($dy));
                $x = $cx + $dx; $y = $cy + $dy;
                if ($x >= 0 && $x < $s && $y >= 0 && $y < $s) $this->set($x, $y, $d !== 2 && $d !== 4);
            }
        }
        $pos = self::ALIGN[$version];
        $n = count($pos);
        for ($i = 0; $i < $n; $i++) for ($j = 0; $j < $n; $j++) {
            if (($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0)) continue;
            for ($dy = -2; $dy <= 2; $dy++) for ($dx = -2; $dx <= 2; $dx++) {
                $this->set($pos[$i] + $dx, $pos[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }
        $this->formatBits(0); // reserve
        if ($version >= 7) {
            $rem = $version;
            for ($i = 0; $i < 12; $i++) $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            $bits = ($version << 12) | $rem;
            for ($i = 0; $i < 18; $i++) {
                $bit = (($bits >> $i) & 1) === 1;
                $a = $s - 11 + $i % 3;
                $b = intdiv($i, 3);
                $this->set($a, $b, $bit);
                $this->set($b, $a, $bit);
            }
        }
    }

    private function formatBits(int $mask): void
    {
        $data = (0 << 3) | $mask; // level M = 00
        $rem = $data;
        for ($i = 0; $i < 10; $i++) $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $g = fn(int $i) => (($bits >> $i) & 1) === 1;
        $s = $this->size;
        for ($i = 0; $i <= 5; $i++) $this->set(8, $i, $g($i));
        $this->set(8, 7, $g(6));
        $this->set(8, 8, $g(7));
        $this->set(7, 8, $g(8));
        for ($i = 9; $i < 15; $i++) $this->set(14 - $i, 8, $g($i));
        for ($i = 0; $i < 8; $i++) $this->set($s - 1 - $i, 8, $g($i));
        for ($i = 8; $i < 15; $i++) $this->set(8, $s - 15 + $i, $g($i));
        $this->set(8, $s - 8, true);
    }

    private function placeData(array $data): void
    {
        $s = $this->size;
        $total = count($data) * 8;
        $i = 0;
        for ($right = $s - 1; $right >= 1; $right -= 2) {
            if ($right === 6) $right = 5;
            for ($vert = 0; $vert < $s; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $s - 1 - $vert : $vert;
                    if (!$this->fn[$y][$x] && $i < $total) {
                        $this->m[$y][$x] = (($data[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        $s = $this->size;
        for ($y = 0; $y < $s; $y++) for ($x = 0; $x < $s; $x++) {
            if ($this->fn[$y][$x]) continue;
            $inv = match ($mask) {
                0 => ($x + $y) % 2 === 0,
                1 => $y % 2 === 0,
                2 => $x % 3 === 0,
                3 => ($x + $y) % 3 === 0,
                4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
                6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
                7 => (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0,
            };
            if ($inv) $this->m[$y][$x] = !$this->m[$y][$x];
        }
    }

    private function penalty(): int
    {
        $s = $this->size;
        $m = $this->m;
        $score = 0;
        // Rule 1: runs of 5+ same color in rows and columns.
        for ($a = 0; $a < $s; $a++) {
            foreach ([true, false] as $isRow) {
                $run = 1;
                for ($b = 1; $b < $s; $b++) {
                    $cur = $isRow ? $m[$a][$b] : $m[$b][$a];
                    $prev = $isRow ? $m[$a][$b - 1] : $m[$b - 1][$a];
                    if ($cur === $prev) { $run++; if ($run === 5) $score += 3; elseif ($run > 5) $score++; }
                    else $run = 1;
                }
            }
        }
        // Rule 2: 2x2 blocks.
        for ($y = 0; $y < $s - 1; $y++) for ($x = 0; $x < $s - 1; $x++) {
            $c = $m[$y][$x];
            if ($c === $m[$y][$x + 1] && $c === $m[$y + 1][$x] && $c === $m[$y + 1][$x + 1]) $score += 3;
        }
        // Rule 3: finder-like patterns 1011101 with 4 light modules on either side.
        $pat1 = [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0];
        $pat2 = [0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1];
        for ($a = 0; $a < $s; $a++) for ($b = 0; $b <= $s - 11; $b++) {
            foreach ([true, false] as $isRow) {
                $ok1 = $ok2 = true;
                for ($k = 0; $k < 11; $k++) {
                    $v = ($isRow ? $m[$a][$b + $k] : $m[$b + $k][$a]) ? 1 : 0;
                    if ($v !== $pat1[$k]) $ok1 = false;
                    if ($v !== $pat2[$k]) $ok2 = false;
                }
                if ($ok1) $score += 40;
                if ($ok2) $score += 40;
            }
        }
        // Rule 4: dark/light balance.
        $dark = 0;
        foreach ($m as $r) foreach ($r as $c) if ($c) $dark++;
        $k = (int)floor(abs($dark * 20 - $s * $s * 10) / ($s * $s));
        return $score + $k * 10;
    }

    private static function mul(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z & 0xFF;
    }

    private static function rsDivisor(int $degree): array
    {
        $r = array_fill(0, $degree, 0);
        $r[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $r[$j] = self::mul($r[$j], $root);
                if ($j + 1 < $degree) $r[$j] ^= $r[$j + 1];
            }
            $root = self::mul($root, 0x02);
        }
        return $r;
    }

    private static function rsRemainder(array $data, array $div): array
    {
        $r = array_fill(0, count($div), 0);
        foreach ($data as $b) {
            $f = $b ^ array_shift($r);
            $r[] = 0;
            foreach ($div as $i => $c) $r[$i] ^= self::mul($c, $f);
        }
        return $r;
    }

    private function toSvg(int $px, string $dark, string $light): string
    {
        $border = 4;
        $dim = $this->size + $border * 2;
        $path = '';
        foreach ($this->m as $y => $row) foreach ($row as $x => $on) {
            if ($on) $path .= 'M' . ($x + $border) . ',' . ($y + $border) . 'h1v1h-1z';
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $dim . ' ' . $dim . '" width="' . $px . '" height="' . $px . '" shape-rendering="crispEdges" role="img" aria-label="QR code">'
            . '<rect width="100%" height="100%" fill="' . $light . '"/><path d="' . $path . '" fill="' . $dark . '"/></svg>';
    }
}
