UPDATE cos_policies
SET status = 'DISABLED', updated_at = CURRENT_TIMESTAMP
WHERE organization_id = 'default'
  AND id IN (
      'policy-discount-small-auto-v1',
      'policy-discount-large-approval-v1',
      'policy-ai-delete-denied-v1'
  );

INSERT INTO tn_migrations (migration)
VALUES ('20260826_000018_remove_obsolete_cos_policies');
