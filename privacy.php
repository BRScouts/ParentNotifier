<?php
require_once __DIR__ . '/config.php';

$pageTitle = 'Privacy notice';
$contactEmail = 'rammyexplorers@gmail.com';

include __DIR__ . '/header.php';
?>

<style>
    .privacy-page {
        max-width: 980px;
    }

    .privacy-panel {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
    }

    .privacy-panel h2,
    .privacy-panel h3 {
        margin-top: 0;
        font-weight: 900;
    }

    .privacy-summary {
        border-left: 8px solid #1d70b8;
        background: #eef7ff;
        padding: 1rem;
        margin-bottom: 1.5rem;
    }

    .privacy-warning {
        border-left: 8px solid #ffdd00;
        background: #fff7bf;
        padding: 1rem;
        margin-bottom: 1.5rem;
    }

    .privacy-list {
        margin-bottom: 0;
    }

    .privacy-list li {
        margin-bottom: 0.45rem;
    }

    .privacy-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 1rem;
    }

    .privacy-table th,
    .privacy-table td {
        border: 2px solid #d8d8d8;
        padding: 0.75rem;
        vertical-align: top;
    }

    .privacy-table th {
        background: #f3f2f1;
        font-weight: 900;
    }

    .muted {
        color: #505a5f;
    }
</style>

<section class="page-hero">
    <div class="container">
        <h1>Privacy notice</h1>
        <p class="lead">
            How personal information is used for Explorer Belt 2026.
        </p>
    </div>
</section>

<main id="main-content" class="container my-5 privacy-page">

    <section class="privacy-summary">
        <h2>Summary</h2>

        <p>
            Explorer Belt 2026 collects and uses personal information so the event can be planned,
            managed and delivered safely. This includes information about young people, parents or carers,
            emergency contacts, leaders, medical needs, allergies, medication, welfare notes, team locations
            and event updates.
        </p>

        <p class="mb-0">
            We do not rely on consent for the core information needed to run the event safely. This information
            is needed so leaders can fulfil their responsibilities during the event.
        </p>
    </section>

    <section class="privacy-panel">
        <h2>Who is responsible for your information?</h2>

        <p>
            This privacy notice applies to the Explorer Belt 2026 event portal used by Bury and Ramsbottom
            Scouts / Rammy Explorers for event administration, safeguarding, welfare, communications and
            parent updates.
        </p>

        <p>
            For questions about this privacy notice or how personal information is used, contact:
        </p>

        <p>
            <strong>Data Protection Contact:</strong>
            <a href="mailto:<?= e($contactEmail) ?>"><?= e($contactEmail) ?></a>
        </p>
    </section>

    <section class="privacy-panel">
        <h2>What information we collect</h2>

        <p>
            Depending on your role and involvement in the event, we may collect and use:
        </p>

        <ul class="privacy-list">
            <li>Young person’s name, date of birth, team, photograph and event participation details.</li>
            <li>Parent, carer and emergency contact names, phone numbers and email addresses.</li>
            <li>Medical information, medication information, allergies, dietary needs and relevant welfare notes.</li>
            <li>First aid, medication, injury, illness or behaviour notes recorded during the event.</li>
            <li>Team location check-ins, approximate route progress and accommodation type.</li>
            <li>Leader names, contact details, role during the event, availability and schedule information.</li>
            <li>Portal access records, submitted forms, email queue records and system audit information.</li>
            <li>Photographs uploaded for identification, team management and event administration.</li>
        </ul>
    </section>

    <section class="privacy-panel">
        <h2>Why we use this information</h2>

        <p>
            We use this information to:
        </p>

        <ul class="privacy-list">
            <li>Plan and safely run Explorer Belt 2026.</li>
            <li>Identify young people and link them to the correct team.</li>
            <li>Contact parents, carers, emergency contacts and leaders when needed.</li>
            <li>Manage medical, allergy, medication, welfare and first aid needs.</li>
            <li>Record check-ins, approximate locations and accommodation information.</li>
            <li>Review team welfare information before anything is shared with parents.</li>
            <li>Send parent-facing updates and reassurance messages.</li>
            <li>Keep internal leader notes needed for safeguarding, first aid and event management.</li>
            <li>Support completion, verification and processing of the Explorer Belt award.</li>
            <li>Meet safeguarding, safety, insurance, legal and record-keeping obligations.</li>
        </ul>
    </section>

    <section class="privacy-panel">
        <h2>Our lawful basis</h2>

        <p>
            We do not rely on consent for the information needed to run the event safely. The main lawful
            bases we rely on are:
        </p>

        <table class="privacy-table">
            <thead>
                <tr>
                    <th>Type of information</th>
                    <th>Lawful basis</th>
                    <th>Reason</th>
                </tr>
            </thead>

            <tbody>
                <tr>
                    <td>General event administration information</td>
                    <td>Legitimate interests</td>
                    <td>
                        Necessary to organise, administer and safely run the event, communicate with families
                        and leaders, manage teams, and provide event updates.
                    </td>
                </tr>

                <tr>
                    <td>Safety, emergency and incident information</td>
                    <td>Vital interests and/or legal obligation where applicable</td>
                    <td>
                        Necessary to protect the health, safety and welfare of young people, leaders and others,
                        and to keep appropriate safety and incident records.
                    </td>
                </tr>

                <tr>
                    <td>Medical, allergy, medication and health information</td>
                    <td>Special category condition: vital interests, health/safety care, or substantial public interest where applicable</td>
                    <td>
                        Necessary so leaders can protect individuals, respond to illness or injury, support medical
                        needs, and manage risk during the event. Special category data requires an Article 6 lawful
                        basis plus an Article 9 condition. :contentReference[oaicite:1]{index=1}
                    </td>
                </tr>

                <tr>
                    <td>Accident, first aid and safeguarding records</td>
                    <td>Legal obligation, vital interests and legitimate interests where applicable</td>
                    <td>
                        Necessary for safety, safeguarding, insurance, legal claims, incident management and
                        local Scout record-keeping. Scouts guidance notes that accident reporting and retention
                        can involve legal obligation processing. :contentReference[oaicite:2]{index=2}
                    </td>
                </tr>
            </tbody>
        </table>
    </section>

    <section class="privacy-panel">
        <h2>Who can see the information</h2>

        <p>
            Access is limited to those who need the information for the event. This may include:
        </p>

        <ul class="privacy-list">
            <li>Explorer Belt leaders and home contacts.</li>
            <li>Event administrators who manage the portal and team records.</li>
            <li>Relevant Scout volunteers where needed for safeguarding, welfare, first aid or award administration.</li>
            <li>Parents or carers, but only for appropriate parent-facing updates and team progress information.</li>
        </ul>

        <div class="privacy-warning">
            <strong>Medical and welfare information is not parent-facing team update content.</strong>
            <p class="mb-0">
                If an Explorer check-in includes injuries, medication, first aid or welfare notes, leaders review
                that privately.
            </p>
        </div>
    </section>

    <section class="privacy-panel">
        <h2>Who we may share information with</h2>

        <p>
            We may share relevant information where necessary with:
        </p>

        <ul class="privacy-list">
            <li>Parents, carers or emergency contacts.</li>
            <li>Medical professionals, emergency services or local authorities where needed.</li>
            <li>The Scout Association, District, County, insurers or safeguarding teams where required.</li>
            <li>Service providers who support the website, email delivery, hosting or technical operation of the portal.</li>
        </ul>

        <p class="mb-0">
            We only share what is necessary for the relevant purpose.
        </p>
    </section>

    <section class="privacy-panel">
        <h2>How long we keep information</h2>

        <p>
            Information collected specifically for Explorer Belt 2026 is collected for the event and may be kept
            for up to <strong>3 months after the event</strong> so we can process the award, resolve queries,
            complete administration and close down the event records.
        </p>

        <p>
            Some records may need to be kept for longer. This may include medical, first aid, accident,
            safeguarding, insurance or legal records. These may be retained in line with the wider Bury and
            Ramsbottom Scouts privacy notice and applicable Scout retention requirements.
        </p>

        <p>
            The Bury and Ramsbottom Scouts privacy notice says some limited records may be kept for up to
            15 years, until age 21, for legal obligations, insurance and legal claims. :contentReference[oaicite:3]{index=3}
        </p>

        <p class="mb-0">
            Wider Scout Association retention guidance also recognises longer retention for some personal and
            sensitive records, particularly where insurance or claims may be involved. :contentReference[oaicite:4]{index=4}
        </p>
    </section>


    <section class="privacy-panel">
        <h2>Your rights</h2>

        <p>
            Under UK data protection law, you may have rights to:
        </p>

        <ul class="privacy-list">
            <li>Ask for a copy of personal information held about you or your child.</li>
            <li>Ask for inaccurate information to be corrected.</li>
            <li>Ask for information to be deleted, where this applies.</li>
            <li>Object to or restrict some types of processing, where this applies.</li>
            <li>Complain to the UK Information Commissioner’s Office.</li>
        </ul>

        <p>
            Some rights are limited where information has to be kept for safeguarding, legal, safety,
            insurance or event management reasons.
        </p>

        <p class="mb-0">
            To exercise a right, contact:
            <a href="mailto:<?= e($contactEmail) ?>"><?= e($contactEmail) ?></a>
        </p>
    </section>

    <section class="privacy-panel">
        <h2>Questions or concerns</h2>

        <p>
            Contact the Data Protection Contact:
            <a href="mailto:<?= e($contactEmail) ?>"><?= e($contactEmail) ?></a>
        </p>

        <p>
            You can also read the wider Bury and Ramsbottom Scouts privacy notice at:
            <a href="https://www.brscouts.org.uk/privacy/" target="_blank" rel="noopener">
                brscouts.org.uk/privacy/
            </a>
        </p>

        <p class="muted mb-0">
            Last updated: <?= e(date('d F Y')) ?>
        </p>
    </section>

</main>

<?php include __DIR__ . '/footer.php'; ?>