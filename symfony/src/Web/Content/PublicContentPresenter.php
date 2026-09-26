<?php

declare(strict_types=1);

namespace App\Web\Content;

use App\Web\Content\ViewModel\PublicArticleViewModel;
use App\Web\Content\ViewModel\PublicBlogViewModel;
use App\Web\Content\ViewModel\PublicGuideViewModel;

final class PublicContentPresenter
{
    /** @param array<string,mixed> $data */
    public function blog(array $data): PublicBlogViewModel
    {
        return new PublicBlogViewModel(
            items: array_values(array_filter($data['items'] ?? [], 'is_array')),
            page: max(1, (int) ($data['page'] ?? 1)),
            pages: max(1, (int) ($data['pages'] ?? 1)),
            total: max(0, (int) ($data['total'] ?? 0)),
        );
    }

    /** @param array{article:array<string,mixed>,related:list<array<string,mixed>>} $data */
    public function article(array $data, string $baseUrl): PublicArticleViewModel
    {
        $article = $data['article'];
        $canonical = trim((string) ($article['canonical_url'] ?? ''));
        if ($canonical === '') {
            $canonical = rtrim($baseUrl, '/') . '/blog/' . rawurlencode((string) ($article['slug'] ?? ''));
        }

        return new PublicArticleViewModel(
            article: $article,
            related: $data['related'],
            canonicalUrl: $canonical,
            metaTitle: (string) (($article['meta_title'] ?? '') ?: (($article['title'] ?? 'Матеріал') . ' | Terra Nova CLUB')),
            metaDescription: (string) (($article['meta_description'] ?? '') ?: ($article['excerpt'] ?? '')),
            metaImage: (string) (($article['og_image_url'] ?? '') ?: ($article['featured_image_url'] ?? '')),
            robots: (string) (($article['robots'] ?? '') ?: 'index,follow'),
            schema: $this->schema($article, 'Article'),
        );
    }

    /** @param array<string,mixed> $landing */
    public function guide(array $landing, string $baseUrl): PublicGuideViewModel
    {
        $canonical = trim((string) ($landing['canonical_url'] ?? ''));
        if ($canonical === '') {
            $canonical = rtrim($baseUrl, '/') . '/guide/' . rawurlencode((string) ($landing['slug'] ?? ''));
        }

        return new PublicGuideViewModel(
            landing: $landing,
            canonicalUrl: $canonical,
            metaTitle: (string) (($landing['meta_title'] ?? '') ?: (($landing['title'] ?? 'Guide') . ' | Terra Nova CLUB')),
            metaDescription: (string) (($landing['meta_description'] ?? '') ?: ($landing['excerpt'] ?? '')),
            metaImage: (string) (($landing['og_image_url'] ?? '') ?: ($landing['featured_image_url'] ?? '')),
            robots: (string) (($landing['robots'] ?? '') ?: 'index,follow'),
            schema: $this->schema($landing, 'WebPage'),
        );
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function schema(array $item, string $type): array
    {
        $encoded = trim((string) ($item['schema_json'] ?? ''));
        if ($encoded !== '') {
            $decoded = json_decode($encoded, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => $type,
            'name' => (string) ($item['title'] ?? ''),
            'description' => (string) (($item['meta_description'] ?? '') ?: ($item['excerpt'] ?? '')),
        ];

        if ($type === 'Article') {
            $schema['headline'] = (string) ($item['title'] ?? '');
            $schema['datePublished'] = $item['published_at'] ?? null;
            $schema['dateModified'] = $item['updated_at'] ?? null;
            $schema['author'] = ['@type' => 'Organization', 'name' => 'Terra Nova CLUB'];
            $schema['publisher'] = ['@type' => 'Organization', 'name' => 'Terra Nova CLUB'];
            if (!empty($item['featured_image_url'])) {
                $schema['image'] = [(string) $item['featured_image_url']];
            }
        }

        return $schema;
    }
}
