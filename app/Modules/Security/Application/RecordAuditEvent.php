<?php

namespace App\Modules\Security\Application;

use App\Models\User;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Models\AuditEvent;
use Illuminate\Support\Str;

class RecordAuditEvent
{
    /** @var array<string, list<string>> */
    private const ALLOWED_METADATA_KEYS = [
        'organization.setting.updated' => ['setting_key', 'value_type'],
        'organization.feature.updated' => ['feature_key', 'enabled'],
        'organization.credential.replaced' => ['provider', 'credential_name', 'status', 'old_revision_id', 'new_revision_id'],
        'ai.prompt.created' => ['prompt_key', 'capability'],
        'ai.prompt_version.created' => ['prompt_key', 'version'],
        'ai.prompt_version.activated' => ['prompt_key', 'version'],
        'ai.prompt_version.retired' => ['prompt_key', 'version'],
        'ai.provider_config.updated' => ['provider_name', 'is_enabled'],
        'ai.model_config.updated' => ['model_name', 'is_enabled'],
        'ai.model_config.created' => ['model_name', 'is_enabled'],
        'ai.model_release.activated' => ['model_name', 'release_number'],
        'ai.safety_control.updated' => ['is_ai_globally_enabled', 'limits_updated'],
        'ai.human_review.submitted' => ['ai_run_id', 'decision', 'safe_reason_code'],
        'client.created' => ['source'],
        'client.profile.updated' => ['source', 'fields'],
        'client.channel_identity.registered' => ['channel', 'verification_status'],
        'client.channel_identity.verified' => ['channel', 'verification_method'],
        'client.consent.recorded' => ['subject', 'version', 'granted', 'evidence'],
        'client.self_booking.blocked' => ['source'],
        'client.self_booking.unblocked' => ['source'],
        'service.created' => ['source', 'is_active', 'has_price'],
        'service.updated' => ['fields', 'is_active', 'has_price'],
        'specialist.created' => ['source', 'is_active', 'timezone_set'],
        'specialist.updated' => ['fields'],
        'specialist.activated' => ['source'],
        'specialist.deactivated' => ['source'],
        'specialist.linked' => ['user_id'],
        'specialist.unlinked' => [],
        'content.section.created' => ['section_key', 'locale', 'is_visible'],
        'content.section.updated' => ['section_key', 'locale', 'is_visible'],
        'legal.document.published' => ['document_type', 'version', 'locale', 'management_mode'],
        'legal.document.draft.updated' => ['document_type', 'version', 'locale'],
        'organization.scheduling.lead_time.updated' => ['minutes'],
        'specialist.schedule.updated' => ['weekday_count', 'interval_count'],
        'specialist.schedule.exception.created' => ['exception_type', 'source'],
        'specialist.schedule.exception.deleted' => ['source'],
        'specialist.unavailable_period.created' => ['source'],
        'specialist.unavailable_period.deleted' => ['source'],
        'booking.created' => ['source', 'visit_format', 'status'],
        'booking.confirmed' => ['source', 'status', 'visit_format'],
        'booking.cancelled' => ['source', 'status', 'inside_cutoff'],
        'booking.rescheduled' => ['source', 'status', 'visit_format'],
        'booking.completed' => ['source', 'status', 'visit_format'],
        'booking.no_show' => ['source', 'status', 'visit_format'],
        'booking.home_visit.withdrawn' => ['source', 'status', 'inside_cutoff'],
        'booking.online.meeting_url.updated' => ['source', 'status', 'visit_format', 'url_set'],
        'schedule.mutation.acknowledged' => ['source', 'mutation', 'affected_booking_count', 'impact_digest'],
        'specialist.service.assigned' => ['source'],
        'specialist.service.unassigned' => ['source'],
        'booking.home_visit.approved' => ['source', 'status', 'visit_format', 'payment_requirement'],
        'booking.home_visit.rejected' => ['source', 'status', 'visit_format'],
        'scenario.template.created' => ['template_key', 'locale', 'version'],
        'scenario.template.updated' => ['template_key', 'locale', 'version'],
        'scenario.rule.created' => ['rule_key', 'trigger_event', 'delay_value', 'delay_unit', 'enabled'],
        'scenario.rule.updated' => ['rule_key', 'trigger_event', 'delay_value', 'delay_unit', 'enabled'],
        'organization.finance.currency.updated' => ['base_currency', 'display_currency', 'force_single_currency', 'rounding_mode', 'allowed_count'],
        'organization.finance.rate.updated' => ['source_currency', 'target_currency', 'rate_version'],
        'finance.obligation.created' => ['source', 'currency', 'amount_minor'],
        'finance.manual_payment.recorded' => ['source', 'payment_method', 'payment_currency', 'settlement_currency', 'receipt_attached'],
        'finance.payment.corrected' => ['source', 'correction_of', 'reason_present'],
        'finance.gateway.initiated' => ['gateway', 'currency', 'source'],
        'finance.gateway.settled' => ['gateway', 'source', 'currency'],
        'finance.gateway.reconciled' => ['gateway', 'status', 'consistent'],
        'medical.profile.created' => ['source', 'key_version', 'updated_fields'],
        'medical.profile.updated' => ['source', 'key_version', 'updated_fields'],
        'medical.session.created' => ['source', 'key_version', 'booking_id', 'client_id', 'specialist_id'],
        'medical.session.updated' => ['source', 'key_version', 'updated_fields'],
        'medical.session.attachment.linked' => ['attachment_id'],
        'medical.session.attachment.unlinked' => ['attachment_id'],
        'survey.definition.created' => ['definition_key', 'version', 'source_present'],
        'survey.definition.updated' => ['definition_key', 'version'],
        'survey.version.published' => ['definition_key', 'version'],
        'survey.attempt.completed' => ['definition_key', 'version', 'tag_count', 'metric_count'],
        'knowledge.source.created' => ['source_type'],
        'knowledge.revision.created' => ['source_id', 'version'],
        'knowledge.ingestion.completed' => ['source_id', 'revision_id', 'chunk_count'],
        'knowledge.ingestion.failed' => ['source_id', 'revision_id', 'error_code'],
        'knowledge.source.retired' => ['active_revision_id'],
        'knowledge.source.reactivated' => ['active_revision_id'],
        'attachment.uploaded' => ['source', 'attachment_type', 'mime_type', 'size_bytes', 'scan_status'],
        'attachment.downloaded' => ['source'],
    ];

    /** @param array<array-key, mixed> $metadata */
    public function handle(
        Organization $organization,
        ?User $actor,
        string $action,
        ?string $targetType,
        ?string $targetId,
        array $metadata = [],
    ): AuditEvent {
        $event = new AuditEvent;
        $event->forceFill([
            'organization_id' => $organization->getKey(),
            'actor_user_id' => $actor?->getKey(),
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'metadata' => $this->sanitize($action, $metadata),
            'occurred_at' => now(),
        ]);
        $event->save();

        return $event->refresh();
    }

    /**
     * @param  array<array-key, mixed>  $metadata
     * @return array<array-key, mixed>
     */
    private function sanitize(string $action, array $metadata): array
    {
        $allowedKeys = self::ALLOWED_METADATA_KEYS[$action] ?? [];
        $safe = [];

        foreach ($metadata as $key => $value) {
            $key = (string) $key;

            if (! in_array($key, $allowedKeys, true) || (! is_scalar($value) && $value !== null)) {
                continue;
            }

            $safe[$key] = is_string($value) ? Str::limit($value, 128, '…') : $value;
        }

        return $safe;
    }
}
