<?php

namespace App\EventListener;

use App\Entity\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Riders and restaurants work from their dashboards, not the customer explore page, so a
 * login that would land on the home page takes them to their dashboard instead. A login
 * that was sent back to a specific page (the one the person tried to open) is left alone.
 */
final class LoginRedirectListener
{
    private const DASHBOARDS = [
        User::ACCOUNT_RIDER => 'app_rider_dashboard',
        User::ACCOUNT_RESTAURANT => 'app_restaurant_dashboard',
    ];

    public function __construct(private readonly UrlGeneratorInterface $urls)
    {
    }

    #[AsEventListener]
    public function onLoginSuccessEvent(LoginSuccessEvent $event): void
    {
        $response = $event->getResponse();
        $user = $event->getUser();
        if (!$response instanceof RedirectResponse || !$user instanceof User || !isset(self::DASHBOARDS[$user->getRole()])) {
            return;
        }
        $home = $this->urls->generate('app_home', [], UrlGeneratorInterface::ABSOLUTE_URL);
        if (rtrim($response->getTargetUrl(), '/') !== rtrim($home, '/')) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->urls->generate(self::DASHBOARDS[$user->getRole()])));
    }
}
