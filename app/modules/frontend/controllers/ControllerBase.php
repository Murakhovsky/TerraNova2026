<?php
declare(strict_types=1);

namespace Modules\Frontend\Controllers;

use PDO;
use Throwable;
use Phalcon\Mvc\Controller;

class ControllerBase extends Controller
{
    protected function getPdo(): PDO
    {
        $config = $this->di->getShared('config');

        return new PDO(
            sprintf(
                'mysql:host=%s;dbname=%s;charset=utf8mb4',
                (string) $config->database->host,
                (string) $config->database->dbname
            ),
            (string) $config->database->username,
            (string) $config->database->password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }

    protected function fetchFeaturedProperties(PDO $pdo, int $limit = 3): array
    {
        $limit = max(1, min($limit, 12));
        $statement = $pdo->query('
            SELECT
                p.id, p.public_id, p.slug, p.title, p.deal_type, p.status,
                p.price_amount, p.price_currency, p.price_period, p.area_total, p.rooms,
                p.short_description, p.is_featured, p.has_3d_tour, p.published_at,
                t.name_uk AS type_name,
                l.city, l.region,
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
            WHERE p.status = \'published\'
            GROUP BY p.id
            ORDER BY p.is_featured DESC, p.published_at DESC, p.id DESC
            LIMIT ' . $limit
        );

        return $statement->fetchAll();
    }

    protected function storeLead(PDO $pdo): string
    {
        $name = trim((string) (
            $this->request->getPost('full_name', 'string', null)
            ?? $this->request->getPost('name', 'string', '')
        ));
        $phone = trim((string) $this->request->getPost('phone', 'string', ''));
        $email = trim((string) $this->request->getPost('email', 'email', ''));
        $message = trim((string) (
            $this->request->getPost('message', 'string', null)
            ?? $this->request->getPost('comment', 'string', '')
        ));
        $propertyId = (int) $this->request->getPost('property_id', 'int', 0);
        $role = $this->leadRole((string) $this->request->getPost('role', 'string', 'buyer'));
        $dealType = $this->leadDealType((string) (
            $this->request->getPost('deal_type', 'string', null)
            ?? $this->request->getPost('lead_type', 'string', 'consultation')
        ));

        if ($name === '' || ($phone === '' && $email === '')) {
            return 'Заповніть ім’я та хоча б один контакт.';
        }

        try {
            $statement = $pdo->prepare('
                INSERT INTO tn_leads (
                    property_id, full_name, phone, email, role, deal_type, message,
                    preferred_contact, source_page, status
                ) VALUES (
                    :property_id, :full_name, :phone, :email, :role, :deal_type, :message,
                    :preferred_contact, :source_page, :status
                )
            ');
            $statement->execute([
                'property_id' => $propertyId > 0 ? $propertyId : null,
                'full_name' => $name,
                'phone' => $phone !== '' ? $phone : null,
                'email' => $email !== '' ? $email : null,
                'role' => $role,
                'deal_type' => $dealType,
                'message' => $message !== '' ? $message : null,
                'preferred_contact' => 'any',
                'source_page' => $this->request->getURI(),
                'status' => 'new',
            ]);
        } catch (Throwable $e) {
            return 'Заявку не вдалося зберегти. Спробуйте ще раз або напишіть нам напряму.';
        }

        return 'Заявку збережено. Менеджер Terra Nova отримає її в CRM.';
    }

    private function leadRole(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $roles = [
            'buyer' => 'buyer',
            'покупець' => 'buyer',
            'owner' => 'seller',
            'seller' => 'seller',
            'власник' => 'seller',
            'investor' => 'investor',
            'інвестор' => 'investor',
            'realtor' => 'realtor',
            'рієлтор' => 'realtor',
            'developer' => 'developer',
            'забудовник' => 'developer',
            'partner' => 'partner',
            'партнер' => 'partner',
        ];

        return $roles[$value] ?? 'other';
    }

    private function leadDealType(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $types = [
            'sale' => 'sale',
            'купівля' => 'sale',
            'продаж' => 'sale',
            'rent' => 'rent',
            'оренда' => 'rent',
            'investment' => 'investment',
            'інвестиції' => 'investment',
            'заявка інвестора' => 'investment',
            'consultation' => 'consultation',
        ];

        return $types[$value] ?? 'consultation';
    }
}
