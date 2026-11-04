<?php

namespace App\Controller;

use App\Entity\RestaurantOperatingHour;
use App\Entity\User;
use App\Form\OperatingHoursFormType;
use App\Security\Voter\ApprovedAccountVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The weekly schedule. Saving replaces it: filled days are created or updated, emptied days are
 * removed. Without any hours the restaurant follows its open/closed switch alone.
 */
#[IsGranted('ROLE_RESTAURANT')]
#[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your restaurant account is awaiting approval.')]
final class RestaurantHoursController extends AbstractController
{
    #[Route('/restaurant/hours', name: 'app_restaurant_hours', methods: ['GET', 'POST'])]
    public function index(Request $request, #[CurrentUser] User $user, EntityManagerInterface $entityManager): Response
    {
        $restaurant = $user->getRestaurant() ?? throw $this->createAccessDeniedException();
        $existing = [];
        $data = [];
        foreach ($restaurant->getOperatingHours() as $hour) {
            $existing[$hour->getDayOfWeek()] = $hour;
            $data['opens_'.$hour->getDayOfWeek()] = $hour->getOpensAt();
            $data['closes_'.$hour->getDayOfWeek()] = $hour->getClosesAt();
        }

        $form = $this->createForm(OperatingHoursFormType::class, $data);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $submitted = $form->getData();
            $now = new \DateTimeImmutable();
            foreach (array_keys(OperatingHoursFormType::DAYS) as $day) {
                $opens = $submitted['opens_'.$day] ?? null;
                $closes = $submitted['closes_'.$day] ?? null;
                if (null === $opens) {
                    if (isset($existing[$day])) {
                        $restaurant->removeOperatingHour($existing[$day]);
                        $entityManager->remove($existing[$day]);
                    }
                    continue;
                }
                $hour = $existing[$day] ?? (new RestaurantOperatingHour())->setRestaurant($restaurant)->setDayOfWeek($day)->setCreatedAt($now);
                $hour->setOpensAt($opens)->setClosesAt($closes)->setUpdatedAt($now);
                if (!isset($existing[$day])) {
                    $restaurant->addOperatingHour($hour);
                    $entityManager->persist($hour);
                }
            }
            $restaurant->setUpdatedAt($now);
            $entityManager->flush();
            $this->addFlash('success', 'Opening hours saved.');

            return $this->redirectToRoute('app_restaurant_hours', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('restaurant_hours/index.html.twig', [
            'form' => $form,
            'days' => OperatingHoursFormType::DAYS,
        ], new Response(status: $form->isSubmitted() ? 422 : 200));
    }
}
