<?php
require_once __DIR__ . '/auth.php';

require_login();

$pdo = db();
$user = current_user();

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

// --- Fetch all teams with their totals ---
$teamSummaries = [];
$grandTotalCredits = 0;
$grandTotalDebits = 0;
$categoryTotals = [];

try {
    $teams = $pdo->query('SELECT id, name FROM teams ORDER BY name ASC')->fetchAll();

    foreach ($teams as $t) {
        $stmt = $pdo->prepare(
            'SELECT type, SUM(amount) as total FROM team_transactions WHERE team_id = ? GROUP BY type'
        );
        $stmt->execute([(int)$t['id']]);
        $totals = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $credits = (float)($totals['credit'] ?? 0);
        $debits = (float)($totals['debit'] ?? 0);
        $balance = $credits - $debits;

        $grandTotalCredits += $credits;
        $grandTotalDebits += $debits;

        // Transaction count
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM team_transactions WHERE team_id = ?');
        $countStmt->execute([(int)$t['id']]);
        $txCount = (int)$countStmt->fetchColumn();

        $teamSummaries[] = [
            'id' => (int)$t['id'],
            'name' => $t['name'],
            'credits' => $credits,
            'debits' => $debits,
            'balance' => $balance,
            'tx_count' => $txCount,
        ];
    }

    // Category breakdown across all teams
    $catStmt = $pdo->query(
        'SELECT category, SUM(amount) as total, COUNT(*) as tx_count
         FROM team_transactions
         WHERE type = "debit" AND category IS NOT NULL AND category != ""
         GROUP BY category
         ORDER BY total DESC'
    );
    $categoryTotals = $catStmt->fetchAll();
} catch (Throwable $e) {
    $teamSummaries = [];
    $categoryTotals = [];
}

$grandBalance = $grandTotalCredits - $grandTotalDebits;

// --- CSV Export: all teams combined ---
if (($_GET['export'] ?? '') === 'csv_all') {
    $stmt = $pdo->query(
        'SELECT t.name as team_name, tx.type, tx.amount, tx.currency, tx.category, tx.description,
                tx.submitted_by, tx.transaction_date, tx.created_at
         FROM team_transactions tx
         JOIN teams t ON t.id = tx.team_id
         ORDER BY tx.transaction_date ASC, tx.created_at ASC'
    );
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="all_team_expenses_' . date('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Team', 'Date', 'Type', 'Amount (EUR)', 'Category', 'Description', 'Submitted By', 'Recorded At']);

    foreach ($rows as $row) {
        fputcsv($out, [
            $row['team_name'],
            $row['transaction_date'],
            $row['type'] === 'credit' ? 'Credit' : 'Debit',
            number_format((float)$row['amount'], 2, '.', ''),
            $row['category'] ?? '',
            $row['description'] ?? '',
            $row['submitted_by'],
            $row['created_at'],
        ]);
    }

    fclose($out);
    exit;
}

include __DIR__ . '/header.php';
?>

<style>
    .accounting-header {
        background: #7413dc;
        color: #fff;
        padding: 1.25rem 1.5rem;
        margin-bottom: 1.5rem;
    }

    .accounting-header h1 {
        font-weight: 900;
        font-size: 1.5rem;
        margin: 0;
        color: #fff;
    }

    .accounting-header p {
        margin: 0.25rem 0 0;
        opacity: 0.85;
    }

    .summary-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .summary-card {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 1.25rem;
        text-align: center;
    }

    .summary-card .sc-label {
        font-size: 0.85rem;
        font-weight: 700;
        color: #505a5f;
        text-transform: uppercase;
        letter-spacing: 0.02em;
    }

    .summary-card .sc-value {
        font-size: 1.75rem;
        font-weight: 900;
        margin-top: 0.25rem;
    }

    .sc-value-green { color: #00703c; }
    .sc-value-red { color: #d4351c; }
    .sc-value-purple { color: #7413dc; }

    .accounting-card {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 1.25rem 1.5rem;
        margin-bottom: 1.5rem;
    }

    .accounting-card h3 {
        font-weight: 900;
        margin-top: 0;
    }

    .team-row {
        border-bottom: 1px solid #d8d8d8;
        padding: 0.75rem 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .team-row:last-child {
        border-bottom: none;
    }

    .team-row-name {
        font-weight: 800;
    }

    .team-row-stats {
        font-size: 0.9rem;
        color: #505a5f;
    }

    .team-row-balance {
        font-weight: 800;
        font-size: 1.1rem;
    }

    .balance-positive { color: #00703c; }
    .balance-negative { color: #d4351c; }

    .cat-bar {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.5rem 0;
        border-bottom: 1px solid #f3f2f1;
    }

    .cat-bar:last-child {
        border-bottom: none;
    }

    .cat-bar-label {
        min-width: 100px;
        font-weight: 700;
        font-size: 0.9rem;
    }

    .cat-bar-fill {
        height: 22px;
        background: #7413dc;
        transition: width 0.3s;
    }

    .cat-bar-amount {
        font-weight: 800;
        font-size: 0.9rem;
        min-width: 80px;
        text-align: right;
    }
</style>

<div class="container-fluid px-4 py-3">

    <div class="accounting-header">
        <h1>Expense Accounting</h1>
        <p>Overview of all team spending &mdash; for treasurer reporting</p>
    </div>

    <!-- Grand totals -->
    <div class="summary-cards">
        <div class="summary-card">
            <div class="sc-label">Total Loaded</div>
            <div class="sc-value sc-value-green">&euro;<?= number_format($grandTotalCredits, 2) ?></div>
        </div>
        <div class="summary-card">
            <div class="sc-label">Total Spent</div>
            <div class="sc-value sc-value-red">&euro;<?= number_format($grandTotalDebits, 2) ?></div>
        </div>
        <div class="summary-card">
            <div class="sc-label">Combined Balance</div>
            <div class="sc-value sc-value-purple">&euro;<?= number_format($grandBalance, 2) ?></div>
        </div>
        <div class="summary-card">
            <div class="sc-label">Total Transactions</div>
            <div class="sc-value"><?= array_sum(array_column($teamSummaries, 'tx_count')) ?></div>
        </div>
    </div>

    <!-- Export button -->
    <div class="mb-3">
        <a href="?export=csv_all" class="btn btn-primary btn-sm">
            Export All Teams CSV (for Treasurer)
        </a>
        <a href="<?= e(url('expenses_manage.php')) ?>" class="btn btn-outline-secondary btn-sm ml-2">
            Manage Team Expenses
        </a>
    </div>

    <div class="row">
        <!-- Team breakdown -->
        <div class="col-lg-7 mb-3">
            <div class="accounting-card">
                <h3>By Team</h3>

                <?php if (empty($teamSummaries)): ?>
                    <p class="text-muted">No teams found.</p>
                <?php else: ?>
                    <?php foreach ($teamSummaries as $ts): ?>
                        <div class="team-row">
                            <div>
                                <div class="team-row-name"><?= e($ts['name']) ?></div>
                                <div class="team-row-stats">
                                    <?= $ts['tx_count'] ?> transaction<?= $ts['tx_count'] !== 1 ? 's' : '' ?>
                                    &middot; Loaded: &euro;<?= number_format($ts['credits'], 2) ?>
                                    &middot; Spent: &euro;<?= number_format($ts['debits'], 2) ?>
                                </div>
                            </div>
                            <div class="team-row-balance <?= $ts['balance'] >= 0 ? 'balance-positive' : 'balance-negative' ?>">
                                &euro;<?= number_format($ts['balance'], 2) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Category breakdown -->
        <div class="col-lg-5 mb-3">
            <div class="accounting-card">
                <h3>Spending by Category</h3>

                <?php if (empty($categoryTotals)): ?>
                    <p class="text-muted">No expenses recorded yet.</p>
                <?php else: ?>
                    <?php
                    $maxCat = max(array_column($categoryTotals, 'total'));
                    $categoryLabels = [
                        'food' => 'Food & Drink',
                        'camping' => 'Camping',
                        'supplies' => 'Supplies',
                        'travel' => 'Travel',
                        'top_up' => 'Top Up',
                        'other' => 'Other',
                    ];
                    ?>
                    <?php foreach ($categoryTotals as $cat): ?>
                        <?php
                        $pct = $maxCat > 0 ? ((float)$cat['total'] / $maxCat) * 100 : 0;
                        $label = $categoryLabels[$cat['category']] ?? ucfirst($cat['category']);
                        ?>
                        <div class="cat-bar">
                            <div class="cat-bar-label"><?= e($label) ?></div>
                            <div style="flex:1;">
                                <div class="cat-bar-fill" style="width:<?= round($pct) ?>%;"></div>
                            </div>
                            <div class="cat-bar-amount">&euro;<?= number_format((float)$cat['total'], 2) ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<?php include __DIR__ . '/footer.php'; ?>
