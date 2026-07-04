# Implementation Plan: Explorer Portal Enhancement

## Overview

This plan implements the Explorer Portal Enhancement feature, transforming the existing single-purpose check-in form into a multi-tab team portal with navigation, announcements, contact leaders, team logging, and emergencies pages.

## Tasks

- [ ] 1. Create database tables and auto-create helpers
  - [-] 1.1. Create a shared helper function `ensure_announcements_tables(PDO $pdo)` that auto-creates `announcements`, `announcement_acknowledgements`, and `team_logs` tables if they don't exist
  - [~] 1.2. Add the helper to a location accessible by both `explorer_checkin.php` and `team_links.php` (inline in each file, matching existing pattern)
  - [~] 1.3. `announcements` table: id, leader_id, team_id (nullable), title, content, created_at
  - [~] 1.4. `announcement_acknowledgements` table: id, announcement_id, team_id, acknowledged_by_name, acknowledged_at, unique key on (announcement_id, team_id)
  - [~] 1.5. `team_logs` table: id, team_id, leader_id (nullable), title, body, created_at (DEFAULT CURRENT_TIMESTAMP, no updated_at)
  - [~] 1.6. Add `EXPLORER_EMERGENCY_PHONE` constant to `config.php` if not already defined
- [ ] 2. Rebuild explorer_checkin.php with multi-tab portal structure
  - [~] 2.1. Refactor `explorer_checkin.php` to use a `tab` query parameter (home, checkin, announcements, contact, emergencies)
  - [~] 2.2. Implement the Navigation Header with tabs, rendering on all views, maintaining token in all links
  - [~] 2.3. Implement badge count on Announcements tab showing unacknowledged announcement count for the team
  - [~] 2.4. Default to `home` tab when no tab parameter is provided
  - [~] 2.5. Validate token on entry — show error without navigation if invalid
  - [~] 2.6. Mobile-responsive navigation using the existing CSS conventions (purple header, bold tabs)
- [ ] 3. Implement Home tab
  - [~] 3.1. Display the team name prominently
  - [~] 3.2. Show static informational content about the expedition (welcome message, key dates, quick links to other tabs)
  - [~] 3.3. Style consistently with the existing app design (panels, purple accents, bold headings)
- [ ] 4. Implement Check In tab
  - [~] 4.1. Move existing check-in form into the `tab=checkin` view
  - [~] 4.2. Preserve all existing POST handling, GPS/map, accommodation fields, welfare checks, per-member reports
  - [~] 4.3. Preserve existing validation and error display
  - [~] 4.4. Preserve existing email queuing to leaders on successful submission
- [ ] 5. Implement Contact Leaders tab
  - [~] 5.1. Query `leader_duty_roster` joined with `leaders` to get on-duty leaders for today with phone numbers
  - [~] 5.2. Display each leader's name with a clickable phone button (`<a href="tel:...">`) styled as a prominent action button
  - [~] 5.3. Exclude leaders without phone numbers from the list
  - [~] 5.4. Show fallback message when no on-duty leaders with phones are available, directing to emergency number
- [ ] 6. Implement Emergencies tab
  - [~] 6.1. Display emergency phone number from `EXPLORER_EMERGENCY_PHONE` or `CONTACT_PHONE` constant
  - [~] 6.2. Render as clickable tel: link with high-visibility styling (large, red/yellow danger colours)
  - [~] 6.3. Add brief guidance text about when to use emergency contacts
- [ ] 7. Implement Announcements tab (explorer portal)
  - [~] 7.1. Query announcements for the team (where team_id IS NULL or team_id = current team), joined with acknowledgements
  - [~] 7.2. Display announcements ordered by created_at DESC with title, content, sender name, date
  - [~] 7.3. Visually distinguish unacknowledged (bold/highlighted border) from acknowledged (muted/green check)
  - [~] 7.4. For unacknowledged: show "Acknowledge" button that reveals an inline form with a name input field
  - [~] 7.5. For acknowledged: show "✓ Acknowledged by [name] at [time]"
  - [~] 7.6. Show empty state message when no announcements exist
- [ ] 8. Implement Announcement acknowledgement POST handler
  - [~] 8.1. Handle POST action `acknowledge_announcement` in `explorer_checkin.php`
  - [~] 8.2. Validate: CSRF token, announcement_id exists, team has access, name is non-empty
  - [~] 8.3. INSERT into `announcement_acknowledgements` (with ON DUPLICATE KEY to prevent double-ack)
  - [~] 8.4. Fetch the announcement's sender leader and current on-duty leaders
  - [~] 8.5. Queue email to sender + on-duty leaders: "[Team name] acknowledged: [Announcement title]"
  - [~] 8.6. Redirect back to announcements tab with success message
- [ ] 9. Add announcement management to team_links.php
  - [~] 9.1. Add `announcements` to the allowed tabs array for team detail view
  - [~] 9.2. Add announcements tab link in the team tabs navigation
  - [~] 9.3. Create announcement form on the team announcements tab (title, content fields)
  - [~] 9.4. Handle POST action `send_team_announcement`: insert with specific team_id
  - [~] 9.5. On creation: queue notification email to team's participant contact emails (from `young_people.parent_emails_json`)
  - [~] 9.6. Display list of announcements for this team with acknowledgement status
  - [~] 9.7. Add "Send announcement to all teams" form on the team overview page or as a separate section
  - [~] 9.8. Handle POST action `send_announcement_all`: insert with team_id = NULL, queue emails to all active teams
- [ ] 10. Add team logs to team_links.php
  - [~] 10.1. Add team log creation form on the team `notes` tab (title required, body optional)
  - [~] 10.2. Handle POST action `add_team_log`: insert into `team_logs` with team_id, leader_id, title, body (created_at auto-set)
  - [~] 10.3. Display team logs on the notes tab, ordered by created_at DESC
  - [~] 10.4. Show each entry with: title, body, leader name, immutable created_at timestamp
  - [~] 10.5. Visually distinguish team logs from person logs (different left-border colour or icon)
- [ ] 11. Fix person log timestamp immutability
  - [~] 11.1. In `team_links.php` person log form: remove any editable date/time input for `occurred_at`
  - [~] 11.2. In `people.php` person log form: remove any editable date/time input for `occurred_at`
  - [~] 11.3. Ensure person log INSERT statements use `NOW()` or rely on DEFAULT for the timestamp column
  - [~] 11.4. Display `occurred_at`/`created_at` as read-only formatted text in log entry listings

## Task Dependency Graph

```
1 --> 2
2 --> 3
2 --> 4
2 --> 5
2 --> 6
1 --> 7
7 --> 8
1 --> 9
1 --> 10
11
```

## Notes

- Tasks 3, 4, 5, 6 can be done in parallel after Task 2
- Tasks 7, 9, 10 depend on Task 1 (database tables)
- Task 8 depends on Task 7 (announcements tab must exist)
- Task 11 is independent and can be done at any time
