<?php

namespace App\MessageHandler;

use App\Message\ConversationMessageSent;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Tells the open conversation page and the recipient's inbox to refresh, so the new
 * message and the unread count appear without reloading. The pages render the content;
 * the signal itself carries none.
 */
#[AsMessageHandler]
final class ConversationMessageSentHandler
{
    public function __construct(private readonly HubInterface $hub)
    {
    }

    public function __invoke(ConversationMessageSent $message): void
    {
        $refresh = '<turbo-stream action="refresh"></turbo-stream>';
        $this->hub->publish(new Update(sprintf('conversations/%s', $message->conversationId), $refresh, true));
        $this->hub->publish(new Update(sprintf('users/%s/notifications', $message->recipientUserId), $refresh, true));
    }
}
