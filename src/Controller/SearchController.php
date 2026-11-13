<?php

namespace App\Controller;

use App\Entity\MenuItem;
use App\Repository\MenuItemRepository;
use App\Repository\RestaurantRepository;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The search engine: restaurants and dishes you can order right now. The query is split into words and every
 * word has to match somewhere (dish, category, restaurant or cuisine); the database ranks the best match first.
 * When nothing matches, a misspelled word is corrected against the names on the menus ("did you mean").
 */
final class SearchController extends AbstractController
{
    public const SORTS = ['relevance' => 'Best match', 'price_asc' => 'Price: low to high', 'price_desc' => 'Price: high to low'];

    /** Small words that say nothing about the food, in English and Filipino. */
    private const STOP_WORDS = ['a', 'an', 'and', 'at', 'for', 'in', 'of', 'on', 'or', 'the', 'to', 'with', 'ng', 'na', 'sa', 'mga', 'may'];

    #[Route('/search', name: 'app_search', methods: ['GET'])]
    public function index(
        Request $request,
        RestaurantRepository $restaurants,
        MenuItemRepository $menuItems,
        PaginatorInterface $paginator,
    ): Response {
        $query = trim($request->query->getString('q'));
        $terms = self::terms($query);
        $category = trim($request->query->getString('category'));
        $minimum = $this->price($request->query->getString('min_price'));
        $maximum = $this->price($request->query->getString('max_price'));
        $sort = $request->query->getString('sort', 'relevance');
        if (!isset(self::SORTS[$sort])) {
            $sort = 'relevance';
        }
        $errors = [];
        if (null !== $minimum && null !== $maximum && 1 === bccomp($minimum, $maximum, 2)) {
            $errors[] = 'The minimum price cannot be higher than the maximum price.';
        }

        $matchingRestaurants = [];
        $dishes = [];
        $suggestion = null;
        $searched = [] !== $terms || '' !== $category || null !== $minimum || null !== $maximum;
        if ($searched && [] === $errors) {
            if ([] !== $terms) {
                $matchingRestaurants = $restaurants->search($terms, 10);
            }
            $dishes = self::orderableNow($menuItems->search($terms, mb_strtolower($category), $minimum, $maximum, $sort));
            if ([] !== $terms && [] === $matchingRestaurants && [] === $dishes) {
                $suggestion = $this->didYouMean($terms, [...$restaurants->findSearchWords(), ...$menuItems->findSearchWords()]);
            }
        }

        return $this->render('search/index.html.twig', [
            'query' => $query,
            'terms' => $terms,
            'sort' => $sort,
            'sorts' => self::SORTS,
            'filters' => [
                'category' => $category,
                'min_price' => $request->query->getString('min_price'),
                'max_price' => $request->query->getString('max_price'),
            ],
            'errors' => $errors,
            'searched' => $searched,
            'suggestion' => $suggestion,
            'restaurants' => $matchingRestaurants,
            'total' => count($dishes),
            'dishes' => $paginator->paginate($dishes, $request->query->getInt('page', 1), 20),
        ]);
    }

    /**
     * The words of a search, lowercased: punctuation splits words, small words like "and" or "ng" are dropped,
     * and repeats count once. Only letters and digits survive, so a word is safe inside a LIKE pattern.
     *
     * @return list<string>
     */
    public static function terms(string $text, int $limit = 6): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_diff(array_unique($words), self::STOP_WORDS);

        return array_slice(array_values(array_map(static fn (string $word): string => mb_substr($word, 0, 40), $words)), 0, $limit);
    }

    /**
     * The dishes a customer could order this minute: the restaurant is open and the dish is inside its time window.
     *
     * @param list<MenuItem> $items
     *
     * @return list<MenuItem>
     */
    public static function orderableNow(array $items, \DateTimeImmutable $now = new \DateTimeImmutable()): array
    {
        return array_values(array_filter(
            $items,
            static fn (MenuItem $item): bool => $item->getMenuCategory()->getRestaurant()->isOpenAt($now) && $item->isOfferedAt($now),
        ));
    }

    /**
     * The search with each unknown word replaced by the closest word on the menus, or null when nothing is close.
     * Close means a few letters off: one for short words, two up to eight letters, three for longer words.
     *
     * @param list<string> $terms
     * @param list<string> $phrases restaurant names, cuisines, dish and category names
     */
    private function didYouMean(array $terms, array $phrases): ?string
    {
        $vocabulary = [];
        foreach ($phrases as $phrase) {
            foreach (self::terms($phrase, 50) as $word) {
                if (mb_strlen($word) >= 3) {
                    $vocabulary[$word] = true;
                }
            }
        }

        $changed = false;
        $corrected = [];
        foreach ($terms as $term) {
            $length = mb_strlen($term);
            if ($length < 3 || isset($vocabulary[$term])) {
                $corrected[] = $term;
                continue;
            }
            $allowed = $length <= 4 ? 1 : ($length <= 8 ? 2 : 3);
            $best = null;
            $bestDistance = \PHP_INT_MAX;
            foreach (array_keys($vocabulary) as $word) {
                $distance = levenshtein($term, (string) $word);
                if ($distance <= $allowed && $distance < $bestDistance) {
                    $best = (string) $word;
                    $bestDistance = $distance;
                }
            }
            $corrected[] = $best ?? $term;
            $changed = $changed || null !== $best;
        }

        return $changed ? implode(' ', $corrected) : null;
    }

    /** A non-negative price rounded to centavos, or null when blank or not a plain number. */
    private function price(string $value): ?string
    {
        $value = trim($value);

        return 1 === preg_match('/^\d{1,8}(\.\d+)?$/', $value) ? bcround($value, 2) : null;
    }
}
