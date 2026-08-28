<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\Persistence\MySql;

use Domains\Property\Application\Contract\PresentationSalesInterface;
use Infrastructure\Platform\Persistence\Pdo\PdoConnection;

final readonly class MysqlPresentationSales implements PresentationSalesInterface
{
    public function __construct(private PdoConnection $database) {}

    public function activeClientCase(int $caseId): ?array
    {
        return $this->database->fetchOne('
            SELECT c.id, c.person_id, c.public_id, c.title, p.full_name, p.email, p.phone, p.telegram
            FROM tn_client_cases c INNER JOIN tn_people p ON p.id = c.person_id
            WHERE c.id = :id AND c.status IN ("active", "paused") LIMIT 1
        ', ['id' => $caseId]);
    }

    public function recordShare(int $caseId, int $personId, ?int $userId, string $title, string $body, ?int $propertyId, string $matchNote): void
    {
        $pdo = $this->database->connection();
        $pdo->prepare('
            INSERT INTO tn_client_case_activities (
                client_case_id, person_id, user_id, activity_type, title, body, completed_at
            ) VALUES (:caseId, :personId, :userId, "presentation", :title, :body, NOW())
        ')->execute(compact('caseId', 'personId', 'userId', 'title', 'body'));

        if ($propertyId !== null) {
            $pdo->prepare('
                INSERT INTO tn_client_case_property_matches (client_case_id, property_id, match_status, note)
                VALUES (:case_id, :property_id, "sent", :note)
                ON DUPLICATE KEY UPDATE match_status = "sent", note = VALUES(note), updated_at = NOW()
            ')->execute(['case_id' => $caseId, 'property_id' => $propertyId, 'note' => $matchNote]);
        }
    }
}
