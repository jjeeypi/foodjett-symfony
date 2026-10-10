<?php

declare(strict_types=1);

namespace App\Event;

final readonly class MessageSentEvent
{
    public function __construct(
        public string $messageId,
        public string $conversationId,
        public string $senderUserId,
        public string $recipientUserId,
        public \DateTimeImmutable $sentAt,
    ) {
    }
}
