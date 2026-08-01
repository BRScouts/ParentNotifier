<?php
require_once __DIR__ . '/auth.php';

require_login();

$pdo = db();
$user = current_user();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// --- Ensure table exists ---
try {
    $tblCheck = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = "leader_expenses"'
    );
    $tblCheck->execute();
    if ((int)$tblCheck->fetchColumn() === 0) {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS leader_expenses (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                leader_id INT UNSIGNED NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                currency CHAR(3) NOT NULL DEFAULT "GBP",
                category VARCHAR(50) NOT NULL,
                description VARCHAR(500) NULL,
                receipt_path VARCHAR(500) NULL,
                is_corporate_card TINYINT(1) NOT NULL DEFAULT 0,
                is_reimbursed TINYINT(1) NOT NULL DEFAULT 0,
                reimbursed_by_leader_id INT UNSIGNED NULL,
                reimbursed_at DATETIME NULL,
                expense_date DATE NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_le_leader (leader_id),
                INDEX idx_le_corporate (is_corporate_card),
                INDEX idx_le_reimbursed (is_reimbursed),
                INDEX idx_le_date (expense_date),
                INDEX idx_le_leader_date (leader_id, expense_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
    }
} catch (Throwable $e) {
    // Continue gracefully
}

// --- CSRF ---
if (empty($_SESSION['leader_expenses_csrf'])) {
    $_SESSION['leader_expenses_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['leader_expenses_csrf'];

function le_csrf_valid(): bool
{
    return isset($_POST['csrf_token'], $_SESSION['leader_expenses_csrf'])
        && hash_equals((string)$_SESSION['leader_expenses_csrf'], (string)$_POST['csrf_token']);
}

$error = '';
$success = '';

// --- Handle form submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!le_csrf_valid()) {
            throw new RuntimeException('Security check failed. Please refresh and try again.');
        }

        $amount = trim($_POST['amount'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $expenseDate = trim($_POST['expense_date'] ?? '');
        $isCorporateCard = ($_POST['card_type'] ?? '') === 'corporate' ? 1 : 0;

        if ($amount === '' || !is_numeric($amount) || (float)$amount <= 0) {
            throw new RuntimeException('Please enter a valid amount greater than zero.');
        }

        if ($category === '') {
            throw new RuntimeException('Please select a category.');
        }

        if ($expenseDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expenseDate)) {
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
            $filename = 'leader-receipt-' . (int)$user['id'] . '-' . bin2hex(random_bytes(10)) . '.' . $ext;

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

        // Insert expense
        $stmt = $pdo->prepare(
            'INSERT INTO leader_expenses
                (leader_id, amount, currency, category, description, receipt_path, is_corporate_card, expense_date)
             VALUES
                (?, ?, "GBP", ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (int)$user['id'],
            round((float)$amount, 2),
            $category,
            substr(strip_tags($description), 0, 500) ?: null,
            $receiptPath,
            $isCorporateCard,
            $expenseDate,
        ]);

        $_SESSION['leader_expense_success'] = true;
        redirect('leader_expenses_submit.php?submitted=1');
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$submittedSuccess = ($_GET['submitted'] ?? '') === '1' && !empty($_SESSION['leader_expense_success']);
if ($submittedSuccess) {
    unset($_SESSION['leader_expense_success']);
}

// --- Fetch my recent expenses ---
$myExpenses = [];
try {
    $stmt = $pdo->prepare(
        'SELECT * FROM leader_expenses WHERE leader_id = ? ORDER BY expense_date DESC, created_at DESC LIMIT 20'
    );
    $stmt->execute([(int)$user['id']]);
    $myExpenses = $stmt->fetchAll();
} catch (Throwable $e) {
    $myExpenses = [];
}

include __DIR__ . '/header.php';
?>

<style>
    .le-header {
        background: #7413dc;
        color: #fff;
        padding: 1.25rem 1.5rem;
        margin-bottom: 1.5rem;
    }

    .le-header h1 {
        font-weight: 900;
        font-size: 1.5rem;
        margin: 0;
        color: #fff;
    }

    .le-card {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 1.25rem 1.5rem;
        margin-bottom: 1.5rem;
    }

    .le-card h3 {
        font-weight: 900;
        margin-top: 0;
    }

    .le-card label {
        font-weight: 800;
    }

    .success-box {
        border-left: 8px solid #00703c;
        background: #e9f8ef;
        padding: 1.25rem;
        margin-bottom: 1rem;
    }

    .card-type-toggle {
        display: flex;
        gap: 0.75rem;
        margin-bottom: 0.5rem;
    }

    .card-type-option {
        flex: 1;
        border: 3px solid #d8d8d8;
        padding: 1rem;
        text-align: center;
        cursor: pointer;
        transition: border-color 0.15s, background 0.15s;
        background: #ffffff;
    }

    .card-type-option:hover {
        border-color: #1d70b8;
        background: #f0f7ff;
    }

    .card-type-option.selected {
        border-color: #7413dc;
        background: #f5edfb;
    }

    .card-type-option .ct-label {
        font-weight: 900;
        font-size: 1rem;
        display: block;
        margin-bottom: 0.25rem;
    }

    .card-type-option .ct-desc {
        font-size: 0.85rem;
        color: #505a5f;
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

    .tx-amount { font-weight: 800; }
    .tx-meta { color: #505a5f; font-size: 0.85rem; }

    .category-badge {
        display: inline-block;
        font-size: 0.75rem;
        font-weight: 700;
        padding: 0.15em 0.5em;
        background: #f3f2f1;
        border: 1px solid #d8d8d8;
        margin-right: 0.25rem;
    }

    .badge-corporate {
        background: #eef7ff;
        border-color: #1d70b8;
        color: #1d70b8;
    }

    .badge-personal {
        background: #fef3e8;
        border-color: #f47738;
        color: #f47738;
    }

    .badge-paid {
        background: #e9f8ef;
        border-color: #00703c;
        color: #00703c;
    }

    .badge-outstanding {
        background: #fdecea;
        border-color: #d4351c;
        color: #d4351c;
    }
</style>

<div class="container-fluid px-4 py-3">

    <div class="le-header">
        <h1>Submit Leader Expense</h1>
    </div>

    <?php if ($submittedSuccess): ?>
        <section class="success-box">
            <strong>Expense submitted.</strong> It has been recorded for accounting.
        </section>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="row">
        <!-- Left: form -->
        <div class="col-lg-5 mb-3">
            <div class="le-card">
                <h3>New Expense</h3>

                <form method="post" enctype="multipart/form-data" id="leaderExpenseForm">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="card_type" id="card_type" value="personal">

                    <!-- Card type toggle -->
                    <div class="form-group">
                        <label>Payment method</label>
                        <div class="card-type-toggle">
                            <div class="card-type-option selected" data-value="personal" id="opt_personal">
                                <span class="ct-label">Personal</span>
                                <span class="ct-desc">Needs reimbursement</span>
                            </div>
                            <div class="card-type-option" data-value="corporate" id="opt_corporate">
                                <span class="ct-label">Corporate Card</span>
                                <span class="ct-desc">No reimbursement needed</span>
                            </div>
                        </div>
                    </div>

                    <!-- Amount -->
                    <div class="form-group">
                        <label for="amount">Amount (&pound;)</label>
                        <input
                            type="number"
                            class="form-control"
                            id="amount"
                            name="amount"
                            step="0.01"
                            min="0.01"
                            placeholder="e.g. 45.00"
                            required
                            inputmode="decimal"
                        >
                    </div>

                    <!-- Category -->
                    <div class="form-group">
                        <label for="category">Category</label>
                        <select class="form-control" id="category" name="category" required>
                            <option value="">-- Select --</option>
                            <option value="food">Food & Drink</option>
                            <option value="travel">Travel & Transport</option>
                            <option value="accommodation">Accommodation</option>
                            <option value="supplies">Supplies & Equipment</option>
                            <option value="activities">Activities & Fees</option>
                            <option value="admin">Admin & Comms</option>
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
                            placeholder="e.g. Fuel for minibus"
                            maxlength="500"
                        >
                    </div>

                    <!-- Date -->
                    <div class="form-group">
                        <label for="expense_date">Date of expense</label>
                        <input
                            type="date"
                            class="form-control"
                            id="expense_date"
                            name="expense_date"
                            value="<?= e(date('Y-m-d')) ?>"
                            required
                        >
                    </div>

                    <!-- Receipt upload -->
                    <div class="form-group">
                        <label for="receipt">Receipt photo</label>
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

                    <button type="submit" class="btn btn-primary btn-lg btn-block">Submit Expense</button>
                </form>
            </div>
        </div>

        <!-- Right: my recent expenses -->
        <div class="col-lg-7 mb-3">
            <div class="le-card">
                <h3>My Recent Expenses</h3>

                <?php if (empty($myExpenses)): ?>
                    <p class="text-muted">No expenses submitted yet.</p>
                <?php else: ?>
                    <?php foreach ($myExpenses as $exp): ?>
                        <div class="transaction-row">
                            <div style="flex:1;">
                                <span class="category-badge"><?= e(ucfirst($exp['category'])) ?></span>
                                <?php if ((int)$exp['is_corporate_card']): ?>
                                    <span class="category-badge badge-corporate">Corporate</span>
                                <?php else: ?>
                                    <span class="category-badge badge-personal">Personal</span>
                                <?php endif; ?>

                                <?php if (!(int)$exp['is_corporate_card']): ?>
                                    <?php if ((int)$exp['is_reimbursed']): ?>
                                        <span class="category-badge badge-paid">Paid</span>
                                    <?php else: ?>
                                        <span class="category-badge badge-outstanding">Outstanding</span>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <br>
                                <strong><?= e($exp['description'] ?: 'Expense') ?></strong>
                                <div class="tx-meta">
                                    <?= e(date('j M Y', strtotime($exp['expense_date']))) ?>
                                    <?php if ($exp['receipt_path']): ?>
                                        &middot; <a href="<?= e(url($exp['receipt_path'])) ?>" target="_blank">View receipt</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="tx-amount">
                                &pound;<?= number_format((float)$exp['amount'], 2) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <div class="mt-3">
                        <a href="<?= e(url('leader_expenses_summary.php')) ?>" class="btn btn-outline-secondary btn-sm">
                            View all leader expenses
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<script>
(function() {
    var cardTypeInput = document.getElementById('card_type');
    var optPersonal = document.getElementById('opt_personal');
    var optCorporate = document.getElementById('opt_corporate');

    function selectCardType(type) {
        cardTypeInput.value = type;
        optPersonal.classList.toggle('selected', type === 'personal');
        optCorporate.classList.toggle('selected', type === 'corporate');
    }

    optPersonal.addEventListener('click', function() { selectCardType('personal'); });
    optCorporate.addEventListener('click', function() { selectCardType('corporate'); });
})();
</script>

<?php include __DIR__ . '/footer.php'; ?>
