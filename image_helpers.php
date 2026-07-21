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
