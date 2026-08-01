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

// --- Handle form submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $team) {
    try {
        if (!expenses_csrf_valid()) {
            throw new RuntimeException('Security check failed. Please refresh and try again.');
        }

        $amount = trim($_POST['amount'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $submittedBy = trim($_POST['submitted_by'] ?? '');
        $transactionDate = trim($_POST['transaction_date'] ?? '');

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

        // Handle receipt upload
        $receiptPath = null;
        if (!empty($_FILES['receipt']) && $_FILES['receipt']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['receipt'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Receipt upload failed. Please try again.');
            }
            if ((int)$file['size'] > 10 * 1024 * 1024) {
                throw new RuntimeException('Receipt file must be smaller than 10MB.');
            }
            $tmpName = $file['tmp_name'];
            if (!is_uploaded_file($tmpName)) {
                throw new RuntimeException('Invalid receipt upload.');
            }
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $tmpName);
            finfo_close($finfo);
            $allowedTypes = [
                'image/jpeg' => 'jpg', 'image/png' => 'png',
                'image/webp' => 'webp', 'image/gif' => 'gif',
                'application/pdf' => 'pdf',
            ];
            if (!isset($allowedTypes[$mimeType])) {
                throw new RuntimeException('Receipt must be a JPG, PNG, WEBP, GIF or PDF file.');
            }
            $ext = $allowedTypes[$mimeType];
            $filename = 'receipt-' . (int)$team['id'] . '-' . bin2hex(random_bytes(10)) . '.' . $ext;
            $uploadDir = '/home/brscouts/exbelt2026.irvalscouts.org.uk/assets/receipts/';
            if (!is_dir($uploadDir)) { $uploadDir = __DIR__ . '/assets/receipts/'; }
            if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
            $destination = rtrim($uploadDir, '/') . '/' . $filename;
            if (!move_uploaded_file($tmpName, $destination)) {
                throw new RuntimeException('Could not save receipt file.');
            }
            $receiptPath = 'assets/receipts/' . $filename;
        }

        // Insert transaction
        $stmt = $pdo->prepare(
            'INSERT INTO team_transactions
                (team_id, type, amount, currency, category, description, receipt_path, submitted_by, transaction_date)
             VALUES (?, "debit", ?, "EUR", ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (int)$team['id'],
            round((float)$amount, 2),
            $category,
            substr(strip_tags($description), 0, 500),
            $receiptPath,
            substr(strip_tags($submittedBy), 0, 150),
            $transactionDate,
        ]);

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

                <!-- Step 5: Receipt -->
                <div class="form-step">
                    <div class="form-step-label">Step 5 &mdash; Snap the receipt (optional)</div>
                    <div class="receipt-drop" id="receiptDrop">
                        <div class="rd-icon">📸</div>
                        <div class="rd-text">Tap to take a photo or choose file</div>
                        <div class="rd-hint">JPG, PNG, PDF up to 10MB</div>
                    </div>
                    <input type="file" id="receipt" name="receipt" accept="image/*,application/pdf"
                           capture="environment" style="display:none;">
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

<script>
(function() {
    // Member selector
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

    // Category pills
    var catInput = document.getElementById('category_input');
    document.querySelectorAll('.cat-pill').forEach(function(pill) {
        pill.addEventListener('click', function() {
            document.querySelectorAll('.cat-pill').forEach(function(p) { p.classList.remove('selected'); });
            pill.classList.add('selected');
            catInput.value = pill.getAttribute('data-value');
        });
    });

    // Receipt drop zone
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

    // Form toggle
    var panel = document.getElementById('expenseFormPanel');
    var toggle = document.getElementById('formToggle');
    if (panel && toggle) {
        toggle.addEventListener('click', function() {
            panel.classList.toggle('collapsed');
        });
    }

    // Form validation
    var form = document.getElementById('expenseForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            if (!hiddenInput.value) { e.preventDefault(); alert('Please select who is submitting this expense.'); return; }
            if (!catInput.value) { e.preventDefault(); alert('Please select a purchase category.'); return; }
        });
    }
})();
</script>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.5.1/dist/jquery.slim.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
