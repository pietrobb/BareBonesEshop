<?php

// Round 8 review (2026-10-21), regression from PR #33-#37: a phone photo stored with an EXIF orientation (the camera
// held upright, the pixels sideways) shows upright on the product page, which serves the original, but lay on its side
// in category cards and thumbnails: the resized copies (LocalStorage::resize, GD) dropped the EXIF block without
// turning the pixels, so they showed the raw sideways image.

echo "\n--- Audit round 8 (rest): resized images keep the EXIF orientation ---\n";

if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
    echo "  [SKIP] R8 orientation: GD with JPEG support is not available\n";
    return;
}

$r8oDir = sys_get_temp_dir() . '/bbe-r8o-' . bin2hex(random_bytes(4));
$r8oStorage = new LocalStorage(['uploads_dir' => $r8oDir, 'uploads_url' => '/uploads']);
// 800x400 landscape pixels: left half red, right half blue.
$r8oImage = imagecreatetruecolor(800, 400);
imagefilledrectangle($r8oImage, 0, 0, 399, 399, imagecolorallocate($r8oImage, 255, 0, 0));
imagefilledrectangle($r8oImage, 400, 0, 799, 399, imagecolorallocate($r8oImage, 0, 0, 255));
ob_start();
imagejpeg($r8oImage, null, 95);
$r8oPlain = (string)ob_get_clean();
$r8oWithOrientation = static function (string $jpeg, int $orientation): string {
    $exif = "Exif\0\0" . "II\x2A\0\x08\0\0\0" . "\x01\0" . "\x12\x01\x03\0\x01\0\0\0" . pack('v', $orientation) . "\0\0" . "\0\0\0\0";
    return "\xFF\xD8" . "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif . substr($jpeg, 2);
};
$r8oColour = static function ($image, int $x, int $y): string {
    $rgb = imagecolorat($image, $x, $y);
    [$r, $b] = [($rgb >> 16) & 0xFF, $rgb & 0xFF];
    return $r > 200 && $b < 60 ? 'red' : ($b > 200 && $r < 60 ? 'blue' : sprintf('#%06x', $rgb));
};
// orientation => [width, height, colour at the top-left quarter, colour at the bottom-right quarter] of the upright image
$r8oCases = [
    1 => [600, 300, 'red', 'blue'],
    3 => [600, 300, 'blue', 'red'],  // turned 180°
    6 => [300, 600, 'red', 'blue'],  // shown turned 90° clockwise: the left (red) half is on top
    8 => [300, 600, 'blue', 'red'],  // shown turned 90° counter-clockwise: the right (blue) half is on top
    2 => [600, 300, 'blue', 'red'],  // mirrored left-right
    5 => [300, 600, 'red', 'blue'],  // transposed
];
try {
    assert_equals(6, ImageMetadata::jpegOrientation($r8oWithOrientation($r8oPlain, 6)), 'R8 orientation: the EXIF orientation of a JPEG is read');
    assert_equals(1, ImageMetadata::jpegOrientation($r8oPlain), 'R8 orientation: a JPEG without EXIF is upright');
    foreach ($r8oCases as $r8oOrientation => [$r8oW, $r8oH, $r8oTop, $r8oBottom]) {
        $r8oPath = "r8o/photo-{$r8oOrientation}.jpg";
        $r8oStorage->store($r8oPath, $r8oWithOrientation($r8oPlain, $r8oOrientation));
        $r8oStorage->resize($r8oPath, 600, 600);
        $r8oResized = @imagecreatefromjpeg($r8oDir . "/r8o/photo-{$r8oOrientation}-medium.jpg");
        assert_true($r8oResized !== false, "R8 orientation {$r8oOrientation}: the medium copy was written");
        if ($r8oResized === false) continue;
        $r8oGot = [imagesx($r8oResized), imagesy($r8oResized),
            $r8oColour($r8oResized, intdiv(imagesx($r8oResized), 4), intdiv(imagesy($r8oResized), 4)),
            $r8oColour($r8oResized, intdiv(imagesx($r8oResized) * 3, 4), intdiv(imagesy($r8oResized) * 3, 4))];
        assert_equals([$r8oW, $r8oH, $r8oTop, $r8oBottom], $r8oGot, "R8 orientation {$r8oOrientation}: the resized copy is turned upright as the original displays");
    }
} finally {
    foreach (glob($r8oDir . '/r8o/*') ?: [] as $r8oFile) @unlink($r8oFile);
    @rmdir($r8oDir . '/r8o');
    @rmdir($r8oDir);
}
