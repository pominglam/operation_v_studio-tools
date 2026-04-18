<?php

declare(strict_types=1);

/**
 * Minimal PNG builder (no GD dependency).
 * Builds a valid RGB PNG with solid color pixels and optional large tEXt payload.
 *
 * @return string PNG bytes
 */
function buildPngBytes(int $width, int $height, string $textPayload): string
{
    $sig = "\x89PNG\r\n\x1a\n";

    $ihdr = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0); // 8-bit RGB

    // Solid pixels (light gray) so the image is valid but compressible.
    $row = "\x00".str_repeat("\xCC\xCC\xCC", $width); // filter=0 then RGB pixels
    $raw = str_repeat($row, $height);
    $idat = gzcompress($raw, 9);

    $chunks = [];
    $chunks[] = pngChunk('IHDR', $ihdr);
    $chunks[] = pngChunk('IDAT', $idat);

    if ($textPayload !== '') {
        // keyword\0text
        $data = "Comment\x00".$textPayload;
        $chunks[] = pngChunk('tEXt', $data);
    }

    $chunks[] = pngChunk('IEND', '');

    return $sig.implode('', $chunks);
}

/**
 * @return string PNG chunk bytes
 */
function pngChunk(string $type, string $data): string
{
    $len = strlen($data);
    $crc = crc32($type.$data);
    $crc = $crc < 0 ? $crc + 0x100000000 : $crc;

    return pack('N', $len).$type.$data.pack('N', $crc);
}
