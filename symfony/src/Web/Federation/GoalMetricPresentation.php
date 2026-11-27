<?php
declare(strict_types=1);

namespace App\Web\Federation;

use App\Persistence\Federation\FederationCapabilityBindingResolver;
use App\Persistence\Federation\FederationTrustedOutcomeEvidenceResolver;
use DateTimeImmutable;
use DateTimeZone;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Orchestration\Goal\FederationGolden50GoalProfile;
use Platform\Orchestration\Goal\FederationSmallTaskPlanFactory;
use Platform\Orchestration\Goal\GoalSpecification;
use Throwable;

/**
 * UI metadata for existing Federation criteria only. Not a metric registry,
 * planner, authority or an evaluator. All measurements use the same trusted
 * evidence resolver as canonical Goal outcomes; baselines are not persisted.
 */
final class GoalMetricPresentation
{
    private const METRICS = [
        'sales.local_tasks_created' => ['Створені завдання CRM', 'завдань', 'Кількість фактично створених локальних Sales-завдань; не кількість завершених Workflow.', 'Sales · журнал завдань CRM', 'period'],
        'sales.won_deals' => ['Виграні угоди', 'угод', 'Кількість угод, закритих із виграшем за період.', 'Sales · історія закриття угод', 'period'],
        'sales.closed_deals' => ['Закриті угоди', 'угод', 'Кількість усіх закритих угод, включно з програними.', 'Sales · історія закриття угод', 'period'],
        'growth.inbound_responses_recorded' => ['Отримані відповіді контактів', 'відповідей', 'Кількість зареєстрованих вхідних відповідей.', 'Growth · журнал відповідей', 'period'],
        'capital_markets.research_results_validated' => ['Перевірені дослідження', 'результатів', 'Кількість валідованих результатів досліджень.', 'Capital Markets · результати досліджень', 'period'],
        'documents.signatures_recorded' => ['Зареєстровані підписи', 'підписів', 'Кількість зафіксованих підписів документів.', 'Документи · реєстр підписів', 'period'],
        'growth.run_linked_inbound_responses' => ['Відповіді, пов’язані з виконанням', 'відповідей', 'Відповіді з доведеним зв’язком із перевіреними діями конкретного запуску.', 'Growth · підтверджені дії та відповіді', 'run'],
        'capital_markets.run_linked_validated_results' => ['Дослідження конкретного запуску', 'результатів', 'Валідовані дослідження із підтвердженим походженням від дій запуску.', 'Capital Markets · журнал походження результатів', 'run'],
        'documents.run_linked_signatures' => ['Підписи конкретного запуску', 'підписів', 'Підписи з підтвердженим походженням від дій запуску.', 'Документи · журнал походження результатів', 'run'],
        FederationGolden50GoalProfile::METRIC => ['Перевірені комерційні пропозиції (Golden 50)', 'пропозицій', 'Пропозиції з перевіреними фактами Growth, Sales і Documents; не виграні угоди.', 'Міждоменний агрегат Growth → Sales → Documents', 'aggregate'],
    ];

    public static function supports(string $id): bool
    {
        return isset(self::METRICS[$id]);
    }

    public static function definitions(): array
    {
        $result = [];
        foreach (self::METRICS as $id => [$label, $unit, $definition, $source, $scope]) {
            $result[$id] = compact('id', 'label', 'unit', 'definition', 'source', 'scope') + [
                'fixed_target' => $id === FederationGolden50GoalProfile::METRIC ? FederationGolden50GoalProfile::TARGET : null,
                'baseline' => null, 'window_start' => null, 'window_end' => null, 'evidence' => [],
                'source_reference' => null, 'readiness' => 'unknown', 'plan_ready' => false,
            ];
        }
        return $result;
    }

    public static function catalog(
        TenantContext $actor,
        FederationTrustedOutcomeEvidenceResolver $outcomes,
        FederationCapabilityBindingResolver $bindings,
        DateTimeImmutable $now,
    ): array {
        if (!$actor->isManager() || !$actor->allows(TenantPermissions::MANAGE)) {
            throw new \DomainException('Manager authorization required.');
        }
        $result = self::definitions();
        $to = $now->setTimezone(new DateTimeZone('UTC'));
        $from = $to->modify('-30 days');
        foreach ($result as $id => &$entry) {
            if ($entry['scope'] === 'period') {
                try {
                    // Local immutable query context only; never create a Goal
                    // or accept a baseline/criterion observation from the browser.
                    $probe = new GoalSpecification('baseline-read', $actor->organizationId()->value(),
                        $actor->userId()->value(), 'Historical baseline preview',
                        [['id' => $id, 'operator' => 'at_least', 'expected' => 1]], []);
                    $observation = $outcomes->collect($probe, $from, $to)[$id] ?? null;
                    if (is_int($observation['value'] ?? null) && $observation['value'] >= 0) {
                        $entry['baseline'] = $observation['value'];
                        $entry['window_start'] = $from->format('Y-m-d H:i') . ' UTC';
                        $entry['window_end'] = $to->format('Y-m-d H:i') . ' UTC';
                        $entry['evidence'] = $observation['evidence'];
                        $entry['source_reference'] = $observation['source'];
                        $entry['readiness'] = 'observed';
                    }
                } catch (Throwable) {
                    // A failed source is unknown, not a zero or a verified outcome.
                }
            } else {
                $entry['readiness'] = $entry['scope'] === 'run' ? 'run_required' : 'aggregate_required';
            }
            try {
                $capability = in_array($id, FederationSmallTaskPlanFactory::CRITERIA, true)
                    ? FederationSmallTaskPlanFactory::CAPABILITY
                    : ($id === FederationGolden50GoalProfile::METRIC ? 'growth.market.discovery' : null);
                if ($capability !== null) {
                    $bindings->requireExecutable($actor, $capability);
                    $entry['plan_ready'] = $id !== FederationGolden50GoalProfile::METRIC
                        || count(array_filter(['federation', 'growth', 'sales', 'documents'],
                            static fn(string $module): bool => $bindings->isTenantModuleEnabled($actor, $module))) === 4;
                }
            } catch (Throwable) {
                // Proposal and execution services independently recheck authority.
            }
        }
        unset($entry);
        return $result;
    }

    /** Strict user-facing deadline; canonical runtime still owns the evidence window. */
    public static function validDeadline(string $value, DateTimeImmutable $now): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) return false;
        $zone = new DateTimeZone('Europe/Kyiv');
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
        return $day !== false && $day->format('Y-m-d') === $value
            && $day >= $now->setTimezone($zone)->setTime(0, 0);
    }

    public static function capabilityLabel(string $id): string
    {
        return match ($id) {
            'sales.create_task' => 'Створити завдання CRM',
            'growth.market.discovery' => 'Знайти потенційних клієнтів',
            'growth.candidate.qualify' => 'Кваліфікувати кандидата',
            'growth.handoff.prepare' => 'Підготувати передачу кандидата',
            'growth.handoff.target.sales' => 'Передати кандидата в Sales',
            'documents.proposal.prepare' => 'Підготувати комерційну пропозицію',
            'property.bulk.create' => 'Імпорт об’єктів нерухомості',
            default => ucfirst(str_replace(['.', '_'], ' ', $id)),
        };
    }
}
