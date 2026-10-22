<?php
/**
 * Removes metadata (EXIF incl. GPS position, XMP, IPTC, comments, text chunks) from an image before it is stored
 * publicly. Lossless: the pixel data is copied untouched. A JPEG keeps its EXIF orientation as a minimal EXIF block,
 * so a rotated phone photo still displays upright. Unknown or malformed data is returned unchanged.
 */
final class ImageMetadata {
    public static function strip(string $bytes): string {
        return match (true) {
            str_starts_with($bytes, "\xFF\xD8") => self::jpeg($bytes),
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => self::png($bytes),
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => self::webp($bytes),
            default => $bytes,
        };
    }

    private static function jpeg(string $bytes): string {
        $length = strlen($bytes);
        $kept = [];
        $orientation = 1;
        $pos = 2;
        while ($pos + 4 <= $length) {
            if ($bytes[$pos] !== "\xFF") return $bytes;
            $marker = ord($bytes[$pos + 1]);
            if ($marker === 0xFF) { $pos++; continue; }
            if ($marker === 0xDA) {
                $kept[] = substr($bytes, $pos); // start of scan: the compressed image and everything after it
                break;
            }
            if (($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) { $kept[] = substr($bytes, $pos, 2); $pos += 2; continue; }
            $segmentLength = unpack('n', substr($bytes, $pos + 2, 2))[1];
            if ($segmentLength < 2 || $pos + 2 + $segmentLength > $length) return $bytes;
            $segment = substr($bytes, $pos, 2 + $segmentLength);
            $payload = substr($segment, 4);
            if ($marker === 0xE1 && str_starts_with($payload, "Exif\0\0")) $orientation = self::exifOrientation(substr($payload, 6)) ?? $orientation;
            // JFIF (APP0), Adobe colour transform (APP14) and the ICC colour profile (APP2) are display data; every
            // other application segment and comments are metadata.
            $isMetadata = ($marker >= 0xE1 && $marker <= 0xEF && $marker !== 0xEE && !($marker === 0xE2 && str_starts_with($payload, "ICC_PROFILE\0")))
                || $marker === 0xFE;
            if (!$isMetadata) $kept[] = $segment;
            $pos += 2 + $segmentLength;
        }
        if ($kept === [] || !str_starts_with((string)end($kept), "\xFF\xDA")) return $bytes;
        if ($orientation !== 1) {
            $exif = "Exif\0\0" . "MM\0\x2A\0\0\0\x08" . "\0\x01" . "\x01\x12\0\x03\0\0\0\x01" . pack('n', $orientation) . "\0\0" . "\0\0\0\0";
            $app1 = "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif;
            // JFIF requires APP0 to stay the first segment.
            $at = str_starts_with($kept[0], "\xFF\xE0") ? 1 : 0;
            array_splice($kept, $at, 0, [$app1]);
        }
        return "\xFF\xD8" . implode('', $kept);
    }

    /** EXIF orientation (1-8) of a JPEG; 1 (upright) when it has none or is not a readable JPEG. */
    public static function jpegOrientation(string $bytes): int {
        $length = strlen($bytes);
        $pos = str_starts_with($bytes, "\xFF\xD8") ? 2 : $length;
        while ($pos + 4 <= $length && $bytes[$pos] === "\xFF") {
            $marker = ord($bytes[$pos + 1]);
            if ($marker === 0xFF) { $pos++; continue; }
            if ($marker === 0xDA) break;
            if (($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) { $pos += 2; continue; }
            $segmentLength = unpack('n', substr($bytes, $pos + 2, 2))[1];
            $payload = substr($bytes, $pos + 4, max(0, $segmentLength - 2));
            if ($marker === 0xE1 && str_starts_with($payload, "Exif\0\0")) return self::exifOrientation(substr($payload, 6)) ?? 1;
            $pos += 2 + $segmentLength;
        }
        return 1;
    }

    /** Orientation (1-8) from the TIFF block of an EXIF segment, null when absent or unreadable. */
    private static function exifOrientation(string $tiff): ?int {
        $order = substr($tiff, 0, 2);
        if ($order !== 'II' && $order !== 'MM') return null;
        $u16 = static fn(int $at): ?int => strlen($tiff) >= $at + 2 ? unpack($order === 'II' ? 'v' : 'n', substr($tiff, $at, 2))[1] : null;
        $u32 = static fn(int $at): ?int => strlen($tiff) >= $at + 4 ? unpack($order === 'II' ? 'V' : 'N', substr($tiff, $at, 4))[1] : null;
        $ifd = $u32(4);
        $count = $ifd === null ? null : $u16($ifd);
        for ($i = 0; $count !== null && $i < min($count, 512); $i++) {
            $entry = $ifd + 2 + $i * 12;
            if ($u16($entry) === 0x0112) {
                $value = $u16($entry + 8);
                return $value !== null && $value >= 1 && $value <= 8 ? $value : null;
            }
        }
        return null;
    }

    private static function png(string $bytes): string {
        $out = substr($bytes, 0, 8);
        $pos = 8;
        $length = strlen($bytes);
        while ($pos + 12 <= $length) {
            $dataLength = unpack('N', substr($bytes, $pos, 4))[1];
            $type = substr($bytes, $pos + 4, 4);
            if ($pos + 12 + $dataLength > $length) return $bytes;
            if (!in_array($type, ['eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME'], true)) $out .= substr($bytes, $pos, 12 + $dataLength);
            $pos += 12 + $dataLength;
            if ($type === 'IEND') return $out;
        }
        return $bytes;
    }

    private static function webp(string $bytes): string {
        $out = '';
        $pos = 12;
        $length = strlen($bytes);
        while ($pos + 8 <= $length) {
            $type = substr($bytes, $pos, 4);
            $size = unpack('V', substr($bytes, $pos + 4, 4))[1];
            $chunk = substr($bytes, $pos, 8 + $size + ($size % 2));
            if (strlen($chunk) < 8 + $size) return $bytes;
            if ($type === 'VP8X' && $size >= 1) $chunk[8] = chr(ord($chunk[8]) & ~0x0C); // no EXIF (0x08) / XMP (0x04) flags
            if ($type !== 'EXIF' && $type !== 'XMP ') $out .= $chunk;
            $pos += strlen($chunk);
        }
        return 'RIFF' . pack('V', strlen($out) + 4) . 'WEBP' . $out;
    }
}
