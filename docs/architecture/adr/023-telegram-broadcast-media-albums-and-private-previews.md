# ADR-023: Telegram Broadcast Media Albums and Private Previews

- Status: Accepted
- Date: 2026-09-03

## Context

Operators need to prepare a broadcast with photos, MP4 video, or arbitrary files, inspect the resulting Telegram message, replace or remove saved media, and send the same payload to a test recipient and the selected audience. Managed files must not become publicly readable just to make a browser preview possible.

## Decision

1. Broadcast media is stored in organization-scoped private storage. Browser previews use short-lived signed routes that re-check authentication, organization context, permission, campaign ownership, and the selected media item.
2. Telegram photo uploads are limited to 10 MB. MP4 videos and documents are limited to 50 MB. Unknown file types are sent through `sendDocument` with their original filename.
3. One media item uses its dedicated Telegram method. Two to ten photos and/or videos use `sendMediaGroup`; two to ten documents use a document-only `sendMediaGroup`. Documents cannot be mixed with photos or videos.
4. A media group carries the message caption on its first item. Inline buttons are rejected before provider I/O for media groups because Telegram does not support a reply markup on the group as a whole.
5. The CRM uses the same Telegram-shaped preview for broadcast, content, and notification-template rich-text editors. A document without a browser-renderable URL is represented as a file card while retaining its filename.

## Consequences

- Operators can see whether media is absent, replaced, or retained before saving and can inspect video and document payloads in the Telegram preview.
- Private media preview links expire and are unusable outside the owning organization.
- A group is delivered as one Telegram API call, while a text-before/after-media mode still produces the configured separate text message and media message.
- Rich-text templates remain text-only; media belongs to the broadcast or auto-message that uses the template.
