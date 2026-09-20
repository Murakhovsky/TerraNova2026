CREATE TABLE IF NOT EXISTS tn_content_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    content_type VARCHAR(32) NOT NULL,
    status VARCHAR(24) NOT NULL,
    title VARCHAR(240) NOT NULL,
    slug VARCHAR(191) NOT NULL,
    category VARCHAR(120) NULL,
    excerpt VARCHAR(2000) NULL,
    body_html TEXT NOT NULL,
    featured_image_url VARCHAR(700) NULL,
    featured_image_alt VARCHAR(240) NULL,
    meta_title VARCHAR(240) NULL,
    meta_description VARCHAR(500) NULL,
    canonical_url VARCHAR(700) NULL,
    og_image_url VARCHAR(700) NULL,
    robots VARCHAR(32) NOT NULL DEFAULT 'index,follow',
    schema_json JSON NULL,
    tags_json JSON NULL,
    author_user_id BIGINT UNSIGNED NULL,
    published_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO tn_content_items(
    id,content_type,status,title,slug,category,excerpt,body_html,
    featured_image_url,featured_image_alt,meta_title,meta_description,
    canonical_url,og_image_url,robots,schema_json,tags_json,author_user_id,published_at,updated_at
)
VALUES
    (9201,'blog_post','published','CI Public Post','seo-ci-public-post','CI','Published public blog fixture.','<p>CI_PUBLIC_BLOG_BODY</p>',
     NULL,NULL,NULL,'Published public blog fixture.',NULL,NULL,'index,follow',NULL,'["ci","public"]',NULL,NOW(),NOW()),
    (9202,'seo_landing','published','CI Public Guide','seo-ci-public-guide','CI','Published public guide fixture.','<p>CI_PUBLIC_GUIDE_BODY</p>',
     NULL,NULL,NULL,'Published public guide fixture.',NULL,NULL,'index,follow',NULL,'["ci","guide"]',NULL,NOW(),NOW()),
    (9203,'blog_post','draft','CI Draft Post','seo-ci-draft-post','CI','Draft blog fixture.','<p>CI_DRAFT_BLOG_BODY</p>',
     NULL,NULL,NULL,'Draft blog fixture.',NULL,NULL,'index,follow',NULL,'[]',NULL,NULL,NOW()),
    (9204,'blog_post','published','CI Noindex Post','seo-ci-noindex-post','CI','Published noindex fixture.','<p>CI_NOINDEX_BLOG_BODY</p>',
     NULL,NULL,NULL,'Published noindex fixture.',NULL,NULL,'noindex,follow',NULL,'[]',NULL,NOW(),NOW())
ON DUPLICATE KEY UPDATE
    content_type=VALUES(content_type),
    status=VALUES(status),
    title=VALUES(title),
    slug=VALUES(slug),
    category=VALUES(category),
    excerpt=VALUES(excerpt),
    body_html=VALUES(body_html),
    meta_description=VALUES(meta_description),
    robots=VALUES(robots),
    tags_json=VALUES(tags_json),
    published_at=VALUES(published_at),
    updated_at=VALUES(updated_at);
