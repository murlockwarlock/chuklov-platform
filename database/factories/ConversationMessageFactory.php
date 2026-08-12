<?php

namespace Database\Factories;

use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\ConversationMessage;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConversationMessage>
 */
class ConversationMessageFactory extends Factory
{
    protected $model = ConversationMessage::class;

    public function definition(): array
    {
        return [
            'channel' => 'telegram',
            'direction' => 'inbound',
            'author_type' => 'client',
            'external_id' => fake()->unique()->numerify('##########'),
            'body' => null,
            'metadata' => [],
            'occurred_at' => now(),
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->afterMaking(fn (ConversationMessage $message): ConversationMessage => $message->forceFill([
            'organization_id' => $organization->getKey(),
        ]));
    }

    public function forClient(Client $client): static
    {
        return $this->afterMaking(fn (ConversationMessage $message): ConversationMessage => $message->forceFill([
            'organization_id' => $client->organization_id,
            'client_id' => $client->getKey(),
        ]));
    }

    public function forConversation(Conversation $conversation): static
    {
        return $this->afterMaking(fn (ConversationMessage $message): ConversationMessage => $message->forceFill([
            'organization_id' => $conversation->organization_id,
            'client_id' => $conversation->client_id,
            'conversation_id' => $conversation->getKey(),
        ]));
    }
}
