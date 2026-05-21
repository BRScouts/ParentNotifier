<?php
$isEdit = isset($formPerson) && is_array($formPerson);

$person = $isEdit ? $formPerson : [
    'id' => 0,
    'team_id' => null,
    'name' => '',
    'dob' => '',
    'photo_url' => '',
    'emergency_contacts_json' => null,
    'parent_emails_json' => null,
    'phones_json' => null,
    'medications_json' => null,
    'allergies_json' => null,
    'notes' => '',
    'is_active' => 1,
];

$contacts = json_items($person['emergency_contacts_json'] ?? null);
$parentEmails = json_items($person['parent_emails_json'] ?? null);
$phones = json_items($person['phones_json'] ?? null);
$medications = json_items($person['medications_json'] ?? null);
$allergies = json_items($person['allergies_json'] ?? null);

if (empty($contacts)) {
    $contacts[] = [
        'name' => '',
        'relationship' => '',
        'phone' => '',
        'email' => '',
    ];
}

if (empty($parentEmails)) {
    $parentEmails[] = '';
}

if (empty($phones)) {
    $phones[] = '';
}

if (empty($medications)) {
    $medications[] = '';
}

if (empty($allergies)) {
    $allergies[] = '';
}
?>

<style>
    .dynamic-section {
        border: 2px solid #d8d8d8;
        background: #ffffff;
        padding: 1rem;
        margin-bottom: 1.25rem;
    }

    .dynamic-section h3 {
        margin-top: 0;
        font-weight: 900;
    }

    .dynamic-row {
        border: 2px solid #d8d8d8;
        background: #f8f8f8;
        padding: 0.75rem;
        margin-bottom: 0.75rem;
    }

    .dynamic-row:last-child {
        margin-bottom: 0;
    }

    .dynamic-row-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr)) auto;
        gap: 0.5rem;
        align-items: end;
    }

    .dynamic-row-grid.simple {
        grid-template-columns: minmax(0, 1fr) auto;
    }

    @media (max-width: 900px) {
        .dynamic-row-grid,
        .dynamic-row-grid.simple {
            grid-template-columns: 1fr;
        }
    }

    .dynamic-row label {
        font-weight: 800;
        font-size: 0.9rem;
    }

    .dynamic-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-top: 0.75rem;
    }

    .remove-row-btn {
        white-space: nowrap;
    }
</style>

<form method="post" enctype="multipart/form-data">
    <input type="hidden" name="action" value="<?= $isEdit ? 'update_person' : 'add_person' ?>">

    <?php if ($isEdit): ?>
        <input type="hidden" name="person_id" value="<?= (int)$person['id'] ?>">
    <?php endif; ?>

    <section class="dynamic-section">
        <h3>Basic details</h3>

        <div class="simple-grid">
            <div class="form-group">
                <label for="person_name">Name</label>
                <input
                    class="form-control"
                    id="person_name"
                    name="name"
                    value="<?= e($person['name']) ?>"
                    required
                >
            </div>

            <div class="form-group">
                <label for="person_dob">Date of birth</label>
                <input
                    class="form-control"
                    id="person_dob"
                    type="date"
                    name="dob"
                    value="<?= e($person['dob'] ?? '') ?>"
                >
            </div>
        </div>

        <div class="simple-grid">
            <div class="form-group">
                <label for="person_team">Team</label>
                <select class="form-control" id="person_team" name="team_id">
                    <option value="0">Not assigned</option>

                    <?php foreach ($teams as $team): ?>
                        <option
                            value="<?= (int)$team['id'] ?>"
                            <?= (int)$team['id'] === (int)$person['team_id'] ? 'selected' : '' ?>
                        >
                            <?= e($team['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="profile_image">Profile image</label>
                <input
                    class="form-control"
                    id="profile_image"
                    type="file"
                    name="profile_image"
                    accept="image/jpeg,image/png,image/webp,image/gif"
                >

                <?php if ($isEdit && !empty($person['photo_url'])): ?>
                    <small class="form-text text-muted">
                        Current image: <?= e($person['photo_url']) ?>
                    </small>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="dynamic-section">
        <h3>Emergency contacts</h3>

        <div id="contactsRows">
            <?php foreach ($contacts as $contact): ?>
                <div class="dynamic-row">
                    <div class="dynamic-row-grid">
                        <div class="form-group mb-0">
                            <label>Name</label>
                            <input
                                class="form-control"
                                name="contact_name[]"
                                value="<?= e($contact['name'] ?? '') ?>"
                            >
                        </div>

                        <div class="form-group mb-0">
                            <label>Relationship</label>
                            <input
                                class="form-control"
                                name="contact_relationship[]"
                                value="<?= e($contact['relationship'] ?? '') ?>"
                            >
                        </div>

                        <div class="form-group mb-0">
                            <label>Phone</label>
                            <input
                                class="form-control"
                                name="contact_phone[]"
                                value="<?= e($contact['phone'] ?? '') ?>"
                            >
                        </div>

                        <div class="form-group mb-0">
                            <label>Email</label>
                            <input
                                class="form-control"
                                type="email"
                                name="contact_email[]"
                                value="<?= e($contact['email'] ?? '') ?>"
                            >
                        </div>

                        <button
                            type="button"
                            class="btn btn-outline-danger remove-row-btn"
                            data-remove-row
                        >
                            Remove
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="dynamic-actions">
            <button type="button" class="btn btn-outline-primary" id="addContactRow">
                Add contact
            </button>
        </div>
    </section>

    <section class="dynamic-section">
        <h3>Parent emails</h3>

        <div id="parentEmailRows">
            <?php foreach ($parentEmails as $email): ?>
                <div class="dynamic-row">
                    <div class="dynamic-row-grid simple">
                        <div class="form-group mb-0">
                            <label>Email</label>
                            <input
                                class="form-control"
                                type="email"
                                name="parent_emails[]"
                                value="<?= e((string)$email) ?>"
                            >
                        </div>

                        <button
                            type="button"
                            class="btn btn-outline-danger remove-row-btn"
                            data-remove-row
                        >
                            Remove
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="dynamic-actions">
            <button type="button" class="btn btn-outline-primary" id="addParentEmailRow">
                Add parent email
            </button>
        </div>
    </section>

    <section class="dynamic-section">
        <h3>Phone numbers</h3>

        <div id="phoneRows">
            <?php foreach ($phones as $phone): ?>
                <div class="dynamic-row">
                    <div class="dynamic-row-grid simple">
                        <div class="form-group mb-0">
                            <label>Phone</label>
                            <input
                                class="form-control"
                                name="phones[]"
                                value="<?= e((string)$phone) ?>"
                            >
                        </div>

                        <button
                            type="button"
                            class="btn btn-outline-danger remove-row-btn"
                            data-remove-row
                        >
                            Remove
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="dynamic-actions">
            <button type="button" class="btn btn-outline-primary" id="addPhoneRow">
                Add phone number
            </button>
        </div>
    </section>

    <section class="dynamic-section">
        <h3>Medications</h3>

        <div id="medicationRows">
            <?php foreach ($medications as $medication): ?>
                <div class="dynamic-row">
                    <div class="dynamic-row-grid simple">
                        <div class="form-group mb-0">
                            <label>Medication</label>
                            <input
                                class="form-control"
                                name="medications[]"
                                value="<?= e((string)$medication) ?>"
                            >
                        </div>

                        <button
                            type="button"
                            class="btn btn-outline-danger remove-row-btn"
                            data-remove-row
                        >
                            Remove
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="dynamic-actions">
            <button type="button" class="btn btn-outline-primary" id="addMedicationRow">
                Add medication
            </button>
        </div>
    </section>

    <section class="dynamic-section">
        <h3>Allergies</h3>

        <div id="allergyRows">
            <?php foreach ($allergies as $allergy): ?>
                <div class="dynamic-row">
                    <div class="dynamic-row-grid simple">
                        <div class="form-group mb-0">
                            <label>Allergy</label>
                            <input
                                class="form-control"
                                name="allergies[]"
                                value="<?= e((string)$allergy) ?>"
                            >
                        </div>

                        <button
                            type="button"
                            class="btn btn-outline-danger remove-row-btn"
                            data-remove-row
                        >
                            Remove
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="dynamic-actions">
            <button type="button" class="btn btn-outline-primary" id="addAllergyRow">
                Add allergy
            </button>
        </div>
    </section>

    <section class="dynamic-section">
        <h3>Notes</h3>

        <div class="form-group">
            <label for="person_notes">General notes</label>
            <textarea
                class="form-control"
                id="person_notes"
                name="notes"
                rows="5"
            ><?= e($person['notes'] ?? '') ?></textarea>
        </div>

        <div class="form-check mb-3">
            <input
                class="form-check-input"
                id="is_active"
                type="checkbox"
                name="is_active"
                <?= (int)$person['is_active'] === 1 ? 'checked' : '' ?>
            >
            <label class="form-check-label" for="is_active">
                Active
            </label>
        </div>
    </section>

    <button class="btn btn-primary">
        <?= $isEdit ? 'Save person' : 'Add person' ?>
    </button>
</form>

<template id="contactRowTemplate">
    <div class="dynamic-row">
        <div class="dynamic-row-grid">
            <div class="form-group mb-0">
                <label>Name</label>
                <input class="form-control" name="contact_name[]">
            </div>

            <div class="form-group mb-0">
                <label>Relationship</label>
                <input class="form-control" name="contact_relationship[]">
            </div>

            <div class="form-group mb-0">
                <label>Phone</label>
                <input class="form-control" name="contact_phone[]">
            </div>

            <div class="form-group mb-0">
                <label>Email</label>
                <input class="form-control" type="email" name="contact_email[]">
            </div>

            <button type="button" class="btn btn-outline-danger remove-row-btn" data-remove-row>
                Remove
            </button>
        </div>
    </div>
</template>

<template id="parentEmailRowTemplate">
    <div class="dynamic-row">
        <div class="dynamic-row-grid simple">
            <div class="form-group mb-0">
                <label>Email</label>
                <input class="form-control" type="email" name="parent_emails[]">
            </div>

            <button type="button" class="btn btn-outline-danger remove-row-btn" data-remove-row>
                Remove
            </button>
        </div>
    </div>
</template>

<template id="phoneRowTemplate">
    <div class="dynamic-row">
        <div class="dynamic-row-grid simple">
            <div class="form-group mb-0">
                <label>Phone</label>
                <input class="form-control" name="phones[]">
            </div>

            <button type="button" class="btn btn-outline-danger remove-row-btn" data-remove-row>
                Remove
            </button>
        </div>
    </div>
</template>

<template id="medicationRowTemplate">
    <div class="dynamic-row">
        <div class="dynamic-row-grid simple">
            <div class="form-group mb-0">
                <label>Medication</label>
                <input class="form-control" name="medications[]">
            </div>

            <button type="button" class="btn btn-outline-danger remove-row-btn" data-remove-row>
                Remove
            </button>
        </div>
    </div>
</template>

<template id="allergyRowTemplate">
    <div class="dynamic-row">
        <div class="dynamic-row-grid simple">
            <div class="form-group mb-0">
                <label>Allergy</label>
                <input class="form-control" name="allergies[]">
            </div>

            <button type="button" class="btn btn-outline-danger remove-row-btn" data-remove-row>
                Remove
            </button>
        </div>
    </div>
</template>

<script>
    (function () {
        function addRow(buttonId, targetId, templateId) {
            var button = document.getElementById(buttonId);
            var target = document.getElementById(targetId);
            var template = document.getElementById(templateId);

            if (!button || !target || !template) {
                return;
            }

            button.addEventListener('click', function () {
                var clone = template.content.cloneNode(true);
                target.appendChild(clone);
            });
        }

        document.addEventListener('click', function (event) {
            var button = event.target.closest('[data-remove-row]');

            if (!button) {
                return;
            }

            var row = button.closest('.dynamic-row');

            if (row) {
                row.remove();
            }
        });

        addRow('addContactRow', 'contactsRows', 'contactRowTemplate');
        addRow('addParentEmailRow', 'parentEmailRows', 'parentEmailRowTemplate');
        addRow('addPhoneRow', 'phoneRows', 'phoneRowTemplate');
        addRow('addMedicationRow', 'medicationRows', 'medicationRowTemplate');
        addRow('addAllergyRow', 'allergyRows', 'allergyRowTemplate');
    })();
</script>