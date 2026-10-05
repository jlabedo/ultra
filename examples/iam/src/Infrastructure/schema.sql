CREATE TABLE IF NOT EXISTS iam_tenants (tenant_id TEXT PRIMARY KEY);
CREATE TABLE IF NOT EXISTS iam_subjects (
    tenant_id TEXT NOT NULL REFERENCES iam_tenants,
    subject_id TEXT NOT NULL, active BOOLEAN NOT NULL,
    PRIMARY KEY (tenant_id, subject_id)
);
CREATE TABLE IF NOT EXISTS iam_groups (
    tenant_id TEXT NOT NULL REFERENCES iam_tenants,
    group_id TEXT NOT NULL, active BOOLEAN NOT NULL,
    PRIMARY KEY (tenant_id, group_id)
);
CREATE TABLE IF NOT EXISTS iam_roles (
    tenant_id TEXT NOT NULL REFERENCES iam_tenants,
    role_id TEXT NOT NULL, name TEXT NOT NULL, active BOOLEAN NOT NULL,
    PRIMARY KEY (tenant_id, role_id)
);
CREATE TABLE IF NOT EXISTS iam_role_permissions (
    tenant_id TEXT NOT NULL, role_id TEXT NOT NULL, permission TEXT NOT NULL,
    PRIMARY KEY (tenant_id, role_id, permission),
    FOREIGN KEY (tenant_id, role_id) REFERENCES iam_roles DEFERRABLE INITIALLY DEFERRED
);
CREATE TABLE IF NOT EXISTS iam_memberships (
    tenant_id TEXT NOT NULL, group_id TEXT NOT NULL, subject_id TEXT NOT NULL, active BOOLEAN NOT NULL,
    PRIMARY KEY (tenant_id, group_id, subject_id),
    FOREIGN KEY (tenant_id, group_id) REFERENCES iam_groups DEFERRABLE INITIALLY DEFERRED,
    FOREIGN KEY (tenant_id, subject_id) REFERENCES iam_subjects DEFERRABLE INITIALLY DEFERRED
);
CREATE INDEX IF NOT EXISTS iam_memberships_subject ON iam_memberships (tenant_id, subject_id, group_id) WHERE active;
CREATE TABLE IF NOT EXISTS iam_bindings (
    tenant_id TEXT NOT NULL, principal_type TEXT NOT NULL CHECK (principal_type IN ('subject', 'group')),
    principal_id TEXT NOT NULL, role_id TEXT NOT NULL,
    scope_type TEXT NOT NULL, scope_id TEXT NOT NULL,
    active BOOLEAN NOT NULL, expires_at BIGINT,
    PRIMARY KEY (tenant_id, principal_type, principal_id, role_id, scope_type, scope_id),
    FOREIGN KEY (tenant_id, role_id) REFERENCES iam_roles DEFERRABLE INITIALLY DEFERRED,
    CHECK ((scope_type = 'tenant' AND scope_id = '*') OR (scope_type <> 'tenant' AND scope_id <> '*'))
);
CREATE TABLE IF NOT EXISTS iam_audit (
    entry_id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL REFERENCES iam_tenants,
    actor_id TEXT NOT NULL, operation TEXT NOT NULL, target TEXT NOT NULL, occurred_at BIGINT NOT NULL, snapshot TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS iam_audit_tenant ON iam_audit (tenant_id, occurred_at, entry_id);
