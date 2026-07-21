<?php
/**
 * One-time migration script to optimize existing people photos.
 *
 * Resizes all existing JPG/PNG/GIF photos in assets/people/ to 400px max
 * dimension and converts them to WebP. Updates the photo_url in the
 * young_people database table to point to the new filenames.
 *
 * Run from CLI:   php migrate_optimize_photos.php
 * Run from web:   requires admin login (auth.php)
 *
 * Safe to run multiple times — skips files already in .webp format.
 */

if (php_sapi_name() === 'cli') {
    require_once __DIR__ . '/config.php';
} else {
    require_once __DIR__ . '/auth.php';
    require_login();

    $user = current_user();
    if (empty($user) || ($user['role'] ?? '') !== 'trip_admin') {
        http_response_code(403);
        die('Admin access required.');
    }
}

require_once __DIR__ . '/image_helpers.php';

$pdo = db();

// Determine the upload directory — use local path if the production path doesn't exist
$uploadDir = '/home/brscouts/exbelt2026.irvalscouts.org.uk/assets/people/';
if (!is_dir($uploadDir)) {
    $uploadDir = __DIR__ . '/assets/people/';
}

$publicPath = 'assets/people/';

$mimeMap = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
];

echo "=== People Photo Optimization Migration ===\n\n";

// Find all people with photo_url set
$stmt = $pdo->query('SELECT id, name, photo_url FROM young_people WHERE photo_url IS NOT NULL AND photo_url != \'\'');
$people = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($people) . " people with photos.\n\n";

$optimized = 0;
$skipped   = 0;
$errors    = 0;

foreach ($people as $person) {
    $photoUrl = $person['photo_url'];
    $filename = basename($photoUrl);
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    // Already WebP — skip
    if ($extension === 'webp') {
        echo "  SKIP (already webp): {$person['name']} — {$filename}\n";
        $skipped++;
        continue;
    }

    // Check the file exists on disk
    $filePath = rtrim($uploadDir, '/') . '/' . $filename;

    if (!file_exists($filePath)) {
        echo "  SKIP (file missing): {$person['name']} — {$filePath}\n";
        $skipped++;
        continue;
    }

    $mimeType = $mimeMap[$extension] ?? null;

    if ($mimeType === null) {
        echo "  SKIP (unsupported): {$person['name']} — {$filename}\n";
        $skipped++;
        continue;
    }

    $sizeBefore = filesize($filePath);

    // Optimize the image
    $newFilename = optimize_person_image($filePath, $mimeType);

    $newFilePath = rtrim($uploadDir, '/') . '/' . $newFilename;
    $sizeAfter = file_exists($newFilePath) ? filesize($newFilePath) : 0;

    if ($newFilename !== $filename) {
        // Update the database record
        $newPublicPath = $publicPath . $newFilename;
        $update = $pdo->prepare('UPDATE young_people SET photo_url = ? WHERE id = ?');
        $update->execute([$newPublicPath, $person['id']]);

        $reduction = $sizeBefore > 0 ? round((1 - $sizeAfter / $sizeBefore) * 100) : 0;
        echo "  OK: {$person['name']} — {$filename} -> {$newFilename} ({$reduction}% smaller)\n";
        $optimized++;
    } else {
        echo "  WARN: {$person['name']} — optimization returned same file\n";
        $errors++;
    }
}

echo "\n=== Leaders Photo Optimization Migration ===\n\n";

// Determine the leaders upload directory
$leaderUploadDir = '/home/brscouts/exbelt2026.irvalscouts.org.uk/assets/leaders/';
if (!is_dir($leaderUploadDir)) {
    $leaderUploadDir = __DIR__ . '/assets/leaders/';
}

$leaderPublicPath = 'assets/leaders/';

// Check if leaders table has photo_url column
$leaderHasPhoto = false;
try {
    $checkCol = $pdo->query("SHOW COLUMNS FROM leaders LIKE 'photo_url'");
    $leaderHasPhoto = $checkCol && $checkCol->rowCount() > 0;
} catch (Throwable $e) {
    // Column check failed — skip leaders
}

if (!$leaderHasPhoto) {
    echo "Leaders table does not have a photo_url column — skipping.\n";
} elseif (!is_dir($leaderUploadDir)) {
    echo "Leaders upload directory does not exist — skipping.\n";
} else {
    $stmt = $pdo->query('SELECT id, name, photo_url FROM leaders WHERE photo_url IS NOT NULL AND photo_url != \'\'');
    $leaders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Found " . count($leaders) . " leaders with photos.\n\n";

    $leaderOptimized = 0;
    $leaderSkipped   = 0;
    $leaderErrors    = 0;

    foreach ($leaders as $leader) {
        $photoUrl = $leader['photo_url'];
        $filename = basename($photoUrl);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // Already WebP — skip
        if ($extension === 'webp') {
            echo "  SKIP (already webp): {$leader['name']} — {$filename}\n";
            $leaderSkipped++;
            continue;
        }

        // Check the file exists on disk
        $filePath = rtrim($leaderUploadDir, '/') . '/' . $filename;

        if (!file_exists($filePath)) {
            echo "  SKIP (file missing): {$leader['name']} — {$filePath}\n";
            $leaderSkipped++;
            continue;
        }

        $mimeType = $mimeMap[$extension] ?? null;

        if ($mimeType === null) {
            echo "  SKIP (unsupported): {$leader['name']} — {$filename}\n";
            $leaderSkipped++;
            continue;
        }

        $sizeBefore = filesize($filePath);

        // Optimize the image
        $newFilename = optimize_person_image($filePath, $mimeType);

        $newFilePath = rtrim($leaderUploadDir, '/') . '/' . $newFilename;
        $sizeAfter = file_exists($newFilePath) ? filesize($newFilePath) : 0;

        if ($newFilename !== $filename) {
            // Update the database record
            $newPublicPath = $leaderPublicPath . $newFilename;
            $update = $pdo->prepare('UPDATE leaders SET photo_url = ? WHERE id = ?');
            $update->execute([$newPublicPath, $leader['id']]);

            $reduction = $sizeBefore > 0 ? round((1 - $sizeAfter / $sizeBefore) * 100) : 0;
            echo "  OK: {$leader['name']} — {$filename} -> {$newFilename} ({$reduction}% smaller)\n";
            $leaderOptimized++;
        } else {
            echo "  WARN: {$leader['name']} — optimization returned same file\n";
            $leaderErrors++;
        }
    }

    $optimized += $leaderOptimized;
    $skipped   += $leaderSkipped;
    $errors    += $leaderErrors;

    echo "\nLeaders — Optimized: {$leaderOptimized} | Skipped: {$leaderSkipped} | Errors: {$leaderErrors}\n";
}

echo "\n=== Done ===\n";
echo "Total Optimized: {$optimized}\n";
echo "Total Skipped:   {$skipped}\n";
echo "Total Errors:    {$errors}\n";
