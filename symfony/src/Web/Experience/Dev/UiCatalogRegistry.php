<?php

declare(strict_types=1);

namespace App\Web\Experience\Dev;

final class UiCatalogRegistry
{
    /** @return list<UiCatalogEntry> */
    public function entries(): array
    {
        return [
            $this->entry('CosIcon', 'Foundation', 'Semantic icon mapped through the COS icon contract.', ['default', 'semantic'], '#catalog-foundation-primitives'),
            $this->entry('CosAvatar', 'Foundation', 'Identity avatar with semantic sizing.', ['sm', 'md', 'lg'], '#catalog-foundation-primitives'),
            $this->entry('CosDivider', 'Foundation', 'Structural divider for dense surfaces.', ['default'], '#catalog-foundation-primitives'),

            $this->entry('CosButton', 'Actions', 'Canonical button presentation for governed UIAction flows.', ['primary', 'secondary', 'ghost', 'danger', 'disabled'], '#buttons-heading'),
            $this->entry('CosIconButton', 'Actions', 'Compact icon-only action with accessible labeling.', ['default', 'disabled'], '#buttons-heading'),
            $this->entry('CosBadge', 'Status', 'Compact semantic status label.', ['neutral', 'positive', 'warning', 'danger', 'info'], '#status-heading'),

            $this->entry('CosAlert', 'Feedback', 'Persistent semantic feedback message.', ['info', 'positive', 'warning', 'danger'], '#catalog-feedback-primitives'),
            $this->entry('CosEmptyState', 'Feedback', 'Explicit zero-result or first-use state.', ['default'], '#empty-heading'),
            $this->entry('CosProgress', 'Feedback', 'Determinate operation progress.', ['0', 'partial', 'complete'], '#catalog-feedback-primitives'),
            $this->entry('CosSkeleton', 'Feedback', 'Loading placeholder preserving layout rhythm.', ['line', 'circle', 'block'], '#catalog-feedback-primitives'),
            $this->entry('CosSpinner', 'Feedback', 'Indeterminate loading indicator with accessible label.', ['default'], '#catalog-feedback-primitives'),
            $this->entry('CosToast', 'Feedback', 'Transient operation feedback.', ['positive', 'warning', 'danger', 'info'], '#interaction-heading'),

            $this->entry('CosInput', 'Forms', 'Canonical text-like field primitive.', ['default', 'required', 'disabled', 'error'], '#forms-platform-heading'),
            $this->entry('CosTextarea', 'Forms', 'Multiline field primitive.', ['default', 'required', 'disabled', 'error'], '#catalog-form-primitives'),
            $this->entry('CosSelect', 'Forms', 'Canonical select field.', ['default', 'required', 'disabled', 'error'], '#forms-platform-heading'),
            $this->entry('CosCheckbox', 'Forms', 'Boolean selection control.', ['unchecked', 'checked', 'disabled'], '#catalog-form-primitives'),
            $this->entry('CosRadio', 'Forms', 'Single-choice selection control.', ['unchecked', 'checked', 'disabled'], '#catalog-form-primitives'),
            $this->entry('CosSwitch', 'Forms', 'Immediate boolean state control.', ['off', 'on', 'disabled'], '#catalog-form-primitives'),
            $this->entry('CosValidationSummary', 'Forms', 'Form-level validation summary and focus target.', ['errors'], '#forms-platform-heading'),

            $this->entry('CosModal', 'Interaction', 'Blocking overlay for focused tasks.', ['closed', 'open'], '#interaction-heading'),
            $this->entry('CosDrawer', 'Interaction', 'Context-preserving side overlay.', ['closed', 'open'], '#interaction-heading'),
            $this->entry('CosDropdown', 'Interaction', 'Compact action/menu surface.', ['closed', 'open'], '#interaction-heading'),
            $this->entry('CosTabs', 'Interaction', 'Local view navigation.', ['active', 'disabled'], '#interaction-heading'),
            $this->entry('CosPopover', 'Interaction', 'Contextual floating information.', ['closed', 'open'], '#interaction-heading'),
            $this->entry('CosTooltip', 'Interaction', 'Accessible short-form contextual hint.', ['focus', 'hover'], '#interaction-heading'),
            $this->entry('CosConfirm', 'Interaction', 'Explicit confirmation for consequential actions.', ['default', 'step-up'], '#interaction-heading'),

            $this->entry('CosCard', 'Data', 'Persistent structured surface using semantic hierarchy.', ['default', 'raised'], '#cards-heading'),
            $this->entry('CosMetric', 'Data', 'KPI/value presentation.', ['default', 'trend'], '#cards-heading'),
            $this->entry('CosDataGrid', 'Data', 'Server-owned dense data exploration platform.', ['search', 'filter', 'sort', 'pagination', 'bulk', 'mobile'], '#data-platform-heading'),

            $this->entry('CosWorkspace', 'Workspace', 'Canonical entity/workflow workspace composition.', ['desktop', 'mobile'], '/dev/workspace'),
            $this->entry('CosWorkspaceHeader', 'Workspace', 'Workspace identity, state and action header.', ['default', 'dense'], '/dev/workspace'),
            $this->entry('CosContextPanel', 'Workspace', 'Secondary contextual information panel.', ['default', 'collapsed'], '/dev/workspace'),
            $this->entry('CosActivityPanel', 'Workspace', 'Entity activity/history panel.', ['default', 'empty'], '/dev/workspace'),

            $this->entry('CosRealtimeSubscription', 'Realtime', 'Declarative Turbo/Mercure subscription boundary.', ['connected', 'reconnecting', 'offline'], '/dev/realtime'),

            $this->entry('CosAIContext', 'AI', 'Headless UI context exposed to agents.', ['default'], '/dev/workspace'),
            $this->entry('CosAgentRun', 'AI', 'Agent execution surface.', ['queued', 'running', 'completed', 'failed'], '/dev/workspace'),
            $this->entry('CosAgentStatus', 'AI', 'Canonical agent status indicator.', ['queued', 'running', 'completed', 'failed'], '/dev/workspace'),
            $this->entry('CosAgentResult', 'AI', 'Structured agent result container.', ['default', 'empty'], '/dev/workspace'),
            $this->entry('CosAgentMetric', 'AI', 'Metric emitted by an agent result.', ['default'], '/dev/workspace'),
            $this->entry('CosAgentEvidence', 'AI', 'Evidence/source presentation for agent output.', ['default'], '/dev/workspace'),
            $this->entry('CosAgentRecommendation', 'AI', 'Structured recommendation item.', ['default'], '/dev/workspace'),
            $this->entry('CosAgentWarning', 'AI', 'Agent warning/risk presentation.', ['warning', 'danger'], '/dev/workspace'),
            $this->entry('CosAgentAction', 'AI', 'Governed action proposed by an agent.', ['available', 'confirmation', 'disabled'], '/dev/workspace'),
        ];
    }

    /** @return array<string,list<array{name:string,category:string,description:string,states:list<string>,reference:string,maturity:string,search:string}>> */
    public function grouped(): array
    {
        $groups = [];

        foreach ($this->entries() as $entry) {
            $groups[$entry->category][] = $entry->toArray();
        }

        return $groups;
    }

    /** @return list<string> */
    public function categories(): array
    {
        return array_keys($this->grouped());
    }

    /** @return array{components:int,categories:int,stable:int,reference:int,experimental:int} */
    public function stats(): array
    {
        $entries = $this->entries();
        $maturity = array_count_values(array_map(static fn (UiCatalogEntry $entry): string => $entry->maturity, $entries));

        return [
            'components' => count($entries),
            'categories' => count($this->categories()),
            'stable' => $maturity['stable'] ?? 0,
            'reference' => $maturity['reference'] ?? 0,
            'experimental' => $maturity['experimental'] ?? 0,
        ];
    }

    /** @param list<string> $states */
    private function entry(
        string $name,
        string $category,
        string $description,
        array $states,
        string $reference,
        string $maturity = 'stable',
    ): UiCatalogEntry {
        return new UiCatalogEntry($name, $category, $description, $states, $reference, $maturity);
    }
}
