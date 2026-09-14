-- Preserve return history while allowing a safe, auditable undo.

ALTER TABLE product_returns
    ADD COLUMN financial_snapshot JSON NULL AFTER migrated_by,
    ADD COLUMN undone_at DATETIME NULL AFTER financial_snapshot,
    ADD COLUMN undone_by INT NULL AFTER undone_at;

ALTER TABLE product_returns
    ADD KEY idx_returns_active (tenant_id, undone_at, created_at);
