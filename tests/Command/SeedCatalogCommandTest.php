<?php

namespace App\Tests\Command;

use App\Command\SeedCatalogCommand;
use App\Entity\DeliveryZone;
use App\Entity\MenuItem;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Repository\DeliveryZoneRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** The demo catalog loads every restaurant ready to order from, and a second run adds nothing twice. */
class SeedCatalogCommandTest extends KernelTestCase
{
    public function testLoadsTheCatalogOnceWithOpenApprovedRestaurantsInsideTheZone(): void
    {
        $kernel = self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        foreach ($entityManager->getRepository(DeliveryZone::class)->findBy(['isActive' => true]) as $zone) {
            $zone->setIsActive(false);
        }
        $entityManager->flush();
        $catalog = json_decode((string) file_get_contents($kernel->getProjectDir().'/data/catalog.json'), true);
        $tester = new CommandTester((new Application($kernel))->find('app:seed-catalog'));

        $tester->execute(['--password' => 'DemoOwner123!']);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('50 restaurants added, 0 already there', $tester->getDisplay());

        $entityManager->clear();
        $restaurants = $entityManager->getRepository(Restaurant::class)->findBy(['slug' => array_column($catalog['restaurants'], 'slug')]);
        self::assertCount(50, $restaurants);
        $zone = $entityManager->getRepository(DeliveryZone::class)->findOneBy(['name' => SeedCatalogCommand::ZONE_NAME]);
        self::assertTrue($zone->isActive());
        foreach ($restaurants as $restaurant) {
            self::assertTrue($restaurant->isOpenNow(), $restaurant->getName().' takes orders right away.');
            self::assertSame(Restaurant::APPROVAL_APPROVED, $restaurant->getApprovalStatus());
            self::assertArrayHasKey($restaurant->getKind(), Restaurant::KINDS);
            self::assertStringContainsString('Dumaguete City', $restaurant->getAddress());
            self::assertLessThan(10, DeliveryZoneRepository::distanceKm(9.3068, 123.3054, (float) $restaurant->getLatitude(), (float) $restaurant->getLongitude()));
        }

        $bySlug = array_combine(array_map(fn (Restaurant $r) => $r->getSlug(), $restaurants), $restaurants);
        self::assertSame(Restaurant::KIND_BAKERY_DESSERTS, $bySlug['red-ribbon']->getKind());
        self::assertSame(Restaurant::KIND_COFFEE_TEA, $bySlug['starbucks']->getKind());
        $items = $entityManager->getRepository(MenuItem::class)->findBy(['menuCategory' => $bySlug['jollibee']->getMenuCategories()->toArray()]);
        self::assertCount(36, $items);
        $chickenjoy = array_values(array_filter($items, fn (MenuItem $i) => '1-pc Chickenjoy with Rice' === $i->getName()))[0];
        self::assertSame('105.00', $chickenjoy->getBasePrice());
        self::assertCount(2, $chickenjoy->getVariants());
        self::assertCount(2, $chickenjoy->getAddons());
        foreach ($items as $item) {
            self::assertNotEmpty($item->getDescription(), $item->getName().' has a description.');
        }

        $owner = $entityManager->getRepository(User::class)->findOneBy(['email' => 'jollibee@restaurants.foodjett.test']);
        self::assertSame(User::ACCOUNT_RESTAURANT, $owner->getRole());
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($owner, 'DemoOwner123!'));

        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('0 restaurants added, 50 already there', $tester->getDisplay());
        self::assertCount(50, $entityManager->getRepository(Restaurant::class)->findBy(['slug' => array_column($catalog['restaurants'], 'slug')]));
    }

    public function testRejectsAShortPassword(): void
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:seed-catalog'));

        self::assertSame(2, $tester->execute(['--password' => 'short']));
        self::assertStringContainsString('at least 8 characters', $tester->getDisplay());
    }
}
