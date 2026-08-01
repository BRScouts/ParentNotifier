<?php
require_once __DIR__ . '/auth.php';

require_login();

$pdo = db();
$user = current_user();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

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
    }
    $cardCheck = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = "team_cards"'
    );
    $cardCheck->execute();
    if ((int)$cardCheck->fetchColumn() === 0) {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS team_cards (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                team_id INT UNSIGNED NOT NULL,
                leader_name VARCHAR(150) NOT NULL,
                pin_number VARCHAR(20) NOT NULL,
                card_description VARCHAR(255) NOT NULL,
                initial_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_tc_team (team_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
    }
} catch (Throwable $e) {}

// --- Determine which team to view ---
$teamId = (int)($_GET['team_id'] ?? 0);
$allTeams = [];
try {
    $stmt = $pdo->query('SELECT id, name FROM teams ORDER BY name ASC');
    $allTeams = $stmt->fetchAll();
} catch (Throwable $e) { $allTeams = []; }

if ($teamId === 0 && !empty($allTeams)) {
    $teamId = (int)$allTeams[0]['id'];
}

$currentTeam = null;
if ($teamId > 0) {
    try {
        $stmt = $pdo->prepare('SELECT * FROM teams WHERE id = ? LIMIT 1');
        $stmt->execute([$teamId]);
        $currentTeam = $stmt->fetch() ?: null;
    } catch (Throwable $e) { $currentTeam = null; }
}

// --- CSRF ---
if (empty($_SESSION['expenses_manage_csrf'])) {
    $_SESSION['expenses_manage_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['expenses_manage_csrf'];

function em_csrf_valid(): bool {
    return isset($_POST['csrf_token'], $_SESSION['expenses_manage_csrf'])
        && hash_equals((string)$_SESSION['expenses_manage_csrf'], (string)$_POST['csrf_token']);
}

$error = '';
$success = '';

// --- Handle POST actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $currentTeam) {
    try {
        if (!em_csrf_valid()) {
            throw new RuntimeException('Security check failed. Please refresh and try again.');
        }
        $action = $_POST['action'] ?? '';

        if ($action === 'add_funds') {
            $amount = trim($_POST['fund_amount'] ?? '');
            $description = trim($_POST['fund_description'] ?? '');
            if ($amount === '' || !is_numeric($amount) || (float)$amount <= 0) {
                throw new RuntimeException('Please enter a valid amount.');
            }
            $stmt = $pdo->prepare(
                'INSERT INTO team_transactions
                    (team_id, type, amount, currency, category, description, submitted_by, transaction_date, created_by_leader_id)
                 VALUES (?, "credit", ?, "EUR", "top_up", ?, ?, CURDATE(), ?)'
            );
            $stmt->execute([
                $teamId, round((float)$amount, 2),
                substr(strip_tags($description ?: 'Funds added'), 0, 500),
                $user['name'], (int)$user['id'],
            ]);
            $success = 'Funds added successfully.';
        }

        if ($action === 'save_card') {
            $leaderName = trim($_POST['card_leader_name'] ?? '');
            $pinNumber = trim($_POST['card_pin_number'] ?? '');
            $cardDescription = trim($_POST['card_description'] ?? '');
            $initialBalance = trim($_POST['card_initial_balance'] ?? '0');
            if ($leaderName === '' || $pinNumber === '' || $cardDescription === '') {
                throw new RuntimeException('Please fill in all card fields.');
            }
            $existingCard = $pdo->prepare('SELECT id FROM team_cards WHERE team_id = ? LIMIT 1');
            $existingCard->execute([$teamId]);
            $cardRow = $existingCard->fetch();
            if ($cardRow) {
                $stmt = $pdo->prepare('UPDATE team_cards SET leader_name=?, pin_number=?, card_description=?, initial_balance=? WHERE id=?');
                $stmt->execute([substr(strip_tags($leaderName),0,150), substr(strip_tags($pinNumber),0,20),
                    substr(strip_tags($cardDescription),0,255), round((float)$initialBalance,2), (int)$cardRow['id']]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO team_cards (team_id, leader_name, pin_number, card_description, initial_balance) VALUES (?,?,?,?,?)');
                $stmt->execute([$teamId, substr(strip_tags($leaderName),0,150), substr(strip_tags($pinNumber),0,20),
                    substr(strip_tags($cardDescription),0,255), round((float)$initialBalance,2)]);
            }
            $success = 'Card details saved.';
        }

        if ($action === 'delete_transaction') {
            $txId = (int)($_POST['transaction_id'] ?? 0);
            if ($txId > 0) {
                $stmt = $pdo->prepare('DELETE FROM team_transactions WHERE id = ? AND team_id = ?');
                $stmt->execute([$txId, $teamId]);
                $success = 'Transaction deleted.';
            }
        }

        if ($action === 'edit_transaction') {
            $txId = (int)($_POST['transaction_id'] ?? 0);
            $editAmount = trim($_POST['edit_amount'] ?? '');
            $editCategory = trim($_POST['edit_category'] ?? '');
            $editDescription = trim($_POST['edit_description'] ?? '');
            $editDate = trim($_POST['edit_transaction_date'] ?? '');
            $editSubmittedBy = trim($_POST['edit_submitted_by'] ?? '');

            if ($txId <= 0) { throw new RuntimeException('Invalid transaction.'); }
            if ($editAmount === '' || !is_numeric($editAmount) || (float)$editAmount <= 0) {
                throw new RuntimeException('Please enter a valid amount.');
            }
            if ($editDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $editDate)) {
                throw new RuntimeException('Please enter a valid date.');
            }

            // Handle new receipt
            $newReceiptPath = null;
            if (!empty($_FILES['edit_receipt']) && $_FILES['edit_receipt']['error'] !== UPLOAD_ERR_NO_FILE) {
                $file = $_FILES['edit_receipt'];
                if ($file['error'] !== UPLOAD_ERR_OK) { throw new RuntimeException('Receipt upload failed.'); }
                if ((int)$file['size'] > 10 * 1024 * 1024) { throw new RuntimeException('Receipt must be under 10MB.'); }
                $tmpName = $file['tmp_name'];
                if (!is_uploaded_file($tmpName)) { throw new RuntimeException('Invalid upload.'); }
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $tmpName);
                finfo_close($finfo);
                $allowedTypes = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','application/pdf'=>'pdf'];
                if (!isset($allowedTypes[$mimeType])) { throw new RuntimeException('Receipt must be JPG, PNG, WEBP, GIF or PDF.'); }
                $ext = $allowedTypes[$mimeType];
                $teamSlug = preg_replace('/[^a-zA-Z0-9]/', '', $currentTeam['name'] ?? 'team');
                $filename = $txId . '-' . $teamSlug . '_' . $editDate . '.' . $ext;
                $uploadDir = '/home/brscouts/exbelt2026.irvalscouts.org.uk/assets/receipts/';
                if (!is_dir($uploadDir)) { $uploadDir = __DIR__ . '/assets/receipts/'; }
                if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
                $destination = rtrim($uploadDir, '/') . '/' . $filename;
                if (!move_uploaded_file($tmpName, $destination)) { throw new RuntimeException('Could not save receipt.'); }
                $newReceiptPath = 'assets/receipts/' . $filename;
            }

            if ($newReceiptPath) {
                $stmt = $pdo->prepare('UPDATE team_transactions SET amount=?, category=?, description=?, transaction_date=?, submitted_by=?, receipt_path=? WHERE id=? AND team_id=?');
                $stmt->execute([round((float)$editAmount,2), $editCategory?:null, substr(strip_tags($editDescription),0,500), $editDate, substr(strip_tags($editSubmittedBy),0,150), $newReceiptPath, $txId, $teamId]);
            } else {
                $stmt = $pdo->prepare('UPDATE team_transactions SET amount=?, category=?, description=?, transaction_date=?, submitted_by=? WHERE id=? AND team_id=?');
                $stmt->execute([round((float)$editAmount,2), $editCategory?:null, substr(strip_tags($editDescription),0,500), $editDate, substr(strip_tags($editSubmittedBy),0,150), $txId, $teamId]);
            }
            $success = 'Transaction updated.';
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

// --- CSV Export ---
if (($_GET['export'] ?? '') === 'csv' && $currentTeam) {
    $stmt = $pdo->prepare(
        'SELECT type, amount, currency, category, description, submitted_by, transaction_date, created_at
         FROM team_transactions WHERE team_id = ? ORDER BY transaction_date ASC, created_at ASC'
    );
    $stmt->execute([$teamId]);
    $rows = $stmt->fetchAll();
    $teamName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $currentTeam['name'] ?? 'team');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="expenses_' . $teamName . '_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Type', 'Amount (EUR)', 'Category', 'Description', 'Submitted By', 'Recorded At']);
    foreach ($rows as $row) {
        fputcsv($out, [$row['transaction_date'], $row['type'] === 'credit' ? 'Credit' : 'Debit',
            number_format((float)$row['amount'], 2, '.', ''), $row['category'] ?? '',
            $row['description'] ?? '', $row['submitted_by'], $row['created_at']]);
    }
    fclose($out);
    exit;
}

// --- Fetch data ---
$transactions = [];
$totalCredits = 0;
$totalDebits = 0;
$teamCard = null;

if ($currentTeam) {
    try {
        $stmt = $pdo->prepare('SELECT * FROM team_transactions WHERE team_id = ? ORDER BY transaction_date DESC, created_at DESC');
        $stmt->execute([$teamId]);
        $transactions = $stmt->fetchAll();
        foreach ($transactions as $t) {
            if ($t['type'] === 'credit') { $totalCredits += (float)$t['amount']; }
            else { $totalDebits += (float)$t['amount']; }
        }
    } catch (Throwable $e) { $transactions = []; }

    try {
        $stmt = $pdo->prepare('SELECT * FROM team_cards WHERE team_id = ? LIMIT 1');
        $stmt->execute([$teamId]);
        $teamCard = $stmt->fetch() ?: null;
    } catch (Throwable $e) { $teamCard = null; }
}

$initialBalance = (float)($teamCard['initial_balance'] ?? 0);
$estimatedBalance = $initialBalance + $totalCredits - $totalDebits;
$activeTab = $_GET['tab'] ?? 'transactions';

$categoryLabels = ['food' => 'Food & Drink', 'camping' => 'Camping', 'supplies' => 'Supplies',
    'travel' => 'Travel', 'top_up' => 'Top Up', 'other' => 'Other'];
$categoryIcons = ['food' => '🍕', 'camping' => '⛺', 'supplies' => '🎒',
    'travel' => '🚌', 'top_up' => '💳', 'other' => '📦'];

include __DIR__ . '/header.php';
?>

<style>
.em-page { max-width: 1100px; margin: 0 auto; padding: 1.5rem 1rem; }

/* Header strip */
.em-header {
    background: linear-gradient(135deg, #7413dc 0%, #5a0fb0 100%);
    color: #fff; padding: 1.5rem; margin-bottom: 1.5rem; display: flex;
    justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;
}
.em-header h1 { font-weight: 900; font-size: 1.4rem; margin: 0; color: #fff; }
.em-header .em-team-select select {
    background: rgba(255,255,255,0.15); color: #fff; border: 2px solid rgba(255,255,255,0.4);
    font-weight: 700; padding: 0.4rem 0.75rem; font-size: 0.95rem;
}
.em-header .em-team-select select option { color: #1d1d1d; }

/* Balance strip */
.em-balance {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 1rem; margin-bottom: 1.5rem;
}
.em-bal-card {
    background: #fff; border: 2px solid #e8e8e8; padding: 1rem 1.25rem; text-align: center;
}
.em-bal-card .bal-label { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: #505a5f; letter-spacing: 0.03em; }
.em-bal-card .bal-value { font-size: 1.6rem; font-weight: 900; margin-top: 0.15rem; }
.bal-green { color: #00703c; }
.bal-red { color: #d4351c; }
.bal-purple { color: #7413dc; }

/* Tabs */
.em-tabs {
    display: flex; gap: 0; border-bottom: 3px solid #e8e8e8; margin-bottom: 1.5rem;
}
.em-tab {
    padding: 0.7rem 1.25rem; font-weight: 800; font-size: 0.95rem; cursor: pointer;
    border-bottom: 3px solid transparent; margin-bottom: -3px; color: #505a5f;
    transition: color 0.15s, border-color 0.15s; text-decoration: none;
}
.em-tab:hover { color: #7413dc; text-decoration: none; }
.em-tab.active { color: #7413dc; border-bottom-color: #7413dc; }

/* Panels */
.em-panel { display: none; }
.em-panel.active { display: block; }

/* Transaction list */
.tx-list { list-style: none; padding: 0; margin: 0; }
.tx-item {
    display: flex; gap: 0.75rem; padding: 0.85rem 0; border-bottom: 1px solid #f0f0f0; align-items: center;
}
.tx-item:last-child { border-bottom: none; }
.tx-icon {
    width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center;
    justify-content: center; font-size: 1rem; flex-shrink: 0;
}
.tx-icon-debit { background: #fdecea; }
.tx-icon-credit { background: #e9f8ef; }
.tx-body { flex: 1; min-width: 0; }
.tx-title { font-weight: 700; font-size: 0.9rem; }
.tx-meta { font-size: 0.78rem; color: #505a5f; }
.tx-right { text-align: right; }
.tx-amount { font-weight: 900; font-size: 0.95rem; }
.tx-amount-debit { color: #d4351c; }
.tx-amount-credit { color: #00703c; }
.tx-delete { font-size: 0.75rem; color: #d4351c; cursor: pointer; border: none; background: none; padding: 0; margin-top: 0.15rem; }
.tx-delete:hover { text-decoration: underline; }

/* Card details */
.card-detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
@media (max-width: 576px) { .card-detail-grid { grid-template-columns: 1fr; } }
.card-visual {
    background: linear-gradient(135deg, #1d1d1d 0%, #3d3d3d 100%);
    color: #fff; padding: 1.5rem; border-radius: 12px; margin-bottom: 1.25rem;
    position: relative; overflow: hidden;
}
.card-visual::after {
    content: ''; position: absolute; top: -30px; right: -30px;
    width: 100px; height: 100px; border-radius: 50%;
    background: rgba(255,255,255,0.08);
}
.card-visual .cv-provider { font-size: 0.85rem; opacity: 0.7; margin-bottom: 0.75rem; }
.card-visual .cv-number { font-size: 1.3rem; font-weight: 900; letter-spacing: 0.05em; }
.card-visual .cv-holder { margin-top: 0.75rem; font-size: 0.9rem; font-weight: 700; }
.card-visual .cv-pin { font-size: 0.8rem; opacity: 0.7; margin-top: 0.25rem; }

/* Add funds */
.fund-form { background: #fff; border: 2px solid #e8e8e8; padding: 1.25rem; }
.fund-form h3 { font-weight: 900; font-size: 1.1rem; margin: 0 0 1rem; }
.fund-amount-wrap { position: relative; max-width: 180px; }
.fund-amount-wrap .cur { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); font-weight: 900; color: #505a5f; }
.fund-amount-wrap input { padding-left: 2rem; font-size: 1.1rem; font-weight: 800; }

/* Actions bar */
.em-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1rem; }
.em-actions .btn { font-weight: 700; font-size: 0.85rem; }

/* Empty state */
.empty-state { text-align: center; padding: 2rem; color: #505a5f; }
.empty-state .es-icon { font-size: 2.5rem; margin-bottom: 0.5rem; }
</style>

<div class="em-page">

    <!-- Header with team selector -->
    <div class="em-header">
        <h1>Team Expenses</h1>
        <form method="get" class="em-team-select">
            <select name="team_id" onchange="this.form.submit()">
                <?php foreach ($allTeams as $t): ?>
                <option value="<?= (int)$t['id'] ?>" <?= (int)$t['id'] === $teamId ? 'selected' : '' ?>><?= e($t['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <?php if ($error): ?><div class="alert alert-danger" style="font-weight:700;"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success" style="font-weight:700;"><?= e($success) ?></div><?php endif; ?>

    <?php if ($currentTeam): ?>

    <!-- Balance cards -->
    <div class="em-balance">
        <div class="em-bal-card">
            <div class="bal-label">Loaded</div>
            <div class="bal-value bal-green">&euro;<?= number_format($initialBalance + $totalCredits, 2) ?></div>
        </div>
        <div class="em-bal-card">
            <div class="bal-label">Spent</div>
            <div class="bal-value bal-red">&euro;<?= number_format($totalDebits, 2) ?></div>
        </div>
        <div class="em-bal-card">
            <div class="bal-label">Balance</div>
            <div class="bal-value bal-purple">&euro;<?= number_format($estimatedBalance, 2) ?></div>
        </div>
        <div class="em-bal-card">
            <div class="bal-label">Transactions</div>
            <div class="bal-value"><?= count($transactions) ?></div>
        </div>
    </div>

    <div style="font-size:0.8rem; color:#505a5f; background:#fff7bf; padding:0.5rem 0.75rem; border-left:4px solid #ffdd00; margin-bottom:1.25rem;">
        Balances are estimates only and may not reflect all purchases if expenses haven't been logged yet.
    </div>

    <!-- Tabs -->
    <div class="em-tabs">
        <a class="em-tab <?= $activeTab === 'transactions' ? 'active' : '' ?>" href="?team_id=<?= $teamId ?>&tab=transactions">Transactions</a>
        <a class="em-tab <?= $activeTab === 'add_funds' ? 'active' : '' ?>" href="?team_id=<?= $teamId ?>&tab=add_funds">Add Funds</a>
        <a class="em-tab <?= $activeTab === 'card' ? 'active' : '' ?>" href="?team_id=<?= $teamId ?>&tab=card">Card Details</a>
    </div>

    <!-- Tab: Transactions -->
    <div class="em-panel <?= $activeTab === 'transactions' ? 'active' : '' ?>">
        <div class="em-actions">
            <a href="?team_id=<?= $teamId ?>&export=csv" class="btn btn-outline-secondary btn-sm">Export CSV</a>
        </div>

        <?php if (empty($transactions)): ?>
        <div class="empty-state">
            <div class="es-icon">📋</div>
            <p>No transactions yet for this team.</p>
        </div>
        <?php else: ?>
        <ul class="tx-list">
            <?php foreach ($transactions as $tx): ?>
            <li class="tx-item">
                <div class="tx-icon <?= $tx['type'] === 'credit' ? 'tx-icon-credit' : 'tx-icon-debit' ?>">
                    <?= $tx['type'] === 'credit' ? '💳' : ($categoryIcons[$tx['category'] ?? 'other'] ?? '📦') ?>
                </div>
                <div class="tx-body">
                    <div class="tx-title">
                        <?php if ($tx['type'] === 'credit'): ?>Funds loaded<?php else: ?><?= e($tx['description'] ?: ($categoryLabels[$tx['category'] ?? 'other'] ?? 'Expense')) ?><?php endif; ?>
                    </div>
                    <div class="tx-meta">
                        <?= e(date('j M Y', strtotime($tx['transaction_date']))) ?>
                        &middot; <?= e($tx['submitted_by']) ?>
                        <?php if ($tx['receipt_path']): ?>
                            &middot; <a href="<?= e(url($tx['receipt_path'])) ?>" target="_blank" style="color:#1d70b8;">receipt</a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="tx-right">
                    <div class="tx-amount <?= $tx['type'] === 'credit' ? 'tx-amount-credit' : 'tx-amount-debit' ?>">
                        <?= $tx['type'] === 'credit' ? '+' : '-' ?>&euro;<?= number_format((float)$tx['amount'], 2) ?>
                    </div>
                    <div style="display:flex; gap:0.3rem; justify-content:flex-end; margin-top:0.15rem;">
                        <button type="button" class="tx-delete" style="color:#1d70b8;" onclick="toggleTxEdit(<?= (int)$tx['id'] ?>)">edit</button>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this transaction?');">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="action" value="delete_transaction">
                            <input type="hidden" name="transaction_id" value="<?= (int)$tx['id'] ?>">
                            <button type="submit" class="tx-delete">delete</button>
                        </form>
                    </div>
                </div>
            </li>
            <!-- Inline edit form -->
            <li class="tx-item" id="txEdit_<?= (int)$tx['id'] ?>" style="display:none; padding:0.75rem; background:#f8f8f8; border:1px solid #e8e8e8;">
                <form method="post" enctype="multipart/form-data" style="width:100%;">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="edit_transaction">
                    <input type="hidden" name="transaction_id" value="<?= (int)$tx['id'] ?>">
                    <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:0.5rem; margin-bottom:0.5rem;">
                        <div>
                            <label style="font-size:0.7rem; font-weight:700;">Amount (&euro;)</label>
                            <input type="number" class="form-control form-control-sm" name="edit_amount" step="0.01" min="0.01" value="<?= e($tx['amount']) ?>" required>
                        </div>
                        <div>
                            <label style="font-size:0.7rem; font-weight:700;">Date</label>
                            <input type="date" class="form-control form-control-sm" name="edit_transaction_date" value="<?= e($tx['transaction_date']) ?>" required>
                        </div>
                        <div>
                            <label style="font-size:0.7rem; font-weight:700;">Category</label>
                            <select class="form-control form-control-sm" name="edit_category">
                                <option value="food" <?= ($tx['category'] ?? '') === 'food' ? 'selected' : '' ?>>Food</option>
                                <option value="camping" <?= ($tx['category'] ?? '') === 'camping' ? 'selected' : '' ?>>Camping</option>
                                <option value="supplies" <?= ($tx['category'] ?? '') === 'supplies' ? 'selected' : '' ?>>Supplies</option>
                                <option value="travel" <?= ($tx['category'] ?? '') === 'travel' ? 'selected' : '' ?>>Travel</option>
                                <option value="top_up" <?= ($tx['category'] ?? '') === 'top_up' ? 'selected' : '' ?>>Top Up</option>
                                <option value="other" <?= ($tx['category'] ?? '') === 'other' ? 'selected' : '' ?>>Other</option>
                            </select>
                        </div>
                    </div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.5rem; margin-bottom:0.5rem;">
                        <div>
                            <label style="font-size:0.7rem; font-weight:700;">Description</label>
                            <input type="text" class="form-control form-control-sm" name="edit_description" value="<?= e($tx['description'] ?? '') ?>" maxlength="500">
                        </div>
                        <div>
                            <label style="font-size:0.7rem; font-weight:700;">Submitted by</label>
                            <input type="text" class="form-control form-control-sm" name="edit_submitted_by" value="<?= e($tx['submitted_by']) ?>">
                        </div>
                    </div>
                    <div style="margin-bottom:0.5rem;">
                        <label style="font-size:0.7rem; font-weight:700;">
                            Replace receipt
                            <?php if ($tx['receipt_path']): ?><span style="font-weight:400;">(<a href="<?= e(url($tx['receipt_path'])) ?>" target="_blank">current</a>)</span><?php endif; ?>
                        </label>
                        <input type="file" class="form-control-file" name="edit_receipt" accept="image/*,application/pdf" style="font-size:0.8rem;">
                    </div>
                    <div style="display:flex; gap:0.4rem;">
                        <button type="submit" class="btn btn-primary btn-sm" style="font-size:0.8rem;">Save</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" style="font-size:0.8rem;" onclick="toggleTxEdit(<?= (int)$tx['id'] ?>)">Cancel</button>
                    </div>
                </form>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>

    <!-- Tab: Add Funds -->
    <div class="em-panel <?= $activeTab === 'add_funds' ? 'active' : '' ?>">
        <div class="fund-form">
            <h3>Load Funds onto Team Card</h3>
            <p style="color:#505a5f; font-size:0.9rem; margin-bottom:1rem;">
                Record a top-up to the team's travel card. This will increase the estimated balance.
            </p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="add_funds">

                <div class="form-group">
                    <label for="fund_amount" style="font-weight:700;">Amount (&euro;)</label>
                    <div class="fund-amount-wrap">
                        <span class="cur">&euro;</span>
                        <input type="number" class="form-control" id="fund_amount" name="fund_amount"
                               step="0.01" min="0.01" placeholder="0.00" required inputmode="decimal">
                    </div>
                </div>

                <div class="form-group">
                    <label for="fund_description" style="font-weight:700;">Note (optional)</label>
                    <input type="text" class="form-control" id="fund_description" name="fund_description"
                           placeholder="e.g. Top-up at Post Office, ATM withdrawal" maxlength="500">
                </div>

                <button type="submit" class="btn btn-success">Add Funds</button>
            </form>
        </div>
    </div>

    <!-- Tab: Card Details -->
    <div class="em-panel <?= $activeTab === 'card' ? 'active' : '' ?>">

        <?php if ($teamCard): ?>
        <div class="card-visual">
            <div class="cv-provider"><?= e($teamCard['card_description']) ?></div>
            <div class="cv-number">**** **** **** ****</div>
            <div class="cv-holder"><?= e($teamCard['leader_name']) ?></div>
            <div class="cv-pin">PIN: <?= e($teamCard['pin_number']) ?></div>
        </div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="save_card">

            <div class="card-detail-grid">
                <div class="form-group">
                    <label for="card_leader_name" style="font-weight:700;">Card holder (leader name)</label>
                    <input type="text" class="form-control" id="card_leader_name" name="card_leader_name"
                           value="<?= e($teamCard['leader_name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="card_pin_number" style="font-weight:700;">PIN number</label>
                    <input type="text" class="form-control" id="card_pin_number" name="card_pin_number"
                           value="<?= e($teamCard['pin_number'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="card_description" style="font-weight:700;">Card type / provider</label>
                    <input type="text" class="form-control" id="card_description" name="card_description"
                           placeholder="e.g. Post Office, Asda, Revolut"
                           value="<?= e($teamCard['card_description'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="card_initial_balance" style="font-weight:700;">Initial balance loaded (&euro;)</label>
                    <input type="number" class="form-control" id="card_initial_balance" name="card_initial_balance"
                           step="0.01" min="0" value="<?= e($teamCard['initial_balance'] ?? '0.00') ?>">
                    <small class="form-text text-muted">This is factored into the balance calculation.</small>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Save Card Details</button>
        </form>
    </div>

    <?php else: ?>
        <div class="alert alert-warning">No teams found. Please create a team first.</div>
    <?php endif; ?>

</div>

<?php include __DIR__ . '/footer.php'; ?>
<script>
function toggleTxEdit(id) {
    var form = document.getElementById('txEdit_' + id);
    if (form) { form.style.display = form.style.display === 'none' ? '' : 'none'; }
}
</script>
