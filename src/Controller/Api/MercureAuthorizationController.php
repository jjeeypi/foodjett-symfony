<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\MercureTopicResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class MercureAuthorizationController extends AbstractController
{
    #[Route('/api/mercure-auth', name: 'api_mercure_auth', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(
        Request $request,
        Authorization $authorization,
        HubInterface $hub,
        MercureTopicResolver $topicResolver,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $topics = $topicResolver->subscriberTopics($user);
        $authorization->setCookie($request, $topics, additionalClaims: [
            'sub' => 'user/'.$user->getId(),
            'client_id' => 'foodjett-spa',
        ]);

        return $this->json([
            'hub_url' => $hub->getPublicUrl(),
            'subscription_parameter' => 'match',
            'topics' => $topics,
        ]);
    }
}
