-- Tenant-level module selection for the lockable developer support setup.
-- NULL preserves all modules for installations that have not been configured.

ALTER TABLE tenants
    ADD COLUMN enabled_modules JSON NULL;
