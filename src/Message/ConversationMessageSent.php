<?php

namespace App\Message;

final class ConversationMessageSent
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $conversationId,
        public readonly string $senderUserId,
        public readonly string $recipientUserId,
        public readonly \DateTimeImmutable $sentAt,
    ) {
    }
}
