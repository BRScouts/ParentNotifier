    -- ============================================================
    -- Migration: Add receipt justification column to team_transactions
    -- Run this against the exbelt database
    -- ============================================================

    ALTER TABLE team_transactions
        ADD COLUMN no_receipt_reason VARCHAR(500) NULL COMMENT 'Justification for why no receipt was uploaded' AFTER receipt_path;
