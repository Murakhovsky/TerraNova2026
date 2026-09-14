<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$manifest = require $root . '/app/Domains/Property/module.php';
$migration = file_get_contents($root . '/app/migrations/20260914_000054_property_v060_listings_publication.sql') ?: '';
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

$assert(version_compare((string)($manifest['version'] ?? '0.0.0'),'0.6.0','>='),'Property manifest must be at least V0.6.0.');
$assert(in_array('app/migrations/20260914_000054_property_v060_listings_publication.sql',$manifest['contributions']['migration_files'] ?? [],true),'Property V0.6 migration must be declared.');
foreach (['tn_property_channels','tn_property_listings','tn_property_listing_media','tn_property_publications','tn_property_listing_publication_history'] as $table) {
    $assert(str_contains($migration,'CREATE TABLE IF NOT EXISTS ' . $table),'Property V0.6 schema missing table: ' . $table);
}
$assert(str_contains($migration,'FOREIGN KEY (organization_id, inventory_id)'),'Listing must reference Inventory, not bypass it to Property.');
$assert(str_contains($migration,'channel_code VARCHAR(64) NOT NULL'),'Publication must own channel identity.');
$assert(str_contains($migration,'external_id VARCHAR(191) NULL') && str_contains($migration,'external_url VARCHAR(700) NULL'),'Channel publication must retain external identity and URL.');
$assert(str_contains($migration,'seo_title VARCHAR(220) NULL') && str_contains($migration,'seo_description VARCHAR(500) NULL'),'SEO belongs to Listing, not canonical Property.');
$assert(str_contains($migration,'media_reference VARCHAR(191) NOT NULL'),'ListingMedia must select media by reference.');

echo "Property V0.6 listings/publication schema: OK\n";
