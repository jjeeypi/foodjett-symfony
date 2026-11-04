<?php

namespace App\Controller;

use App\Entity\RestaurantReview;
use App\Entity\User;
use App\Form\ReviewReplyFormType;
use App\Repository\RestaurantReviewRepository;
use App\Security\Voter\ApprovedAccountVoter;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Reviews of the restaurant, newest first, each with a form for a public reply. */
#[IsGranted('ROLE_RESTAURANT')]
#[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your restaurant account is awaiting approval.')]
final class RestaurantReviewsController extends AbstractController
{
    #[Route('/restaurant/reviews', name: 'app_restaurant_reviews', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] User $user, RestaurantReviewRepository $reviews, PaginatorInterface $paginator): Response
    {
        $restaurant = $user->getRestaurant() ?? throw $this->createAccessDeniedException();
        $page = $paginator->paginate($reviews->createForRestaurantQuery($restaurant), $request->query->getInt('page', 1), 20);
        $forms = [];
        foreach ($page as $review) {
            $forms[$review->getId()] = $this->replyForm($review)->createView();
        }

        return $this->render('restaurant_reviews/index.html.twig', ['reviews' => $page, 'forms' => $forms]);
    }

    #[Route('/restaurant/reviews/{id}/reply', name: 'app_restaurant_review_reply', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reply(string $id, Request $request, #[CurrentUser] User $user, RestaurantReviewRepository $reviews, EntityManagerInterface $entityManager): Response
    {
        $restaurant = $user->getRestaurant() ?? throw $this->createAccessDeniedException();
        $review = $reviews->findOneForRestaurant($id, $restaurant) ?? throw $this->createNotFoundException('Review not found.');
        $form = $this->replyForm($review)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $now = new \DateTimeImmutable();
            $review->setRestaurantReply(trim((string) $review->getRestaurantReply()))->setRepliedAt($now)->setUpdatedAt($now);
            $entityManager->flush();
            $this->addFlash('success', 'Reply saved.');
        } else {
            $this->addFlash('error', 'Write a reply first.');
        }

        return $this->redirectToRoute('app_restaurant_reviews', [], Response::HTTP_SEE_OTHER);
    }

    private function replyForm(RestaurantReview $review): FormInterface
    {
        return $this->container->get('form.factory')->createNamed('reply_'.$review->getId(), ReviewReplyFormType::class, $review, [
            'action' => $this->generateUrl('app_restaurant_review_reply', ['id' => $review->getId()]),
        ]);
    }
}
