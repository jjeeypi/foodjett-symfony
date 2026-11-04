<?php

namespace App\Controller;

use App\Entity\RestaurantReview;
use App\Entity\RiderReview;
use App\Entity\User;
use App\Repository\AuditLogRepository;
use App\Repository\RestaurantReviewRepository;
use App\Repository\RiderReviewRepository;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Review moderation: admins read restaurant and rider reviews and remove abusive ones. */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/review')]
final class AdminReviewController extends AbstractController
{
    #[Route(name: 'app_admin_review', methods: ['GET'])]
    public function index(Request $request, RestaurantReviewRepository $restaurantReviews, RiderReviewRepository $riderReviews, PaginatorInterface $paginator): Response
    {
        $type = 'rider' === $request->query->getString('type') ? 'rider' : 'restaurant';
        $maxRating = $request->query->getInt('max_rating');
        $maxRating = $maxRating >= 1 && $maxRating <= 5 ? $maxRating : null;
        $query = 'rider' === $type ? $riderReviews->createAdminListQuery($maxRating) : $restaurantReviews->createAdminListQuery($maxRating);

        return $this->render('admin_review/index.html.twig', [
            'reviews' => $paginator->paginate($query, $request->query->getInt('page', 1), 25),
            'type' => $type,
            'max_rating' => $maxRating,
        ]);
    }

    #[Route('/{type}/{id}', name: 'app_admin_review_delete', methods: ['POST'], requirements: ['type' => 'restaurant|rider', 'id' => '\d+'])]
    public function delete(Request $request, string $type, string $id, #[CurrentUser] User $admin, EntityManagerInterface $entityManager, AuditLogRepository $audit): Response
    {
        $review = $entityManager->find('rider' === $type ? RiderReview::class : RestaurantReview::class, $id) ?? throw $this->createNotFoundException('Review not found.');
        if (!$this->isCsrfTokenValid('review'.$type.$id, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }
        $audit->record($admin, 'review.deleted', $type.'_review', (string) $review->getId(), [
            'type' => $type,
            'order_id' => $review->getOrder()->getId(),
            'rating' => $review->getRating(),
            'comment' => $review->getComment(),
        ]);
        $entityManager->remove($review);
        $entityManager->flush();
        $this->addFlash('success', 'Review removed.');

        return $this->redirectToRoute('app_admin_review', ['type' => $type], Response::HTTP_SEE_OTHER);
    }
}
