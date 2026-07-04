# Implementation Plan: Explorer Portal Enhancement

## Overview

This plan transforms the existing single-purpose Explorer Check-in Portal into a multi-tab team portal with navigation, home page, announcements with acknowledgement workflow, contact leaders, team logging, emergencies page, and leader-facing announcement management. Implementation uses PHP (no framework), MySQL with PDO, and Bootstrap 4.6.2 — following existing application patterns throughout.

## Tasks

- [x] 1. Database tables and shared helpers
  - [x] 1.1 Create auto-creating table helper functions
    - Add `ensure_announcements_table(PDO $pdo)`, `ensure_announcement_acknowledgements_table(PDO $pdo)`, and `ensure_team_logs_table(PDO $pdo)` functions in `explorer_checkin.php` (matching the existing pattern of inline table creation)
    - Schema per design: `announcements` (id, team_id nullable, sender_leader_id, title, content, created_at), `announcement_acknowledgements` (id, announcement_id, team_id, acknowledged_by_name, acknowledged_at, UNIQUE on announcement_id+team_id), `team_logs` (id, team_id, leader_id, title, body, created_at DEFAULT CURRENT_TIMESTAMP)
    - _Requirements: 7.2, 7.6, 6.3, 9.1, 9.2_

  - [x] 1.2 Add EXPLORER_EMERGENCY_PHONE constant to config.php
    - Define constant with a default phone number value if not already defined
    - _Requirements: 11.1_

- [x] 2. Portal navigation structure and layout
  - [x] 2.1 Create explorer_header.php partial
    - Render HTML `<head>` with Bootstrap 4.6.2 CSS, Leaflet CSS, app.css
    - Render responsive navbar with tabs: Home, Check In, Announcements (with badge), Contact Leaders, Emergencies
    - Each nav link includes `?token=` parameter for token propagation
    - Highlight active tab based on current script filename using `basename($_SERVER['SCRIPT_FILENAME'])`
    - Query unacknowledged announcement count for badge display; hide badge when count is zero
    - Mobile-responsive using Bootstrap navbar-toggler
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.6_

  - [x] 2.2 Create explorer_footer.php partial
    - Include jQuery slim, Bootstrap JS bundle, and any page-specific JS
    - Close `</body></html>` tags
    - _Requirements: 1.2_

  - [x] 2.3 Implement token validation error page
    - When token is invalid/missing, render an error page without the Navigation_Header
    - Return HTTP 404 for invalid tokens
    - _Requirements: 1.5_

- [x] 3. Home page (explorer_portal.php)
  - [x] 3.1 Create explorer_portal.php as default landing page
    - Include config.php, start session, validate token using `explorer_fetch_team()`
    - Store token in session as `explorer_portal_token`
    - Include explorer_header.php and explorer_footer.php
    - Display team name prominently in a hero/panel section
    - Show static informational content about the expedition (welcome message, quick links to other tabs)
    - _Requirements: 2.1, 2.2, 2.3, 2.4_

- [x] 4. Integrate check-in into portal navigation
  - [x] 4.1 Refactor explorer_checkin.php to use shared portal layout
    - Replace inline `<head>` and hero header with `include explorer_header.php`
    - Replace inline footer scripts with `include explorer_footer.php`
    - Keep ALL existing form handling, POST logic, validation, map, GPS, member reports unchanged
    - Keep the existing inline `<style>` for checkin-specific styles
    - _Requirements: 3.1, 3.2, 3.3, 3.4_

- [x] 5. Checkpoint
  - Ensure the portal navigation renders correctly on all pages, token propagation works, and existing check-in functionality is preserved. Ask the user if questions arise.

- [x] 6. Contact Leaders page (explorer_contact.php)
  - [x] 6.1 Create explorer_contact.php
    - Validate token, include portal header/footer
    - Query `leader_duty_roster` joined with `leaders` for today's date where `status = 'on_duty'` and phone is non-empty
    - Display each on-duty leader's name and phone number as a clickable `<a href="tel:...">` styled as a prominent button
    - When no on-duty leaders with phones are available, show fallback message directing to emergency number
    - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5_

- [x] 7. Emergencies page (explorer_emergencies.php)
  - [x] 7.1 Create explorer_emergencies.php
    - Validate token, include portal header/footer
    - Display emergency phone number from `EXPLORER_EMERGENCY_PHONE` or `CONTACT_PHONE` constant
    - Render as large clickable `<a href="tel:...">` with high-visibility styling (danger-box styling, large font)
    - Add brief guidance text about when to call emergency contacts
    - _Requirements: 11.1, 11.2, 11.3_

- [x] 8. Announcements display (explorer_announcements.php)
  - [x] 8.1 Create explorer_announcements.php — display logic
    - Validate token, include portal header/footer
    - Query announcements where `team_id IS NULL OR team_id = :current_team_id`, joined with `announcement_acknowledgements` for this team
    - Order by `created_at DESC`
    - Display each announcement: title, content, sender name (from leaders table), creation date
    - Visually distinguish unacknowledged (bold border, highlighted panel) from acknowledged (muted, green checkmark)
    - For unacknowledged: show "Acknowledge" button that reveals inline form with name input
    - For acknowledged: show "✓ Acknowledged by [name] at [time]"
    - Show empty state message when no announcements exist
    - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5, 6.1, 6.6_

  - [x] 8.2 Implement announcement acknowledgement POST handler
    - Handle POST with `action=acknowledge_announcement` in explorer_announcements.php
    - Validate: CSRF token, announcement_id exists and is targeted to this team, name is non-empty (trim + check)
    - INSERT into `announcement_acknowledgements` using INSERT IGNORE or ON DUPLICATE KEY to ensure idempotence
    - Fetch announcement sender leader + current on-duty leaders
    - Queue notification emails to sender + on-duty leaders: "[Team name] acknowledged: [Title]"
    - Redirect back to announcements page (PRG pattern)
    - _Requirements: 6.2, 6.3, 6.4, 6.5, 6.6, 8.4_

  - [x]* 8.3 Write property test for announcement visibility (Property 3)
    - **Property 3: Announcement visibility completeness**
    - Verify that for any team, the query returns all announcements where team_id IS NULL OR team_id = current_team_id, and excludes announcements targeting a different team
    - **Validates: Requirements 5.1, 5.2**

  - [x]* 8.4 Write property test for badge count accuracy (Property 2)
    - **Property 2: Badge count accuracy**
    - Verify that badge count equals total targeted announcements minus acknowledged count for the team
    - **Validates: Requirements 1.3, 1.4**

  - [x]* 8.5 Write property test for acknowledgement idempotence (Property 4)
    - **Property 4: Acknowledgement idempotence**
    - Verify that submitting a duplicate acknowledgement for the same team+announcement has no effect (unique key enforced)
    - **Validates: Requirements 6.6**

  - [x]* 8.6 Write property test for name validation (Property 5)
    - **Property 5: Acknowledgement name validation**
    - Verify that empty or whitespace-only names are always rejected and leave announcement unacknowledged
    - **Validates: Requirements 6.4**

- [x] 9. Checkpoint
  - Ensure announcements display correctly, acknowledgement flow works end-to-end, badge count updates, and emails are queued. Ask the user if questions arise.

- [x] 10. Leader announcement management (announcements_manage.php)
  - [x] 10.1 Create announcements_manage.php with session auth
    - Require leader login (use existing `require_login()` from auth.php)
    - Include standard header.php/footer.php (leader admin layout)
    - Display announcement creation form: title (required), content (required), target selector (All Teams / specific team dropdown)
    - Validate title and content are non-empty
    - _Requirements: 7.1, 7.2, 7.5_

  - [x] 10.2 Implement announcement creation POST handler
    - Handle POST with `action=create_announcement`
    - Validate CSRF, inputs, and that selected team exists (if specific team targeted)
    - INSERT into `announcements` with sender_leader_id from session
    - For specific team: queue one email to team's contact_email
    - For all teams: query active teams and queue one email per team with non-empty contact_email
    - Email content includes announcement title and link to explorer portal announcements page
    - Redirect with success message (PRG)
    - _Requirements: 7.2, 7.3, 7.4, 7.6, 8.1, 8.2, 8.3, 8.5_

  - [x] 10.3 Display existing announcements list
    - Show all announcements ordered by created_at DESC with sender name, target, title, and acknowledgement status per team
    - _Requirements: 7.2_

  - [x]* 10.4 Write property test for email targeting (Property 6)
    - **Property 6: Notification email targeting correctness**
    - Verify: specific team target produces exactly 1 email queue record; all-teams target produces one record per active team with non-empty contact_email
    - **Validates: Requirements 8.1, 8.2, 8.5**

- [x] 11. Team logs on team_links.php
  - [x] 11.1 Add team log creation form on team notes tab
    - Add a "Team Log" section on the existing `notes` tab in team_links.php
    - Form fields: title (required), body (optional textarea)
    - POST action: `add_team_log`
    - _Requirements: 9.1, 9.4_

  - [x] 11.2 Implement add_team_log POST handler
    - Validate title is non-empty, CSRF token valid
    - INSERT into `team_logs` (team_id, leader_id from session, title, body) — `created_at` auto-set by DEFAULT
    - Redirect back to team notes tab (PRG)
    - _Requirements: 9.1, 9.2_

  - [x] 11.3 Display team logs on notes tab
    - Query `team_logs` for the current team, ordered by `created_at DESC`
    - Display each entry: title, body, leader name, formatted created_at timestamp (read-only)
    - Visually distinguish from person logs using a different left-border colour or icon prefix
    - _Requirements: 9.3, 9.4, 9.5, 10.3, 10.4_

  - [x]* 11.4 Write property test for team log timestamp immutability (Property 7)
    - **Property 7: Team log timestamp immutability**
    - Verify that `created_at` value at insertion equals `created_at` at any future read (no UPDATE modifies it)
    - **Validates: Requirements 9.2, 10.3**

- [x] 12. Person log timestamp immutability fix
  - [x] 12.1 Remove editable occurred_at field from person log forms
    - In `team_links.php`: remove any date/time input for `occurred_at` from the person log creation form
    - In `people.php` or `people_form_partial.php`: remove any editable occurred_at field
    - Ensure INSERT statements use `NOW()` or rely on column DEFAULT for the timestamp
    - Display occurred_at/created_at as read-only formatted text in log listings
    - _Requirements: 10.1, 10.2, 10.4_

- [x] 13. Final checkpoint
  - Ensure all tests pass, all portal tabs work correctly with token propagation, announcement flow is complete (create → display → acknowledge → email), team logs display with immutable timestamps, and person log timestamps are no longer editable. Ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- Each task references specific requirements for traceability
- Checkpoints ensure incremental validation
- Property tests validate universal correctness properties from the design document
- The existing `explorer_fetch_team()` function in explorer_checkin.php is reused across all portal pages
- Tables auto-create following the existing pattern (check `information_schema` before CREATE)
- All forms use CSRF tokens and PRG pattern per existing codebase conventions
- Email queuing uses the existing `email_queue` table and `explorer_queue_email()` helper

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1", "1.2"] },
    { "id": 1, "tasks": ["2.1", "2.2", "2.3"] },
    { "id": 2, "tasks": ["3.1", "4.1", "6.1", "7.1"] },
    { "id": 3, "tasks": ["8.1", "12.1"] },
    { "id": 4, "tasks": ["8.2", "8.3", "8.4"] },
    { "id": 5, "tasks": ["8.5", "8.6", "10.1"] },
    { "id": 6, "tasks": ["10.2", "10.3", "11.1"] },
    { "id": 7, "tasks": ["10.4", "11.2", "11.3"] },
    { "id": 8, "tasks": ["11.4"] }
  ]
}
```
