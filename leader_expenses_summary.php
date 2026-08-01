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
                currency CHAR(3) NOT NULL DEFAULT "EUR",
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
if (empty($_SESSION['leader_exp_summary_csrf'])) {
    $_SESSION['leader_exp_summary_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['leader_exp_summary_csrf'];

function les_csrf_valid(): bool
{
    return isset($_POST['csrf_token'], $_SESSION['leader_exp_summary_csrf'])
        && hash_equals((string)$_SESSION['leader_exp_summary_csrf'], (string)$_POST['csrf_token']);
}

$error = '';
$success = '';

// --- Handle POST actions (mark paid / delete) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!les_csrf_valid()) {
            throw new RuntimeException('Security check failed. Please refresh and try again.');
        }

        $action = $_POST['action'] ?? '';

        if ($action === 'mark_paid') {
            $expId = (int)($_POST['expense_id'] ?? 0);
            if ($expId > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE leader_expenses
                     SET is_reimbursed = 1, reimbursed_by_leader_id = ?, reimbursed_at = NOW()
                     WHERE id = ? AND is_corporate_card = 0'
                );
                $stmt->execute([(int)$user['id'], $expId]);
                $success = 'Marked as paid.';
            }
        }

        if ($action === 'mark_unpaid') {
            $expId = (int)($_POST['expense_id'] ?? 0);
            if ($expId > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE leader_expenses
                     SET is_reimbursed = 0, reimbursed_by_leader_id = NULL, reimbursed_at = NULL
                     WHERE id = ?'
                );
                $stmt->execute([$expId]);
                $success = 'Marked as unpaid.';
            }
        }

        if ($action === 'delete_expense') {
            $expId = (int)($_POST['expense_id'] ?? 0);
            if ($expId > 0) {
                $stmt = $pdo->prepare('DELETE FROM leader_expenses WHERE id = ?');
                $stmt->execute([$expId]);
                $success = 'Expense deleted.';
            }
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

// --- Filters ---
$filterLeader = (int)($_GET['leader'] ?? 0);
$filterType = $_GET['type'] ?? ''; // 'corporate', 'personal', or ''
$filterStatus = $_GET['status'] ?? ''; // 'paid', 'outstanding', or ''

// --- Fetch leaders for filter ---
$leaders = [];
try {
    $leaders = $pdo->query('SELECT id, name FROM leaders WHERE is_active = 1 ORDER BY name ASC')->fetchAll();
} catch (Throwable $e) {
    $leaders = [];
}

// --- Build query ---
$where = [];
$params = [];

if ($filterLeader > 0) {
    $where[] = 'le.leader_id = ?';
    $params[] = $filterLeader;
}

if ($filterType === 'corporate') {
    $where[] = 'le.is_corporate_card = 1';
} elseif ($filterType === 'personal') {
    $where[] = 'le.is_corporate_card = 0';
}

if ($filterStatus === 'paid') {
    $where[] = 'le.is_reimbursed = 1';
} elseif ($filterStatus === 'outstanding') {
    $where[] = 'le.is_reimbursed = 0 AND le.is_corporate_card = 0';
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$expenses = [];
try {
    $sql = "SELECT le.*, l.name AS leader_name, rl.name AS reimbursed_by_name
            FROM leader_expenses le
            LEFT JOIN leaders l ON l.id = le.leader_id
            LEFT JOIN leaders rl ON rl.id = le.reimbursed_by_leader_id
            {$whereClause}
            ORDER BY le.expense_date DESC, le.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $expenses = $stmt->fetchAll();
} catch (Throwable $e) {
    $expenses = [];
}

// --- Totals ---
$totalCorporate = 0;
$totalPersonal = 0;
$totalPaid = 0;
$totalOutstanding = 0;

try {
    $totStmt = $pdo->query(
        'SELECT
            SUM(CASE WHEN is_corporate_card = 1 THEN amount ELSE 0 END) AS corporate,
            SUM(CASE WHEN is_corporate_card = 0 THEN amount ELSE 0 END) AS personal,
            SUM(CASE WHEN is_corporate_card = 0 AND is_reimbursed = 1 THEN amount ELSE 0 END) AS paid,
            SUM(CASE WHEN is_corporate_card = 0 AND is_reimbursed = 0 THEN amount ELSE 0 END) AS outstanding
         FROM leader_expenses'
    );
    $tots = $totStmt->fetch();
    $totalCorporate = (float)($tots['corporate'] ?? 0);
    $totalPersonal = (float)($tots['personal'] ?? 0);
    $totalPaid = (float)($tots['paid'] ?? 0);
    $totalOutstanding = (float)($tots['outstanding'] ?? 0);
} catch (Throwable $e) {
    // Defaults remain 0
}

// --- CSV Export ---
if (($_GET['export'] ?? '') === 'csv') {
    $csvSql = "SELECT le.*, l.name AS leader_name, rl.name AS reimbursed_by_name
               FROM leader_expenses le
               LEFT JOIN leaders l ON l.id = le.leader_id
               LEFT JOIN leaders rl ON rl.id = le.reimbursed_by_leader_id
               {$whereClause}
               ORDER BY le.expense_date ASC, le.created_at ASC";
    $csvStmt = $pdo->prepare($csvSql);
    $csvStmt->execute($params);
    $csvRows = $csvStmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="leader_expenses_' . date('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Leader', 'Amount (EUR)', 'Category', 'Description', 'Card Type', 'Reimbursed', 'Reimbursed By', 'Reimbursed At']);

    foreach ($csvRows as $row) {
        fputcsv($out, [
            $row['expense_date'],
            $row['leader_name'],
            number_format((float)$row['amount'], 2, '.', ''),
            $row['category'],
            $row['description'] ?? '',
            (int)$row['is_corporate_card'] ? 'Corporate' : 'Personal',
            (int)$row['is_reimbursed'] ? 'Yes' : 'No',
            $row['reimbursed_by_name'] ?? '',
            $row['reimbursed_at'] ?? '',
        ]);
    }

    fclose($out);
    exit;
}

include __DIR__ . '/header.php';
?>

<style>
    .les-header {
        background: #7413dc;
        color: #fff;
        padding: 1.25rem 1.5rem;
        margin-bottom: 1.5rem;
    }

    .les-header h1 {
        font-weight: 900;
        font-size: 1.5rem;
        margin: 0;
        color: #fff;
    }

    .summary-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .summary-card {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 1rem;
        text-align: center;
    }

    .summary-card .sc-label {
        font-size: 0.8rem;
        font-weight: 700;
        color: #505a5f;
        text-transform: uppercase;
        letter-spacing: 0.02em;
    }

    .summary-card .sc-value {
        font-size: 1.5rem;
        font-weight: 900;
        margin-top: 0.2rem;
    }

    .sc-value-blue { color: #1d70b8; }
    .sc-value-orange { color: #f47738; }
    .sc-value-green { color: #00703c; }
    .sc-value-red { color: #d4351c; }

    .les-card {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 1.25rem 1.5rem;
        margin-bottom: 1.5rem;
    }

    .les-card h3 {
        font-weight: 900;
        margin-top: 0;
    }

    .filter-bar {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        align-items: flex-end;
        margin-bottom: 1.5rem;
    }

    .filter-bar .form-group {
        margin-bottom: 0;
    }

    .expense-row {
        border-bottom: 1px solid #d8d8d8;
        padding: 0.75rem 0;
    }

    .expense-row:last-child {
        border-bottom: none;
    }

    .expense-row-top {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 0.75rem;
    }

    .expense-row-actions {
        display: flex;
        gap: 0.5rem;
        align-items: center;
        margin-top: 0.4rem;
    }

    .tx-amount { font-weight: 800; font-size: 1.1rem; }
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

    .badge-corporate { background: #eef7ff; border-color: #1d70b8; color: #1d70b8; }
    .badge-personal { background: #fef3e8; border-color: #f47738; color: #f47738; }
    .badge-paid { background: #e9f8ef; border-color: #00703c; color: #00703c; }
    .badge-outstanding { background: #fdecea; border-color: #d4351c; color: #d4351c; }
</style>

<div class="container-fluid px-4 py-3">

    <div class="les-header">
        <h1>Leader Expenses Summary</h1>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

    <!-- Summary cards -->
    <div class="summary-cards">
        <div class="summary-card">
            <div class="sc-label">Corporate Card</div>
            <div class="sc-value sc-value-blue">&euro;<?= number_format($totalCorporate, 2) ?></div>
        </div>
        <div class="summary-card">
            <div class="sc-label">Personal (Total)</div>
            <div class="sc-value sc-value-orange">&euro;<?= number_format($totalPersonal, 2) ?></div>
        </div>
        <div class="summary-card">
            <div class="sc-label">Reimbursed</div>
            <div class="sc-value sc-value-green">&euro;<?= number_format($totalPaid, 2) ?></div>
        </div>
        <div class="summary-card">
            <div class="sc-label">Outstanding</div>
            <div class="sc-value sc-value-red">&euro;<?= number_format($totalOutstanding, 2) ?></div>
        </div>
    </div>

    <!-- Actions row -->
    <div class="mb-3">
        <a href="<?= e(url('leader_expenses_submit.php')) ?>" class="btn btn-primary btn-sm">
            Submit New Expense
        </a>
        <a href="?<?= e(http_build_query(array_filter(['leader' => $filterLeader, 'type' => $filterType, 'status' => $filterStatus, 'export' => 'csv']))) ?>" class="btn btn-outline-secondary btn-sm ml-2">
            Export CSV
        </a>
    </div>

    <!-- Filters -->
    <form method="get" class="filter-bar">
        <div class="form-group">
            <label for="filter_leader" style="font-weight:700;font-size:0.85rem;">Leader</label>
            <select class="form-control form-control-sm" name="leader" id="filter_leader">
                <option value="0">All leaders</option>
                <?php foreach ($leaders as $l): ?>
                    <option value="<?= (int)$l['id'] ?>" <?= $filterLeader === (int)$l['id'] ? 'selected' : '' ?>>
                        <?= e($l['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="filter_type" style="font-weight:700;font-size:0.85rem;">Card Type</label>
            <select class="form-control form-control-sm" name="type" id="filter_type">
                <option value="">All</option>
                <option value="corporate" <?= $filterType === 'corporate' ? 'selected' : '' ?>>Corporate</option>
                <option value="personal" <?= $filterType === 'personal' ? 'selected' : '' ?>>Personal</option>
            </select>
        </div>

        <div class="form-group">
            <label for="filter_status" style="font-weight:700;font-size:0.85rem;">Status</label>
            <select class="form-control form-control-sm" name="status" id="filter_status">
                <option value="">All</option>
                <option value="outstanding" <?= $filterStatus === 'outstanding' ? 'selected' : '' ?>>Outstanding</option>
                <option value="paid" <?= $filterStatus === 'paid' ? 'selected' : '' ?>>Paid</option>
            </select>
        </div>

        <div class="form-group">
            <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
            <a href="<?= e(url('leader_expenses_summary.php')) ?>" class="btn btn-link btn-sm">Clear</a>
        </div>
    </form>

    <!-- Expenses list -->
    <div class="les-card">
        <h3>Expenses (<?= count($expenses) ?>)</h3>

        <?php if (empty($expenses)): ?>
            <p class="text-muted">No expenses match your filters.</p>
        <?php else: ?>
            <?php foreach ($expenses as $exp): ?>
                <div class="expense-row">
                    <div class="expense-row-top">
                        <div style="flex:1;">
                            <strong><?= e($exp['leader_name'] ?? 'Unknown') ?></strong>
                            &mdash;
                            <span class="category-badge"><?= e(ucfirst($exp['category'])) ?></span>
                            <?php if ((int)$exp['is_corporate_card']): ?>
                                <span class="category-badge badge-corporate">Corporate</span>
                            <?php else: ?>
                                <span class="category-badge badge-personal">Personal</span>
                                <?php if ((int)$exp['is_reimbursed']): ?>
                                    <span class="category-badge badge-paid">Paid</span>
                                <?php else: ?>
                                    <span class="category-badge badge-outstanding">Outstanding</span>
                                <?php endif; ?>
                            <?php endif; ?>

                            <br>
                            <?= e($exp['description'] ?: '(No description)') ?>

                            <div class="tx-meta">
                                <?= e(date('j M Y', strtotime($exp['expense_date']))) ?>
                                <?php if ($exp['receipt_path']): ?>
                                    &middot; <a href="<?= e(url($exp['receipt_path'])) ?>" target="_blank">View receipt</a>
                                <?php endif; ?>
                                <?php if ((int)$exp['is_reimbursed'] && $exp['reimbursed_by_name']): ?>
                                    &middot; Paid by <?= e($exp['reimbursed_by_name']) ?>
                                    <?php if ($exp['reimbursed_at']): ?>
                                        on <?= e(date('j M Y', strtotime($exp['reimbursed_at']))) ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="tx-amount">
                            &euro;<?= number_format((float)$exp['amount'], 2) ?>
                        </div>
                    </div>

                    <div class="expense-row-actions">
                        <?php if (!(int)$exp['is_corporate_card'] && !(int)$exp['is_reimbursed']): ?>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="action" value="mark_paid">
                                <input type="hidden" name="expense_id" value="<?= (int)$exp['id'] ?>">
                                <button type="submit" class="btn btn-success btn-sm">Mark Paid</button>
                            </form>
                        <?php elseif (!(int)$exp['is_corporate_card'] && (int)$exp['is_reimbursed']): ?>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="action" value="mark_unpaid">
                                <input type="hidden" name="expense_id" value="<?= (int)$exp['id'] ?>">
                                <button type="submit" class="btn btn-outline-warning btn-sm">Undo Paid</button>
                            </form>
                        <?php endif; ?>

                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this expense?');">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="action" value="delete_expense">
                            <input type="hidden" name="expense_id" value="<?= (int)$exp['id'] ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<?php include __DIR__ . '/footer.php'; ?>
