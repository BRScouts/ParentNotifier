# Explorer Belt Live - PHP/MySQL rebuild

This is a clean rebuild of the Explorer Belt parent update portal.

## Setup

1. Upload these files to the site root.
2. Edit `config.php` and check DB credentials and `BASE_URL`.
3. Import `database.sql` in phpMyAdmin.
4. Log in at `/login.php`.

Demo login seeded by `database.sql`:

- Email: `admin@example.com`
- Password: `ChangeMe123!`

Change this immediately after first login.

## Core behaviour

- `/index.php` redirects to `403.php` unless logged in or using a valid team token.
- `/team.php?token=...` is the parent landing page.
- Team locations are stored in `team_locations`, not `posts`.
- Posts can be public to all team links or private to one team.
- Leaders have accounts, bios, photo URLs and schedule entries.
- Public location map shows rounded/approximate coordinates.

## Main files

- `config.php` - database and shared helpers.
- `auth.php` - login/session helpers.
- `dashboard.php` - create posts and check-ins.
- `team.php` - parent landing page with right sidebar.
- `leaders.php` - public/token leader view and admin leader management.
- `team_links.php` - private links for parents.
- `team_locations.php` - leader-only location history.
