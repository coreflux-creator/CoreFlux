-- A reopened-and-reclosed period needs a new retained packet before lock.
ALTER TABLE accounting_periods
    ADD COLUMN close_cycle INT UNSIGNED NOT NULL DEFAULT 0;

ALTER TABLE accounting_close_packets
    ADD COLUMN close_cycle INT UNSIGNED NOT NULL DEFAULT 0;
