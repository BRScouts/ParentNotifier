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
} catch (Throwable $e) {
    // Continue gracefully
}

// --- Determine which team to view ---
$teamId = (int)($_GET['team_id'] ?? 0);

// Fetch all teams for selector
$allTeams = [];
try {
    $stmt = $pdo->query('SELECT id, name FROM teams ORDER BY name ASC');
    $allTeams = $stmt->fetchAll();
} catch (Throwable $e) {
    $allTeams = [];
}

// Default to first team if none selected
if ($teamId === 0 && !empty($allTeams)) {
    $teamId = (int)$allTeams[0]['id'];
}

$currentTeam = null;
if ($teamId > 0) {
    try {
        $stmt = $pdo->prepare('SELECT * FROM teams WHERE id = ? LIMIT 1');
        $stmt->execute([$teamId]);
        $currentTeam = $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        $currentTeam = null;
    }
}

// --- CSRF ---
if (empty($_SESSION['expenses_manage_csrf'])) {
    $_SESSION['expenses_manage_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['expenses_manage_csrf'];

function em_csrf_valid(): bool
{
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

        // Add funds (credit)
        if ($action === 'add_funds') {
            $amount = trim($_POST['fund_amount'] ?? '');
            $description = trim($_POST['fund_description'] ?? '');

            if ($amount === '' || !is_numeric($amount) || (float)$amount <= 0) {
                throw new RuntimeException('Please enter a valid amount.');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO team_transactions
                    (team_id, type, amount, currency, category, description, submitted_by, transaction_date, created_by_leader_id)
                 VALUES
                    (?, "credit", ?, "EUR", "top_up", ?, ?, CURDATE(), ?)'
            );
            $stmt->execute([
                $teamId,
                round((float)$amount, 2),
                substr(strip_tags($description ?: 'Funds added'), 0, 500),
                $user['name'],
                (int)$user['id'],
            ]);

            $success = 'Funds added successfully.';
        }

        // Save/update card details
        if ($action === 'save_card') {
            $leaderName = trim($_POST['card_leader_name'] ?? '');
            $pinNumber = trim($_POST['card_pin_number'] ?? '');
            $cardDescription = trim($_POST['card_description'] ?? '');
            $initialBalance = trim($_POST['card_initial_balance'] ?? '0');

            if ($leaderName === '' || $pinNumber === '' || $cardDescription === '') {
                throw new RuntimeException('Please fill in all card fields.');
            }

            // Check if card already exists for this team
            $existingCard = $pdo->prepare('SELECT id FROM team_cards WHERE team_id = ? LIMIT 1');
            $existingCard->execute([$teamId]);
            $cardRow = $existingCard->fetch();

            if ($cardRow) {
                $stmt = $pdo->prepare(
                    'UPDATE team_cards SET leader_name = ?, pin_number = ?, card_description = ?, initial_balance = ? WHERE id = ?'
                );
                $stmt->execute([
                    substr(strip_tags($leaderName), 0, 150),
                    substr(strip_tags($pinNumber), 0, 20),
                    substr(strip_tags($cardDescription), 0, 255),
                    round((float)$initialBalance, 2),
                    (int)$cardRow['id'],
                ]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO team_cards (team_id, leader_name, pin_number, card_description, initial_balance) VALUES (?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $teamId,
                    substr(strip_tags($leaderName), 0, 150),
                    substr(strip_tags($pinNumber), 0, 20),
                    substr(strip_tags($cardDescription), 0, 255),
                    round((float)$initialBalance, 2),
                ]);
            }

            $success = 'Card details saved.';
        }

        // Delete transaction
        if ($action === 'delete_transaction') {
            $txId = (int)($_POST['transaction_id'] ?? 0);
            if ($txId > 0) {
                $stmt = $pdo->prepare('DELETE FROM team_transactions WHERE id = ? AND team_id = ?');
                $stmt->execute([$txId, $teamId]);
                $success = 'Transaction deleted.';
            }
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
        fputcsv($out, [
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

// --- Fetch data for display ---
$transactions = [];
$totalCredits = 0;
$totalDebits = 0;
$teamCard = null;

if ($currentTeam) {
    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM team_transactions WHERE team_id = ? ORDER BY transaction_date DESC, created_at DESC'
        );
        $stmt->execute([$teamId]);
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

    try {
        $stmt = $pdo->prepare('SELECT * FROM team_cards WHERE team_id = ? LIMIT 1');
        $stmt->execute([$teamId]);
        $teamCard = $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        $teamCard = null;
    }
}

$estimatedBalance = $totalCredits - $totalDebits;

include __DIR__ . '/header.php';
?>

<style>
    .expenses-header {
        background: #7413dc;
        color: #fff;
        padding: 1.25rem 1.5rem;
        margin-bottom: 1.5rem;
    }

    .expenses-header h1 {
        font-weight: 900;
        font-size: 1.5rem;
        margin: 0;
        color: #fff;
    }

    .expense-card {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 1.25rem 1.5rem;
        margin-bottom: 1.5rem;
    }

    .expense-card h3 {
        font-weight: 900;
        margin-top: 0;
    }

    .balance-banner {
        background: #7413dc;
        color: #ffffff;
        padding: 1.25rem 1.5rem;
        margin-bottom: 1.5rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
    }

    .balance-banner h3 {
        color: #ffffff;
        font-weight: 900;
        margin: 0;
    }

    .balance-banner .balance-amount {
        font-size: 2rem;
        font-weight: 900;
    }

    .balance-banner .balance-detail {
        font-size: 0.9rem;
        opacity: 0.85;
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

    .tx-amount-debit { color: #d4351c; font-weight: 800; }
    .tx-amount-credit { color: #00703c; font-weight: 800; }
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

    .team-selector {
        margin-bottom: 1rem;
    }
</style>

<div class="container-fluid px-4 py-3">

    <div class="expenses-header">
        <h1>Team Expenses &amp; Card Management</h1>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

    <!-- Team selector -->
    <div class="team-selector">
        <form method="get" class="form-inline">
            <label class="mr-2" for="team_id"><strong>Team:</strong></label>
            <select class="form-control mr-2" name="team_id" id="team_id" onchange="this.form.submit()">
                <?php foreach ($allTeams as $t): ?>
                    <option value="<?= (int)$t['id'] ?>" <?= (int)$t['id'] === $teamId ? 'selected' : '' ?>>
                        <?= e($t['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <noscript><button type="submit" class="btn btn-secondary btn-sm">Go</button></noscript>
        </form>
    </div>

    <?php if ($currentTeam): ?>

    <!-- Balance banner -->
    <div class="balance-banner">
        <div>
            <h3>Estimated Balance</h3>
            <div class="balance-amount">&euro;<?= number_format($estimatedBalance, 2) ?></div>
            <div class="balance-detail">
                Loaded: &euro;<?= number_format($totalCredits, 2) ?> &middot;
                Spent: &euro;<?= number_format($totalDebits, 2) ?> &middot;
                Transactions: <?= count($transactions) ?>
            </div>
        </div>
        <div>
            <a href="?team_id=<?= $teamId ?>&export=csv" class="btn btn-light btn-sm">
                Export CSV
            </a>
        </div>
    </div>

    <div class="row">
        <!-- Left column: Card details + Add funds -->
        <div class="col-lg-4 mb-3">

            <!-- Card details -->
            <div class="expense-card">
                <h3>Travel Card</h3>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="save_card">

                    <div class="form-group">
                        <label for="card_leader_name">Leader name (card holder)</label>
                        <input type="text" class="form-control" id="card_leader_name" name="card_leader_name"
                               value="<?= e($teamCard['leader_name'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="card_pin_number">PIN number</label>
                        <input type="text" class="form-control" id="card_pin_number" name="card_pin_number"
                               value="<?= e($teamCard['pin_number'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="card_description">Card type / provider</label>
                        <input type="text" class="form-control" id="card_description" name="card_description"
                               placeholder="e.g. Post Office, Asda, Revolut"
                               value="<?= e($teamCard['card_description'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="card_initial_balance">Initial balance loaded (&euro;)</label>
                        <input type="number" class="form-control" id="card_initial_balance" name="card_initial_balance"
                               step="0.01" min="0"
                               value="<?= e($teamCard['initial_balance'] ?? '0.00') ?>">
                    </div>

                    <button type="submit" class="btn btn-primary btn-block">Save Card Details</button>
                </form>
            </div>

            <!-- Add funds -->
            <div class="expense-card">
                <h3>Add Funds</h3>
                <p class="text-muted" style="font-size:0.9rem;">Record a top-up to the team card.</p>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="add_funds">

                    <div class="form-group">
                        <label for="fund_amount">Amount (&euro;)</label>
                        <input type="number" class="form-control" id="fund_amount" name="fund_amount"
                               step="0.01" min="0.01" placeholder="e.g. 160.00" required>
                    </div>

                    <div class="form-group">
                        <label for="fund_description">Note (optional)</label>
                        <input type="text" class="form-control" id="fund_description" name="fund_description"
                               placeholder="e.g. Top-up at Post Office" maxlength="500">
                    </div>

                    <button type="submit" class="btn btn-success btn-block">Add Funds</button>
                </form>
            </div>
        </div>

        <!-- Right column: Transaction history -->
        <div class="col-lg-8 mb-3">
            <div class="expense-card">
                <h3>Transaction History</h3>

                <?php if (empty($transactions)): ?>
                    <p class="text-muted">No transactions recorded yet.</p>
                <?php else: ?>
                    <?php foreach ($transactions as $tx): ?>
                        <div class="transaction-row">
                            <div style="flex:1;">
                                <?php if ($tx['type'] === 'credit'): ?>
                                    <strong style="color:#00703c;">Funds added</strong>
                                <?php else: ?>
                                    <span class="category-badge"><?= e(ucfirst($tx['category'] ?? 'other')) ?></span>
                                    <strong><?= e($tx['description'] ?: 'Expense') ?></strong>
                                <?php endif; ?>
                                <div class="tx-meta">
                                    <?= e(date('j M Y', strtotime($tx['transaction_date']))) ?>
                                    &middot; by <?= e($tx['submitted_by']) ?>
                                    <?php if ($tx['receipt_path']): ?>
                                        &middot; <a href="<?= e(url($tx['receipt_path'])) ?>" target="_blank">View receipt</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div style="text-align:right;">
                                <?php if ($tx['type'] === 'credit'): ?>
                                    <span class="tx-amount-credit">+&euro;<?= number_format((float)$tx['amount'], 2) ?></span>
                                <?php else: ?>
                                    <span class="tx-amount-debit">-&euro;<?= number_format((float)$tx['amount'], 2) ?></span>
                                <?php endif; ?>

                                <form method="post" style="display:inline; margin-left:0.5rem;" onsubmit="return confirm('Delete this transaction?');">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="delete_transaction">
                                    <input type="hidden" name="transaction_id" value="<?= (int)$tx['id'] ?>">
                                    <button type="submit" class="btn btn-link btn-sm text-danger p-0" title="Delete">
                                        &times;
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php else: ?>
        <div class="alert alert-warning">No teams found. Please create a team first.</div>
    <?php endif; ?>

</div>

<?php include __DIR__ . '/footer.php'; ?>
