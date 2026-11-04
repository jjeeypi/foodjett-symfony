<?php

namespace App\Tests\Controller;

use App\Entity\DeliveryZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** The address picker asks whether a spot is inside the delivery area before the form is sent. */
class DeliveryCoverageControllerTest extends WebTestCase
{
    public function testAnswersForPointsInsideAndOutsideTheActiveZones(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        foreach ($entityManager->getRepository(DeliveryZone::class)->findBy(['isActive' => true]) as $zone) {
            $zone->setIsActive(false);
        }
        $entityManager->flush();

        $client->request('GET', '/delivery/coverage?lat=14.6&lng=121.0');
        self::assertResponseIsSuccessful();
        self::assertSame(['covered' => true], json_decode((string) $client->getResponse()->getContent(), true), 'Without zones Foodjett delivers everywhere.');

        $now = new \DateTimeImmutable();
        $entityManager->persist((new DeliveryZone())->setName('Coverage Test Zone')
            ->setPolygon(['type' => 'circle', 'center' => [9.3068, 123.3054], 'radius_km' => 10])
            ->setIsActive(true)->setCreatedAt($now)->setUpdatedAt($now));
        $entityManager->flush();

        $client->request('GET', '/delivery/coverage?lat=9.3131&lng=123.3058');
        self::assertSame(['covered' => true], json_decode((string) $client->getResponse()->getContent(), true), 'Silliman University is inside.');

        $client->request('GET', '/delivery/coverage?lat=14.6&lng=121.0');
        self::assertSame(['covered' => false], json_decode((string) $client->getResponse()->getContent(), true), 'Manila is outside.');

        $client->request('GET', '/delivery/coverage?lat=abc&lng=123');
        self::assertResponseStatusCodeSame(422);
    }
}
