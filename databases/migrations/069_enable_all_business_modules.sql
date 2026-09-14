-- New businesses start with every feature available. Upgrade tenants that
-- still have the former six-module default without overwriting custom choices.

UPDATE tenants
   SET enabled_modules = JSON_ARRAY(
       'credit_sales', 'returns', 'inventory', 'customers', 'reports', 'documents',
       'services', 'finances', 'payroll', 'commissions', 'staff'
   )
 WHERE enabled_modules IS NULL
    OR (
        JSON_LENGTH(enabled_modules) = 6
        AND JSON_CONTAINS(enabled_modules, JSON_QUOTE('credit_sales')) = 1
        AND JSON_CONTAINS(enabled_modules, JSON_QUOTE('returns')) = 1
        AND JSON_CONTAINS(enabled_modules, JSON_QUOTE('inventory')) = 1
        AND JSON_CONTAINS(enabled_modules, JSON_QUOTE('customers')) = 1
        AND JSON_CONTAINS(enabled_modules, JSON_QUOTE('reports')) = 1
        AND JSON_CONTAINS(enabled_modules, JSON_QUOTE('documents')) = 1
    );
