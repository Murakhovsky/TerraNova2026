<?php
declare(strict_types=1);

namespace Infrastructure\Persistence\MySql\Property;

use Domains\Property\Application\Contract\PropertyCatalogInterface;
use Domains\Property\Application\Contract\PropertyPresentationInterface;
use Domains\Notification\Application\Contract\TelegramAutomationInterface;
use Infrastructure\Database\Connection\DatabaseService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Throwable;

class PropertyPresentationService implements PropertyPresentationInterface
{
    private const VARIANTS = ['client', 'partner'];
    private const CHANNELS = ['email', 'telegram', 'viber'];
    private const REMOTE_IMAGE_HOSTS = ['images.unsplash.com'];
    private const MAX_IMAGE_BYTES = 10485760;

    public function __construct(
        private PropertyCatalogInterface $catalog,
        private DatabaseService $database,
        private ?TelegramAutomationInterface $telegram = null
    ) {
    }

    public function generate(string $slug, string $variant = 'client'): ?array
    {
        $variant = $this->variant($variant);
        $property = $this->catalog->propertyBySlug($slug);

        if ($property) {
            if ($variant === 'client' && !in_array((string) $property['status'], ['published', 'active', 'reserved'], true)) {
                return null;
            }

            $images = array_slice($this->catalog->propertyImages((int) $property['id']), 0, 8);
            foreach ($images as &$image) {
                $image['data_url'] = $this->imageDataUrl((string) ($image['image_url'] ?? ''));
            }
            unset($image);

            $html = $this->template([
                'documentType' => 'property',
                'variant' => $variant,
                'property' => $property,
                'images' => $images,
                'features' => $this->catalog->propertyFeatures((int) $property['id']),
                'group' => null,
                'properties' => [],
                'generatedAt' => date('d.m.Y H:i'),
            ]);

            return [
                'bytes' => $this->render($html),
                'filename' => 'terra-nova-' . $this->filenamePart((string) $property['slug']) . '-' . $variant . '.pdf',
                'variant' => $variant,
                'kind' => 'property',
                'property_id' => (int) $property['id'],
                'group_id' => null,
                'title' => (string) $property['title'],
                'updated_at' => (string) ($property['updated_at'] ?? ''),
            ];
        }

        $group = $this->catalog->propertyGroupBySlug($slug);
        if (!$group) {
            return null;
        }

        $properties = $variant === 'partner'
            ? $this->partnerGroupProperties((int) $group['id'])
            : $this->catalog->propertyGroupPresentationProperties((int) $group['id']);
        foreach ($properties as &$item) {
            $item['cover_data_url'] = $this->imageDataUrl((string) ($item['cover_url'] ?? ''));
        }
        unset($item);

        $html = $this->template([
            'documentType' => 'group',
            'variant' => $variant,
            'property' => null,
            'images' => [],
            'features' => [],
            'group' => $group,
            'properties' => $properties,
            'generatedAt' => date('d.m.Y H:i'),
        ]);

        return [
            'bytes' => $this->render($html),
            'filename' => 'terra-nova-' . $this->filenamePart((string) $group['slug']) . '-' . $variant . '.pdf',
            'variant' => $variant,
            'kind' => 'group',
            'property_id' => null,
            'group_id' => (int) $group['id'],
            'title' => (string) $group['title'],
            'updated_at' => (string) ($group['updated_at'] ?? ''),
        ];
    }

    public function recordDownload(array $document, ?array $user, string $sourcePage): void
    {
        try {
            $propertyId = !empty($document['property_id']) ? (int) $document['property_id'] : null;
            $this->database->connection()->prepare('
                INSERT INTO tn_analytics_events (
                    event_type, entity_type, entity_id, property_id, user_id, source_page, payload
                ) VALUES (
                    "presentation_download", :entity_type, :entity_id, :property_id, :user_id, :source_page, :payload
                )
            ')->execute([
                'entity_type' => $propertyId ? 'property' : 'system',
                'entity_id' => $propertyId ?: null,
                'property_id' => $propertyId,
                'user_id' => !empty($user['id']) ? (int) $user['id'] : null,
                'source_page' => mb_substr($sourcePage, 0, 255),
                'payload' => json_encode([
                    'variant' => $document['variant'],
                    'kind' => $document['kind'],
                    'group_id' => $document['group_id'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            if ($propertyId && !empty($user['id'])) {
                $this->recordPropertyActivity(
                    $propertyId,
                    !empty($user['id']) ? (int) $user['id'] : null,
                    'PDF-презентацію сформовано',
                    'Версія: ' . ($document['variant'] === 'partner' ? 'партнерська' : 'клієнтська')
                );
            }
        } catch (Throwable) {
            // Presentation delivery must not fail because analytics is unavailable.
        }
    }

    public function registerShare(array $input, ?array $user = null): array
    {
        $caseId = (int) ($input['client_case_id'] ?? 0);
        $slug = trim((string) ($input['slug'] ?? ''));
        $variant = $this->variant((string) ($input['variant'] ?? 'client'));
        $channel = in_array((string) ($input['channel'] ?? ''), self::CHANNELS, true)
            ? (string) $input['channel']
            : 'email';
        $pdfUrl = trim((string) ($input['pdf_url'] ?? ''));
        $pageUrl = trim((string) ($input['page_url'] ?? ''));

        $case = $this->database->fetchOne('
            SELECT c.id, c.person_id, c.public_id, c.title, p.full_name, p.email, p.phone, p.telegram
            FROM tn_client_cases c
            INNER JOIN tn_people p ON p.id = c.person_id
            WHERE c.id = :id AND c.status IN ("active", "paused")
            LIMIT 1
        ', ['id' => $caseId]);
        $document = $slug !== '' ? $this->documentContext($slug, $variant) : null;

        if (!$case || !$document || !$this->safePublicUrl($pdfUrl) || !$this->safePublicUrl($pageUrl)) {
            return ['ok' => false, 'message' => 'Не вдалося підготувати надсилання презентації.', 'redirect_url' => null];
        }

        $channelLabels = ['email' => 'Email', 'telegram' => 'Telegram', 'viber' => 'Viber'];
        $subject = 'Презентація Terra Nova: ' . $document['title'];
        $message = implode("\n", [
            'Добрий день, ' . $case['full_name'] . '.',
            '',
            'Підготували презентацію: ' . $document['title'] . '.',
            'PDF: ' . $pdfUrl,
            'Сторінка об’єкта: ' . $pageUrl,
            '',
            'Terra Nova CLUB',
        ]);

        try {
            $pdo = $this->database->connection();
            $pdo->beginTransaction();

            $pdo->prepare('
                INSERT INTO tn_client_case_activities (
                    client_case_id, person_id, user_id, activity_type, title, body, completed_at
                ) VALUES (
                    :client_case_id, :person_id, :user_id, "presentation", :title, :body, NOW()
                )
            ')->execute([
                'client_case_id' => $caseId,
                'person_id' => (int) $case['person_id'],
                'user_id' => !empty($user['id']) ? (int) $user['id'] : null,
                'title' => 'Презентацію підготовлено для надсилання',
                'body' => $channelLabels[$channel] . ': ' . $document['title'] . "\n" . $pdfUrl,
            ]);

            if (!empty($document['property_id'])) {
                $propertyId = (int) $document['property_id'];
                $pdo->prepare('
                    INSERT INTO tn_client_case_property_matches (client_case_id, property_id, match_status, note)
                    VALUES (:client_case_id, :property_id, "sent", :note)
                    ON DUPLICATE KEY UPDATE match_status = "sent", note = VALUES(note), updated_at = NOW()
                ')->execute([
                    'client_case_id' => $caseId,
                    'property_id' => $propertyId,
                    'note' => 'Презентація: ' . $channelLabels[$channel],
                ]);

                $this->recordPropertyActivity(
                    $propertyId,
                    !empty($user['id']) ? (int) $user['id'] : null,
                    'Презентацію надіслано клієнту',
                    $case['public_id'] . ' / ' . $channelLabels[$channel],
                    $pdo
                );
            }

            $pdo->prepare('
                INSERT INTO tn_analytics_events (
                    event_type, entity_type, entity_id, property_id, user_id, source_page, payload
                ) VALUES (
                    "presentation_share", :entity_type, :entity_id, :property_id, :user_id, :source_page, :payload
                )
            ')->execute([
                'entity_type' => !empty($document['property_id']) ? 'property' : 'client_case',
                'entity_id' => !empty($document['property_id']) ? (int) $document['property_id'] : $caseId,
                'property_id' => !empty($document['property_id']) ? (int) $document['property_id'] : null,
                'user_id' => !empty($user['id']) ? (int) $user['id'] : null,
                'source_page' => mb_substr($pageUrl, 0, 255),
                'payload' => json_encode([
                    'client_case_id' => $caseId,
                    'channel' => $channel,
                    'variant' => $variant,
                    'kind' => $document['kind'],
                    'group_id' => $document['group_id'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            $pdo->commit();
            if (!empty($document['property_id'])) {
                $this->telegram?->notifyPresentationShared(
                    (int) $document['property_id'],
                    !empty($user['id']) ? (int) $user['id'] : null,
                    $channel,
                    $variant
                );
            }
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return ['ok' => false, 'message' => 'Не вдалося записати надсилання в CRM.', 'redirect_url' => null];
        }

        $redirectUrl = match ($channel) {
            'telegram' => 'https://t.me/share/url?url=' . rawurlencode($pdfUrl) . '&text=' . rawurlencode($subject),
            'viber' => 'viber://forward?text=' . rawurlencode($message),
            default => 'mailto:' . rawurlencode((string) ($case['email'] ?? ''))
                . '?subject=' . rawurlencode($subject)
                . '&body=' . rawurlencode($message),
        };

        return [
            'ok' => true,
            'message' => 'Презентацію записано в історію кейсу.',
            'redirect_url' => $redirectUrl,
        ];
    }

    private function render(string $html): string
    {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('chroot', [BASE_PATH . '/public', BASE_PATH . '/vendor/dompdf/dompdf/lib/fonts']);
        $options->set('tempDir', BASE_PATH . '/tmp/pdfs');
        $options->set('fontCache', BASE_PATH . '/tmp/pdfs/fonts');

        $this->ensureDirectory(BASE_PATH . '/tmp/pdfs/fonts');

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
        $dompdf->getCanvas()->page_text(520, 806, '{PAGE_NUM} / {PAGE_COUNT}', $font, 8, [0.35, 0.37, 0.35]);

        return $dompdf->output();
    }

    private function documentContext(string $slug, string $variant): ?array
    {
        $property = $this->catalog->propertyBySlug($slug);
        if ($property) {
            if ($variant === 'client' && !in_array((string) $property['status'], ['published', 'active', 'reserved'], true)) {
                return null;
            }

            return [
                'variant' => $variant,
                'kind' => 'property',
                'property_id' => (int) $property['id'],
                'group_id' => null,
                'title' => (string) $property['title'],
            ];
        }

        $group = $this->catalog->propertyGroupBySlug($slug);
        if (!$group) {
            return null;
        }

        return [
            'variant' => $variant,
            'kind' => 'group',
            'property_id' => null,
            'group_id' => (int) $group['id'],
            'title' => (string) $group['title'],
        ];
    }

    private function partnerGroupProperties(int $groupId): array
    {
        return $this->database->fetchAll('
            SELECT
                p.id, p.public_id, p.slug, p.title, p.deal_type, p.status, p.source_type,
                p.price_amount, p.price_currency, p.price_period, p.min_price_amount,
                p.commission_type, p.commission_value, p.area_total, p.rooms,
                p.short_description, t.name_uk AS type_name, l.city,
                COALESCE(cover.image_url, first_image.image_url) AS cover_url
            FROM tn_properties p
            INNER JOIN tn_property_types t ON t.id = p.type_id
            INNER JOIN tn_locations l ON l.id = p.location_id
            LEFT JOIN tn_property_images cover ON cover.property_id = p.id AND cover.is_cover = 1
            LEFT JOIN tn_property_images first_image ON first_image.id = (
                SELECT i.id FROM tn_property_images i
                WHERE i.property_id = p.id
                ORDER BY i.sort_order, i.id
                LIMIT 1
            )
            WHERE p.property_group_id = :group_id
              AND p.status NOT IN ("archived")
            GROUP BY p.id
            ORDER BY FIELD(p.status, "published", "active", "reserved", "moderation", "draft", "sold"), p.sale_priority DESC, p.id DESC
        ', ['group_id' => $groupId]);
    }

    private function template(array $variables): string
    {
        extract($variables, EXTR_SKIP);
        ob_start();
        include APP_PATH . '/Interfaces/Web/View/property/pdf.phtml';

        return (string) ob_get_clean();
    }

    private function imageDataUrl(string $url): ?string
    {
        $bytes = $this->imageBytes($url);
        if ($bytes === null || !function_exists('imagecreatefromstring')) {
            return null;
        }

        $source = @imagecreatefromstring($bytes);
        if (!$source) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, 1600 / max(1, $width), 1200 / max(1, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        $white = imagecolorallocate($target, 255, 255, 255);
        imagefill($target, 0, 0, $white);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagejpeg($target, null, 76);
        $jpeg = (string) ob_get_clean();

        return 'data:image/jpeg;base64,' . base64_encode($jpeg);
    }

    private function imageBytes(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        $path = (string) ($parts['path'] ?? $url);
        $host = mb_strtolower((string) ($parts['host'] ?? ''));

        if ($host === '') {
            $publicRoot = realpath(BASE_PATH . '/public');
            $file = realpath(BASE_PATH . '/public/' . ltrim($path, '/'));
            if (!$publicRoot || !$file || !str_starts_with($file, $publicRoot . DIRECTORY_SEPARATOR) || !is_file($file)) {
                return null;
            }

            $size = filesize($file);
            return $size !== false && $size <= self::MAX_IMAGE_BYTES ? file_get_contents($file) ?: null : null;
        }

        if (($parts['scheme'] ?? '') !== 'https' || !in_array($host, self::REMOTE_IMAGE_HOSTS, true)) {
            return null;
        }

        $context = stream_context_create(['http' => ['timeout' => 8, 'follow_location' => 0, 'user_agent' => 'TerraNova-PDF/1.0']]);
        $stream = @fopen($url, 'rb', false, $context);
        if (!$stream) {
            return null;
        }

        $bytes = '';
        while (!feof($stream) && strlen($bytes) <= self::MAX_IMAGE_BYTES) {
            $chunk = fread($stream, 65536);
            if ($chunk === false) {
                fclose($stream);
                return null;
            }
            $bytes .= $chunk;
        }
        fclose($stream);

        return strlen($bytes) <= self::MAX_IMAGE_BYTES ? $bytes : null;
    }

    private function recordPropertyActivity(
        int $propertyId,
        ?int $userId,
        string $title,
        string $body,
        ?\PDO $pdo = null
    ): void {
        ($pdo ?: $this->database->connection())->prepare('
            INSERT INTO tn_property_activities (property_id, user_id, activity_type, title, body)
            VALUES (:property_id, :user_id, "presentation", :title, :body)
        ')->execute([
            'property_id' => $propertyId,
            'user_id' => $userId,
            'title' => mb_substr($title, 0, 180),
            'body' => $body,
        ]);
    }

    private function safePublicUrl(string $url): bool
    {
        $parts = parse_url($url);

        return in_array((string) ($parts['scheme'] ?? ''), ['http', 'https'], true)
            && !empty($parts['host']);
    }

    private function variant(string $variant): string
    {
        return in_array($variant, self::VARIANTS, true) ? $variant : 'client';
    }

    private function filenamePart(string $value): string
    {
        $value = preg_replace('/[^a-z0-9-]+/i', '-', $value) ?: 'presentation';

        return trim(mb_strtolower($value), '-');
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
    }
}
