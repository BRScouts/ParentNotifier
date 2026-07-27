<?php
require_once __DIR__ . '/auth.php';

require_login();

$pdo = db();
$user = current_user();

include __DIR__ . '/header.php';
?>

<main id="main-content" class="container my-4">
    <div class="panel panel-grey p-4">
        <h1 class="mb-3" style="font-weight: 900; font-size: 1.75rem;">Leader Help Guide</h1>
        <p class="text-muted mb-4">
            A quick reference for common tasks in <?= e(APP_NAME) ?>. Use the search below to find what you need.
        </p>

        <!-- Search -->
        <div class="mb-4">
            <div class="input-group" style="max-width: 500px;">
                <input
                    type="search"
                    id="helpSearch"
                    class="form-control"
                    placeholder="Search help articles..."
                    aria-label="Search help articles"
                    autocomplete="off"
                >
                <div class="input-group-append">
                    <span class="input-group-text" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                            <path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85zm-5.242.656a5 5 0 1 1 0-10 5 5 0 0 1 0 10z"/>
                        </svg>
                    </span>
                </div>
            </div>
            <p id="helpSearchNoResults" class="text-danger mt-2 mb-0" style="display: none; font-weight: 700;">
                No matching articles found. Try a different search term.
            </p>
        </div>

        <!-- Table of Contents -->
        <div class="mb-4 help-toc">
            <h2 style="font-weight: 800; font-size: 1.1rem;">Contents</h2>
            <ol style="padding-left: 1.25rem;">
                <li><a href="#help-checkin">Approve a Check-In (Parent Notification)</a></li>
                <li><a href="#help-links">How Links Work</a></li>
                <li><a href="#help-mass-email">How to Send a Mass Email</a></li>
                <li><a href="#help-announcement">How to Send an Announcement</a></li>
                <li><a href="#help-edit-person">How to Edit a Person</a></li>
                <li><a href="#help-logs">How to Add a First Aid / Behaviour Log</a></li>
                <li><a href="#help-parent-view">What Parents See</a></li>
                <li><a href="#help-team-view">What Teams See</a></li>
                <li><a href="#help-troubleshooting">Troubleshooting</a></li>
            </ol>
        </div>

        <hr>

        <!-- Section 1: Approve a Check-In -->
        <section class="help-section mb-5" id="help-checkin">
            <h2 style="font-weight: 800; font-size: 1.3rem;">1. Approve a Check-In</h2>
            <p>When a team submits a check-in, it appears on the <a href="<?= e(url('team_links.php')) ?>"><strong>Teams</strong></a> page awaiting leader approval.</p>

            <h3 style="font-weight: 700; font-size: 1.05rem;">Steps:</h3>
            <ol>
                <li>Go to <a href="<?= e(url('team_links.php')) ?>"><strong>Teams</strong></a> to see all teams and their current check-in status.</li>
                <li>Look at the colour-coded team cards to quickly identify which teams need attention (see below).</li>
                <li>Click the team card to open it, then go to the <strong>"Pending reviews"</strong> tab.</li>
                <li>Review the check-in details (location, team status, welfare notes).</li>
                <li>Click <strong>"Approve"</strong> to confirm the check-in is valid.</li>
                <li>Once approved, the system automatically sends a push notification and/or email to the parents of that team letting them know their explorer has checked in safely.</li>
            </ol>

            <h3 style="font-weight: 700; font-size: 1.05rem;">Team card status colours:</h3>
            <p>Each team card on the overview has a coloured border showing its check-in status at a glance:</p>

            <!-- Visual examples of team cards -->
            <div style="display: flex; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;">
                <div style="border: 4px solid #00703c; background: #ffffff; padding: 1rem 1.25rem; min-width: 200px; flex: 1; max-width: 280px;">
                    <div style="font-weight: 900; font-size: 1.05rem;">Team Example</div>
                    <div style="font-size: 0.9rem; color: #00703c; font-weight: 700; margin-top: 0.25rem;">Parents notified</div>
                    <div style="font-size: 0.8rem; color: #505a5f; margin-top: 0.25rem;">Last approved: 26 Jul, 18:30</div>
                </div>

                <div style="border: 4px solid transparent; background: linear-gradient(#ffffff, #ffffff) padding-box, repeating-linear-gradient(45deg, #00703c 0, #00703c 10px, #ffdd00 10px, #ffdd00 20px) border-box; padding: 1rem 1.25rem; min-width: 200px; flex: 1; max-width: 280px;">
                    <div style="font-weight: 900; font-size: 1.05rem;">Team Example</div>
                    <div style="font-size: 0.9rem; color: #1d1d1d; font-weight: 700; margin-top: 0.25rem;">Submitted, pending review</div>
                    <div style="font-size: 0.8rem; color: #505a5f; margin-top: 0.25rem;">Waiting for leader review</div>
                </div>

                <div style="border: 4px solid #ffdd00; background: #fff7bf; padding: 1rem 1.25rem; min-width: 200px; flex: 1; max-width: 280px;">
                    <div style="font-weight: 900; font-size: 1.05rem;">Team Example</div>
                    <div style="font-size: 0.9rem; color: #1d1d1d; font-weight: 700; margin-top: 0.25rem;">No check-in after 19:00 Finland</div>
                    <div style="font-size: 0.8rem; color: #505a5f; margin-top: 0.25rem;">No approved check-in today</div>
                </div>

                <div style="border: 4px solid #b1b4b6; background: #f8f8f8; padding: 1rem 1.25rem; min-width: 200px; flex: 1; max-width: 280px;">
                    <div style="font-weight: 900; font-size: 1.05rem;">Team Example</div>
                    <div style="font-size: 0.9rem; color: #505a5f; font-weight: 700; margin-top: 0.25rem;">Normal</div>
                    <div style="font-size: 0.8rem; color: #505a5f; margin-top: 0.25rem;">No approved check-in today</div>
                </div>
            </div>

            <table class="table table-bordered" style="max-width: 600px;">
                <thead>
                    <tr>
                        <th>Card style</th>
                        <th>Status</th>
                        <th>Meaning</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><span style="display:inline-block; width:20px; height:20px; border: 3px solid #00703c; background:#ffffff;"></span> <strong>Green border</strong></td>
                        <td>Parents notified</td>
                        <td>Check-in approved. Parents have been notified automatically.</td>
                    </tr>
                    <tr>
                        <td><span style="display:inline-block; width:20px; height:20px; border: 3px solid transparent; background: repeating-linear-gradient(45deg, #00703c 0, #00703c 5px, #ffdd00 5px, #ffdd00 10px);"></span> <strong>Green &amp; yellow striped</strong></td>
                        <td>Submitted, pending review</td>
                        <td>Team has submitted a check-in but a leader hasn't approved it yet. No notification sent.</td>
                    </tr>
                    <tr>
                        <td><span style="display:inline-block; width:20px; height:20px; border: 3px solid #ffdd00; background:#fff7bf;"></span> <strong>Yellow border</strong></td>
                        <td>Overdue</td>
                        <td>No check-in received after 19:00 (Finland time). May need follow-up.</td>
                    </tr>
                    <tr>
                        <td><span style="display:inline-block; width:20px; height:20px; border: 3px solid #b1b4b6; background:#f8f8f8;"></span> <strong>Grey border</strong></td>
                        <td>Normal</td>
                        <td>Before the deadline &mdash; no check-in yet but not overdue.</td>
                    </tr>
                </tbody>
            </table>

            <div class="alert alert-info" style="border-radius: 0; border-width: 2px;">
                <strong>Note:</strong> Parents only receive notifications for approved (green) check-ins. If you reject or ignore a check-in, no notification is sent.
            </div>

            <h3 style="font-weight: 700; font-size: 1.05rem;">Manual check-in (when a team can't submit):</h3>
            <p>If a team is unable to submit their own check-in (e.g. no phone signal, dead battery, or technical issue), a leader can submit one on their behalf:</p>
            <ol>
                <li>Go to <a href="<?= e(url('team_links.php')) ?>"><strong>Teams</strong></a> and click on the team.</li>
                <li>Select the <strong>"Manual check-in"</strong> tab.</li>
                <li>Search for the team's location on the map or enter coordinates manually.</li>
                <li>Fill in the form: location name, accommodation type, team status, and any welfare/first aid notes.</li>
                <li>Click <strong>"Submit manual check-in"</strong>.</li>
                <li>The check-in is created as if the team submitted it and follows the normal approval flow.</li>
            </ol>

            <div class="alert alert-warning" style="border-radius: 0; border-width: 2px;">
                <strong>Tip:</strong> Use manual check-in as a last resort. Encourage teams to submit their own check-ins so the welfare questions are answered directly by the participants.
            </div>
        </section>

        <!-- Section 2: How Links Work -->
        <section class="help-section mb-5" id="help-links">
            <h2 style="font-weight: 800; font-size: 1.3rem;">2. How Links Work</h2>
            <p>Each team has <strong>two unique links</strong> &mdash; one for parents and one for participants (explorers). Both are managed from the <a href="<?= e(url('team_links.php')) ?>"><strong>Teams</strong></a> page.</p>

            <h3 style="font-weight: 700; font-size: 1.1rem;">Parent Links</h3>
            <p>The parent link gives parents read-only access to their team's updates via the main dashboard.</p>
            <ul>
                <li><strong>What it shows:</strong> Parents see the dashboard with update posts and photos, check-in status, schedule, leaders list, and the contact page &mdash; all scoped to their team.</li>
                <li><strong>Sharing:</strong> Copy the parent link from <a href="<?= e(url('team_links.php')) ?>">Teams</a> and share it with parents via email or message. Anyone with the link can view that team's updates.</li>
                <li><strong>What parents can't do:</strong> They cannot edit anything, see other teams, view announcements, or access admin features.</li>
            </ul>

            <h3 style="font-weight: 700; font-size: 1.1rem;">Participant (Explorer) Links</h3>
            <p>The explorer link gives the team access to the Explorer Portal where they manage their check-ins and view announcements.</p>
            <ul>
                <li><strong>What it shows:</strong> The Explorer Portal with check-in submission, announcements, schedule, and the ability to contact leaders.</li>
                <li><strong>Sharing:</strong> Give the explorer link to the team members before the expedition starts. This is how they'll submit their daily check-ins.</li>
                <li><strong>What participants can't do:</strong> They cannot see other teams' data, access parent views, or use any leader admin tools.</li>
            </ul>

            <h3 style="font-weight: 700; font-size: 1.05rem;">Managing links:</h3>
            <ul>
                <li>Both links are found on the <a href="<?= e(url('team_links.php')) ?>"><strong>Teams</strong></a> page under each team.</li>
                <li><strong>Regenerating:</strong> If a link is compromised, you can regenerate the token which invalidates the old link. Make sure to re-share the new one.</li>
                <li>Parent links and explorer links are separate &mdash; regenerating one does not affect the other.</li>
            </ul>
        </section>

        <!-- Section 3: Mass Email -->
        <section class="help-section mb-5" id="help-mass-email">
            <h2 style="font-weight: 800; font-size: 1.3rem;">3. How to Send a Mass Email</h2>
            <p>Mass emails let you communicate with all parents or specific groups at once.</p>

            <h3 style="font-weight: 700; font-size: 1.05rem;">Steps:</h3>
            <ol>
                <li>Navigate to <a href="<?= e(url('email_all.php')) ?>"><strong>Email</strong></a> in the top menu.</li>
                <li>Select the recipients &mdash; you can email all parents, a specific team's parents, or leaders.</li>
                <li>Write your subject and message body. The email uses a branded HTML template automatically.</li>
                <li>Click <strong>"Send Email"</strong> to queue the email for delivery.</li>
                <li>Emails are sent in batches by the background queue to avoid overloading the mail server.</li>
            </ol>

            <div class="alert alert-warning" style="border-radius: 0; border-width: 2px;">
                <strong>Tip:</strong> Check the <a href="<?= e(url('analytics.php')) ?>">Analytics</a> page afterwards to see open rates and delivery status for your emails.
            </div>
        </section>

        <!-- Section 4: Announcements -->
        <section class="help-section mb-5" id="help-announcement">
            <h2 style="font-weight: 800; font-size: 1.3rem;">4. How to Send an Announcement</h2>
            <p>Announcements are pinned notices that appear <strong>only in the Explorer Portal</strong> for participants (teams). They are used to communicate important information that teams need to acknowledge. They are not visible to parents or on the leader dashboard.</p>

            <h3 style="font-weight: 700; font-size: 1.05rem;">Steps:</h3>
            <ol>
                <li>Go to <a href="<?= e(url('announcements_manage.php')) ?>"><strong>Announcements</strong></a> in the navigation.</li>
                <li>Click <strong>"New Announcement"</strong>.</li>
                <li>Enter a title and body for the announcement.</li>
                <li>Choose the priority level if available (normal or urgent).</li>
                <li>Save the announcement &mdash; it will immediately appear in the Explorer Portal for all teams.</li>
            </ol>

            <h3 style="font-weight: 700; font-size: 1.05rem;">How participants see announcements:</h3>
            <ul>
                <li>Unacknowledged announcements are shown to teams <strong>during the check-in process</strong> &mdash; they must acknowledge them before they can complete their check-in.</li>
                <li>Teams can also view and acknowledge announcements from the <strong>Announcements page</strong> in the Explorer Portal at any time.</li>
                <li>A badge count shows how many unacknowledged announcements remain.</li>
                <li>Once acknowledged, the announcement is marked as read for that team.</li>
            </ul>

            <h3 style="font-weight: 700; font-size: 1.05rem;">Managing announcements:</h3>
            <ul>
                <li>You can edit or delete announcements from the same <a href="<?= e(url('announcements_manage.php')) ?>">Announcements</a> page.</li>
                <li>You can track which teams have acknowledged each announcement.</li>
            </ul>

            <div class="alert alert-info" style="border-radius: 0; border-width: 2px;">
                <strong>Remember:</strong> Announcements are only for explorer participants via the Explorer Portal. To communicate with parents, use the <a href="<?= e(url('email_all.php')) ?>">Email</a> feature or post an update on the <a href="<?= e(url('dashboard.php')) ?>">Dashboard</a>.
            </div>
        </section>

        <!-- Section 5: Edit a Person -->
        <section class="help-section mb-5" id="help-edit-person">
            <h2 style="font-weight: 800; font-size: 1.3rem;">5. How to Edit a Person</h2>
            <p>The <a href="<?= e(url('people.php')) ?>">People</a> page lets you manage all explorers and parents in the system.</p>

            <h3 style="font-weight: 700; font-size: 1.05rem;">Steps:</h3>
            <ol>
                <li>Go to <a href="<?= e(url('people.php')) ?>"><strong>People</strong></a> in the navigation.</li>
                <li>Find the person using the search bar or by scrolling through the list.</li>
                <li>Click on the person's name or the <strong>"Edit"</strong> button.</li>
                <li>Update their details (name, email, phone, team assignment, emergency contacts, medical info, etc.).</li>
                <li>Click <strong>"Save"</strong> to apply the changes.</li>
            </ol>

            <div class="alert alert-info" style="border-radius: 0; border-width: 2px;">
                <strong>Note:</strong> Changes to email addresses will update where notifications are sent. Double-check email addresses are correct.
            </div>
        </section>

        <!-- Section 6: First Aid / Behaviour Log -->
        <section class="help-section mb-5" id="help-logs">
            <h2 style="font-weight: 800; font-size: 1.3rem;">6. How to Add a First Aid / Behaviour Log</h2>
            <p>Logs allow leaders to record incidents for safeguarding and welfare purposes.</p>

            <h3 style="font-weight: 700; font-size: 1.05rem;">Steps:</h3>
            <ol>
                <li>Go to <a href="<?= e(url('people.php')) ?>"><strong>People</strong></a> and find the relevant person.</li>
                <li>Open their profile and look for the <strong>"Logs"</strong> or <strong>"Welfare"</strong> section.</li>
                <li>Click <strong>"Add Log Entry"</strong>.</li>
                <li>Select the type: <strong>First Aid</strong> or <strong>Behaviour</strong>.</li>
                <li>Fill in the details: date/time of incident, description, action taken, and who responded.</li>
                <li>Save the entry. It is timestamped and attributed to the leader who created it.</li>
            </ol>

            <div class="alert alert-warning" style="border-radius: 0; border-width: 2px;">
                <strong>Important:</strong> Log entries cannot be deleted once saved (for safeguarding integrity). Ensure details are accurate before submitting.
            </div>
        </section>

        <!-- Section 7: What Parents See -->
        <section class="help-section mb-5" id="help-parent-view">
            <h2 style="font-weight: 800; font-size: 1.3rem;">7. What Parents See</h2>
            <p>Parents access the system via their team's unique link. Their view is read-only and scoped to their team.</p>

            <h3 style="font-weight: 700; font-size: 1.05rem;">Parents can see:</h3>
            <ul>
                <li><strong>Dashboard:</strong> Latest update posts and photos from their team.</li>
                <li><strong>Check-in status:</strong> Whether their team has checked in today and the last known location (approximate).</li>
                <li><strong>Schedule:</strong> The expedition schedule and any upcoming events.</li>
                <li><strong>Leaders:</strong> Who the leaders are and their on-duty status.</li>
                <li><strong>Contact:</strong> How to contact the leadership team in an emergency.</li>
            </ul>

            <h3 style="font-weight: 700; font-size: 1.05rem;">Parents cannot:</h3>
            <ul>
                <li>Edit any information or post updates.</li>
                <li>See other teams' data.</li>
                <li>View announcements (these are only shown in the Explorer Portal for participants).</li>
                <li>Access the People page, Email, or admin features.</li>
            </ul>
        </section>

        <!-- Section 8: What Teams See -->
        <section class="help-section mb-5" id="help-team-view">
            <h2 style="font-weight: 800; font-size: 1.3rem;">8. What Teams See</h2>
            <p>Explorer teams (participants) interact with the system via the Explorer Portal using their team credentials.</p>

            <h3 style="font-weight: 700; font-size: 1.05rem;">Teams can:</h3>
            <ul>
                <li><strong>Submit check-ins:</strong> Report their location and status at designated times.</li>
                <li><strong>View announcements:</strong> See any messages from leaders.</li>
                <li><strong>View schedule:</strong> See the expedition timetable.</li>
                <li><strong>Contact leaders:</strong> Send messages or flag emergencies.</li>
            </ul>

            <h3 style="font-weight: 700; font-size: 1.05rem;">Teams cannot:</h3>
            <ul>
                <li>See other teams' check-ins or data.</li>
                <li>Access parent views or leader admin tools.</li>
                <li>Edit people, send emails, or manage announcements.</li>
            </ul>
        </section>

        <!-- Section 9: Troubleshooting -->
        <section class="help-section mb-5" id="help-troubleshooting">
            <h2 style="font-weight: 800; font-size: 1.3rem;">9. Troubleshooting</h2>

            <div class="mb-3">
                <h3 style="font-weight: 700; font-size: 1.05rem;">Parents say they aren't receiving notifications</h3>
                <ul>
                    <li>Check the parent's email address is correct in the <a href="<?= e(url('people.php')) ?>">People</a> section.</li>
                    <li>Ask them to check their spam/junk folder.</li>
                    <li>Ensure the check-in was <strong>approved</strong> (pending check-ins don't trigger notifications).</li>
                    <li>If using push notifications, the parent must have enabled them in their browser.</li>
                </ul>
            </div>

            <div class="mb-3">
                <h3 style="font-weight: 700; font-size: 1.05rem;">A team's link isn't working</h3>
                <ul>
                    <li>Go to <a href="<?= e(url('team_links.php')) ?>"><strong>Teams</strong></a> and check the link is still active.</li>
                    <li>If the token was regenerated, the old link will no longer work &mdash; share the new one.</li>
                    <li>Ensure the parent is using the full URL (sometimes links get truncated in messages).</li>
                </ul>
            </div>

            <div class="mb-3">
                <h3 style="font-weight: 700; font-size: 1.05rem;">Emails aren't sending</h3>
                <ul>
                    <li>Emails are queued and sent in batches. Allow a few minutes for delivery.</li>
                    <li>Check the <a href="<?= e(url('analytics.php')) ?>"><strong>Analytics</strong></a> page to see if emails are stuck in the queue.</li>
                    <li>If the issue persists, contact the system administrator.</li>
                </ul>
            </div>

            <div class="mb-3">
                <h3 style="font-weight: 700; font-size: 1.05rem;">I can't find a person in the system</h3>
                <ul>
                    <li>Use the search bar at the top of the page or in the <a href="<?= e(url('people.php')) ?>">People</a> section.</li>
                    <li>Try searching by email or phone number as well as name.</li>
                    <li>Check if the person has been imported &mdash; new participants need to be added via <a href="<?= e(url('people.php')) ?>">People</a> or Import.</li>
                </ul>
            </div>

            <div class="mb-3">
                <h3 style="font-weight: 700; font-size: 1.05rem;">Check-in is showing as overdue</h3>
                <ul>
                    <li>Teams should check in before the daily deadline (shown on the dashboard).</li>
                    <li>If a team has checked in but it still shows overdue, check the check-in hasn't been accidentally rejected.</li>
                    <li>Contact the team directly if they haven't checked in and you're concerned.</li>
                </ul>
            </div>

            <div class="mb-3">
                <h3 style="font-weight: 700; font-size: 1.05rem;">Need more help?</h3>
                <ul>
                    <li>Contact the system administrator or development team via the <a href="<?= e(url('contact.php')) ?>"><strong>Contact</strong></a> page.</li>
                    <li>Report bugs or feature requests to <a href="https://ckenterprises.co.uk" target="_blank" rel="noopener">CK Enterprises UK</a>.</li>
                </ul>
            </div>
        </section>

    </div>
</main>

<script>
(function () {
    var searchInput = document.getElementById('helpSearch');
    var sections = document.querySelectorAll('.help-section');
    var tocBlock = document.querySelector('.help-toc');
    var noResults = document.getElementById('helpSearchNoResults');

    if (!searchInput || !sections.length) return;

    searchInput.addEventListener('input', function () {
        var query = this.value.trim().toLowerCase();

        if (query === '') {
            // Show everything
            sections.forEach(function (section) {
                section.style.display = '';
            });
            if (tocBlock) tocBlock.style.display = '';
            noResults.style.display = 'none';
            return;
        }

        var visibleCount = 0;

        sections.forEach(function (section) {
            var text = section.textContent.toLowerCase();
            if (text.indexOf(query) !== -1) {
                section.style.display = '';
                visibleCount++;
            } else {
                section.style.display = 'none';
            }
        });

        // Hide TOC when filtering
        if (tocBlock) tocBlock.style.display = 'none';

        // Show no-results message
        noResults.style.display = visibleCount === 0 ? '' : 'none';
    });
})();
</script>

<?php include __DIR__ . '/footer.php'; ?>
