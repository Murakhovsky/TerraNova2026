CREATE TABLE IF NOT EXISTS tn_content_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    content_type VARCHAR(32) NOT NULL,
    status VARCHAR(24) NOT NULL,
    slug VARCHAR(191) NOT NULL,
    robots VARCHAR(32) NOT NULL DEFAULT 'index,follow',
    published_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO tn_content_items(id,content_type,status,slug,robots,published_at,updated_at)
VALUES
    (9201,'blog_post','published','seo-ci-public-post','index,follow',NOW(),NOW()),
    (9202,'seo_landing','published','seo-ci-public-guide','index,follow',NOW(),NOW()),
    (9203,'blog_post','draft','seo-ci-draft-post','index,follow',NULL,NOW()),
    (9204,'blog_post','published','seo-ci-noindex-post','noindex,follow',NOW(),NOW())
ON DUPLICATE KEY UPDATE
    content_type=VALUES(content_type),
    status=VALUES(status),
    slug=VALUES(slug),
    robots=VALUES(robots),
    published_at=VALUES(published_at),
    updated_at=VALUES(updated_at);
