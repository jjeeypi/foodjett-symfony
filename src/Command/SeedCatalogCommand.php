<?php

namespace App\Command;

use App\Entity\DeliveryZone;
use App\Entity\MenuCategory;
use App\Entity\MenuItem;
use App\Entity\MenuItemAddon;
use App\Entity\MenuItemVariant;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Repository\DeliveryZoneRepository;
use App\Repository\RestaurantRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Loads the demo restaurants from data/catalog.json: each gets an owner account, a made-up
 * Dumaguete address, approval, open status and its full menu. Running it again skips restaurants
 * that already exist and only fills in pictures that were missing the first time.
 */
#[AsCommand(
    name: 'app:seed-catalog',
    description: 'Load the demo restaurants and menus from data/catalog.json.',
)]
class SeedCatalogCommand
{
    public const ZONE_NAME = 'Dumaguete and nearby towns';
    private const CENTER = [9.3068, 123.3054];

    /** Central barangays, with coordinates from the address picker list. */
    private const AREAS = [
        ['Poblacion 1', 9.303424, 123.311076], ['Poblacion 2', 9.302422, 123.306572],
        ['Poblacion 3', 9.304636, 123.308355], ['Poblacion 4', 9.306468, 123.309634],
        ['Poblacion 5', 9.310359, 123.307601], ['Poblacion 6', 9.309253, 123.306295],
        ['Poblacion 7', 9.308343, 123.304706], ['Poblacion 8', 9.303203, 123.304224],
        ['Calindagan', 9.300267, 123.311458], ['Daro', 9.318746, 123.302255],
        ['Looc', 9.314797, 123.310585], ['Piapi', 9.321301, 123.305484],
        ['Bantayan', 9.328073, 123.307059], ['Taclobo', 9.30897, 123.29613],
        ['Tabuctubig', 9.301351, 123.30226], ['Bagacay', 9.29967, 123.29328],
    ];
    private const STREETS = [
        'Perdices Street', 'Real Street', 'San Jose Street', 'Legaspi Street', 'Silliman Avenue', 'Hibbard Avenue',
        'Santa Rosa Street', 'E.J. Blanco Drive', 'Colon Street', 'Locsin Street', 'Noblefranca Street',
        'Dr. V. Locsin Street', 'Teves Street', 'Pinili Street', 'North National Highway', 'South National Highway',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly RestaurantRepository $restaurants,
        private readonly UserRepository $users,
        private readonly DeliveryZoneRepository $zones,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Password for the restaurant owner accounts (a random one is made and shown when left out)')] ?string $password = null,
        #[Option('Catalog file, relative to the project folder')] string $file = 'data/catalog.json',
    ): int {
        $path = $this->projectDir.'/'.$file;
        $catalog = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (!is_array($catalog) || !is_array($catalog['restaurants'] ?? null)) {
            $io->error('Cannot read the catalog at '.$path.'.');

            return Command::FAILURE;
        }
        if (null !== $password && mb_strlen($password) < 8) {
            $io->error('The password needs at least 8 characters.');

            return Command::INVALID;
        }
        $generated = null === $password;
        $password ??= bin2hex(random_bytes(6));

        $now = new \DateTimeImmutable();
        $added = $skipped = $pictures = 0;
        $hash = null;
        foreach ($catalog['restaurants'] as $index => $data) {
            $existing = $this->restaurants->findOneBy(['slug' => $data['slug']]);
            if (null !== $existing) {
                $pictures += $this->fillMissingPictures($existing, $data, $now);
                ++$skipped;
                continue;
            }
            $email = $data['slug'].'@restaurants.foodjett.test';
            if (null !== $this->users->findOneBy(['email' => $email])) {
                $io->warning(sprintf('Skipped %s: %s is already taken by another account.', $data['name'], $email));
                ++$skipped;
                continue;
            }
            $owner = (new User())->setName($data['name'].' Owner')->setEmail($email)->setPhone($this->phone($index))
                ->setRole(User::ACCOUNT_RESTAURANT)->setStatus(User::STATUS_ACTIVE)->setCreatedAt($now)->setUpdatedAt($now);
            // One hash for every owner: hashing is slow on purpose and they all share the password.
            $hash ??= $this->passwordHasher->hashPassword($owner, $password);
            $owner->setPassword($hash);
            $this->entityManager->persist($owner);
            $this->entityManager->persist($this->restaurant($owner, $data, $now));
            ++$added;
        }
        $zone = $this->ensureZone($now);
        $this->entityManager->flush();

        $io->success(sprintf('%d restaurants added, %d already there, %d pictures filled in.', $added, $skipped, $pictures));
        if ($zone) {
            $io->note('Added the active delivery zone "'.self::ZONE_NAME.'" (10 km around Dumaguete).');
        }
        if ($added > 0) {
            $io->text(sprintf('Owners sign in as <slug>@restaurants.foodjett.test, for example %s@restaurants.foodjett.test.', $catalog['restaurants'][0]['slug']));
            $io->text($generated ? 'Their password (shown once): '.$password : 'Their password is the one you passed.');
        }

        return Command::SUCCESS;
    }

    /** @param array<string, mixed> $data */
    private function restaurant(User $owner, array $data, \DateTimeImmutable $now): Restaurant
    {
        [$address, $latitude, $longitude] = $this->address($data['slug']);
        $restaurant = (new Restaurant())->setUser($owner)->setName($data['name'])->setSlug($data['slug'])
            ->setKind($data['kind'])->setCuisineType($data['cuisine_type'])->setDescription($data['description'])
            ->setAddress($address)->setLatitude($latitude)->setLongitude($longitude)
            ->setDefaultPrepTimeMinutes((int) $data['default_prep_time_minutes'])->setMinOrderAmount($this->money($data['min_order_amount']))
            ->setCoverPhotoPath($this->picture('covers', $data['cover_image'] ?? null))
            ->setApprovalStatus(Restaurant::APPROVAL_APPROVED)->setOperatingStatus(Restaurant::OPERATING_OPEN)
            ->setCreatedAt($now)->setUpdatedAt($now);
        $owner->setRestaurant($restaurant);

        // No opening hours are set, so the restaurant follows its open switch around the clock.
        foreach ($data['categories'] as $categoryData) {
            $category = (new MenuCategory())->setName($categoryData['name'])->setSortOrder((int) $categoryData['sort_order'])
                ->setCreatedAt($now)->setUpdatedAt($now);
            $restaurant->addMenuCategory($category);
            foreach ($categoryData['items'] as $itemData) {
                $item = (new MenuItem())->setName($itemData['name'])->setDescription($itemData['description'] ?: null)
                    ->setBasePrice($this->money($itemData['base_price']))->setIsFeatured((bool) $itemData['is_featured'])
                    ->setPhotoPath($this->picture('items', $itemData['image'] ?? null))
                    ->setCreatedAt($now)->setUpdatedAt($now);
                $category->addItem($item);
                foreach ($itemData['variants'] as $variant) {
                    $item->addVariant((new MenuItemVariant())->setName($variant['name'])->setPriceDelta($this->money($variant['price_delta']))
                        ->setCreatedAt($now)->setUpdatedAt($now));
                }
                foreach ($itemData['addons'] as $addon) {
                    $item->addAddon((new MenuItemAddon())->setName($addon['name'])->setPrice($this->money($addon['price']))
                        ->setCreatedAt($now)->setUpdatedAt($now));
                }
            }
        }

        return $restaurant;
    }

    /**
     * Pictures are generated after the menus, so a later run links the ones that now exist.
     *
     * @param array<string, mixed> $data
     */
    private function fillMissingPictures(Restaurant $restaurant, array $data, \DateTimeImmutable $now): int
    {
        $filled = 0;
        if (null === $restaurant->getCoverPhotoPath() && null !== $cover = $this->picture('covers', $data['cover_image'] ?? null)) {
            $restaurant->setCoverPhotoPath($cover)->setUpdatedAt($now);
            ++$filled;
        }
        $images = [];
        foreach ($data['categories'] as $categoryData) {
            foreach ($categoryData['items'] as $itemData) {
                $images[$categoryData['name']."\n".$itemData['name']] = $itemData['image'] ?? null;
            }
        }
        foreach ($restaurant->getMenuCategories() as $category) {
            foreach ($category->getItems() as $item) {
                $image = $images[$category->getName()."\n".$item->getName()] ?? null;
                if (null === $item->getPhotoPath() && null !== $photo = $this->picture('items', $image)) {
                    $item->setPhotoPath($photo)->setUpdatedAt($now);
                    ++$filled;
                }
            }
        }

        return $filled;
    }

    /** The public path of a catalog picture, or null when it has not been made yet. */
    private function picture(string $folder, ?string $file): ?string
    {
        if (null === $file || '' === $file || str_contains($file, '/') || str_contains($file, '\\')) {
            return null;
        }
        $path = 'images/catalog/'.$folder.'/'.$file;

        return is_file($this->projectDir.'/public/'.$path) ? $path : null;
    }

    /**
     * A made-up street address in a central barangay. The slug seeds the choice, so every run
     * gives a restaurant the same address.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function address(string $slug): array
    {
        $seed = crc32($slug);
        [$area, $latitude, $longitude] = self::AREAS[$seed % count(self::AREAS)];
        $street = self::STREETS[intdiv($seed, 16) % count(self::STREETS)];
        $number = 1 + intdiv($seed, 256) % 299;
        // Up to about 150 m away from the barangay point.
        $latitude += ((intdiv($seed, 4096) % 201) - 100) / 75000;
        $longitude += ((intdiv($seed, 1048576) % 201) - 100) / 75000;

        return [
            sprintf('%d %s, Barangay %s, Dumaguete City, Negros Oriental', $number, $street, $area),
            number_format($latitude, 7, '.', ''),
            number_format($longitude, 7, '.', ''),
        ];
    }

    /** A unique, obviously fake number in the +63 999 range. */
    private function phone(int $index): string
    {
        return '+63999'.sprintf('%07d', 4200000 + $index);
    }

    private function money(string|int|float $amount): string
    {
        return bcadd((string) $amount, '0', 2);
    }

    /** With no active zone everything is accepted; the demo adds one around Dumaguete so coverage checks mean something. */
    private function ensureZone(\DateTimeImmutable $now): bool
    {
        if ([] !== $this->zones->findBy(['isActive' => true]) || null !== $this->zones->findOneBy(['name' => self::ZONE_NAME])) {
            return false;
        }
        $this->entityManager->persist((new DeliveryZone())->setName(self::ZONE_NAME)
            ->setPolygon(['type' => 'circle', 'center' => self::CENTER, 'radius_km' => 10])
            ->setIsActive(true)->setCreatedAt($now)->setUpdatedAt($now));

        return true;
    }
}
