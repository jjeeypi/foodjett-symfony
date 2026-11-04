<?php

namespace App\MessageHandler;

use App\Message\ConversationMessageSent;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ConversationMessageSentHandler{
    public function __invoke(ConversationMessageSent $message): void
    {
        // do something with your message
    }
}
