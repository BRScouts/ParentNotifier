-- Migration: Add miles_covered column to explorer_checkins
-- Run this in phpMyAdmin or via CLI against the exbelt database.

ALTER TABLE explorer_checkins
    ADD COLUMN miles_covered DECIMAL(6,1) NULL DEFAULT NULL
    AFTER welfare_notes;
