<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Customer;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

abstract class AbstractCustomerController extends AbstractController
{
    protected function customer(): Customer
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $customer = $user->getCustomer();
        if (!$customer instanceof Customer) {
            throw $this->createNotFoundException('Customer profile not found.');
        }

        return $customer;
    }

    protected function customerUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    /** @return array<string, mixed>|JsonResponse */
    protected function body(Request $request): array|JsonResponse
    {
        if (str_contains((string) $request->headers->get('Content-Type'), 'application/json')) {
            try {
                return $request->toArray();
            } catch (\Throwable) {
                return $this->json(['message' => 'The request body must contain valid JSON.'], 400);
            }
        }

        return $request->request->all();
    }
}
