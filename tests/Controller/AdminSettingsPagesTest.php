<?php

namespace App\Tests\Controller;

use App\Entity\Admin;
use App\Entity\AuditLog;
use App\Entity\DeliveryZone;
use App\Entity\PlatformSetting;
use App\Entity\User;
use App\Entity\Voucher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/** Platform vouchers, delivery zones and platform settings for admins. */
class AdminSettingsPagesTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $now = new \DateTimeImmutable();
        $user = (new User())->setName('Settings Admin')->setEmail('settings-admin@foodjett.test')->setPhone('+639190500001')
            ->setPassword(password_hash('TestPassword123!', PASSWORD_BCRYPT, ['cost' => 4]))->setRole(User::ACCOUNT_ADMIN)
            ->setStatus(User::STATUS_ACTIVE)->setCreatedAt($now)->setUpdatedAt($now);
        $admin = (new Admin())->setUser($user)->setCreatedAt($now)->setUpdatedAt($now);
        $this->entityManager()->persist($user);
        $this->entityManager()->persist($admin);
        $this->entityManager()->flush();
        $this->client->loginUser($user, 'main');
    }

    public function testPlatformVoucherIsCreatedEditedAndDeleted(): void
    {
        $this->client->submit($this->page('/admin/voucher/new')->selectButton('Save')->form([
            'voucher[code]' => 'adminweb20',
            'voucher[type]' => Voucher::TYPE_PERCENTAGE,
            'voucher[value]' => '20',
            'voucher[minOrderAmount]' => '100',
            'voucher[usageLimitTotal]' => '50',
            'voucher[usageLimitPerCustomer]' => '1',
        ]));
        self::assertResponseRedirects('/admin/voucher');
        $voucher = $this->entityManager()->getRepository(Voucher::class)->findOneBy(['code' => 'ADMINWEB20']) ?? self::fail('The voucher was not created.');
        self::assertSame(Voucher::SCOPE_PLATFORM, $voucher->getScope());
        self::assertNull($voucher->getRestaurant());
        self::assertSame('20.00', $voucher->getValue());
        $id = (string) $voucher->getId();

        $this->client->submit($this->page('/admin/voucher/new')->selectButton('Save')->form([
            'voucher[code]' => 'ADMINWEB20', 'voucher[type]' => Voucher::TYPE_FIXED, 'voucher[value]' => '10',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'A voucher with that code already exists.');

        $form = $this->page('/admin/voucher/'.$id.'/edit')->selectButton('Update')->form(['voucher[value]' => '15']);
        $form['voucher[isActive]']->untick();
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/voucher');
        $voucher = $this->find(Voucher::class, $id);
        self::assertSame('15.00', $voucher->getValue());
        self::assertFalse($voucher->isActive());

        $this->client->submit($this->page('/admin/voucher/'.$id)->selectButton('Delete')->form());
        self::assertResponseRedirects('/admin/voucher');
        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->find(Voucher::class, $id));
        self::assertSame(['voucher.created', 'voucher.updated', 'voucher.deleted'], $this->auditActions($id));
    }

    public function testRestaurantVouchersAreNotReachableHere(): void
    {
        $now = new \DateTimeImmutable();
        $voucher = (new Voucher())->setCode('RESTAURANTONLY')->setScope(Voucher::SCOPE_RESTAURANT)->setType(Voucher::TYPE_FREE_DELIVERY)
            ->setIsActive(true)->setCreatedAt($now)->setUpdatedAt($now);
        $this->entityManager()->persist($voucher);
        $this->entityManager()->flush();

        $this->client->request('GET', '/admin/voucher/'.$voucher->getId().'/edit');
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeliveryZoneIsDrawnAsACircleToggledAndDeleted(): void
    {
        $this->client->submit($this->page('/admin/delivery-zone/new')->selectButton('Save')->form([
            'delivery_zone[name]' => 'Admin Web Zone',
            'delivery_zone[latitude]' => '14.5995',
            'delivery_zone[longitude]' => '120.9842',
            'delivery_zone[radiusKm]' => '5',
        ]));
        self::assertResponseRedirects('/admin/delivery-zone');
        $zone = $this->entityManager()->getRepository(DeliveryZone::class)->findOneBy(['name' => 'Admin Web Zone']) ?? self::fail('The zone was not created.');
        self::assertSame(['type' => 'circle', 'center' => [14.5995, 120.9842], 'radius_km' => 5.0], $zone->getPolygon());
        self::assertTrue($zone->isActive());
        $id = (string) $zone->getId();

        $crawler = $this->page('/admin/delivery-zone/'.$id.'/edit');
        self::assertSame('5.00', $crawler->filter('#delivery_zone_radiusKm')->attr('value'));
        $this->client->submit($crawler->selectButton('Update')->form(['delivery_zone[radiusKm]' => '0']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'The radius must be more than zero.');

        $this->client->submit($this->page('/admin/delivery-zone')->filter('#zone-'.$id)->selectButton('Switch off')->form());
        self::assertResponseRedirects('/admin/delivery-zone');
        self::assertFalse($this->find(DeliveryZone::class, $id)->isActive());

        $this->client->submit($this->page('/admin/delivery-zone/'.$id)->selectButton('Delete')->form());
        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->find(DeliveryZone::class, $id));
        self::assertSame(['delivery_zone.created', 'delivery_zone.toggled', 'delivery_zone.deleted'], $this->auditActions($id));
    }

    public function testPlatformSettingsAreAddedAndChangedWithNumbersChecked(): void
    {
        $this->client->submit($this->page('/admin/setting/new')->selectButton('Save')->form([
            'platform_setting[key]' => 'admin_web_test_rate',
            'platform_setting[value]' => 'lots',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'This setting must be a number of zero or more.');

        $this->client->submit($this->page('/admin/setting/new')->selectButton('Save')->form([
            'platform_setting[key]' => 'admin_web_test_rate',
            'platform_setting[value]' => ' 12.50 ',
        ]));
        self::assertResponseRedirects('/admin/setting');
        $setting = $this->entityManager()->getRepository(PlatformSetting::class)->findOneBy(['key' => 'admin_web_test_rate']) ?? self::fail('The setting was not saved.');
        self::assertSame('12.50', $setting->getValue());
        $id = (string) $setting->getId();

        $crawler = $this->page('/admin/setting/'.$id.'/edit');
        self::assertSame('disabled', $crawler->filter('#platform_setting_key')->attr('disabled'));
        $this->client->submit($crawler->selectButton('Update')->form(['platform_setting[value]' => '15']));
        self::assertResponseRedirects('/admin/setting');
        self::assertSame('15', $this->find(PlatformSetting::class, $id)->getValue());

        $this->page('/admin/setting');
        self::assertSelectorTextContains('#setting-admin_web_test_rate .value', '15');
        self::assertSame(['platform_setting.updated', 'platform_setting.updated'], $this->auditActions($id));
    }

    private function page(string $url): Crawler
    {
        $this->entityManager()->clear();
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function find(string $class, string $id): object
    {
        $this->entityManager()->clear();

        return $this->entityManager()->find($class, $id) ?? self::fail($class.' '.$id.' is missing.');
    }

    /** @return list<string> */
    private function auditActions(string $subjectId): array
    {
        return $this->entityManager()->getRepository(AuditLog::class)->createQueryBuilder('log')->select('log.action')
            ->andWhere('log.subjectId = :id')->setParameter('id', $subjectId)->orderBy('log.id')->getQuery()->getSingleColumnResult();
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
