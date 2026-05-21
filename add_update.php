<?php
require_once __DIR__ . '/auth.php';

require_login();

$pdo = db();
$user = current_user();
$error = '';

const POST_UPLOAD_DIR = '/home/brscouts/exbelt2026.irvalscouts.org.uk/assets/posts/';
const POST_UPLOAD_PUBLIC_PATH = 'assets/posts/';

$teams = $pdo->query('SELECT * FROM teams ORDER BY name ASC')->fetchAll();

/**
 * Helpers
 */

function clean_post_html(string $html): string
{
    $html = trim($html);

    if ($html === '') {
        return '';
    }

    $allowedTags = '<p><br><strong><b><em><i><u><a><ol><ul><li><span><blockquote>';

    $html = strip_tags($html, $allowedTags);

    $html = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
    $html = preg_replace('/href\s*=\s*([\'"])\s*javascript:[^\'"]*\1/i', 'href="#"', $html);

    $html = preg_replace_callback('/style\s*=\s*([\'"])(.*?)\1/i', function ($matches) {
        $style = $matches[2];
        $safeRules = [];

        foreach (explode(';', $style) as $rule) {
            $rule = trim($rule);

            if ($rule === '') {
                continue;
            }

            if (preg_match('/^(color|background-color)\s*:\s*(#[0-9a-f]{3,6}|rgb\([0-9,\s]+\)|[a-z]+)$/i', $rule)) {
                $safeRules[] = $rule;
            }
        }

        if (empty($safeRules)) {
            return '';
        }

        return ' style="' . e(implode('; ', $safeRules)) . '"';
    }, $html);

    return $html;
}

function plain_text_to_html(string $text): string
{
    $text = trim($text);

    if ($text === '') {
        return '';
    }

    return '<p>' . nl2br(e($text)) . '</p>';
}

function html_to_email_text(string $html): string
{
    $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
    $html = preg_replace('/<\/p>/i', "\n\n", $html);
    $html = preg_replace('/<\/li>/i', "\n", $html);

    $text = html_entity_decode(strip_tags((string)$html), ENT_QUOTES, 'UTF-8');
    $text = preg_replace("/\n{3,}/", "\n\n", $text);

    return trim((string)$text);
}

function decode_json_items(?string $json): array
{
    if (!$json) {
        return [];
    }

    $decoded = json_decode($json, true);

    if (!is_array($decoded)) {
        return [];
    }

    return array_values(array_filter($decoded, static function ($item) {
        return is_string($item) && trim($item) !== '';
    }));
}

function get_team_parent_emails(PDO $pdo, int $teamId): array
{
    $stmt = $pdo->prepare(
        'SELECT parent_emails_json
         FROM young_people
         WHERE team_id = ?
           AND is_active = 1'
    );

    $stmt->execute([$teamId]);

    $emails = [];

    foreach ($stmt->fetchAll() as $row) {
        foreach (decode_json_items($row['parent_emails_json'] ?? null) as $email) {
            $email = trim((string)$email);

            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[strtolower($email)] = $email;
            }
        }
    }

    return array_values($emails);
}

function get_all_parent_emails(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT parent_emails_json
         FROM young_people
         WHERE is_active = 1'
    );

    $emails = [];

    foreach ($stmt->fetchAll() as $row) {
        foreach (decode_json_items($row['parent_emails_json'] ?? null) as $email) {
            $email = trim((string)$email);

            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[strtolower($email)] = $email;
            }
        }
    }

    return array_values($emails);
}

function queue_email(
    PDO $pdo,
    string $toEmail,
    string $subject,
    string $content,
    ?int $teamId = null,
    ?int $postId = null
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO email_queue
            (to_email, subject, content, related_team_id, related_post_id)
         VALUES
            (?, ?, ?, ?, ?)'
    );

    $stmt->execute([
        $toEmail,
        $subject,
        $content,
        $teamId,
        $postId,
    ]);
}

function queue_update_emails(
    PDO $pdo,
    ?int $teamId,
    string $visibility,
    string $subject,
    string $content,
    int $postId
): int {
    if ($visibility === 'team' && $teamId) {
        $emails = get_team_parent_emails($pdo, $teamId);
    } else {
        $emails = get_all_parent_emails($pdo);
    }

    $count = 0;

    foreach ($emails as $email) {
        queue_email(
            $pdo,
            $email,
            $subject,
            $content,
            $visibility === 'team' ? $teamId : null,
            $postId
        );

        $count++;
    }

    return $count;
}

function upload_post_photos(PDO $pdo, int $postId): array
{
    if (empty($_FILES['photos']) || !is_array($_FILES['photos'])) {
        return [];
    }

    if (!is_dir(POST_UPLOAD_DIR)) {
        if (!mkdir(POST_UPLOAD_DIR, 0755, true) && !is_dir(POST_UPLOAD_DIR)) {
            throw new RuntimeException('Could not create post upload directory.');
        }
    }

    $files = $_FILES['photos'];
    $uploadedPaths = [];

    $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    $count = is_array($files['name']) ? count($files['name']) : 0;
    $sortOrder = 0;

    for ($i = 0; $i < $count; $i++) {
        $uploadError = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;

        if ($uploadError === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        if ($uploadError !== UPLOAD_ERR_OK) {
            throw new RuntimeException('One of the photos failed to upload.');
        }

        $tmpName = $files['tmp_name'][$i] ?? '';
        $size = (int)($files['size'][$i] ?? 0);
        $originalName = (string)($files['name'][$i] ?? '');

        if ($size > 8 * 1024 * 1024) {
            throw new RuntimeException('Each photo must be smaller than 8MB.');
        }

        if (!is_uploaded_file($tmpName)) {
            throw new RuntimeException('One of the uploaded photos was invalid.');
        }

        $imageInfo = getimagesize($tmpName);

        if ($imageInfo === false) {
            throw new RuntimeException('Please upload image files only.');
        }

        $mimeType = $imageInfo['mime'] ?? '';

        if (!isset($allowedMimeTypes[$mimeType])) {
            throw new RuntimeException('Photos must be JPG, PNG, WEBP or GIF.');
        }

        $extension = $allowedMimeTypes[$mimeType];
        $filename = 'post-' . $postId . '-' . bin2hex(random_bytes(10)) . '.' . $extension;
        $destination = rtrim(POST_UPLOAD_DIR, '/') . '/' . $filename;

        if (!move_uploaded_file($tmpName, $destination)) {
            throw new RuntimeException('Could not save one of the uploaded photos.');
        }

        $publicPath = POST_UPLOAD_PUBLIC_PATH . $filename;

        $stmt = $pdo->prepare(
            'INSERT INTO post_photos
                (post_id, photo_url, original_filename, sort_order)
             VALUES
                (?, ?, ?, ?)'
        );

        $stmt->execute([
            $postId,
            $publicPath,
            $originalName,
            $sortOrder,
        ]);

        $uploadedPaths[] = $publicPath;
        $sortOrder++;
    }

    return $uploadedPaths;
}

function build_update_email_content(
    string $title,
    string $teamLabel,
    string $bodyText,
    string $postUrl
): string {
    return
        $title . "\n\n" .
        "Team: " . $teamLabel . "\n\n" .
        $bodyText . "\n\n" .
        "View the update here:\n" .
        $postUrl . "\n\n" .
        "This is an automated notification from the Explorer Belt Live portal.";
}

/**
 * Handle form submit
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $teamId = ($_POST['team_id'] ?? '') !== '' ? (int)$_POST['team_id'] : null;
    $visibility = $_POST['visibility'] ?? 'public';
    $postType = $_POST['post_type'] ?? 'general';
    $title = trim($_POST['title'] ?? '');
    $bodyHtmlRaw = trim($_POST['body_html'] ?? '');
    $bodyPlainFallback = trim($_POST['body_plain'] ?? '');
    $photoUrl = trim($_POST['photo_url'] ?? '');
    $isPinned = isset($_POST['is_pinned']) ? 1 : 0;
    $sendEmail = isset($_POST['send_email']) ? 1 : 0;

    if (!in_array($visibility, ['public', 'team'], true)) {
        $visibility = 'public';
    }

    if (!in_array($postType, ['general', 'team_update', 'photo', 'important'], true)) {
        $postType = 'general';
    }

    if ($visibility === 'team' && !$teamId) {
        $error = 'Choose a team for a team-only post.';
    }

    $bodyHtml = clean_post_html($bodyHtmlRaw);

    if ($bodyHtml === '' && $bodyPlainFallback !== '') {
        $bodyHtml = plain_text_to_html($bodyPlainFallback);
    }

    if ($title === '' || $bodyHtml === '') {
        $error = 'Post title and update content are required.';
    }

    if ($error === '') {
        $pdo->beginTransaction();

        try {
            if ($isPinned === 1) {
                $pdo->exec('UPDATE posts SET is_pinned = 0');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO posts
                    (team_id, leader_id, title, body, post_type, visibility, photo_url, is_pinned, is_published, published_at)
                 VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())'
            );

            $stmt->execute([
                $teamId,
                $user['id'],
                $title,
                $bodyHtml,
                $postType,
                $visibility,
                $photoUrl,
                $isPinned,
            ]);

            $postId = (int)$pdo->lastInsertId();

            $uploadedPhotos = upload_post_photos($pdo, $postId);

            if ($photoUrl === '' && !empty($uploadedPhotos)) {
                $stmt = $pdo->prepare('UPDATE posts SET photo_url = ? WHERE id = ?');
                $stmt->execute([
                    $uploadedPhotos[0],
                    $postId,
                ]);
            }

            if ($sendEmail === 1) {
                $teamLabel = 'All teams';
                $parentUrl = url('dashboard.php#post-' . $postId);

                if ($visibility === 'team' && $teamId) {
                    $stmt = $pdo->prepare('SELECT name, parent_token FROM teams WHERE id = ? LIMIT 1');
                    $stmt->execute([$teamId]);
                    $team = $stmt->fetch();

                    $teamLabel = $team['name'] ?? 'Team';
                    $parentToken = $team['parent_token'] ?? '';

                    $parentUrl = $parentToken !== ''
                        ? url('dashboard.php?token=' . $parentToken . '#post-' . $postId)
                        : url('dashboard.php#post-' . $postId);
                }

                $emailSubject = $visibility === 'team'
                    ? $teamLabel . ' update: ' . $title
                    : 'Explorer Belt update: ' . $title;

                $emailContent = build_update_email_content(
                    $title,
                    $teamLabel,
                    html_to_email_text($bodyHtml),
                    $parentUrl
                );

                queue_update_emails(
                    $pdo,
                    $teamId,
                    $visibility,
                    $emailSubject,
                    $emailContent,
                    $postId
                );
            }

            $pdo->commit();

            redirect('dashboard.php#post-' . $postId);
        } catch (Throwable $exception) {
            $pdo->rollBack();
            $error = 'Could not publish the update. ' . $exception->getMessage();
        }
    }
}

include __DIR__ . '/header.php';
?>

<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">

<style>
    .page-hero,
    .page-hero h1,
    .page-hero h2,
    .page-hero h3,
    .page-hero p,
    .page-hero .lead {
        color: #ffffff !important;
    }

    .add-update-layout {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 340px;
        gap: 1.5rem;
        align-items: start;
    }

    @media (max-width: 900px) {
        .add-update-layout {
            grid-template-columns: 1fr;
        }
    }

    .admin-panel,
    .help-panel {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 1.5rem;
    }

    .admin-panel h2,
    .help-panel h2 {
        margin-top: 0;
        font-weight: 900;
    }

    .admin-panel label {
        font-weight: 800;
    }

    .editor-wrap {
        border: 1px solid #ced4da;
        background: #ffffff;
    }

    #editor {
        min-height: 220px;
        background: #ffffff;
    }

    .ql-toolbar.ql-snow {
        border: 0;
        border-bottom: 1px solid #ced4da;
    }

    .ql-container.ql-snow {
        border: 0;
        font-size: 1rem;
    }

    .notification-box {
        border-left: 8px solid #1d70b8;
        background: #eef7ff;
        padding: 1rem;
        margin-bottom: 1rem;
    }

    .photo-help {
        border-left: 8px solid #ffdd00;
        background: #fff7bf;
        padding: 1rem;
        margin-top: 0.75rem;
    }

    .fallback-editor {
        display: none;
    }

    .muted {
        color: #505a5f;
    }
</style>

<section class="page-hero">
    <div class="container">
        <h1>Add update</h1>
        <p class="lead">
            Publish a parent-facing update to all teams or one team.
        </p>
    </div>
</section>

<main id="main-content" class="container my-5">

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <p>
        <a href="<?= e(url('dashboard.php')) ?>">
            Back to dashboard
        </a>
    </p>

    <div class="add-update-layout">

        <section class="admin-panel">
            <h2>Update details</h2>

            <form method="post" enctype="multipart/form-data" id="updateForm">

                <div class="form-group">
                    <label for="title">Title</label>
                    <input class="form-control" id="title" name="title" required>
                </div>

                <div class="form-group">
                    <label for="editor">Update</label>

                    <div class="editor-wrap" id="editorWrap">
                        <div id="editor"></div>
                    </div>

                    <textarea id="body_html" name="body_html" hidden></textarea>

                    <textarea
                        id="body_plain"
                        name="body_plain"
                        class="form-control fallback-editor"
                        rows="6"
                        placeholder="Write the update here..."
                    ></textarea>

                    <small class="form-text text-muted">
                        You can use basic formatting such as bold, italic, links, lists and simple colours.
                    </small>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="visibility">Who can see this?</label>
                        <select class="form-control" id="visibility" name="visibility">
                            <option value="public">All team parent links</option>
                            <option value="team">One team only</option>
                        </select>
                    </div>

                    <div class="form-group col-md-6">
                        <label for="team_id">Team</label>
                        <select class="form-control" id="team_id" name="team_id">
                            <option value="">No specific team</option>

                            <?php foreach ($teams as $team): ?>
                                <option value="<?= (int)$team['id'] ?>">
                                    <?= e($team['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="post_type">Post type</label>
                        <select class="form-control" id="post_type" name="post_type">
                            <option value="general">General update</option>
                            <option value="team_update">Team update</option>
                            <option value="photo">Photo</option>
                            <option value="important">Important</option>
                        </select>
                    </div>

                    <div class="form-group col-md-6">
                        <label for="photo_url">Optional external photo URL</label>
                        <input class="form-control" id="photo_url" name="photo_url" type="url">
                    </div>
                </div>

                <div class="form-group">
                    <label for="photos">Upload photos</label>
                    <input
                        class="form-control"
                        id="photos"
                        name="photos[]"
                        type="file"
                        accept="image/jpeg,image/png,image/webp,image/gif"
                        multiple
                    >

                    <div class="photo-help">
                        <strong>Photo upload:</strong>
                        You can upload multiple photos. Each photo must be JPG, PNG, WEBP or GIF and under 8MB.
                    </div>
                </div>

                <div class="notification-box">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="send_email" name="send_email" checked>
                        <label class="form-check-label" for="send_email">
                            Send email notification
                        </label>
                    </div>

                    <p class="muted mb-0">
                        If unchecked, the update will only appear on the portal and no emails will be queued.
                    </p>
                </div>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="is_pinned" name="is_pinned">
                    <label class="form-check-label" for="is_pinned">
                        Pin this update
                    </label>
                </div>

                <button class="btn btn-primary" type="submit">
                    Publish update
                </button>
            </form>
        </section>

        <aside class="help-panel">
            <h2>Publishing notes</h2>

            <p>
                A pinned update appears at the top of the feed.
            </p>

            <p>
                Only one update can be pinned at a time. If you pin this update, any previously pinned update will be unpinned.
            </p>

            <p>
                Email notifications are queued and sent later by the cron job.
            </p>

            <p class="muted mb-0">
                For all-team updates, email notifications are queued once per unique parent email address.
            </p>
        </aside>

    </div>

</main>

<script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>

<script>
    (function () {
        var form = document.getElementById('updateForm');
        var hiddenBody = document.getElementById('body_html');
        var plainBody = document.getElementById('body_plain');
        var visibility = document.getElementById('visibility');
        var team = document.getElementById('team_id');
        var editorWrap = document.getElementById('editorWrap');
        var quill = null;

        if (typeof Quill !== 'undefined') {
            var toolbarOptions = [
                ['bold', 'italic', 'underline'],
                [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                ['link'],
                [{ 'color': [] }, { 'background': [] }],
                ['clean']
            ];

            quill = new Quill('#editor', {
                theme: 'snow',
                modules: {
                    toolbar: toolbarOptions
                },
                placeholder: 'Write the update here...'
            });
        } else {
            editorWrap.style.display = 'none';
            plainBody.style.display = 'block';
        }

        form.addEventListener('submit', function (event) {
            var plainText = '';

            if (quill) {
                hiddenBody.value = quill.root.innerHTML.trim();
                plainText = quill.getText().trim();
                plainBody.value = '';
            } else {
                hiddenBody.value = '';
                plainText = plainBody.value.trim();
            }

            if (plainText === '') {
                event.preventDefault();
                alert('Please enter the update content.');
                return;
            }

            if (visibility.value === 'team' && team.value === '') {
                event.preventDefault();
                alert('Please choose a team for a team-only update.');
                return;
            }
        });
    })();
</script>

<?php include __DIR__ . '/footer.php'; ?>