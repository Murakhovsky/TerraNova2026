-- Retain immutable Action history and permit separately approved revisions.
-- Product-level admission is serialized using a connection-scoped MySQL lock.
ALTER TABLE pm_shopify_outbound_receipts
 ADD COLUMN request_json JSON NULL,
 DROP PRIMARY KEY,
 ADD PRIMARY KEY (organization_id,action_key),
 ADD KEY idx_pm_outbound_product (organization_id,connection_id,product_id,created_at);
