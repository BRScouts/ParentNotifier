# Requirements Document

## Introduction

This document defines requirements for enhancing the Explorer Check-in Portal (explorer_checkin.php) from a single-purpose check-in form into a full multi-page team portal. The portal is accessed by expedition teams via a unique token URL during the trip. The enhancement introduces a navigation structure, a static information home page, retains the existing check-in functionality, and adds a contact leaders page, an announcements system with acknowledgement workflow, team-level logging, and an emergencies reference page.

## Glossary

- **Explorer_Portal**: The token-authenticated web interface accessed by expedition teams at explorer_checkin.php, providing information, check-in, announcements, contact, and emergency capabilities.
- **Team**: An expedition group consisting of young people, identified by a unique explorer_token in the teams table.
- **Leader**: An authenticated staff member who manages teams, sends announcements, and is assigned duty shifts.
- **Announcement**: A message created by a Leader and delivered to one or more Teams, requiring acknowledgement from the Team.
- **Acknowledgement**: A Team's confirmation of having read an Announcement, recorded with the name of the person who acknowledged it.
- **On_Duty_Leader**: A Leader whose record in the leader_duty_roster table has status "on_duty" for the current date.
- **Navigation_Header**: A persistent tab-based navigation bar displayed on all Explorer_Portal pages, providing links to portal sections.
- **Badge**: A numeric indicator displayed on the Navigation_Header showing the count of unacknowledged Announcements for the Team.
- **Team_Log**: A log entry recorded against a Team (rather than an individual person), with an immutable auto-set timestamp.
- **Person_Log**: An existing log entry recorded against an individual young person in the person_logs table.
- **Email_Queue**: The email_queue table used for asynchronous email delivery, processed by a cron job.
- **Participant_Contact_Email**: The contact email address associated with a Team's participants (the team's contact_email field), distinct from leader emails.
- **Home_Page**: The default landing page of the Explorer_Portal displaying static informational content relevant to the expedition.

## Requirements

### Requirement 1: Portal Navigation Structure

**User Story:** As a team member, I want a navigation header with tabs on the explorer portal, so that I can easily access all portal sections without remembering separate URLs.

#### Acceptance Criteria

1. WHEN a Team accesses the Explorer_Portal with a valid token, THE Explorer_Portal SHALL display a Navigation_Header containing tabs for: Home, Check In, Announcements, Contact Leaders, and Emergencies.
2. THE Navigation_Header SHALL persist across all Explorer_Portal pages, maintaining the token parameter in all navigation links.
3. THE Navigation_Header SHALL display a Badge on the Announcements tab showing the count of unacknowledged Announcements for the Team.
4. WHEN the Team has zero unacknowledged Announcements, THE Explorer_Portal SHALL hide the Badge from the Announcements tab.
5. WHEN a Team accesses the Explorer_Portal with an invalid or missing token, THE Explorer_Portal SHALL display an error message without rendering the Navigation_Header.
6. THE Navigation_Header SHALL render responsively using a mobile-friendly layout suitable for use on smartphones.

### Requirement 2: Home Page

**User Story:** As a team member, I want a home page on the explorer portal, so that I can find useful information about the expedition in one place.

#### Acceptance Criteria

1. WHEN a Team accesses the Explorer_Portal with a valid token, THE Explorer_Portal SHALL display the Home_Page as the default landing view.
2. THE Home_Page SHALL display the Team name prominently.
3. THE Home_Page SHALL provide static informational content relevant to the expedition.
4. THE Home_Page SHALL render using a clean, mobile-friendly layout consistent with the portal design.

### Requirement 3: Check-in Integration

**User Story:** As a team member, I want to submit check-ins from within the new portal, so that I can continue using the check-in feature alongside the new portal capabilities.

#### Acceptance Criteria

1. WHEN a Team navigates to the Check In tab, THE Explorer_Portal SHALL display the existing check-in form with all current functionality preserved.
2. THE check-in form SHALL continue to collect location (map with GPS), accommodation type, accommodation notes, submitted by name, welfare information, and per-member first aid reports.
3. WHEN a Team submits a valid check-in, THE Explorer_Portal SHALL save the check-in record and queue notification emails to leaders as per existing behaviour.
4. WHEN a Team submits a check-in with validation errors, THE Explorer_Portal SHALL display appropriate error messages and retain the form input.

### Requirement 4: Contact Leaders Page

**User Story:** As a team member, I want to see which leaders are currently on duty with their phone numbers, so that I can contact them directly if needed.

#### Acceptance Criteria

1. WHEN a Team navigates to the Contact Leaders page, THE Explorer_Portal SHALL display a list of On_Duty_Leaders for the current date.
2. THE Explorer_Portal SHALL display each On_Duty_Leader's name and phone number.
3. THE Explorer_Portal SHALL render each phone number as a clickable telephone link (tel: protocol) for direct dialling from mobile devices.
4. WHEN an On_Duty_Leader has no phone number set, THE Explorer_Portal SHALL exclude that Leader from the Contact Leaders list.
5. WHEN no On_Duty_Leaders with phone numbers are available, THE Explorer_Portal SHALL display a message directing the Team to use the emergency contact number.

### Requirement 5: Announcements Display

**User Story:** As a team member, I want to view announcements sent by leaders, so that I can stay informed about important updates during the expedition.

#### Acceptance Criteria

1. WHEN a Team navigates to the Announcements page, THE Explorer_Portal SHALL display all Announcements addressed to that Team, ordered by creation date descending.
2. THE Explorer_Portal SHALL display Announcements addressed to all Teams alongside Announcements addressed specifically to the viewing Team.
3. THE Explorer_Portal SHALL visually distinguish unacknowledged Announcements from acknowledged Announcements using distinct styling.
4. THE Explorer_Portal SHALL display the Announcement title, content, sender name, and creation date for each Announcement.
5. WHEN the Team has no Announcements, THE Explorer_Portal SHALL display a message indicating no announcements are available.

### Requirement 6: Announcement Acknowledgement

**User Story:** As a team member, I want to acknowledge announcements, so that leaders know our team has read and understood the information.

#### Acceptance Criteria

1. WHEN a Team views an unacknowledged Announcement, THE Explorer_Portal SHALL display an "Acknowledge" button for that Announcement.
2. WHEN a Team clicks the Acknowledge button, THE Explorer_Portal SHALL prompt for the name of the person acknowledging the Announcement.
3. WHEN a Team submits an Acknowledgement with a valid name, THE Explorer_Portal SHALL record the Acknowledgement with the person's name and the current timestamp.
4. IF a Team submits an Acknowledgement without entering a name, THEN THE Explorer_Portal SHALL display a validation error and prevent the submission.
5. WHEN an Announcement is acknowledged, THE Explorer_Portal SHALL queue a notification email to all On_Duty_Leaders and to the Leader who sent the Announcement.
6. WHEN an Announcement has already been acknowledged, THE Explorer_Portal SHALL display the acknowledged status with the acknowledger's name and timestamp instead of the Acknowledge button.

### Requirement 7: Announcement Creation by Leaders

**User Story:** As a leader, I want to send announcements to all teams or individual teams, so that I can communicate important information to teams during the expedition.

#### Acceptance Criteria

1. WHEN a Leader creates an Announcement, THE system SHALL allow the Leader to select either all Teams or a specific individual Team as the recipient.
2. WHEN a Leader submits an Announcement, THE system SHALL record the Announcement with the sender Leader ID, recipient scope (all or specific team), title, and content.
3. WHEN an Announcement is created, THE system SHALL queue a notification email to the Participant_Contact_Email of the targeted Team or Teams.
4. WHEN an Announcement targets all Teams, THE system SHALL queue a notification email to the Participant_Contact_Email of every active Team.
5. THE system SHALL validate that the Announcement title and content are non-empty before saving.
6. THE system SHALL record the creation timestamp automatically when an Announcement is saved.

### Requirement 8: Announcement Notification Emails

**User Story:** As a team participant contact, I want to receive an email when a new announcement is posted, so that the team is alerted even if they are not actively checking the portal.

#### Acceptance Criteria

1. WHEN an Announcement is created targeting a specific Team, THE system SHALL insert one record into the Email_Queue with the Team's Participant_Contact_Email as the recipient.
2. WHEN an Announcement is created targeting all Teams, THE system SHALL insert one Email_Queue record per active Team using each Team's Participant_Contact_Email.
3. THE notification email SHALL include the Announcement title and a link to the Explorer_Portal Announcements page.
4. WHEN an Announcement is acknowledged, THE system SHALL insert Email_Queue records notifying all current On_Duty_Leaders and the sending Leader of the Acknowledgement.
5. IF a Team has no Participant_Contact_Email set, THEN THE system SHALL skip email delivery for that Team without blocking the Announcement creation.

### Requirement 9: Team Log Entries

**User Story:** As a leader, I want to add log entries against a team, so that I can record team-level events and communications that are not specific to an individual.

#### Acceptance Criteria

1. WHEN a Leader creates a Team_Log entry, THE system SHALL record the entry with the team ID, leader ID, title, body, and an auto-generated timestamp.
2. THE system SHALL set the created_at timestamp automatically at the time of insertion and prevent modification of the timestamp by any user.
3. THE system SHALL display Team_Log entries on the team management page ordered by creation time descending.
4. THE system SHALL validate that the Team_Log title is non-empty before saving.
5. WHEN viewing a Team's logs, THE system SHALL distinguish Team_Log entries from Person_Log entries using visual indicators.

### Requirement 10: Person Log Timestamp Immutability

**User Story:** As an organisation administrator, I want log entry timestamps to be immutable, so that the audit trail remains trustworthy and tamper-proof.

#### Acceptance Criteria

1. WHEN a Leader creates a Person_Log entry, THE system SHALL auto-set the created_at timestamp to the current server time.
2. THE system SHALL NOT provide any interface field allowing Leaders to edit the created_at timestamp of an existing Person_Log entry.
3. THE system SHALL NOT provide any interface field allowing Leaders to edit the created_at timestamp of an existing Team_Log entry.
4. WHEN displaying log entries, THE system SHALL show the immutable created_at timestamp as the authoritative time of the log entry.

### Requirement 11: Emergencies Page

**User Story:** As a team member, I want quick access to emergency contact information, so that I can get help immediately in an urgent situation.

#### Acceptance Criteria

1. WHEN a Team navigates to the Emergencies page, THE Explorer_Portal SHALL display the configured emergency contact phone number.
2. THE Explorer_Portal SHALL render the emergency phone number as a clickable telephone link for direct dialling.
3. THE Explorer_Portal SHALL display the emergency contact information prominently with high-visibility styling.
