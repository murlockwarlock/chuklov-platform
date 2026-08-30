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
        'organization.setting.removed' => ['setting_key', 'value_type'],
        'organization.feature.updated' => ['feature_key', 'enabled'],
        'organization.credential.replaced' => ['provider', 'credential_name', 'status', 'old_revision_id', 'new_revision_id'],
        'ai.prompt.created' => ['prompt_key', 'capability'],
        'ai.prompt_version.created' => ['prompt_key', 'version'],
        'ai.prompt_version.activated' => ['prompt_key', 'version'],
        'ai.prompt_version.retired' => ['prompt_key', 'version'],
        'ai.provider_config.updated' => ['provider_name', 'is_enabled', 'credential_reassigned'],
        'ai.model_config.updated' => ['model_name', 'is_enabled'],
        'ai.model_config.created' => ['model_name', 'is_enabled'],
        'ai.model_release.activated' => ['model_name', 'release_number'],
        'ai.safety_control.updated' => ['is_ai_globally_enabled', 'limits_updated'],
        'ai.human_review.submitted' => ['ai_run_id', 'decision', 'safe_reason_code'],
        'ai.evaluation_case.created' => ['eval_suite_id', 'is_synthetic'],
        'ai.evaluation_case.updated' => ['eval_suite_id', 'is_active'],
        'ai.evaluation_run.completed' => ['eval_suite_id', 'total_cases', 'passed_cases', 'failed_cases'],
        'client.created' => ['source'],
        'client.profile.updated' => ['source', 'fields'],
        'client.channel_identity.registered' => ['channel', 'verification_status'],
        'client.channel_identity.verified' => ['channel', 'verification_method'],
        'client.consent.recorded' => ['subject', 'version', 'granted', 'evidence'],
        'client.self_booking.blocked' => ['source'],
        'client.self_booking.unblocked' => ['source'],
        'attribution.accepted' => ['source_type', 'capture_channel', 'has_referral', 'has_utm'],
        'attribution.manual_source.accepted' => ['source_type'],
        'attribution.legacy.adopted' => ['source_type'],
        'referral.identity.created' => ['client_id'],
        'referral.relationship.created' => ['referrer_client_id', 'referred_client_id', 'establishment_method'],
        'referral.commercial_evidence.observed' => ['relationship_id', 'referred_client_id', 'obligation_id', 'ledger_entry_id', 'evidence_type', 'source'],
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
        'broadcast.campaign.created' => ['send_mode', 'channel_count'],
        'broadcast.campaign.updated' => ['draft_version'],
        'broadcast.campaign.previewed' => ['matched_count', 'eligible_count', 'suppressed_count'],
        'broadcast.campaign.test_sent' => ['test_recipient_id', 'channel'],
        'broadcast.campaign.test_failed' => ['test_recipient_id', 'channel', 'reason'],
        'broadcast.campaign.test_unknown' => ['test_recipient_id', 'channel', 'reason'],
        'broadcast.campaign.scheduled' => ['audience_count', 'eligible_count', 'scheduled_at'],
        'broadcast.campaign.started' => ['audience_count', 'eligible_count', 'scheduled_at'],
        'broadcast.campaign.cancelled' => [],
        'broadcast.campaign.execution_blocked' => ['reason'],
        'broadcast.client.classification.updated' => ['tag_count', 'b2b_role_set'],
        'b2b.client.specialist_answer.updated' => ['source', 'old_answer', 'answer'],
        'b2b.lead.submitted' => ['source', 'status', 'has_sales_call'],
        'b2b.lead.status.updated' => ['source', 'old_status', 'new_status'],
        'b2b.sales_call.created' => ['source', 'status', 'meeting_mode', 'provider_sync_status'],
        'b2b.sales_call.rescheduled' => ['source', 'status', 'provider_sync_status'],
        'b2b.sales_call.cancelled' => ['source', 'status', 'provider_sync_status'],
        'b2b.sales_call.provider_sync.updated' => ['operation', 'status', 'provider', 'error_code'],
        'b2b.sales_call.manual_link.updated' => ['source', 'meeting_mode', 'url_set'],
        'organization.finance.currency.updated' => ['base_currency', 'display_currency', 'force_single_currency', 'rounding_mode', 'allowed_count'],
        'organization.finance.rate.updated' => ['source_currency', 'target_currency', 'rate_version'],
        'finance.obligation.created' => ['source', 'currency'],
        'finance.manual_payment.recorded' => ['source', 'payment_method', 'payment_currency', 'settlement_currency', 'receipt_attached'],
        'finance.payment.corrected' => ['source', 'correction_of', 'reason_present'],
        'finance.gateway.initiated' => ['gateway', 'currency', 'source'],
        'finance.gateway.settled' => ['gateway', 'source', 'currency'],
        'finance.gateway.reconciled' => ['gateway', 'status', 'consistent'],
        'feedback.configuration.updated' => ['enabled', 'positive_threshold', 'low_score_feedback_required', 'review_url_ru_set', 'review_url_en_set', 'review_destinations_count'],
        'feedback.submitted' => ['score', 'band', 'source', 'has_internal_feedback'],
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
        'knowledge.source.created' => ['source_type', 'client_companion_enabled'],
        'knowledge.source.updated' => ['fields'],
        'knowledge.revision.created' => ['source_id', 'version'],
        'knowledge.ingestion.retry_requested' => ['source_id', 'revision_id', 'ingestion_run_id', 'attempt_number'],
        'knowledge.ingestion.retry_dispatch_failed' => ['source_id', 'revision_id', 'restored'],
        'knowledge.ingestion.reprocess_requested' => ['source_id', 'revision_id'],
        'knowledge.ingestion.pending_start_requested' => ['source_id', 'revision_id'],
        'knowledge.ingestion.dispatch_failed' => ['source_id', 'revision_id', 'operation'],
        'knowledge.ingestion.completed' => ['source_id', 'revision_id', 'chunk_count', 'ingestion_run_id', 'attempt_number'],
        'knowledge.ingestion.failed' => ['source_id', 'revision_id', 'error_code', 'ingestion_run_id', 'attempt_number'],
        'knowledge.source.retired' => ['active_revision_id'],
        'knowledge.source.reactivated' => ['active_revision_id'],
        'knowledge.source.deleted' => ['deleted_chunk_count', 'deleted_run_count', 'deleted_revision_count', 'retained_revision_count'],
        'attachment.uploaded' => ['source', 'attachment_type', 'mime_type', 'size_bytes'],
        'attachment.downloaded' => ['source'],
        'knowledge.source.client_companion_scope.updated' => ['enabled'],
        'companion.context.reset' => ['new_epoch'],
        'companion.feedback.recorded' => ['value'],
        'companion.export.created' => ['format', 'identified', 'metadata_only'],
        'companion.handoff.resolved' => ['reason'],
        'companion.ai.resumed' => ['source'],
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
