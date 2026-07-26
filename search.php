<?php
require_once __DIR__ . '/auth.php';

require_login();

$pdo = db();
$user = current_user();

$query = trim($_GET['q'] ?? '');
$results = [];
$searched = $query !== '';

if ($searched && mb_strlen($query) >= 2) {
    $searchTerm = '%' . $query . '%';

    // Search young_people table: name, email, phone, DOB, parent emails, emergency contacts, phones
    $sql = "
        SELECT 
            yp.id,
            yp.name,
            yp.dob,
            yp.participant_email,
            yp.participant_phone,
            yp.parent_emails_json,
            yp.emergency_contacts_json,
            yp.phones_json,
            yp.photo_url,
            yp.is_active,
            t.name AS team_name
        FROM young_people yp
        LEFT JOIN teams t ON t.id = yp.team_id
        WHERE yp.name LIKE ?
           OR yp.participant_email LIKE ?
           OR yp.participant_phone LIKE ?
           OR yp.dob LIKE ?
           OR yp.parent_emails_json LIKE ?
           OR yp.emergency_contacts_json LIKE ?
           OR yp.phones_json LIKE ?
           OR yp.home_address LIKE ?
        ORDER BY yp.name ASC
        LIMIT 50
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $searchTerm,
        $searchTerm,
        $searchTerm,
        $searchTerm,
        $searchTerm,
        $searchTerm,
        $searchTerm,
        $searchTerm,
    ]);

    $results = $stmt->fetchAll();
}

/**
 * Highlight search term in text
 */
function search_highlight(string $text, string $term): string
{
    if ($term === '') {
        return e($text);
    }

    $escaped = e($text);
    $escapedTerm = e($term);

    return str_ireplace(
        $escapedTerm,
        '<mark>' . $escapedTerm . '</mark>',
        $escaped
    );
}

/**
 * Extract matching info from emergency contacts JSON
 */
function search_matching_contacts(string $json, string $query): array
{
    $contacts = json_decode($json, true);
    $matches = [];

    if (!is_array($contacts)) {
        return $matches;
    }

    foreach ($contacts as $contact) {
        $fields = [
            'name' => $contact['name'] ?? '',
            'phone' => $contact['phone'] ?? '',
            'mobile_phone' => $contact['mobile_phone'] ?? '',
            'home_phone' => $contact['home_phone'] ?? '',
            'email' => $contact['email'] ?? '',
            'relationship' => $contact['relationship'] ?? '',
        ];

        foreach ($fields as $key => $value) {
            if ($value !== '' && stripos($value, $query) !== false) {
                $label = match ($key) {
                    'name' => 'Contact name',
                    'phone', 'mobile_phone' => 'Contact mobile',
                    'home_phone' => 'Contact home phone',
                    'email' => 'Contact email',
                    'relationship' => 'Relationship',
                    default => $key,
                };

                $matches[] = [
                    'label' => $label,
                    'value' => $value,
                    'contact_name' => $contact['name'] ?? '',
                ];
            }
        }
    }

    return $matches;
}

/**
 * Extract matching parent emails
 */
function search_matching_parent_emails(string $json, string $query): array
{
    $emails = json_decode($json, true);
    $matches = [];

    if (!is_array($emails)) {
        return $matches;
    }

    foreach ($emails as $email) {
        if (is_string($email) && stripos($email, $query) !== false) {
            $matches[] = $email;
        }
    }

    return $matches;
}

/**
 * Extract matching phones from phones_json
 */
function search_matching_phones(string $json, string $query): array
{
    $phones = json_decode($json, true);
    $matches = [];

    if (!is_array($phones)) {
        return $matches;
    }

    foreach ($phones as $phone) {
        if (is_string($phone) && stripos($phone, $query) !== false) {
            $matches[] = $phone;
        }
    }

    return $matches;
}

require_once __DIR__ . '/header.php';
?>

<main class="container" style="padding-top: 2rem; padding-bottom: 3rem;">
    <div class="search-results-page">
        <h1 class="search-results-title">Search</h1>

        <form class="search-results-form" action="<?= e(url('search.php')) ?>" method="get" role="search">
            <div class="search-results-input-wrap">
                <input
                    type="search"
                    name="q"
                    class="form-control search-results-input"
                    placeholder="Search by name, email, phone number, DOB..."
                    value="<?= e($query) ?>"
                    autofocus
                    aria-label="Search participants"
                >
                <button type="submit" class="btn btn-primary search-results-btn">Search</button>
            </div>
            <p class="search-help-text">
                Search participants by name, email address, phone number, date of birth, or parent/emergency contact details.
            </p>
        </form>

        <?php if ($searched && $query !== '' && mb_strlen($query) < 2): ?>
            <div class="alert alert-warning mt-3">
                Please enter at least 2 characters to search.
            </div>
        <?php elseif ($searched && empty($results)): ?>
            <div class="alert alert-info mt-3">
                No results found for <strong><?= e($query) ?></strong>. Try a different search term.
            </div>
        <?php elseif (!empty($results)): ?>
            <p class="search-results-count">
                Found <strong><?= count($results) ?></strong> result<?= count($results) !== 1 ? 's' : '' ?> for <strong><?= e($query) ?></strong>
            </p>

            <div class="search-results-list">
                <?php foreach ($results as $person): ?>
                    <?php
                    $matchReasons = [];

                    // Check which fields matched
                    if (stripos($person['name'] ?? '', $query) !== false) {
                        $matchReasons[] = '<span class="match-badge match-badge-name">Name match</span>';
                    }

                    if (stripos($person['participant_email'] ?? '', $query) !== false) {
                        $matchReasons[] = '<span class="match-badge match-badge-email">Email: ' . e($person['participant_email']) . '</span>';
                    }

                    if (stripos($person['participant_phone'] ?? '', $query) !== false) {
                        $matchReasons[] = '<span class="match-badge match-badge-phone">Phone: ' . e($person['participant_phone']) . '</span>';
                    }

                    if (stripos($person['dob'] ?? '', $query) !== false) {
                        $matchReasons[] = '<span class="match-badge match-badge-dob">DOB match</span>';
                    }

                    // Parent emails
                    $parentEmailMatches = search_matching_parent_emails($person['parent_emails_json'] ?? '', $query);
                    foreach ($parentEmailMatches as $parentEmail) {
                        $matchReasons[] = '<span class="match-badge match-badge-parent">Parent email: ' . e($parentEmail) . '</span>';
                    }

                    // Emergency contacts
                    $contactMatches = search_matching_contacts($person['emergency_contacts_json'] ?? '', $query);
                    foreach ($contactMatches as $match) {
                        $matchReasons[] = '<span class="match-badge match-badge-contact">' . e($match['label']) . ': ' . e($match['value']) . '</span>';
                    }

                    // Phones JSON
                    $phoneMatches = search_matching_phones($person['phones_json'] ?? '', $query);
                    foreach ($phoneMatches as $phone) {
                        $matchReasons[] = '<span class="match-badge match-badge-phone">Phone: ' . e($phone) . '</span>';
                    }

                    $personUrl = url('people.php?person_id=' . (int)$person['id']);
                    $isActive = (int)($person['is_active'] ?? 1) === 1;
                    ?>
                    <a href="<?= e($personUrl) ?>" class="search-result-card<?= !$isActive ? ' search-result-inactive' : '' ?>">
                        <div class="search-result-main">
                            <div class="search-result-name">
                                <?= search_highlight($person['name'] ?? 'Unknown', $query) ?>
                                <?php if (!$isActive): ?>
                                    <span class="badge badge-secondary ml-1">Inactive</span>
                                <?php endif; ?>
                            </div>

                            <div class="search-result-meta">
                                <?php if (!empty($person['team_name'])): ?>
                                    <span class="search-result-team"><?= e($person['team_name']) ?></span>
                                <?php endif; ?>

                                <?php if (!empty($person['dob'])): ?>
                                    <span class="search-result-dob">DOB: <?= e(date('d/m/Y', strtotime($person['dob']))) ?></span>
                                <?php endif; ?>

                                <?php if (!empty($person['participant_email'])): ?>
                                    <span class="search-result-email"><?= e($person['participant_email']) ?></span>
                                <?php endif; ?>

                                <?php if (!empty($person['participant_phone'])): ?>
                                    <span class="search-result-phone"><?= e($person['participant_phone']) ?></span>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($matchReasons)): ?>
                                <div class="search-result-matches">
                                    <?= implode(' ', $matchReasons) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="search-result-arrow" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16">
                                <path fill-rule="evenodd" d="M4.646 1.646a.5.5 0 0 1 .708 0l6 6a.5.5 0 0 1 0 .708l-6 6a.5.5 0 0 1-.708-.708L10.293 8 4.646 2.354a.5.5 0 0 1 0-.708z"/>
                            </svg>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if (count($results) >= 50): ?>
                <p class="text-muted mt-3" style="font-size: 0.9rem;">
                    Showing first 50 results. Try a more specific search term to narrow down.
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

<?php require_once __DIR__ . '/footer.php'; ?>
