<?php

namespace App\Controller;

use App\Entity\Customer;
use App\Entity\Restaurant;
use App\Entity\RestaurantDocument;
use App\Entity\Rider;
use App\Entity\RiderDocument;
use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Form\RestaurantRegistrationFormType;
use App\Form\RiderRegistrationFormType;
use App\Repository\PlatformSettingRepository;
use App\Repository\RestaurantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Sign-up for customers, plus applications for restaurants and riders. Applicants are
 * signed in straight away; restaurants and riders stay pending until an admin approves them.
 */
class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly Security $security,
        #[Autowire('%app.private_upload_dir%')]
        private readonly string $privateUploadDir,
    ) {
    }

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(Request $request): Response
    {
        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $now = new \DateTimeImmutable();
            $this->prepareUser($user, $form, User::ACCOUNT_CUSTOMER, $now);
            $customer = (new Customer())->setUser($user)->setCreatedAt($now)->setUpdatedAt($now);

            $this->entityManager->persist($user);
            $this->entityManager->persist($customer);
            $this->entityManager->flush();

            $this->addFlash('success', 'Welcome to Foodjett!');

            return $this->security->login($user, 'form_login', 'main');
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
            'heading' => 'Create your account',
        ]);
    }

    #[Route('/register/restaurant', name: 'app_register_restaurant', methods: ['GET', 'POST'])]
    public function registerRestaurant(
        Request $request,
        RestaurantRepository $restaurants,
        PlatformSettingRepository $settings,
        SluggerInterface $slugger,
    ): Response {
        $restaurant = new Restaurant();
        $form = $this->createForm(RestaurantRegistrationFormType::class, $restaurant);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $now = new \DateTimeImmutable();
            $user = $restaurant->getUser();
            $this->prepareUser($user, $form->get('user'), User::ACCOUNT_RESTAURANT, $now);
            $restaurant
                ->setSlug($restaurants->nextFreeSlug(mb_strtolower((string) $slugger->slug($restaurant->getName()))))
                ->setCommissionRate($settings->decimal('default_commission_rate', '15.00'))
                ->setApprovalStatus(Restaurant::APPROVAL_PENDING)
                ->setOperatingStatus(Restaurant::OPERATING_CLOSED)
                ->setCreatedAt($now)
                ->setUpdatedAt($now);

            $this->saveApplication($user, $restaurant, function (array &$stored) use ($form, $restaurant, $now): void {
                foreach (RestaurantDocument::TYPES as $type) {
                    $stored[] = $path = $this->storePrivateFile($form->get('document_'.$type)->getData(), 'restaurants/documents');
                    $document = (new RestaurantDocument())
                        ->setRestaurant($restaurant)
                        ->setType($type)
                        ->setFilePath($path)
                        ->setStatus(RestaurantDocument::STATUS_PENDING)
                        ->setCreatedAt($now)
                        ->setUpdatedAt($now);
                    $restaurant->addDocument($document);
                    $this->entityManager->persist($document);
                }
            });

            $this->addFlash('success', 'Your restaurant application was submitted. An admin will review your documents.');

            return $this->security->login($user, 'form_login', 'main');
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
            'heading' => 'Apply as a restaurant',
        ]);
    }

    #[Route('/register/rider', name: 'app_register_rider', methods: ['GET', 'POST'])]
    public function registerRider(Request $request): Response
    {
        $rider = new Rider();
        $form = $this->createForm(RiderRegistrationFormType::class, $rider);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $now = new \DateTimeImmutable();
            $user = $rider->getUser();
            $this->prepareUser($user, $form->get('user'), User::ACCOUNT_RIDER, $now);
            $plate = $rider->getPlateNumber();
            $rider
                ->setPlateNumber(null === $plate || '' === trim($plate) ? null : trim($plate))
                ->setApprovalStatus(Rider::APPROVAL_PENDING)
                ->setAvailabilityStatus(Rider::AVAILABILITY_OFFLINE)
                ->setCreatedAt($now)
                ->setUpdatedAt($now);

            $this->saveApplication($user, $rider, function (array &$stored) use ($form, $rider, $now): void {
                foreach (RiderDocument::TYPES as $type) {
                    $stored[] = $path = $this->storePrivateFile($form->get('document_'.$type)->getData(), 'riders/documents');
                    $document = (new RiderDocument())
                        ->setRider($rider)
                        ->setType($type)
                        ->setFilePath($path)
                        ->setStatus(RiderDocument::STATUS_PENDING)
                        ->setCreatedAt($now)
                        ->setUpdatedAt($now);
                    $rider->addDocument($document);
                    $this->entityManager->persist($document);
                }
            });

            $this->addFlash('success', 'Your rider application was submitted. An admin will review your documents.');

            return $this->security->login($user, 'form_login', 'main');
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
            'heading' => 'Apply as a rider',
        ]);
    }

    private function prepareUser(User $user, FormInterface $accountForm, string $role, \DateTimeImmutable $now): void
    {
        /** @var string $plainPassword */
        $plainPassword = $accountForm->get('plainPassword')->getData();
        $user
            ->setName(trim($user->getName()))
            ->setRole($role)
            ->setStatus(User::STATUS_ACTIVE)
            ->setCreatedAt($now)
            ->setUpdatedAt($now)
            ->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
    }

    /**
     * Saves the account, its profile and its documents in one transaction. Files already
     * moved into storage are deleted again when the transaction fails.
     *
     * @param callable(list<string>&): void $addDocuments
     */
    private function saveApplication(User $user, Restaurant|Rider $profile, callable $addDocuments): void
    {
        $stored = [];
        try {
            $this->entityManager->wrapInTransaction(function () use ($user, $profile, $addDocuments, &$stored): void {
                $this->entityManager->persist($user);
                $this->entityManager->persist($profile);
                $addDocuments($stored);
                $this->entityManager->flush();
            });
        } catch (\Throwable $exception) {
            foreach ($stored as $path) {
                @unlink($this->privateUploadDir.'/'.$path);
            }
            throw $exception;
        }
    }

    /** Moves an upload into private storage under a random name; returns its path relative to that storage. */
    private function storePrivateFile(UploadedFile $file, string $directory): string
    {
        $filename = bin2hex(random_bytes(20)).'.'.($file->guessExtension() ?: 'bin');
        $file->move($this->privateUploadDir.'/'.$directory, $filename);

        return $directory.'/'.$filename;
    }
}
