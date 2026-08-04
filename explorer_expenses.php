<?php
require_once __DIR__ . '/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$pdo = db();
$token = trim($_GET['token'] ?? $_SESSION['explorer_portal_token'] ?? '');

$team = explorer_fetch_team($pdo, $token);

if (!$team) {
    include __DIR__ . '/explorer_error.php';
}

$_SESSION['explorer_portal_token'] = $token;

// --- Ensure tables exist ---
try {
    $tblCheck = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = "team_transactions"'
    );
    $tblCheck->execute();
    if ((int)$tblCheck->fetchColumn() === 0) {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS team_transactions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                team_id INT UNSIGNED NOT NULL,
                type ENUM("debit","credit") NOT NULL DEFAULT "debit",
                amount DECIMAL(10,2) NOT NULL,
                currency CHAR(3) NOT NULL DEFAULT "EUR",
                category VARCHAR(50) NULL,
                description VARCHAR(500) NULL,
                receipt_path VARCHAR(500) NULL,
                no_receipt_reason VARCHAR(500) NULL,
                submitted_by VARCHAR(150) NOT NULL,
                transaction_date DATE NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_by_leader_id INT UNSIGNED NULL,
                INDEX idx_tt_team (team_id),
                INDEX idx_tt_type (type),
                INDEX idx_tt_date (transaction_date),
                INDEX idx_tt_team_date (team_id, transaction_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
    } else {
        // Ensure no_receipt_reason column exists
        $colCheck = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = "team_transactions" AND column_name = "no_receipt_reason"'
        );
        $colCheck->execute();
        if ((int)$colCheck->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE team_transactions ADD COLUMN no_receipt_reason VARCHAR(500) NULL AFTER receipt_path');
        }
    }
} catch (Throwable $e) {
    // Continue gracefully
}

// --- CSRF ---
if (empty($_SESSION['explorer_expenses_csrf'])) {
    $_SESSION['explorer_expenses_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['explorer_expenses_csrf'];

function expenses_csrf_valid(): bool
{
    return isset($_POST['csrf_token'], $_SESSION['explorer_expenses_csrf'])
        && hash_equals((string)$_SESSION['explorer_expenses_csrf'], (string)$_POST['csrf_token']);
}

// --- Fetch team members for "who is submitting" ---
$teamMembers = [];
try {
    $stmt = $pdo->prepare(
        'SELECT id, name, photo_url
         FROM young_people
         WHERE team_id = ? AND is_active = 1
         ORDER BY name ASC'
    );
    $stmt->execute([(int)$team['id']]);
    $teamMembers = $stmt->fetchAll();
} catch (Throwable $e) {
    $teamMembers = [];
}

$error = '';
$success = '';

// --- Handle EDIT form submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $team && isset($_POST['edit_expense_id'])) {
    try {
        if (!expenses_csrf_valid()) {
            throw new RuntimeException('Security check failed. Please refresh and try again.');
        }

        $editId = (int)$_POST['edit_expense_id'];

        // Verify this transaction belongs to this team
        $checkStmt = $pdo->prepare('SELECT * FROM team_transactions WHERE id = ? AND team_id = ? AND type = "debit"');
        $checkStmt->execute([$editId, (int)$team['id']]);
        $existingTx = $checkStmt->fetch();
        if (!$existingTx) {
            throw new RuntimeException('Expense not found or you do not have permission to edit it.');
        }

        $amount = trim($_POST['amount'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $submittedBy = trim($_POST['submitted_by'] ?? '');
        $transactionDate = trim($_POST['transaction_date'] ?? '');
        $noReceiptReason = trim($_POST['no_receipt_reason'] ?? '');
        $noReceiptChecked = !empty($_POST['no_receipt']);

        if ($amount === '' || !is_numeric($amount) || (float)$amount <= 0) {
            throw new RuntimeException('Please enter a valid amount greater than zero.');
        }
        if ($category === '') {
            throw new RuntimeException('Please select a purchase category.');
        }
        if ($submittedBy === '') {
            throw new RuntimeException('Please select who is submitting this expense.');
        }
        if ($transactionDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $transactionDate)) {
            throw new RuntimeException('Please enter a valid date.');
        }

        // Receipt validation: must upload a receipt OR provide justification
        $hasExistingReceipt = !empty($existingTx['receipt_path']);
        $hasNewUpload = !empty($_FILES['receipt']) && $_FILES['receipt']['error'] !== UPLOAD_ERR_NO_FILE;

        if (!$hasExistingReceipt && !$hasNewUpload && !$noReceiptChecked) {
            throw new RuntimeException('Please upload a receipt or explain why you don\'t have one.');
        }
        if ($noReceiptChecked && $noReceiptReason === '') {
            throw new RuntimeException('Please explain why you don\'t have a receipt.');
        }

        // Handle new receipt upload
        $receiptTmpName = null;
        $receiptExt = null;
        if ($hasNewUpload) {
            $file = $_FILES['receipt'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Receipt upload failed. Please try again.');
            }
            if ((int)$file['size'] > 10 * 1024 * 1024) {
                throw new RuntimeException('Receipt file must be smaller than 10MB.');
            }
            $receiptTmpName = $file['tmp_name'];
            if (!is_uploaded_file($receiptTmpName)) {
                throw new RuntimeException('Invalid receipt upload.');
            }
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $receiptTmpName);
            finfo_close($finfo);
            $allowedTypes = [
                'image/jpeg' => 'jpg', 'image/png' => 'png',
                'image/webp' => 'webp', 'image/gif' => 'gif',
                'application/pdf' => 'pdf',
            ];
            if (!isset($allowedTypes[$mimeType])) {
                throw new RuntimeException('Receipt must be a JPG, PNG, WEBP, GIF or PDF file.');
            }
            $receiptExt = $allowedTypes[$mimeType];
        }

        // Update transaction
        $receiptPath = $existingTx['receipt_path']; // keep existing by default
        $stmt = $pdo->prepare(
            'UPDATE team_transactions
             SET amount = ?, category = ?, description = ?, submitted_by = ?, transaction_date = ?, no_receipt_reason = ?
             WHERE id = ? AND team_id = ?'
        );
        $stmt->execute([
            round((float)$amount, 2),
            $category,
            substr(strip_tags($description), 0, 500),
            substr(strip_tags($submittedBy), 0, 150),
            $transactionDate,
            $noReceiptChecked ? substr(strip_tags($noReceiptReason), 0, 500) : null,
            $editId,
            (int)$team['id'],
        ]);

        // Move new receipt if uploaded
        if ($receiptTmpName && $receiptExt) {
            $teamSlug = preg_replace('/[^a-zA-Z0-9]/', '', $team['name'] ?? 'team');
            $filename = $editId . '-' . $teamSlug . '_' . $transactionDate . '.' . $receiptExt;
            $uploadDir = '/home/brscouts/exbelt2026.irvalscouts.org.uk/assets/receipts/';
            if (!is_dir($uploadDir)) { $uploadDir = __DIR__ . '/assets/receipts/'; }
            if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
            $destination = rtrim($uploadDir, '/') . '/' . $filename;
            if (move_uploaded_file($receiptTmpName, $destination)) {
                $receiptPath = 'assets/receipts/' . $filename;
                $upd = $pdo->prepare('UPDATE team_transactions SET receipt_path = ?, no_receipt_reason = NULL WHERE id = ?');
                $upd->execute([$receiptPath, $editId]);
            }
        }

        $_SESSION['explorer_expense_success'] = [
            'amount' => round((float)$amount, 2),
            'category' => $category,
            'submitted_by' => $submittedBy,
        ];
        redirect('explorer_expenses.php?token=' . urlencode($token) . '&submitted=1');
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

// --- Handle NEW expense form submission ---
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $team && !isset($_POST['edit_expense_id'])) {
    try {
        if (!expenses_csrf_valid()) {
            throw new RuntimeException('Security check failed. Please refresh and try again.');
        }

        $amount = trim($_POST['amount'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $submittedBy = trim($_POST['submitted_by'] ?? '');
        $transactionDate = trim($_POST['transaction_date'] ?? '');
        $noReceiptReason = trim($_POST['no_receipt_reason'] ?? '');
        $noReceiptChecked = !empty($_POST['no_receipt']);

        if ($amount === '' || !is_numeric($amount) || (float)$amount <= 0) {
            throw new RuntimeException('Please enter a valid amount greater than zero.');
        }
        if ($category === '') {
            throw new RuntimeException('Please select a purchase category.');
        }
        if ($submittedBy === '') {
            throw new RuntimeException('Please select who is submitting this expense.');
        }
        if ($transactionDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $transactionDate)) {
            throw new RuntimeException('Please enter a valid date.');
        }

        // Receipt validation: must upload a receipt OR provide justification
        $hasUpload = !empty($_FILES['receipt']) && $_FILES['receipt']['error'] !== UPLOAD_ERR_NO_FILE;
        if (!$hasUpload && !$noReceiptChecked) {
            throw new RuntimeException('Please upload a receipt or check "I don\'t have a receipt" and provide a reason.');
        }
        if ($noReceiptChecked && $noReceiptReason === '') {
            throw new RuntimeException('Please explain why you don\'t have a receipt.');
        }

        // Handle receipt upload - validate first, move after insert to get transaction ID
        $receiptTmpName = null;
        $receiptExt = null;
        if ($hasUpload) {
            $file = $_FILES['receipt'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Receipt upload failed. Please try again.');
            }
            if ((int)$file['size'] > 10 * 1024 * 1024) {
                throw new RuntimeException('Receipt file must be smaller than 10MB.');
            }
            $receiptTmpName = $file['tmp_name'];
            if (!is_uploaded_file($receiptTmpName)) {
                throw new RuntimeException('Invalid receipt upload.');
            }
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $receiptTmpName);
            finfo_close($finfo);
            $allowedTypes = [
                'image/jpeg' => 'jpg', 'image/png' => 'png',
                'image/webp' => 'webp', 'image/gif' => 'gif',
                'application/pdf' => 'pdf',
            ];
            if (!isset($allowedTypes[$mimeType])) {
                throw new RuntimeException('Receipt must be a JPG, PNG, WEBP, GIF or PDF file.');
            }
            $receiptExt = $allowedTypes[$mimeType];
        }

        // Insert transaction
        $stmt = $pdo->prepare(
            'INSERT INTO team_transactions
                (team_id, type, amount, currency, category, description, receipt_path, no_receipt_reason, submitted_by, transaction_date)
             VALUES (?, "debit", ?, "EUR", ?, ?, NULL, ?, ?, ?)'
        );
        $stmt->execute([
            (int)$team['id'],
            round((float)$amount, 2),
            $category,
            substr(strip_tags($description), 0, 500),
            $noReceiptChecked ? substr(strip_tags($noReceiptReason), 0, 500) : null,
            substr(strip_tags($submittedBy), 0, 150),
            $transactionDate,
        ]);
        $transactionId = (int)$pdo->lastInsertId();

        // Now move receipt with proper filename: {ID}-{TeamName}_{Date}.ext
        $receiptPath = null;
        if ($receiptTmpName && $receiptExt) {
            $teamSlug = preg_replace('/[^a-zA-Z0-9]/', '', $team['name'] ?? 'team');
            $filename = $transactionId . '-' . $teamSlug . '_' . $transactionDate . '.' . $receiptExt;
            $uploadDir = '/home/brscouts/exbelt2026.irvalscouts.org.uk/assets/receipts/';
            if (!is_dir($uploadDir)) { $uploadDir = __DIR__ . '/assets/receipts/'; }
            if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
            $destination = rtrim($uploadDir, '/') . '/' . $filename;
            if (move_uploaded_file($receiptTmpName, $destination)) {
                $receiptPath = 'assets/receipts/' . $filename;
                $upd = $pdo->prepare('UPDATE team_transactions SET receipt_path = ? WHERE id = ?');
                $upd->execute([$receiptPath, $transactionId]);
            }
        }

        $_SESSION['explorer_expense_success'] = [
            'amount' => round((float)$amount, 2),
            'category' => $category,
            'submitted_by' => $submittedBy,
        ];
        redirect('explorer_expenses.php?token=' . urlencode($token) . '&submitted=1');
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$submittedSuccess = ($_GET['submitted'] ?? '') === '1' && !empty($_SESSION['explorer_expense_success']);
$successData = $_SESSION['explorer_expense_success'] ?? null;
if ($submittedSuccess) {
    unset($_SESSION['explorer_expense_success']);
}

// --- Fetch transaction history for this team ---
$transactions = [];
$totalCredits = 0;
$totalDebits = 0;
try {
    $stmt = $pdo->prepare(
        'SELECT * FROM team_transactions WHERE team_id = ? ORDER BY transaction_date DESC, created_at DESC'
    );
    $stmt->execute([(int)$team['id']]);
    $transactions = $stmt->fetchAll();
    foreach ($transactions as $t) {
        if ($t['type'] === 'credit') { $totalCredits += (float)$t['amount']; }
        else { $totalDebits += (float)$t['amount']; }
    }
} catch (Throwable $e) { $transactions = []; }

// Fetch initial balance from team card
$cardInitialBalance = 0;
try {
    $cardStmt = $pdo->prepare('SELECT initial_balance FROM team_cards WHERE team_id = ? LIMIT 1');
    $cardStmt->execute([(int)$team['id']]);
    $cardRow = $cardStmt->fetch();
    if ($cardRow) { $cardInitialBalance = (float)$cardRow['initial_balance']; }
} catch (Throwable $e) {}

$estimatedBalance = $cardInitialBalance + $totalCredits - $totalDebits;

function expense_initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    $letters = '';
    foreach ($parts as $part) {
        if ($part !== '') { $letters .= strtoupper(substr($part, 0, 1)); }
        if (strlen($letters) >= 2) break;
    }
    return $letters !== '' ? $letters : '?';
}

function expense_media_url(?string $path): string {
    $path = trim((string)$path);
    if ($path === '') return '';
    if (preg_match('/^https?:\/\//i', $path)) return $path;
    return url($path);
}

$categoryLabels = ['food' => 'Food & Drink', 'camping' => 'Camping', 'supplies' => 'Supplies', 'travel' => 'Travel', 'other' => 'Other'];
$categoryIcons = ['food' => '🍕', 'camping' => '⛺', 'supplies' => '🎒', 'travel' => '🚌', 'other' => '📦'];

include __DIR__ . '/explorer_header.php';
?>

<style>
body { background: #f3f2f1; color: #1d1d1d; }

/* Hero balance */
.balance-hero {
    background: linear-gradient(135deg, #7413dc 0%, #5a0fb0 100%);
    color: #fff;
    padding: 1.75rem 1.5rem;
    margin: -1rem -15px 1.5rem;
    text-align: center;
}
.balance-hero h1 { font-size: 1.1rem; font-weight: 700; margin: 0 0 0.25rem; opacity: 0.9; }
.balance-hero .hero-amount { font-size: 2.75rem; font-weight: 900; line-height: 1.1; }
.balance-hero .hero-sub {
    display: flex; justify-content: center; gap: 1.5rem;
    margin-top: 0.5rem; font-size: 0.9rem; opacity: 0.85;
}
.balance-hero .hero-sub span { display: inline-flex; align-items: center; gap: 0.3rem; }

/* Success toast */
.success-toast {
    background: #00703c; color: #fff; padding: 1rem 1.25rem;
    margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.75rem;
    animation: slideDown 0.3s ease;
}
.success-toast .st-icon { font-size: 1.5rem; }
.success-toast .st-text { font-weight: 700; }
.success-toast .st-detail { font-size: 0.85rem; opacity: 0.9; }

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Form panel */
.form-panel {
    background: #fff; border: 2px solid #d8d8d8; padding: 1.5rem; margin-bottom: 1.5rem;
}
.form-panel-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 1rem; cursor: pointer;
}
.form-panel-header h2 { font-weight: 900; font-size: 1.2rem; margin: 0; }
.form-panel-header .toggle-icon {
    width: 32px; height: 32px; border-radius: 50%; background: #f3f2f1;
    display: flex; align-items: center; justify-content: center;
    font-weight: 900; font-size: 1.2rem; transition: transform 0.2s;
}
.form-panel.collapsed .form-panel-body { display: none; }
.form-panel.collapsed .toggle-icon { transform: rotate(180deg); }
.form-panel label { font-weight: 700; font-size: 0.95rem; }

/* Step indicators */
.form-step { margin-bottom: 1.25rem; }
.form-step-label {
    font-size: 0.75rem; font-weight: 800; text-transform: uppercase;
    letter-spacing: 0.05em; color: #7413dc; margin-bottom: 0.4rem;
}

/* Member selector */
.member-selector { display: flex; flex-wrap: wrap; gap: 0.75rem; }
.member-select-btn {
    display: flex; flex-direction: column; align-items: center; gap: 0.35rem;
    padding: 0.6rem; border: 3px solid #e8e8e8; border-radius: 14px;
    background: #fff; cursor: pointer; transition: all 0.15s; width: 88px; text-align: center;
}
.member-select-btn:hover { border-color: #7413dc; background: #f9f5ff; }
.member-select-btn:active { transform: scale(0.95); }
.member-select-btn.selected { border-color: #00703c; background: #e9f8ef; box-shadow: 0 0 0 2px #00703c; }
.member-select-photo {
    width: 48px; height: 48px; border-radius: 50%; border: 2px solid #d8d8d8; object-fit: cover;
}
.member-select-placeholder {
    width: 48px; height: 48px; border-radius: 50%; border: 2px solid #d8d8d8;
    background: #7413dc; color: #fff; display: inline-flex; align-items: center;
    justify-content: center; font-weight: 900; font-size: 1rem;
}
.member-select-btn.selected .member-select-photo,
.member-select-btn.selected .member-select-placeholder { border-color: #00703c; }
.member-select-name { font-size: 0.78rem; font-weight: 700; line-height: 1.15; word-break: break-word; }

/* Category pills */
.category-pills { display: flex; flex-wrap: wrap; gap: 0.5rem; }
.cat-pill {
    padding: 0.5rem 1rem; border: 2px solid #e8e8e8; border-radius: 24px;
    font-size: 0.9rem; font-weight: 700; cursor: pointer; transition: all 0.15s;
    background: #fff; display: inline-flex; align-items: center; gap: 0.4rem;
}
.cat-pill:hover { border-color: #7413dc; background: #f9f5ff; }
.cat-pill.selected { border-color: #7413dc; background: #7413dc; color: #fff; }

/* Amount input */
.amount-input-wrap {
    position: relative; max-width: 200px;
}
.amount-input-wrap .currency-symbol {
    position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
    font-weight: 900; font-size: 1.1rem; color: #505a5f;
}
.amount-input-wrap input {
    padding-left: 2rem; font-size: 1.25rem; font-weight: 800;
}

/* Receipt upload */
.receipt-drop {
    border: 2px dashed #d8d8d8; padding: 1.25rem; text-align: center;
    cursor: pointer; transition: border-color 0.2s, background 0.2s; border-radius: 8px;
}
.receipt-drop:hover { border-color: #7413dc; background: #f9f5ff; }
.receipt-drop.has-file { border-color: #00703c; background: #e9f8ef; }
.receipt-drop .rd-icon { font-size: 2rem; margin-bottom: 0.25rem; }
.receipt-drop .rd-text { font-weight: 700; font-size: 0.9rem; }
.receipt-drop .rd-hint { font-size: 0.8rem; color: #505a5f; }

/* Transaction timeline */
.tx-timeline { list-style: none; padding: 0; margin: 0; }
.tx-item {
    display: flex; gap: 0.75rem; padding: 0.85rem 0;
    border-bottom: 1px solid #f0f0f0; align-items: flex-start;
}
.tx-item:last-child { border-bottom: none; }
.tx-icon {
    width: 38px; height: 38px; border-radius: 50%; display: flex;
    align-items: center; justify-content: center; font-size: 1.1rem;
    flex-shrink: 0; background: #f3f2f1;
}
.tx-icon-debit { background: #fdecea; }
.tx-icon-credit { background: #e9f8ef; }
.tx-body { flex: 1; min-width: 0; }
.tx-title { font-weight: 700; font-size: 0.95rem; }
.tx-meta { font-size: 0.8rem; color: #505a5f; margin-top: 0.1rem; }
.tx-amount { font-weight: 900; font-size: 1rem; white-space: nowrap; }
.tx-amount-debit { color: #d4351c; }
.tx-amount-credit { color: #00703c; }

/* Submit button */
.btn-submit-expense {
    background: #7413dc; border: none; color: #fff; font-weight: 800;
    font-size: 1.05rem; padding: 0.85rem 2rem; width: 100%;
    transition: background 0.15s;
}
.btn-submit-expense:hover { background: #5a0fb0; color: #fff; }

/* Responsive */
@media (min-width: 576px) {
    .balance-hero { margin: -1rem 0 1.5rem; border-radius: 0; }
}
@media (max-width: 400px) {
    .member-select-btn { width: 76px; padding: 0.5rem; }
    .member-select-photo, .member-select-placeholder { width: 40px; height: 40px; }
}

/* No receipt indicator */
.tx-no-receipt { color: #d4351c; font-weight: 700; font-size: 0.8rem; cursor: help; }

/* Edit modal overlay */
.edit-modal-overlay {
    display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.5); z-index: 9999;
    align-items: center; justify-content: center; padding: 1rem;
}
.edit-modal-overlay.active { display: flex; }
.edit-modal {
    background: #fff; width: 100%; max-width: 500px; max-height: 90vh;
    overflow-y: auto; padding: 1.5rem; position: relative;
    border: 3px solid #7413dc;
}
.edit-modal h3 { font-weight: 900; font-size: 1.15rem; margin: 0 0 1rem; color: #7413dc; }
.edit-modal .form-step { margin-bottom: 1rem; }
.edit-modal label { font-weight: 700; font-size: 0.9rem; display: block; margin-bottom: 0.3rem; }
.edit-modal .btn-close-modal {
    position: absolute; top: 0.75rem; right: 0.75rem; background: none; border: none;
    font-size: 1.5rem; cursor: pointer; color: #505a5f; line-height: 1;
}
.edit-modal .btn-save-edit {
    background: #7413dc; border: none; color: #fff; font-weight: 800;
    font-size: 1rem; padding: 0.75rem 2rem; width: 100%; margin-top: 0.5rem;
}
.edit-modal .btn-save-edit:hover { background: #5a0fb0; color: #fff; }
.edit-modal .existing-receipt-note {
    background: #e9f8ef; border: 1px solid #00703c; padding: 0.5rem 0.75rem;
    font-size: 0.85rem; font-weight: 600; margin-bottom: 0.5rem;
}

/* No receipt section styling */
.no-receipt-section { }
.no-receipt-toggle input[type="checkbox"] {
    width: 18px; height: 18px; accent-color: #7413dc; cursor: pointer;
}
</style>

<div class="container mb-5">

    <!-- Balance hero -->
    <div class="balance-hero">
        <h1>Team Card Balance</h1>
        <div class="hero-amount">&euro;<?= number_format($estimatedBalance, 2) ?></div>
        <div class="hero-sub">
            <span>&#9650; Loaded: &euro;<?= number_format($cardInitialBalance + $totalCredits, 2) ?></span>
            <span>&#9660; Spent: &euro;<?= number_format($totalDebits, 2) ?></span>
        </div>
    </div>

    <div style="font-size:0.8rem; color:#505a5f; background:#fff7bf; padding:0.5rem 0.75rem; border-left:4px solid #ffdd00; margin-bottom:1.25rem;">
        Balances are estimates only and may not reflect all purchases if expenses haven't been logged yet.
    </div>

    <!-- Success toast -->
    <?php if ($submittedSuccess && $successData): ?>
    <div class="success-toast">
        <div class="st-icon">&#10003;</div>
        <div>
            <div class="st-text">Expense recorded</div>
            <div class="st-detail">
                &euro;<?= number_format((float)$successData['amount'], 2) ?>
                for <?= e($categoryLabels[$successData['category']] ?? $successData['category']) ?>
                by <?= e($successData['submitted_by']) ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Error -->
    <?php if ($error): ?>
    <div class="alert alert-danger" style="font-weight:700;"><?= e($error) ?></div>
    <?php endif; ?>

    <!-- Expense form -->
    <div class="form-panel <?= $submittedSuccess ? 'collapsed' : '' ?>" id="expenseFormPanel">
        <div class="form-panel-header" id="formToggle">
            <h2>Record a Purchase</h2>
            <div class="toggle-icon">&#8963;</div>
        </div>
        <div class="form-panel-body">
            <form method="post" enctype="multipart/form-data" id="expenseForm">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="submitted_by" id="submitted_by" value="">
                <input type="hidden" name="category" id="category_input" value="">

                <!-- Step 1: Who -->
                <div class="form-step">
                    <div class="form-step-label">Step 1 &mdash; Who is logging this?</div>
                    <div class="member-selector" id="memberSelector">
                        <?php foreach ($teamMembers as $member): ?>
                        <?php $photoUrl = expense_media_url($member['photo_url'] ?? ''); $initials = expense_initials($member['name']); ?>
                        <div class="member-select-btn" data-name="<?= e($member['name']) ?>">
                            <?php if ($photoUrl): ?>
                                <img class="member-select-photo" src="<?= e($photoUrl) ?>" alt="<?= e($member['name']) ?>">
                            <?php else: ?>
                                <span class="member-select-placeholder"><?= e($initials) ?></span>
                            <?php endif; ?>
                            <span class="member-select-name"><?= e($member['name']) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Step 2: What did you buy? -->
                <div class="form-step">
                    <div class="form-step-label">Step 2 &mdash; What did you buy?</div>
                    <div class="category-pills" id="categoryPills">
                        <div class="cat-pill" data-value="food">🍕 Food & Drink</div>
                        <div class="cat-pill" data-value="camping">⛺ Camping</div>
                        <div class="cat-pill" data-value="supplies">🎒 Supplies</div>
                        <div class="cat-pill" data-value="travel">🚌 Travel</div>
                        <div class="cat-pill" data-value="other">📦 Other</div>
                    </div>
                </div>

                <!-- Step 3: How much? -->
                <div class="form-step">
                    <div class="form-step-label">Step 3 &mdash; How much?</div>
                    <div class="d-flex align-items-center gap-3" style="gap:1rem;">
                        <div class="amount-input-wrap">
                            <span class="currency-symbol">&euro;</span>
                            <input type="number" class="form-control" id="amount" name="amount"
                                   step="0.01" min="0.01" placeholder="0.00" required inputmode="decimal">
                        </div>
                        <input type="date" class="form-control" id="transaction_date" name="transaction_date"
                               value="<?= e(date('Y-m-d')) ?>" required style="max-width:180px;">
                    </div>
                </div>

                <!-- Step 4: Details -->
                <div class="form-step">
                    <div class="form-step-label">Step 4 &mdash; Quick description</div>
                    <input type="text" class="form-control" id="description" name="description"
                           placeholder="e.g. Lunch at K-Market, bus tickets..." maxlength="500">
                </div>

                <!-- Step 5: Receipt (mandatory) -->
                <div class="form-step">
                    <div class="form-step-label">Step 5 &mdash; Upload the receipt <span style="color:#d4351c;">*</span></div>
                    <div class="receipt-drop" id="receiptDrop">
                        <div class="rd-icon">📸</div>
                        <div class="rd-text">Tap to take a photo or choose file</div>
                        <div class="rd-hint">JPG, PNG, PDF up to 10MB</div>
                    </div>
                    <input type="file" id="receipt" name="receipt" accept="image/*,application/pdf"
                           style="display:none;">

                    <div class="no-receipt-section" style="margin-top:0.75rem;">
                        <label class="no-receipt-toggle" style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-weight:600; font-size:0.9rem;">
                            <input type="checkbox" name="no_receipt" id="noReceiptCheckbox" value="1">
                            I don't have a receipt
                        </label>
                        <div class="no-receipt-reason-wrap" id="noReceiptReasonWrap" style="display:none; margin-top:0.6rem;">
                            <label for="no_receipt_reason" style="font-weight:700; font-size:0.85rem; color:#d4351c; display:block; margin-bottom:0.3rem;">
                                Why don't you have a receipt? <span style="color:#d4351c;">*</span>
                            </label>
                            <textarea class="form-control" id="no_receipt_reason" name="no_receipt_reason"
                                      rows="2" maxlength="500"
                                      placeholder="e.g. Receipt was not provided by vendor, lost receipt, digital purchase with no receipt issued..."></textarea>
                            <div style="font-size:0.75rem; color:#505a5f; margin-top:0.25rem;">
                                You must provide a valid reason for not uploading a receipt.
                            </div>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-submit-expense" id="submitExpenseBtn">
                    Submit Expense
                </button>
            </form>
        </div>
    </div>

    <!-- Transaction timeline -->
    <div class="form-panel" style="border-color:#e8e8e8;">
        <h2 style="font-weight:900; font-size:1.2rem; margin:0 0 1rem;">
            Spending History
            <?php if (!empty($transactions)): ?>
                <span style="font-weight:400; font-size:0.85rem; color:#505a5f;">(<?= count($transactions) ?> transaction<?= count($transactions) !== 1 ? 's' : '' ?>)</span>
            <?php endif; ?>
        </h2>

        <?php if (empty($transactions)): ?>
            <p style="color:#505a5f; margin:0;">No transactions yet. Your spending will appear here once you submit your first expense.</p>
        <?php else: ?>
            <ul class="tx-timeline">
                <?php foreach ($transactions as $tx): ?>
                <li class="tx-item">
                    <div class="tx-icon <?= $tx['type'] === 'credit' ? 'tx-icon-credit' : 'tx-icon-debit' ?>">
                        <?php if ($tx['type'] === 'credit'): ?>&#9650;<?php else: ?><?= $categoryIcons[$tx['category'] ?? 'other'] ?? '📦' ?><?php endif; ?>
                    </div>
                    <div class="tx-body">
                        <div class="tx-title">
                            <?php if ($tx['type'] === 'credit'): ?>
                                Funds loaded
                            <?php else: ?>
                                <?= e($tx['description'] ?: ($categoryLabels[$tx['category'] ?? 'other'] ?? 'Expense')) ?>
                            <?php endif; ?>
                        </div>
                        <div class="tx-meta">
                            <?= e(date('j M', strtotime($tx['transaction_date']))) ?>
                            &middot; <?= e($tx['submitted_by']) ?>
                            <?php if ($tx['receipt_path']): ?>
                                &middot; <a href="<?= e(url($tx['receipt_path'])) ?>" target="_blank" style="color:#1d70b8;">receipt</a>
                            <?php elseif (!empty($tx['no_receipt_reason'])): ?>
                                &middot; <span class="tx-no-receipt" title="<?= e($tx['no_receipt_reason']) ?>">⚠ no receipt</span>
                            <?php elseif ($tx['type'] === 'debit'): ?>
                                &middot; <span class="tx-no-receipt">⚠ no receipt</span>
                            <?php endif; ?>
                            <?php if ($tx['type'] === 'debit'): ?>
                                &middot; <a href="#" class="tx-edit-btn" data-id="<?= (int)$tx['id'] ?>"
                                   data-amount="<?= e($tx['amount']) ?>"
                                   data-category="<?= e($tx['category'] ?? '') ?>"
                                   data-description="<?= e($tx['description'] ?? '') ?>"
                                   data-submitted-by="<?= e($tx['submitted_by']) ?>"
                                   data-date="<?= e($tx['transaction_date']) ?>"
                                   data-receipt="<?= e($tx['receipt_path'] ?? '') ?>"
                                   data-no-receipt-reason="<?= e($tx['no_receipt_reason'] ?? '') ?>"
                                   style="color:#7413dc; font-weight:700;">edit</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="tx-amount <?= $tx['type'] === 'credit' ? 'tx-amount-credit' : 'tx-amount-debit' ?>">
                        <?= $tx['type'] === 'credit' ? '+' : '-' ?>&euro;<?= number_format((float)$tx['amount'], 2) ?>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

</div>

<!-- Edit Expense Modal -->
<div class="edit-modal-overlay" id="editModal">
    <div class="edit-modal">
        <button type="button" class="btn-close-modal" id="closeEditModal">&times;</button>
        <h3>Edit Expense</h3>
        <form method="post" enctype="multipart/form-data" id="editExpenseForm">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="edit_expense_id" id="edit_expense_id" value="">
            <input type="hidden" name="submitted_by" id="edit_submitted_by" value="">
            <input type="hidden" name="category" id="edit_category_input" value="">

            <div class="form-step">
                <label>Who submitted this?</label>
                <div class="member-selector" id="editMemberSelector">
                    <?php foreach ($teamMembers as $member): ?>
                    <?php $photoUrl = expense_media_url($member['photo_url'] ?? ''); $initials = expense_initials($member['name']); ?>
                    <div class="member-select-btn" data-name="<?= e($member['name']) ?>">
                        <?php if ($photoUrl): ?>
                            <img class="member-select-photo" src="<?= e($photoUrl) ?>" alt="<?= e($member['name']) ?>">
                        <?php else: ?>
                            <span class="member-select-placeholder"><?= e($initials) ?></span>
                        <?php endif; ?>
                        <span class="member-select-name"><?= e($member['name']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-step">
                <label>Category</label>
                <div class="category-pills" id="editCategoryPills">
                    <div class="cat-pill" data-value="food">🍕 Food & Drink</div>
                    <div class="cat-pill" data-value="camping">⛺ Camping</div>
                    <div class="cat-pill" data-value="supplies">🎒 Supplies</div>
                    <div class="cat-pill" data-value="travel">🚌 Travel</div>
                    <div class="cat-pill" data-value="other">📦 Other</div>
                </div>
            </div>

            <div class="form-step">
                <label>Amount &amp; Date</label>
                <div class="d-flex align-items-center" style="gap:0.75rem; flex-wrap:wrap;">
                    <div class="amount-input-wrap">
                        <span class="currency-symbol">&euro;</span>
                        <input type="number" class="form-control" id="edit_amount" name="amount"
                               step="0.01" min="0.01" placeholder="0.00" required inputmode="decimal">
                    </div>
                    <input type="date" class="form-control" id="edit_transaction_date" name="transaction_date"
                           required style="max-width:180px;">
                </div>
            </div>

            <div class="form-step">
                <label>Description</label>
                <input type="text" class="form-control" id="edit_description" name="description"
                       placeholder="e.g. Lunch at K-Market, bus tickets..." maxlength="500">
            </div>

            <div class="form-step">
                <label>Receipt</label>
                <div id="editExistingReceipt" class="existing-receipt-note" style="display:none;">
                    ✓ Receipt already uploaded — <a href="#" id="editReceiptLink" target="_blank">view</a>
                </div>
                <div class="receipt-drop" id="editReceiptDrop">
                    <div class="rd-icon">📸</div>
                    <div class="rd-text">Upload a new receipt (replaces existing)</div>
                    <div class="rd-hint">JPG, PNG, PDF up to 10MB</div>
                </div>
                <input type="file" id="edit_receipt" name="receipt" accept="image/*,application/pdf"
                       style="display:none;">

                <div class="no-receipt-section" style="margin-top:0.75rem;">
                    <label class="no-receipt-toggle" style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-weight:600; font-size:0.9rem;">
                        <input type="checkbox" name="no_receipt" id="editNoReceiptCheckbox" value="1">
                        I don't have a receipt
                    </label>
                    <div id="editNoReceiptReasonWrap" style="display:none; margin-top:0.6rem;">
                        <label for="edit_no_receipt_reason" style="font-weight:700; font-size:0.85rem; color:#d4351c; display:block; margin-bottom:0.3rem;">
                            Why don't you have a receipt? <span style="color:#d4351c;">*</span>
                        </label>
                        <textarea class="form-control" id="edit_no_receipt_reason" name="no_receipt_reason"
                                  rows="2" maxlength="500"
                                  placeholder="e.g. Receipt was not provided by vendor, lost receipt..."></textarea>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-save-edit">Save Changes</button>
        </form>
    </div>
</div>

<script>
(function() {
    // Member selector (new expense form)
    var selector = document.getElementById('memberSelector');
    var hiddenInput = document.getElementById('submitted_by');
    if (selector) {
        selector.querySelectorAll('.member-select-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                selector.querySelectorAll('.member-select-btn').forEach(function(b) { b.classList.remove('selected'); });
                btn.classList.add('selected');
                hiddenInput.value = btn.getAttribute('data-name');
            });
        });
    }

    // Category pills (new expense form)
    var catInput = document.getElementById('category_input');
    document.querySelectorAll('#expenseForm .cat-pill').forEach(function(pill) {
        pill.addEventListener('click', function() {
            document.querySelectorAll('#expenseForm .cat-pill').forEach(function(p) { p.classList.remove('selected'); });
            pill.classList.add('selected');
            catInput.value = pill.getAttribute('data-value');
        });
    });

    // Receipt drop zone (new expense form)
    var dropZone = document.getElementById('receiptDrop');
    var fileInput = document.getElementById('receipt');
    if (dropZone && fileInput) {
        dropZone.addEventListener('click', function() { fileInput.click(); });
        fileInput.addEventListener('change', function() {
            if (fileInput.files.length > 0) {
                dropZone.classList.add('has-file');
                dropZone.querySelector('.rd-text').textContent = fileInput.files[0].name;
                dropZone.querySelector('.rd-icon').textContent = '✓';
            } else {
                dropZone.classList.remove('has-file');
                dropZone.querySelector('.rd-text').textContent = 'Tap to take a photo or choose file';
                dropZone.querySelector('.rd-icon').textContent = '📸';
            }
        });
    }

    // No receipt checkbox (new expense form)
    var noReceiptCb = document.getElementById('noReceiptCheckbox');
    var noReceiptWrap = document.getElementById('noReceiptReasonWrap');
    if (noReceiptCb && noReceiptWrap) {
        noReceiptCb.addEventListener('change', function() {
            noReceiptWrap.style.display = noReceiptCb.checked ? 'block' : 'none';
        });
    }

    // Form toggle
    var panel = document.getElementById('expenseFormPanel');
    var toggle = document.getElementById('formToggle');
    if (panel && toggle) {
        toggle.addEventListener('click', function() {
            panel.classList.toggle('collapsed');
        });
    }

    // Form validation (new expense)
    var form = document.getElementById('expenseForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            if (!hiddenInput.value) { e.preventDefault(); alert('Please select who is submitting this expense.'); return; }
            if (!catInput.value) { e.preventDefault(); alert('Please select a purchase category.'); return; }
            // Validate receipt or justification
            var hasFile = fileInput && fileInput.files.length > 0;
            var noReceiptChecked = noReceiptCb && noReceiptCb.checked;
            if (!hasFile && !noReceiptChecked) {
                e.preventDefault();
                alert('Please upload a receipt or check "I don\'t have a receipt" and provide a reason.');
                return;
            }
            if (noReceiptChecked) {
                var reasonField = document.getElementById('no_receipt_reason');
                if (!reasonField || reasonField.value.trim() === '') {
                    e.preventDefault();
                    alert('Please explain why you don\'t have a receipt.');
                    return;
                }
            }
        });
    }

    // ===================== EDIT MODAL =====================
    var editModal = document.getElementById('editModal');
    var closeEditBtn = document.getElementById('closeEditModal');
    var editForm = document.getElementById('editExpenseForm');
    var editMemberSelector = document.getElementById('editMemberSelector');
    var editSubmittedBy = document.getElementById('edit_submitted_by');
    var editCatInput = document.getElementById('edit_category_input');
    var editReceiptDrop = document.getElementById('editReceiptDrop');
    var editFileInput = document.getElementById('edit_receipt');
    var editNoReceiptCb = document.getElementById('editNoReceiptCheckbox');
    var editNoReceiptWrap = document.getElementById('editNoReceiptReasonWrap');

    // Edit member selector
    if (editMemberSelector) {
        editMemberSelector.querySelectorAll('.member-select-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                editMemberSelector.querySelectorAll('.member-select-btn').forEach(function(b) { b.classList.remove('selected'); });
                btn.classList.add('selected');
                editSubmittedBy.value = btn.getAttribute('data-name');
            });
        });
    }

    // Edit category pills
    if (editCatInput) {
        document.querySelectorAll('#editCategoryPills .cat-pill').forEach(function(pill) {
            pill.addEventListener('click', function() {
                document.querySelectorAll('#editCategoryPills .cat-pill').forEach(function(p) { p.classList.remove('selected'); });
                pill.classList.add('selected');
                editCatInput.value = pill.getAttribute('data-value');
            });
        });
    }

    // Edit receipt drop zone
    if (editReceiptDrop && editFileInput) {
        editReceiptDrop.addEventListener('click', function() { editFileInput.click(); });
        editFileInput.addEventListener('change', function() {
            if (editFileInput.files.length > 0) {
                editReceiptDrop.classList.add('has-file');
                editReceiptDrop.querySelector('.rd-text').textContent = editFileInput.files[0].name;
                editReceiptDrop.querySelector('.rd-icon').textContent = '✓';
            } else {
                editReceiptDrop.classList.remove('has-file');
                editReceiptDrop.querySelector('.rd-text').textContent = 'Upload a new receipt (replaces existing)';
                editReceiptDrop.querySelector('.rd-icon').textContent = '📸';
            }
        });
    }

    // Edit no receipt checkbox
    if (editNoReceiptCb && editNoReceiptWrap) {
        editNoReceiptCb.addEventListener('change', function() {
            editNoReceiptWrap.style.display = editNoReceiptCb.checked ? 'block' : 'none';
        });
    }

    // Open edit modal
    document.querySelectorAll('.tx-edit-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var data = btn.dataset;

            document.getElementById('edit_expense_id').value = data.id;
            document.getElementById('edit_amount').value = data.amount;
            document.getElementById('edit_transaction_date').value = data.date;
            document.getElementById('edit_description').value = data.description;

            // Select the member
            editSubmittedBy.value = data.submittedBy;
            editMemberSelector.querySelectorAll('.member-select-btn').forEach(function(b) {
                b.classList.toggle('selected', b.getAttribute('data-name') === data.submittedBy);
            });

            // Select category
            editCatInput.value = data.category;
            document.querySelectorAll('#editCategoryPills .cat-pill').forEach(function(p) {
                p.classList.toggle('selected', p.getAttribute('data-value') === data.category);
            });

            // Handle existing receipt display
            var existingReceiptNote = document.getElementById('editExistingReceipt');
            var receiptLink = document.getElementById('editReceiptLink');
            if (data.receipt) {
                existingReceiptNote.style.display = 'block';
                receiptLink.href = data.receipt;
            } else {
                existingReceiptNote.style.display = 'none';
            }

            // Handle no receipt reason
            if (data.noReceiptReason) {
                editNoReceiptCb.checked = true;
                editNoReceiptWrap.style.display = 'block';
                document.getElementById('edit_no_receipt_reason').value = data.noReceiptReason;
            } else {
                editNoReceiptCb.checked = false;
                editNoReceiptWrap.style.display = 'none';
                document.getElementById('edit_no_receipt_reason').value = '';
            }

            // Reset file input
            editFileInput.value = '';
            editReceiptDrop.classList.remove('has-file');
            editReceiptDrop.querySelector('.rd-text').textContent = 'Upload a new receipt (replaces existing)';
            editReceiptDrop.querySelector('.rd-icon').textContent = '📸';

            editModal.classList.add('active');
        });
    });

    // Close edit modal
    if (closeEditBtn) {
        closeEditBtn.addEventListener('click', function() { editModal.classList.remove('active'); });
    }
    if (editModal) {
        editModal.addEventListener('click', function(e) {
            if (e.target === editModal) { editModal.classList.remove('active'); }
        });
    }

    // Edit form validation
    if (editForm) {
        editForm.addEventListener('submit', function(e) {
            if (!editSubmittedBy.value) { e.preventDefault(); alert('Please select who submitted this expense.'); return; }
            if (!editCatInput.value) { e.preventDefault(); alert('Please select a category.'); return; }
            // Receipt: must have existing receipt, new upload, or justification
            var hasExisting = document.getElementById('editExistingReceipt').style.display !== 'none';
            var hasNewFile = editFileInput && editFileInput.files.length > 0;
            var noReceiptChecked = editNoReceiptCb && editNoReceiptCb.checked;
            if (!hasExisting && !hasNewFile && !noReceiptChecked) {
                e.preventDefault();
                alert('Please upload a receipt or check "I don\'t have a receipt" and provide a reason.');
                return;
            }
            if (noReceiptChecked) {
                var reasonField = document.getElementById('edit_no_receipt_reason');
                if (!reasonField || reasonField.value.trim() === '') {
                    e.preventDefault();
                    alert('Please explain why you don\'t have a receipt.');
                    return;
                }
            }
        });
    }
})();
</script>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.5.1/dist/jquery.slim.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
