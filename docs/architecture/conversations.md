# Conversations

M2 provides the organization/client-scoped `conversations` and `conversation_messages` foundation. `RecordConversationMessage` normalizes channel keys, enforces the client boundary, deduplicates provider message identifiers, updates conversation timestamps, and retains only an allowlisted minimal metadata shape. Direction and author type are explicit enums so future human/AI messages share the same model.

Future flow: verified inbound event → normalized conversation/message → Application logic → AI or human response → delivery adapter. AI workflows and channel delivery beyond the Telegram foundation remain deferred. See REQ-CONVERSATION-001.
