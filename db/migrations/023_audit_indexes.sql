-- 1.15: audit log lookups (dashboard portal activity, client portal page, audit log filters)
ALTER TABLE audit_log
  ADD INDEX idx_audit_action (action, created_at),
  ADD INDEX idx_audit_portal_user (portal_user_id, id),
  ADD INDEX idx_audit_user (user_id, id);
