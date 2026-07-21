<?php
/**
 * Image optimization helpers for people photos.
 *
 * Resizes uploaded images to a sensible maximum dimension and converts
 * them to WebP format for significantly smaller file sizes. A 2MB JPEG
 * typically becomes a 20-50KB WebP at these settings.
 */

const IMAGE_MAX_DIMENSION = 400;  // px — profile photos never need more
const IMAGE_WEBP_QUALITY  = 80;   // 0-100, good balance of quality vs size

/**
 * Optimize an image file in place: resize to max dimension and convert to WebP.
 *
 * @param string $sourcePath Absolute path to the original image on disk.
 * @param string $mimeType  The MIME type of the source image.
 * @return string The new filename (with .webp extension) after optimization,
 *               or the original filename if optimization was not possible.
 */
function optimize_person_image(string $sourcePath, string $mimeType): string
{
    // Load the source image based on its MIME type
    $sourceImage = null;

    switch ($mimeType) {
        case 'image/jpeg':
            $sourceImage = @imagecreatefromjpeg($sourcePath);
            break;
        case 'image/png':
            $sourceImage = @imagecreatefrompng($sourcePath);
            break;
        case 'image/webp':
            $sourceImage = @imagecreatefromwebp($sourcePath);
            break;
        case 'image/gif':
            $sourceImage = @imagecreatefromgif($sourcePath);
            break;
    }

    if ($sourceImage === null || $sourceImage === false) {
        // If GD can't handle it, keep the original untouched
        return basename($sourcePath);
    }

    // Correct EXIF orientation for JPEGs (phones store rotation in metadata)
    if ($mimeType === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($sourcePath);
        if ($exif && !empty($exif['Orientation'])) {
            $sourceImage = apply_exif_orientation($sourceImage, (int) $exif['Orientation']);
        }
    }

    $origWidth  = imagesx($sourceImage);
    $origHeight = imagesy($sourceImage);

    // Calculate new dimensions maintaining aspect ratio
    $maxDim = IMAGE_MAX_DIMENSION;

    if ($origWidth > $maxDim || $origHeight > $maxDim) {
        if ($origWidth >= $origHeight) {
            $newWidth  = $maxDim;
            $newHeight = (int) round($origHeight * ($maxDim / $origWidth));
        } else {
            $newHeight = $maxDim;
            $newWidth  = (int) round($origWidth * ($maxDim / $origHeight));
        }
    } else {
        // Already small enough, just convert format
        $newWidth  = $origWidth;
        $newHeight = $origHeight;
    }

    // Create the resized image
    $resized = imagecreatetruecolor($newWidth, $newHeight);

    // Preserve transparency for PNG/WebP/GIF sources
    if (in_array($mimeType, ['image/png', 'image/webp', 'image/gif'], true)) {
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefill($resized, 0, 0, $transparent);
    }

    imagecopyresampled($resized, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);
    unset($sourceImage);

    // Write as WebP to the same directory with a .webp extension
    $directory    = dirname($sourcePath);
    $baseName     = pathinfo($sourcePath, PATHINFO_FILENAME);
    $webpFilename = $baseName . '.webp';
    $webpPath     = $directory . '/' . $webpFilename;

    $success = imagewebp($resized, $webpPath, IMAGE_WEBP_QUALITY);
    unset($resized);

    if ($success) {
        // Remove the original file now we have the optimized version
        if (realpath($sourcePath) !== realpath($webpPath)) {
            @unlink($sourcePath);
        }
        return $webpFilename;
    }

    // Fallback: WebP write failed, keep original
    return basename($sourcePath);
}

/**
 * Apply EXIF orientation correction to a GD image resource.
 *
 * Mobile phones often store photos in landscape with an EXIF orientation
 * tag indicating how to display them. GD ignores this, so we apply it manually.
 */
function apply_exif_orientation(\GdImage $image, int $orientation): \GdImage
{
    switch ($orientation) {
        case 2: // Horizontal flip
            imageflip($image, IMG_FLIP_HORIZONTAL);
            break;
        case 3: // 180 degrees
            $image = imagerotate($image, 180, 0);
            break;
        case 4: // Vertical flip
            imageflip($image, IMG_FLIP_VERTICAL);
            break;
        case 5: // 90 CW + horizontal flip
            $image = imagerotate($image, -90, 0);
            imageflip($image, IMG_FLIP_HORIZONTAL);
            break;
        case 6: // 90 CW
            $image = imagerotate($image, -90, 0);
            break;
        case 7: // 90 CCW + horizontal flip
            $image = imagerotate($image, 90, 0);
            imageflip($image, IMG_FLIP_HORIZONTAL);
            break;
        case 8: // 90 CCW
            $image = imagerotate($image, 90, 0);
            break;
    }

    return $image;
}

/**
 * Rotate an existing WebP person photo by 90 degrees clockwise and save in place.
 *
 * @param string $absolutePath Absolute path to the .webp file on disk.
 * @return bool True on success, false on failure.
 */
function rotate_person_image_cw(string $absolutePath): bool
{
    if (!file_exists($absolutePath)) {
        return false;
    }

    $image = @imagecreatefromwebp($absolutePath);

    if ($image === false) {
        return false;
    }

    // GD's imagerotate uses counter-clockwise degrees, so -90 = 90 CW
    $rotated = imagerotate($image, -90, 0);
    unset($image);

    if ($rotated === false) {
        return false;
    }

    $success = imagewebp($rotated, $absolutePath, IMAGE_WEBP_QUALITY);
    unset($rotated);

    return $success;
}

/**
 * Rotate an existing WebP person photo by 90 degrees counter-clockwise and save in place.
 *
 * @param string $absolutePath Absolute path to the .webp file on disk.
 * @return bool True on success, false on failure.
 */
function rotate_person_image_ccw(string $absolutePath): bool
{
    if (!file_exists($absolutePath)) {
        return false;
    }

    $image = @imagecreatefromwebp($absolutePath);

    if ($image === false) {
        return false;
    }

    // GD's imagerotate uses counter-clockwise degrees, so 90 = 90 CCW
    $rotated = imagerotate($image, 90, 0);
    unset($image);

    if ($rotated === false) {
        return false;
    }

    $success = imagewebp($rotated, $absolutePath, IMAGE_WEBP_QUALITY);
    unset($rotated);

    return $success;
}
