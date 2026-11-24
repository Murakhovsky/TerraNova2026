-- Shopify S5: verified order identity pointer only. No order PII or Sales writes.
-- Runs once through Product Management module migration lifecycle.
ALTER TABLE pm_webhook_inbox
  ADD COLUMN resource_gid VARCHAR(191) NULL AFTER payload_sha256,
  ADD KEY idx_pm_webhook_order_pointer (organization_id,connection_id,resource_gid,state);
