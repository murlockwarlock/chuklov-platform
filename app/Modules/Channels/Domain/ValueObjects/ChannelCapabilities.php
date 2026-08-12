<?php

namespace App\Modules\Channels\Domain\ValueObjects;

final readonly class ChannelCapabilities
{
    public function __construct(
        public bool $supportsWebApp = false,
        public bool $supportsInlineButtons = false,
        public bool $supportsFileAttachments = false,
        public bool $supportsProactiveDelivery = false,
        public bool $supportsThreads = false,
    ) {}

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return [
            'web_app' => $this->supportsWebApp,
            'inline_buttons' => $this->supportsInlineButtons,
            'file_attachments' => $this->supportsFileAttachments,
            'proactive_delivery' => $this->supportsProactiveDelivery,
            'threads' => $this->supportsThreads,
        ];
    }
}
