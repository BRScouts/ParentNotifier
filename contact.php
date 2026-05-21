<?php
require_once __DIR__ . '/auth.php';

$pdo = db();
$user = current_user();
$parentTeam = parent_access_team();

if (!$user && !$parentTeam) {
    redirect('403.php');
}

/**
 * Get the current home contact.
 */
$stmt = $pdo->prepare(
    'SELECT 
        l.id,
        l.name,
        l.email,
        l.phone,
        l.bio,
        l.photo_url,
        ls.schedule_start,
        ls.schedule_end,
        ls.notes
     FROM leader_schedules ls
     INNER JOIN leaders l ON l.id = ls.leader_id
     WHERE ls.status = "home_contact"
       AND l.is_active = 1
       AND NOW() BETWEEN ls.schedule_start AND ls.schedule_end
     ORDER BY ls.schedule_start ASC
     LIMIT 1'
);
$stmt->execute();
$currentHomeContact = $stmt->fetch();

/**
 * Get the next scheduled home contact, if there is no current one.
 */
$nextHomeContact = null;

if (!$currentHomeContact) {
    $stmt = $pdo->prepare(
        'SELECT 
            l.id,
            l.name,
            l.email,
            l.phone,
            l.bio,
            l.photo_url,
            ls.schedule_start,
            ls.schedule_end,
            ls.notes
         FROM leader_schedules ls
         INNER JOIN leaders l ON l.id = ls.leader_id
         WHERE ls.status = "home_contact"
           AND l.is_active = 1
           AND ls.schedule_start > NOW()
         ORDER BY ls.schedule_start ASC
         LIMIT 1'
    );
    $stmt->execute();
    $nextHomeContact = $stmt->fetch();
}

function contact_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $letters = '';

    foreach ($parts as $part) {
        if ($part !== '') {
            $letters .= strtoupper(substr($part, 0, 1));
        }

        if (strlen($letters) >= 2) {
            break;
        }
    }

    return $letters !== '' ? $letters : '?';
}

include __DIR__ . '/header.php';
?>

<style>
    .page-hero,
    .page-hero h1,
    .page-hero h2,
    .page-hero h3,
    .page-hero p,
    .page-hero .lead {
        color: #ffffff !important;
    }

    .contact-layout {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 380px;
        gap: 1.5rem;
        align-items: start;
    }

    @media (max-width: 980px) {
        .contact-layout {
            grid-template-columns: 1fr;
        }
    }

    .contact-panel,
    .contact-card {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
    }

    .contact-panel h2,
    .contact-card h2,
    .contact-card h3 {
        margin-top: 0;
        font-weight: 900;
    }

    .urgent-panel {
        border-left: 10px solid #d4351c;
        background: #fff1f0;
    }

    .info-panel {
        border-left: 10px solid #1d70b8;
        background: #eef7ff;
    }

    .home-contact-card {
        border: 2px solid #1d1d1d;
        background: #ffffff;
        padding: 1.25rem;
    }

    .home-contact-profile {
        display: grid;
        grid-template-columns: 86px minmax(0, 1fr);
        gap: 1rem;
        align-items: start;
    }

    @media (max-width: 520px) {
        .home-contact-profile {
            grid-template-columns: 72px minmax(0, 1fr);
        }
    }

    .home-contact-photo {
        width: 86px !important;
        height: 86px !important;
        min-width: 86px !important;
        min-height: 86px !important;
        max-width: 86px !important;
        max-height: 86px !important;
        object-fit: cover;
        border: 2px solid #1d1d1d;
        background: #7413dc;
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.8rem;
        font-weight: 900;
    }

    @media (max-width: 520px) {
        .home-contact-photo {
            width: 72px !important;
            height: 72px !important;
            min-width: 72px !important;
            min-height: 72px !important;
            max-width: 72px !important;
            max-height: 72px !important;
            font-size: 1.5rem;
        }
    }

    .home-contact-profile h3 {
        margin: 0;
        font-size: 1.3rem;
        font-weight: 900;
    }

    .contact-status {
        display: inline-block;
        border: 2px solid #1d1d1d;
        background: #1d70b8;
        color: #ffffff;
        padding: 0.3rem 0.55rem;
        font-weight: 900;
        margin-top: 0.5rem;
    }

    .contact-muted {
        color: #505a5f;
    }

    .contact-detail-list {
        margin: 1rem 0 0;
        padding: 0;
        list-style: none;
    }

    .contact-detail-list li {
        border-top: 1px solid #d8d8d8;
        padding: 0.65rem 0;
    }

    .contact-detail-list li:first-child {
        border-top: 0;
    }

    .placeholder-box {
        border: 2px dashed #b1b4b6;
        background: #f8f8f8;
        padding: 1rem;
    }

    .contact-steps {
        margin-bottom: 0;
    }

    .contact-steps li {
        margin-bottom: 0.5rem;
    }

    .contact-steps li:last-child {
        margin-bottom: 0;
    }
</style>

<section class="page-hero">
    <div class="container">
        <h1>Contact us</h1>
        <p class="lead">
            How to contact the Explorer Belt trip team during the trip.
        </p>
    </div>
</section>

<main id="main-content" class="container my-5">

    <div class="contact-layout">

        <div>

            <section class="contact-panel info-panel">
                <h2>Please use the home contact</h2>
                <p>
                    Please do not contact leaders in country directly unless you have been specifically asked to do so.
                    Leaders in country may be supporting young people, travelling, or managing the programme.
                </p>
                <p class="mb-0">
                    The home contact is the preferred point of contact for parents and guardians.
                </p>
            </section>

            <section class="contact-panel urgent-panel">
                <h2>Emergency guidance</h2>
                <p>
                    This section is placeholder information. Replace it with your agreed emergency procedure before the trip.
                </p>

                <ol class="contact-steps">
                    <li>
                        In a life-threatening emergency, contact the relevant emergency services first.
                    </li>
                    <li>
                        Contact the current home contact using the details provided by the trip team.
                    </li>
                    <li>
                        If you cannot reach the home contact, use the backup contact process agreed before departure.
                    </li>
                </ol>
            </section>

            <section class="contact-panel">
                <h2>General enquiries</h2>

                <div class="placeholder-box">
                    <p>
                        <strong>Placeholder group contact:</strong>
                        contact@example.org
                    </p>

                    <p>
                        <strong>Placeholder phone:</strong>
                        01234 567890
                    </p>

                    <p class="mb-0">
                        Replace these details with the correct group, district, or trip contact information.
                    </p>
                </div>
            </section>

            <section class="contact-panel">
                <h2>What to include when contacting us</h2>

                <ul class="contact-steps">
                    <li>Your name and relationship to the young person.</li>
                    <li>The young person’s name and team name.</li>
                    <li>A clear description of what you need help with.</li>
                    <li>The best number or email for us to contact you back on.</li>
                </ul>
            </section>

        </div>

        <aside>

            <section class="contact-card">
                <h2>Current home contact</h2>

                <?php if ($currentHomeContact): ?>
                    <div class="home-contact-card">
                        <div class="home-contact-profile">
                            <div>
                                <?php if (!empty($currentHomeContact['photo_url'])): ?>
                                    <img
                                        class="home-contact-photo"
                                        src="<?= e($currentHomeContact['photo_url']) ?>"
                                        alt="Photo of <?= e($currentHomeContact['name']) ?>"
                                    >
                                <?php else: ?>
                                    <div class="home-contact-photo" aria-hidden="true">
                                        <?= e(contact_initials($currentHomeContact['name'])) ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div>
                                <h3><?= e($currentHomeContact['name']) ?></h3>

                                <span class="contact-status">
                                    Home contact now
                                </span>

                                <p class="contact-muted mt-2 mb-0">
                                    <?= e(format_datetime($currentHomeContact['schedule_start'])) ?>
                                    to
                                    <?= e(format_datetime($currentHomeContact['schedule_end'])) ?>
                                </p>
                            </div>
                        </div>

                        <?php if (!empty($currentHomeContact['bio'])): ?>
                            <p class="mt-3">
                                <?= nl2br(e($currentHomeContact['bio'])) ?>
                            </p>
                        <?php endif; ?>

                        <?php if (!empty($currentHomeContact['notes'])): ?>
                            <p class="contact-muted">
                                <?= nl2br(e($currentHomeContact['notes'])) ?>
                            </p>
                        <?php endif; ?>

                        <ul class="contact-detail-list">
                            <?php if ($user): ?>
                                <?php if (!empty($currentHomeContact['phone'])): ?>
                                    <li>
                                        <strong>Phone:</strong>
                                        <?= e($currentHomeContact['phone']) ?>
                                    </li>
                                <?php endif; ?>

                                <?php if (!empty($currentHomeContact['email'])): ?>
                                    <li>
                                        <strong>Email:</strong>
                                        <a href="mailto:<?= e($currentHomeContact['email']) ?>">
                                            <?= e($currentHomeContact['email']) ?>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            <?php else: ?>
                                <li>
                                    Contact details will be shared through the agreed parent communication process.
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>

                <?php elseif ($nextHomeContact): ?>

                    <div class="home-contact-card">
                        <div class="home-contact-profile">
                            <div>
                                <?php if (!empty($nextHomeContact['photo_url'])): ?>
                                    <img
                                        class="home-contact-photo"
                                        src="<?= e($nextHomeContact['photo_url']) ?>"
                                        alt="Photo of <?= e($nextHomeContact['name']) ?>"
                                    >
                                <?php else: ?>
                                    <div class="home-contact-photo" aria-hidden="true">
                                        <?= e(contact_initials($nextHomeContact['name'])) ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div>
                                <h3><?= e($nextHomeContact['name']) ?></h3>

                                <span class="contact-status">
                                    Next home contact
                                </span>

                                <p class="contact-muted mt-2 mb-0">
                                    Starts:
                                    <?= e(format_datetime($nextHomeContact['schedule_start'])) ?>
                                </p>
                            </div>
                        </div>

                        <p class="mt-3 mb-0">
                            No home contact is currently scheduled, but the next home contact is listed above.
                        </p>
                    </div>

                <?php else: ?>

                    <div class="placeholder-box">
                        <p>
                            No home contact is currently scheduled.
                        </p>
                        <p class="mb-0">
                            Add a leader schedule entry with status <strong>Home contact</strong> to show someone here.
                        </p>
                    </div>

                <?php endif; ?>
            </section>

            <section class="contact-card">
                <h2>Useful links</h2>

                <ul class="contact-detail-list">
                    <li>
                        <a href="<?= e($user ? url('dashboard.php') : url('dashboard.php?token=' . $parentTeam['parent_token'])) ?>">
                            View latest updates
                        </a>
                    </li>

                    <li>
                        <a href="<?= e($parentTeam ? url('leaders.php?token=' . $parentTeam['parent_token']) : url('leaders.php')) ?>">
                            View leaders
                        </a>
                    </li>

                    <?php if ($user): ?>
                        <li>
                            <a href="<?= e(url('team_links.php')) ?>">
                                Manage teams
                            </a>
                        </li>

                        <li>
                            <a href="<?= e(url('leaders.php?tab=schedule')) ?>">
                                Manage leader schedule
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </section>

        </aside>

    </div>

</main>

<?php include __DIR__ . '/footer.php'; ?>