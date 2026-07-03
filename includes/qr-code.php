<?php

function qr_gf_multiply(int $x, int $y): int
{
    $result = 0;
    while ($y > 0) {
        if (($y & 1) !== 0) {
            $result ^= $x;
        }
        $x <<= 1;
        if (($x & 0x100) !== 0) {
            $x ^= 0x11D;
        }
        $y >>= 1;
    }

    return $result & 0xFF;
}

function qr_reed_solomon_divisor(int $degree): array
{
    $result = array_fill(0, $degree, 0);
    $result[$degree - 1] = 1;
    $root = 1;

    for ($i = 0; $i < $degree; $i++) {
        for ($j = 0; $j < $degree; $j++) {
            $result[$j] = qr_gf_multiply($result[$j], $root);
            if ($j + 1 < $degree) {
                $result[$j] ^= $result[$j + 1];
            }
        }
        $root = qr_gf_multiply($root, 0x02);
    }

    return $result;
}

function qr_reed_solomon_remainder(array $data, int $degree): array
{
    $divisor = qr_reed_solomon_divisor($degree);
    $result = array_fill(0, $degree, 0);

    foreach ($data as $byte) {
        $factor = $byte ^ $result[0];
        array_shift($result);
        $result[] = 0;

        for ($i = 0; $i < $degree; $i++) {
            $result[$i] ^= qr_gf_multiply($divisor[$i], $factor);
        }
    }

    return $result;
}

function qr_append_bits(array &$bits, int $value, int $length): void
{
    for ($i = $length - 1; $i >= 0; $i--) {
        $bits[] = (($value >> $i) & 1) !== 0;
    }
}

function qr_fixed_version_5_codewords(string $text): array
{
    $bytes = array_values(unpack('C*', $text) ?: []);
    $dataCodewords = 108;
    $eccCodewords = 26;

    if (count($bytes) > 106) {
        throw new RuntimeException('QR text is too long for the built-in generator.');
    }

    $bits = [];
    qr_append_bits($bits, 0b0100, 4);
    qr_append_bits($bits, count($bytes), 8);
    foreach ($bytes as $byte) {
        qr_append_bits($bits, $byte, 8);
    }

    $capacityBits = $dataCodewords * 8;
    $terminator = min(4, $capacityBits - count($bits));
    qr_append_bits($bits, 0, $terminator);
    while (count($bits) % 8 !== 0) {
        $bits[] = false;
    }

    $data = [];
    for ($i = 0; $i < count($bits); $i += 8) {
        $byte = 0;
        for ($j = 0; $j < 8; $j++) {
            $byte = ($byte << 1) | ($bits[$i + $j] ? 1 : 0);
        }
        $data[] = $byte;
    }

    for ($pad = 0; count($data) < $dataCodewords; $pad++) {
        $data[] = ($pad % 2 === 0) ? 0xEC : 0x11;
    }

    return array_merge($data, qr_reed_solomon_remainder($data, $eccCodewords));
}

function qr_blank_matrix(int $size): array
{
    return [
        'modules' => array_fill(0, $size, array_fill(0, $size, false)),
        'function' => array_fill(0, $size, array_fill(0, $size, false)),
    ];
}

function qr_set_function_module(array &$qr, int $x, int $y, bool $dark): void
{
    $qr['modules'][$y][$x] = $dark;
    $qr['function'][$y][$x] = true;
}

function qr_add_finder_pattern(array &$qr, int $x, int $y): void
{
    $size = count($qr['modules']);
    for ($dy = -1; $dy <= 7; $dy++) {
        for ($dx = -1; $dx <= 7; $dx++) {
            $xx = $x + $dx;
            $yy = $y + $dy;
            if ($xx < 0 || $xx >= $size || $yy < 0 || $yy >= $size) {
                continue;
            }

            $dark = $dx >= 0 && $dx <= 6 && $dy >= 0 && $dy <= 6
                && ($dx === 0 || $dx === 6 || $dy === 0 || $dy === 6 || ($dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4));
            qr_set_function_module($qr, $xx, $yy, $dark);
        }
    }
}

function qr_add_alignment_pattern(array &$qr, int $centerX, int $centerY): void
{
    for ($dy = -2; $dy <= 2; $dy++) {
        for ($dx = -2; $dx <= 2; $dx++) {
            $dark = max(abs($dx), abs($dy)) !== 1;
            qr_set_function_module($qr, $centerX + $dx, $centerY + $dy, $dark);
        }
    }
}

function qr_add_function_patterns(array &$qr): void
{
    $size = count($qr['modules']);

    qr_add_finder_pattern($qr, 0, 0);
    qr_add_finder_pattern($qr, $size - 7, 0);
    qr_add_finder_pattern($qr, 0, $size - 7);

    for ($i = 8; $i < $size - 8; $i++) {
        qr_set_function_module($qr, $i, 6, $i % 2 === 0);
        qr_set_function_module($qr, 6, $i, $i % 2 === 0);
    }

    qr_add_alignment_pattern($qr, 30, 30);

    for ($i = 0; $i <= 8; $i++) {
        if ($i !== 6) {
            qr_set_function_module($qr, 8, $i, false);
            qr_set_function_module($qr, $i, 8, false);
        }
    }
    for ($i = $size - 8; $i < $size; $i++) {
        qr_set_function_module($qr, $i, 8, false);
        qr_set_function_module($qr, 8, $i, false);
    }

    qr_set_function_module($qr, 8, $size - 8, true);
}

function qr_mask_bit(int $mask, int $x, int $y): bool
{
    return match ($mask) {
        0 => (($x + $y) % 2) === 0,
        1 => ($y % 2) === 0,
        2 => ($x % 3) === 0,
        3 => (($x + $y) % 3) === 0,
        default => false,
    };
}

function qr_add_data(array &$qr, array $codewords, int $mask): void
{
    $size = count($qr['modules']);
    $bits = [];
    foreach ($codewords as $byte) {
        for ($i = 7; $i >= 0; $i--) {
            $bits[] = (($byte >> $i) & 1) !== 0;
        }
    }

    $bitIndex = 0;
    $upward = true;
    for ($right = $size - 1; $right >= 1; $right -= 2) {
        if ($right === 6) {
            $right = 5;
        }

        for ($vert = 0; $vert < $size; $vert++) {
            $y = $upward ? $size - 1 - $vert : $vert;
            for ($j = 0; $j < 2; $j++) {
                $x = $right - $j;
                if ($qr['function'][$y][$x]) {
                    continue;
                }

                $dark = $bits[$bitIndex] ?? false;
                if (qr_mask_bit($mask, $x, $y)) {
                    $dark = !$dark;
                }
                $qr['modules'][$y][$x] = $dark;
                $bitIndex++;
            }
        }

        $upward = !$upward;
    }
}

function qr_format_bits(int $mask): int
{
    $data = (1 << 3) | $mask;
    $remainder = $data;
    for ($i = 0; $i < 10; $i++) {
        $remainder = ($remainder << 1) ^ ((($remainder >> 9) & 1) !== 0 ? 0x537 : 0);
    }

    return (($data << 10) | $remainder) ^ 0x5412;
}

function qr_add_format_bits(array &$qr, int $mask): void
{
    $size = count($qr['modules']);
    $bits = qr_format_bits($mask);
    $get = static fn (int $i): bool => (($bits >> $i) & 1) !== 0;

    for ($i = 0; $i <= 5; $i++) {
        qr_set_function_module($qr, 8, $i, $get($i));
    }
    qr_set_function_module($qr, 8, 7, $get(6));
    qr_set_function_module($qr, 8, 8, $get(7));
    qr_set_function_module($qr, 7, 8, $get(8));
    for ($i = 9; $i < 15; $i++) {
        qr_set_function_module($qr, 14 - $i, 8, $get($i));
    }

    for ($i = 0; $i < 8; $i++) {
        qr_set_function_module($qr, $size - 1 - $i, 8, $get($i));
    }
    for ($i = 8; $i < 15; $i++) {
        qr_set_function_module($qr, 8, $size - 15 + $i, $get($i));
    }
    qr_set_function_module($qr, 8, $size - 8, true);
}

function qr_create_matrix(string $text): array
{
    $mask = 0;
    $size = 37;
    $qr = qr_blank_matrix($size);
    qr_add_function_patterns($qr);
    qr_add_data($qr, qr_fixed_version_5_codewords($text), $mask);
    qr_add_format_bits($qr, $mask);

    return $qr['modules'];
}

function qr_write_png(string $text, string $filePath, int $scale = 10, int $border = 4, ?string $colorHex = null, ?string $logoPath = null): void
{
    if (!extension_loaded('gd')) {
        throw new RuntimeException('The GD extension is required to generate QR PNG files.');
    }

    $matrix = qr_create_matrix($text);
    $moduleCount = count($matrix);
    $imageSize = ($moduleCount + ($border * 2)) * $scale;
    $image = imagecreatetruecolor($imageSize, $imageSize);
    if (!$image) {
        throw new RuntimeException('Unable to create QR image.');
    }

    // Transparent canvas for id-card overlays.
    imagealphablending($image, false);
    imagesavealpha($image, true);
    $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
    imagefilledrectangle($image, 0, 0, $imageSize, $imageSize, $transparent);
    imagealphablending($image, true);

    $red = 0;
    $green = 0;
    $blue = 0;
    if ($colorHex && preg_match('/^#?([a-f0-9]{3}|[a-f0-9]{6})$/i', trim($colorHex), $matches)) {
        $hex = $matches[1];
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $val = hexdec($hex);
        $red = ($val >> 16) & 0xFF;
        $green = ($val >> 8) & 0xFF;
        $blue = $val & 0xFF;
    }
    $qrColor = imagecolorallocate($image, $red, $green, $blue);

    foreach ($matrix as $y => $row) {
        foreach ($row as $x => $dark) {
            if (!$dark) {
                continue;
            }
            $left = ($x + $border) * $scale;
            $top = ($y + $border) * $scale;
            imagefilledrectangle($image, $left, $top, $left + $scale - 1, $top + $scale - 1, $qrColor);
        }
    }

    if ($logoPath && file_exists($logoPath)) {
        $logo = imagecreatefrompng($logoPath);
        if ($logo) {
            imagealphablending($logo, true);
            imagesavealpha($logo, true);

            $logoWidth = imagesx($logo);
            $logoHeight = imagesy($logo);

            $matrixPixelSize = $moduleCount * $scale;
            $targetLogoWidth = max(1, (int)round($matrixPixelSize * 0.18));
            $targetLogoHeight = max(1, (int)round($logoHeight * ($targetLogoWidth / $logoWidth)));
            $logoX = (int)round(($imageSize - $targetLogoWidth) / 2);
            $logoY = (int)round(($imageSize - $targetLogoHeight) / 2);
            $padding = max(2, (int)round($scale * 0.8));

            imagealphablending($image, false);
            imagefilledrectangle(
                $image,
                max(0, $logoX - $padding),
                max(0, $logoY - $padding),
                min($imageSize - 1, $logoX + $targetLogoWidth + $padding - 1),
                min($imageSize - 1, $logoY + $targetLogoHeight + $padding - 1),
                $transparent
            );
            imagealphablending($image, true);

            imagecopyresampled(
                $image, $logo,
                $logoX, $logoY,
                0, 0,
                $targetLogoWidth, $targetLogoHeight,
                $logoWidth, $logoHeight
            );
            imagedestroy($logo);
        }
    }

    $dir = dirname($filePath);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        imagedestroy($image);
        throw new RuntimeException('Unable to create QR directory.');
    }

    if (!imagepng($image, $filePath)) {
        imagedestroy($image);
        throw new RuntimeException('Unable to write QR PNG file.');
    }

    imagedestroy($image);
}
