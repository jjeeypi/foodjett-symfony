<?php

namespace App\MessageHandler;

use App\Message\ConversationMessageSent;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\Exception\ExceptionInterface as MercureException;
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
    public function __construct(private readonly HubInterface $hub, private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(ConversationMessageSent $message): void
    {
        $refresh = '<turbo-stream action="refresh"></turbo-stream>';
        $this->publish(new Update(sprintf('conversations/%s', $message->conversationId), $refresh, true));
        $this->publish(new Update(sprintf('users/%s/notifications', $message->recipientUserId), $refresh, true));
    }

    /** Live updates are a convenience: a hub that is down must not fail the change that was just saved. */
    private function publish(Update $update): void
    {
        try {
            $this->hub->publish($update);
        } catch (MercureException $exception) {
            $this->logger->warning('Live update not sent: {message}', ['message' => $exception->getMessage(), 'topics' => $update->getTopics()]);
        }
    }
}
