<?php

namespace App\Services;

use App\Models\ContactMapping;
use App\Models\ConversationMapping;

class ContactMappingService
{
    public function findByChatwootContactId(int $chatwootContactId): ?ContactMapping
    {
        return ContactMapping::where('chatwoot_contact_id', $chatwootContactId)->first();
    }

    public function createContactMapping(array $attributes): ContactMapping
    {
        return ContactMapping::create($attributes);
    }

    public function findConversationMapping(int $chatwootConversationId): ?ConversationMapping
    {
        return ConversationMapping::where('chatwoot_conversation_id', $chatwootConversationId)->first();
    }

    public function createConversationMapping(array $attributes): ConversationMapping
    {
        return ConversationMapping::create($attributes);
    }
}
