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
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
                'application/pdf' => 'pdf',
            ];

            if (!isset($allowedTypes[$mimeType])) {
                throw new RuntimeException('Receipt must be a JPG, PNG, WEBP, GIF or PDF file.');
            }

            $ext = $allowedTypes[$mimeType];
            $filename = 'receipt-' . (int)$team['id'] . '-' . bin2hex(random_bytes(10)) . '.' . $ext;

            // Use production path if available, otherwise local
            $uploadDir = '/home/brscouts/exbelt2026.irvalscouts.org.uk/assets/receipts/';
            if (!is_dir($uploadDir)) {
                $uploadDir = __DIR__ . '/assets/receipts/';
            }

            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

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
             VALUES
                (?, "debit", ?, "EUR", ?, ?, ?, ?, ?)'
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

        $_SESSION['explorer_expense_success'] = true;
        redirect('explorer_expenses.php?token=' . urlencode($token) . '&submitted=1');
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$submittedSuccess = ($_GET['submitted'] ?? '') === '1' && !empty($_SESSION['explorer_expense_success']);
if ($submittedSuccess) {
    unset($_SESSION['explorer_expense_success']);
}

// --- Fetch transaction history for this team ---
$transactions = [];
$totalCredits = 0;
$totalDebits = 0;
try {
    $stmt = $pdo->prepare(
        'SELECT * FROM team_transactions
         WHERE team_id = ?
         ORDER BY transaction_date DESC, created_at DESC'
    );
    $stmt->execute([(int)$team['id']]);
    $transactions = $stmt->fetchAll();

    foreach ($transactions as $t) {
        if ($t['type'] === 'credit') {
            $totalCredits += (float)$t['amount'];
        } else {
            $totalDebits += (float)$t['amount'];
        }
    }
} catch (Throwable $e) {
    $transactions = [];
}

$estimatedBalance = $totalCredits - $totalDebits;

// Helper for member display
function expense_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $letters = '';
    foreach ($parts as $part) {
        if ($part !== '') {
            $letters .= strtoupper(substr($part, 0, 1));
        }
        if (strlen($letters) >= 2) break;
    }
    return $letters !== '' ? $letters : '?';
}

function expense_media_url(?string $path): string
{
    $path = trim((string)$path);
    if ($path === '') return '';
    if (preg_match('/^https?:\/\//i', $path)) return $path;
    return url($path);
}

include __DIR__ . '/explorer_header.php';
?>

<style>
    body { background: #f3f2f1; color: #1d1d1d; }

    .expense-panel {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
    }

    .expense-panel h2, .expense-panel h3 {
        font-weight: 900;
        margin-top: 0;
    }

    .expense-panel label {
        font-weight: 800;
    }

    .success-box {
        border-left: 8px solid #00703c;
        background: #e9f8ef;
        padding: 1.25rem;
        margin-bottom: 1rem;
    }

    .balance-card {
        background: #7413dc;
        color: #ffffff;
        padding: 1.25rem 1.5rem;
        margin-bottom: 1.5rem;
    }

    .balance-card h3 {
        color: #ffffff;
        font-weight: 900;
        margin: 0 0 0.5rem;
    }

    .balance-amount {
        font-size: 2rem;
        font-weight: 900;
        line-height: 1.2;
    }

    .balance-detail {
        font-size: 0.9rem;
        opacity: 0.85;
        margin-top: 0.25rem;
    }

    .transaction-row {
        border-bottom: 1px solid #d8d8d8;
        padding: 0.75rem 0;
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 0.75rem;
    }

    .transaction-row:last-child {
        border-bottom: none;
    }

    .tx-amount-debit {
        color: #d4351c;
        font-weight: 800;
    }

    .tx-amount-credit {
        color: #00703c;
        font-weight: 800;
    }

    .tx-meta {
        color: #505a5f;
        font-size: 0.85rem;
    }

    .category-badge {
        display: inline-block;
        font-size: 0.75rem;
        font-weight: 700;
        padding: 0.15em 0.5em;
        background: #f3f2f1;
        border: 1px solid #d8d8d8;
        margin-right: 0.25rem;
    }

    .member-selector {
        display: flex;
        flex-wrap: wrap;
        gap: 1rem;
        margin-bottom: 0.75rem;
    }

    .member-select-btn {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.4rem;
        padding: 0.75rem;
        border: 3px solid #d8d8d8;
        border-radius: 12px;
        background: #ffffff;
        cursor: pointer;
        transition: border-color 0.15s, background 0.15s, transform 0.1s;
        width: 100px;
        text-align: center;
    }

    .member-select-btn:hover {
        border-color: #1d70b8;
        background: #f0f7ff;
    }

    .member-select-btn:active {
        transform: scale(0.95);
    }

    .member-select-btn.selected {
        border-color: #00703c;
        background: #e9f8ef;
        box-shadow: 0 0 0 2px #00703c;
    }

    .member-select-photo {
        width: 56px;
        height: 56px;
        border-radius: 50%;
        border: 2px solid #1d1d1d;
        object-fit: cover;
    }

    .member-select-placeholder {
        width: 56px;
        height: 56px;
        border-radius: 50%;
        border: 2px solid #1d1d1d;
        background: #7413dc;
        color: #ffffff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 900;
        font-size: 1.1rem;
    }

    .member-select-btn.selected .member-select-photo,
    .member-select-btn.selected .member-select-placeholder {
        border-color: #00703c;
    }

    .member-select-name {
        font-size: 0.85rem;
        font-weight: 700;
        color: #1d1d1d;
        line-height: 1.2;
        word-break: break-word;
    }
</style>

<div class="container mb-5">

    <?php if ($submittedSuccess): ?>
        <section class="success-box">
            <h2>Expense submitted</h2>
            <p>Your expense has been recorded. Leaders will see this in the team account.</p>
        </section>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <!-- Balance overview -->
    <div class="balance-card">
        <h3>Estimated Balance</h3>
        <div class="balance-amount">&euro;<?= number_format($estimatedBalance, 2) ?></div>
        <div class="balance-detail">
            Loaded: &euro;<?= number_format($totalCredits, 2) ?> &middot;
            Spent: &euro;<?= number_format($totalDebits, 2) ?>
        </div>
    </div>

    <!-- Expense form -->
    <section class="expense-panel">
        <h2>Submit an Expense</h2>
        <p class="muted">Record a purchase made with the team card. Upload the receipt if you have one.</p>

        <form method="post" enctype="multipart/form-data" id="expenseForm">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

            <!-- Who is submitting -->
            <div class="form-group">
                <label>Who is submitting this expense?</label>
                <input type="hidden" name="submitted_by" id="submitted_by" value="" required>

                <div class="member-selector" id="memberSelector">
                    <?php foreach ($teamMembers as $member): ?>
                        <?php
                        $photoUrl = expense_media_url($member['photo_url'] ?? '');
                        $initials = expense_initials($member['name']);
                        ?>
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

            <!-- Amount -->
            <div class="form-group">
                <label for="amount">Amount (&euro;)</label>
                <input
                    type="number"
                    class="form-control"
                    id="amount"
                    name="amount"
                    step="0.01"
                    min="0.01"
                    placeholder="e.g. 12.50"
                    required
                    inputmode="decimal"
                >
            </div>

            <!-- Category -->
            <div class="form-group">
                <label for="category">Purchase type</label>
                <select class="form-control" id="category" name="category" required>
                    <option value="">-- Select --</option>
                    <option value="food">Food & Drink</option>
                    <option value="camping">Camping / Accommodation</option>
                    <option value="supplies">Supplies & Equipment</option>
                    <option value="travel">Travel & Transport</option>
                    <option value="other">Other</option>
                </select>
            </div>

            <!-- Description -->
            <div class="form-group">
                <label for="description">Description (optional)</label>
                <input
                    type="text"
                    class="form-control"
                    id="description"
                    name="description"
                    placeholder="e.g. Lunch from K-Market"
                    maxlength="500"
                >
            </div>

            <!-- Date -->
            <div class="form-group">
                <label for="transaction_date">Date of purchase</label>
                <input
                    type="date"
                    class="form-control"
                    id="transaction_date"
                    name="transaction_date"
                    value="<?= e(date('Y-m-d')) ?>"
                    required
                >
            </div>

            <!-- Receipt upload -->
            <div class="form-group">
                <label for="receipt">Receipt photo (optional)</label>
                <input
                    type="file"
                    class="form-control-file"
                    id="receipt"
                    name="receipt"
                    accept="image/*,application/pdf"
                    capture="environment"
                >
                <small class="form-text text-muted">JPG, PNG, WEBP, GIF or PDF. Max 10MB.</small>
            </div>

            <button type="submit" class="btn btn-primary btn-lg btn-block" id="submitExpenseBtn">
                Submit Expense
            </button>
        </form>
    </section>

    <!-- Transaction history -->
    <?php if (!empty($transactions)): ?>
    <section class="expense-panel">
        <h3>Transaction History</h3>

        <?php foreach ($transactions as $tx): ?>
            <div class="transaction-row">
                <div>
                    <?php if ($tx['type'] === 'credit'): ?>
                        <strong>Funds added</strong>
                    <?php else: ?>
                        <span class="category-badge"><?= e(ucfirst($tx['category'] ?? 'other')) ?></span>
                        <strong><?= e($tx['description'] ?: 'Expense') ?></strong>
                    <?php endif; ?>
                    <div class="tx-meta">
                        <?= e(date('j M Y', strtotime($tx['transaction_date']))) ?>
                        &middot; by <?= e($tx['submitted_by']) ?>
                        <?php if ($tx['receipt_path']): ?>
                            &middot; <a href="<?= e(url($tx['receipt_path'])) ?>" target="_blank">Receipt</a>
                        <?php endif; ?>
                    </div>
                </div>
                <div>
                    <?php if ($tx['type'] === 'credit'): ?>
                        <span class="tx-amount-credit">+&euro;<?= number_format((float)$tx['amount'], 2) ?></span>
                    <?php else: ?>
                        <span class="tx-amount-debit">-&euro;<?= number_format((float)$tx['amount'], 2) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </section>
    <?php elseif (!$submittedSuccess): ?>
    <section class="expense-panel">
        <h3>Transaction History</h3>
        <p class="muted">No transactions yet. Submit your first expense above.</p>
    </section>
    <?php endif; ?>

</div>

<script>
(function() {
    // Member selector
    var selector = document.getElementById('memberSelector');
    var hiddenInput = document.getElementById('submitted_by');

    if (selector) {
        var btns = selector.querySelectorAll('.member-select-btn');
        btns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                btns.forEach(function(b) { b.classList.remove('selected'); });
                btn.classList.add('selected');
                hiddenInput.value = btn.getAttribute('data-name');
            });
        });
    }

    // Form validation
    var form = document.getElementById('expenseForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            if (!hiddenInput.value) {
                e.preventDefault();
                alert('Please select who is submitting this expense.');
                return false;
            }
        });
    }
})();
</script>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.5.1/dist/jquery.slim.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
