<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\SupportTicket;
use App\Entity\User;
use App\Enum\SupportTicketStatus;
use App\Enum\UserStatus;
use App\Service\ApiPaginator;
use App\Service\UploadStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/customer', name: 'api_customer_account_')]
#[IsGranted('ROLE_CUSTOMER')]
final class CustomerAccountController extends AbstractCustomerController
{
    private const IMAGE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ApiPaginator $paginator,
        private readonly UploadStorage $uploads,
    ) {
    }

    #[Route('/profile', name: 'profile', methods: ['GET'])]
    public function profile(): JsonResponse
    {
        return $this->json(['profile' => $this->profileData($this->customerUser())]);
    }

    #[Route('/profile', name: 'profile_update', methods: ['PATCH'])]
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $this->customerUser();
        $data = $this->body($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $errors = [];
        $name = array_key_exists('name', $data) ? trim((string) $data['name']) : $user->getName();
        $email = array_key_exists('email', $data) ? strtolower(trim((string) $data['email'])) : $user->getEmail();
        $phone = array_key_exists('phone', $data) ? trim((string) $data['phone']) : $user->getPhone();
        if ('' === $name || mb_strlen($name) > 255) {
            $errors['name'][] = 'Name must be between 1 and 255 characters.';
        }
        if (null === $email || false === filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
            $errors['email'][] = 'A valid email address is required.';
        } elseif ($this->identifierExists('email', $email, $user)) {
            $errors['email'][] = 'This email address is already in use.';
        }
        if (null !== $phone && ('' === $phone || mb_strlen($phone) > 255)) {
            $errors['phone'][] = 'Phone must be between 1 and 255 characters.';
        } elseif (null !== $phone && $this->identifierExists('phone', $phone, $user)) {
            $errors['phone'][] = 'This phone number is already in use.';
        }
        if ([] !== $errors) {
            return $this->json(['message' => 'The submitted data is invalid.', 'errors' => $errors], 422);
        }

        $emailChanged = $email !== $user->getEmail();
        $user->setName($name)->setEmail($email)->setPhone($phone)->setUpdatedAt(new \DateTimeImmutable());
        if ($emailChanged) {
            $user->setEmailVerifiedAt(null);
        }
        $avatar = $request->files->get('avatar');
        if (null !== $avatar) {
            try {
                $path = $this->uploads->store($avatar, 'customers/avatars', self::IMAGE_MIME_TYPES);
            } catch (\InvalidArgumentException $exception) {
                return $this->json(['message' => $exception->getMessage(), 'errors' => ['avatar' => [$exception->getMessage()]]], 422);
            }
            $this->uploads->delete($user->getAvatarPath());
            $user->setAvatarPath($path);
        }
        $this->entityManager->flush();

        return $this->json(['message' => 'Profile updated.', 'email_verification_reset' => $emailChanged, 'profile' => $this->profileData($user)]);
    }

    #[Route('/support-tickets', name: 'support_index', methods: ['GET'])]
    public function supportTickets(Request $request): JsonResponse
    {
        $query = $this->entityManager->getRepository(SupportTicket::class)->createQueryBuilder('ticket')
            ->andWhere('ticket.user = :user')->setParameter('user', $this->customerUser())
            ->orderBy('ticket.createdAt', 'DESC');

        return $this->json($this->paginator->paginate($query, $request, $this->ticketData(...)));
    }

    #[Route('/support-tickets', name: 'support_store', methods: ['POST'])]
    public function createSupportTicket(Request $request): JsonResponse
    {
        $data = $this->body($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $subject = trim((string) ($data['subject'] ?? ''));
        $message = trim((string) ($data['message'] ?? ''));
        $errors = [];
        if ('' === $subject || mb_strlen($subject) > 255) {
            $errors['subject'][] = 'Subject must be between 1 and 255 characters.';
        }
        if ('' === $message || mb_strlen($message) > 10000) {
            $errors['message'][] = 'Message must be between 1 and 10000 characters.';
        }
        if ([] !== $errors) {
            return $this->json(['message' => 'The submitted data is invalid.', 'errors' => $errors], 422);
        }
        $now = new \DateTimeImmutable();
        $ticket = (new SupportTicket())->setUser($this->customerUser())->setSubject($subject)->setMessage($message)
            ->setStatus(SupportTicketStatus::OPEN)->setCreatedAt($now)->setUpdatedAt($now);
        $this->entityManager->persist($ticket);
        $this->entityManager->flush();

        return $this->json(['message' => 'Support ticket created.', 'ticket' => $this->ticketData($ticket)], 201);
    }

    #[Route('/account', name: 'delete', methods: ['DELETE'])]
    public function deleteAccount(): JsonResponse
    {
        $user = $this->customerUser();
        $this->uploads->delete($user->getAvatarPath());
        $user->setName('Deleted Customer')->setEmail(null)->setPhone(null)->setAvatarPath(null)
            ->setEmailVerifiedAt(null)->setPhoneVerifiedAt(null)->setStatus(UserStatus::BANNED)->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return new JsonResponse(null, 204);
    }

    private function identifierExists(string $field, string $value, User $current): bool
    {
        return null !== $this->entityManager->getRepository(User::class)->createQueryBuilder('account')
            ->select('account.id')->andWhere(sprintf('account.%s = :value', $field))->setParameter('value', $value)
            ->andWhere('account.id != :current')->setParameter('current', $current->getId())
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /** @return array<string, mixed> */
    private function profileData(User $user): array
    {
        return [
            'id' => $user->getId(), 'name' => $user->getName(), 'email' => $user->getEmail(), 'phone' => $user->getPhone(),
            'avatar_path' => $user->getAvatarPath(), 'email_verified_at' => $user->getEmailVerifiedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function ticketData(SupportTicket $ticket): array
    {
        return [
            'id' => $ticket->getId(), 'subject' => $ticket->getSubject(), 'message' => $ticket->getMessage(),
            'status' => $ticket->getStatus()->value, 'created_at' => $ticket->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updated_at' => $ticket->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
