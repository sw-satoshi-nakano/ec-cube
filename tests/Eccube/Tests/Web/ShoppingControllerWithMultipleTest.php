<?php

/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) EC-CUBE CO.,LTD. All Rights Reserved.
 *
 * http://www.ec-cube.co.jp/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Eccube\Tests\Web;

use Eccube\Entity\Master\OrderStatus;
use Eccube\Repository\BaseInfoRepository;
use Eccube\Repository\Master\OrderStatusRepository;
use Eccube\Repository\OrderRepository;
use Eccube\Service\CartService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * 複数配送指定のテストケース.
 *
 * Todo list:
 * 1. testCompleteWithLogin
 * 2. multi shipping with 1 item, 1 address => one shipping
 * 3. multi shipping with 1 item 2 quantity, 1 address => one shipping
 * 4. multi shipping with 2 item 2 quantity, 1 address => one shipping
 * 5. multi shipping with 2 item (first item quantities is 1, next item quantities is 2), 2 address => two shipping
 * 6. multi shipping with 2 item (each item quantities is 2), 2 address => two shipping
 * 7. multi shipping with 3 item, 1 address => one shipping
 * 8. multi shipping with 3 item, 2 address => two shipping
 * 9. multi shipping with 3 item, 3 address => three shipping
 * 10. multi shipping with cart unlock => redirect to cart
 * 11. multi shipping add with cart unlock => redirect to cart
 * 12. multi shipping without cart item => redirect to cart
 * 13. multi shipping with total quantity of product are not equal => reload with error message: 数量の数が異なっています
 * 14. multi shipping with orders have shipped earlier. => redirect to shopping
 *
 * @author Kentaro Ohkouchi
 */
class ShoppingControllerWithMultipleTest extends AbstractShoppingControllerTestCase
{
    /** @var BaseInfoRepository */
    private $baseInfoRepository;

    /** @var OrderRepository */
    private $orderRepository;

    /** @var OrderStatusRepository */
    private $orderStatusRepository;

    public function setUp()
    {
        parent::setUp();
        $this->baseInfoRepository = $this->container->get(BaseInfoRepository::class);
        $this->orderRepository = $this->container->get(OrderRepository::class);
        $this->orderStatusRepository = $this->container->get(OrderStatusRepository::class);
    }

    /**
     * tearDown: rollback and clear mail
     */
    public function tearDown()
    {
        $this->cleanUpMailCatcherMessages();
        parent::tearDown();
    }

    /**
     * カート→購入確認画面→複数配送設定画面→購入確認画面→完了画面
     */
    public function testCompleteWithLogin()
    {
        $Customer = $this->createCustomer();
        $Customer->addCustomerAddress($this->createCustomerAddress($Customer));

        // カート画面
        $this->scenarioCartIn($Customer);
        $this->scenarioCartIn($Customer); // 2個カート投入

        // 確認画面
        $crawler = $this->scenarioConfirm($Customer);
        $this->expected = 'ご注文手続き';
        $this->actual = $crawler->filter('div.ec-pageHeader h1')->text();
        $this->verify();

        // 複数配送画面
        $crawler = $this->client->request('GET', $this->generateUrl('shopping_shipping_multiple'));

        // 配送先1, 配送先2の情報を返す
        $shippings = $crawler->filter('#form_shipping_multiple_0_shipping_0_customer_address > option')->each(
            function ($node, $i) {
                return [
                    'customer_address' => $node->attr('value'),
                    'quantity' => 1,
                ];
            }
        );

        $crawler = $this->client->request(
            'POST',
            $this->generateUrl('shopping_shipping_multiple'),
            ['form' => [
                    'shipping_multiple' => [0 => [
                                // 配送先1, 配送先2 の 情報を渡す
                                'shipping' => $shippings,
                            ],
                        ],
                    '_token' => 'dummy',
                ],
            ]
        );

        // 確認画面
        $crawler = $this->scenarioConfirm($Customer);

        // 完了画面
        $crawler = $this->scenarioCheckout($Customer);

        $this->assertTrue($this->client->getResponse()->isRedirect($this->generateUrl('shopping_complete')));

        $BaseInfo = $this->baseInfoRepository->get();
        /** @var \Swift_Message[] $Messages */
        $Messages = $this->getMailCollector(false)->getMessages();
        $Message = $Messages[0];

        $this->expected = '['.$BaseInfo->getShopName().'] ご注文ありがとうございます';
        $this->actual = $Message->getSubject();
        $this->verify();

        $this->assertRegexp('/◎お届け先2/u', $Message->getBody(), '複数配送のため, お届け先2が存在する');

        // 生成された受注のチェック
        $Order = $this->orderRepository->findOneBy(
            [
                'Customer' => $Customer,
            ]
        );

        $OrderNew = $this->orderStatusRepository->find(OrderStatus::NEW);
        $this->expected = $OrderNew;
        $this->actual = $Order->getOrderStatus();
        $this->verify();

        $this->expected = $Customer->getName01();
        $this->actual = $Order->getName01();
        $this->verify();
    }

    public function testDisplayCustomerAddress()
    {
        $Customer = $this->createCustomer();
        $Customer->addCustomerAddress($this->createCustomerAddress($Customer));

        // 2個カート投入
        $this->scenarioCartIn($Customer);
        $this->scenarioCartIn($Customer);

        // 確認画面
        $crawler = $this->scenarioConfirm($Customer);
        $this->expected = 'ご注文手続き';
        $this->actual = $crawler->filter('div.ec-pageHeader h1')->text();
        $this->verify();

        // 複数配送画面
        $crawler = $this->client->request('GET', $this->generateUrl('shopping_shipping_multiple'));
        // 配送先1, 配送先2の情報を返す
        $shippings = $crawler->filter('#form_shipping_multiple_0_shipping_0_customer_address > option')->each(
            function ($node, $i) {
                return [
                    'customer_address' => $node->html(),
                    'quantity' => 1,
                ];
            }
        );

        $address = $Customer->getName01().' '.$Customer->getPref()->getName().' '.$Customer->getAddr01().' '.$Customer->getAddr02();
        $this->expected = $address;
        $this->actual = $shippings[0]['customer_address'];
        $this->verify();
    }

    /**
     * Test add multi shipping
     */
    public function testAddMultiShippingOneAddressOneItem()
    {
        $Customer = $this->createCustomer();

        $this->scenarioCartIn($Customer);

        // 確認画面
        $crawler = $this->scenarioConfirm($Customer);
        // お届け先指定画面
        $this->scenarioRedirectTo($Customer, [
            '_shopping_order' => [
                'Shippings' => [
                    0 => [
                        'Delivery' => 1,
                        'DeliveryTime' => 1,
                    ],
                ],
                'Payment' => 1,
                'use_pont' => 0,
                'message' => $this->getFaker()->realText(),
                'redirect_to' => $this->generateUrl('shopping_shipping_multiple', [], UrlGeneratorInterface::ABSOLUTE_PATH),
            ],
        ]);

        $multiForm = [
            '_token' => 'dummy',
            'shipping_multiple' => [
                [
                    'shipping' => [
                        [
                            'customer_address' => 0,
                            'quantity' => 1,
                        ],
                    ],
                ],
            ],
        ];

        $this->client->request(
            'POST',
            $this->generateUrl('shopping_shipping_multiple'),
            ['form' => $multiForm]
        );

        $this->assertTrue($this->client->getResponse()->isRedirect($this->generateUrl('shopping')));

        $Order = $this->orderRepository->findOneBy(['Customer' => $Customer]);

        // One shipping
        $this->assertCount(1, $Order->getShippings());
    }

    /**
     * Test add multi shipping
     */
    public function testAddMultiShippingOneAddressOneItemTwoQuantities()
    {
        $Customer = $this->createCustomer();

        $this->client->request('POST', '/cart/add', ['product_class_id' => 1, 'quantity' => 1]);

        $this->scenarioCartIn($Customer);
        $this->scenarioCartIn($Customer);

        // 確認画面
        $crawler = $this->scenarioConfirm($Customer);

        // お届け先指定画面
        $this->scenarioRedirectTo($Customer, [
            '_shopping_order' => [
                'Shippings' => [
                    0 => [
                        'Delivery' => 1,
                        'DeliveryTime' => 1,
                    ],
                ],
                'Payment' => 1,
                'use_pont' => 0,
                'message' => $this->getFaker()->realText(),
                'redirect_to' => $this->generateUrl('shopping_shipping_multiple', [], UrlGeneratorInterface::ABSOLUTE_PATH),
            ],
        ]);

        $multiForm = [
            '_token' => 'dummy',
            'shipping_multiple' => [
                [
                    'shipping' => [
                        [
                            'customer_address' => 0,
                            'quantity' => 1,
                        ],
                        [
                            'customer_address' => 0,
                            'quantity' => 1,
                        ],
                    ],
                ],
            ],
        ];

        $this->client->request(
            'POST',
            $this->generateUrl('shopping_shipping_multiple'),
            ['form' => $multiForm]
        );

        $this->assertTrue($this->client->getResponse()->isRedirect($this->generateUrl('shopping')));

        $Order = $this->orderRepository->findOneBy(['Customer' => $Customer]);

        // One shipping
        $this->assertCount(1, $Order->getShippings());
    }

    /**
     * Test add multi shipping
     */
    public function testAddMultiShippingOneAddressTwoItems()
    {
        $Customer = $this->createCustomer();

        // Product test 1 with type 1
        $Product1 = $this->createProduct();
        $ProductClass1 = $Product1->getProductClasses()->first();
        $ProductClass1->setStock(111);

        // Product test 2
        $Product2 = $this->createProduct();
        $ProductClass2 = $Product2->getProductClasses()->first();
        $ProductClass2->setStock(111);

        $this->entityManager->persist($ProductClass1);
        $this->entityManager->persist($ProductClass2);
        $this->entityManager->flush();

        // Item of product 1
        $this->scenarioCartIn($Customer, $ProductClass1->getId());
        $this->scenarioCartIn($Customer, $ProductClass1->getId());

        // Item of product 2
        $this->scenarioCartIn($Customer, $ProductClass2->getId());
        $this->scenarioCartIn($Customer, $ProductClass2->getId());

        // 確認画面
        $crawler = $this->scenarioConfirm($Customer);

        // お届け先指定画面
        $this->scenarioRedirectTo($Customer, [
            '_shopping_order' => [
                'Shippings' => [
                    0 => [
                        'Delivery' => 1,
                        'DeliveryTime' => 1,
                    ],
                ],
                'Payment' => 1,
                'use_pont' => 0,
                'message' => $this->getFaker()->realText(),
                'redirect_to' => $this->generateUrl('shopping_shipping_multiple', [], UrlGeneratorInterface::ABSOLUTE_PATH),            ],
        ]);

        $multiForm = [
            '_token' => 'dummy',
            'shipping_multiple' => [
                [
                    'shipping' => [
                        [
                            'customer_address' => 0,
                            'quantity' => 1,
                        ],
                        [
                            'customer_address' => 0,
                            'quantity' => 1,
                        ],
                    ],
                ],
                [
                    'shipping' => [
                        [
                            'customer_address' => 0,
                            'quantity' => 1,
                        ],
                        [
                            'customer_address' => 0,
                            'quantity' => 1,
                        ],
                    ],
                ],
            ],
        ];

        $this->client->request(
            'POST',
            $this->generateUrl('shopping_shipping_multiple'),
            ['form' => $multiForm]
        );

        $this->assertTrue($this->client->getResponse()->isRedirect($this->generateUrl('shopping')));

        $Order = $this->orderRepository->findOneBy(['Customer' => $Customer]);
        $Shipping = $Order->getShippings();

        // One shipping
        $this->assertCount(1, $Order->getShippings());
    }

    /**
     * Test add multi shipping
     */
    public function testAddMultiShippingTwoAddressesTwoItemsOneAndTwoQuantities()
    {
        $Customer = $this->createCustomer();
        $Customer->addCustomerAddress($this->createCustomerAddress($Customer));

        // Product test 1 with type 1
        $Product1 = $this->createProduct();
        $ProductClass1 = $Product1->getProductClasses()->first();
        $ProductClass1->setStock(111);

        // Product test 2
        $Product2 = $this->createProduct();
        $ProductClass2 = $Product2->getProductClasses()->first();
        $ProductClass2->setStock(111);

        $this->entityManager->persist($ProductClass1);
        $this->entityManager->persist($ProductClass2);
        $this->entityManager->flush();

        // Item of product 1
        $this->scenarioCartIn($Customer, $ProductClass1->getId());

        // Item of product 2
        $this->scenarioCartIn($Customer, $ProductClass2->getId());
        $this->scenarioCartIn($Customer, $ProductClass2->getId());

        // 確認画面
        $crawler = $this->scenarioConfirm($Customer);

        // お届け先指定画面
        $this->scenarioRedirectTo($Customer, [
            '_shopping_order' => [
                'Shippings' => [
                    0 => [
                        'Delivery' => 1,
                        'DeliveryTime' => 1,
                    ],
                ],
                'Payment' => 1,
                'use_pont' => 0,
                'message' => $this->getFaker()->realText(),
                'redirect_to' => $this->generateUrl('shopping_shipping_multiple', [], UrlGeneratorInterface::ABSOLUTE_PATH),            ],
        ]);

        $multiForm = [
            '_token' => 'dummy',
            'shipping_multiple' => [
                [
                    'shipping' => [
                        [
                            'customer_address' => 0,
                            'quantity' => 2,
                        ],
                    ],
                ],
                [
                    'shipping' => [
                        [
                            'customer_address' => 0,
                            'quantity' => 2,
                        ],
                        [
                            'customer_address' => 1,
                            'quantity' => 2,
                        ],
                    ],
                ],
            ],
        ];

        $this->client->request(
            'POST',
            $this->generateUrl('shopping_shipping_multiple'),
            ['form' => $multiForm]
        );

        $this->assertTrue($this->client->getResponse()->isRedirect($this->generateUrl('shopping')));

        $Order = $this->orderRepository->findOneBy(['Customer' => $Customer]);

        // Two shipping
        $this->assertCount(2, $Order->getShippings());
    }

    /**
     * Test add multi shipping
     */
    public function testAddMultiShippingTwoAddressesTwoItemsEachTwoQuantities()
    {
        $Customer = $this->createCustomer();
        $Customer->addCustomerAddress($this->createCustomerAddress($Customer));

        // Product test 1 with type 1
        $Product1 = $this->createProduct();
        $ProductClass1 = $Product1->getProductClasses()->first();
        $ProductClass1->setStock(111);

        // Product test 2
        $Product2 = $this->createProduct();
        $ProductClass2 = $Product2->getProductClasses()->first();
        $ProductClass2->setStock(111);

        $this->entityManager->persist($ProductClass1);
        $this->entityManager->persist($ProductClass2);
        $this->entityManager->flush();

        // Item of product 1
        $this->scenarioCartIn($Customer, $ProductClass1->getId());
        $this->scenarioCartIn($Customer, $ProductClass1->getId());

        // Item of product 2
        $this->scenarioCartIn($Customer, $ProductClass2->getId());
        $this->scenarioCartIn($Customer, $ProductClass2->getId());

        $this->scenarioCartIn($Customer);

        // 確認画面
        $crawler = $this->scenarioConfirm($Customer);

        // お届け先指定画面
        $this->scenarioRedirectTo($Customer, [
            '_shopping_order' => [
                'Shippings' => [
                    0 => [
                        'Delivery' => 1,
                        'DeliveryTime' => 1,
                    ],
                ],
                'Payment' => 1,
                'use_pont' => 0,
                'message' => $this->getFaker()->realText(),
                'redirect_to' => $this->generateUrl('shopping_shipping_multiple', [], UrlGeneratorInterface::ABSOLUTE_PATH),            ],
        ]);

        $multiForm = [
            '_token' => 'dummy',
            'shipping_multiple' => [
                [
                    'shipping' => [
                        [
                            'customer_address' => 0,
                            'quantity' => 1,
                        ],
                        [
                            'customer_address' => 1,
                            'quantity' => 1,
                        ],
                    ],
                ],
                [
                    'shipping' => [
                        [
                            'customer_address' => 0,
                            'quantity' => 1,
                        ],
                        [
                            'customer_address' => 1,
                            'quantity' => 1,
                        ],
                    ],
                ],
            ],
        ];

        $this->client->request(
            'POST',
            $this->generateUrl('shopping_shipping_multiple'),
            ['form' => $multiForm]
        );

        $this->assertTrue($this->client->getResponse()->isRedirect($this->generateUrl('shopping')));

        $Order = $this->orderRepository->findOneBy(['Customer' => $Customer]);

        // Two shipping
        $this->assertCount(2, $Order->getShippings());
    }

    /**
     * Test add multi shipping
     */
    public function testAddMultiShippingOneAddressThreeItems()
    {
        $Customer = $this->createCustomer();

        // Product test 1 with type 1
        $Product1 = $this->createProduct();
        $ProductClass1 = $Product1->getProductClasses()->first();
        $ProductClass1->setStock(111);

        // Product test 2
        $Product2 = $this->createProduct();
        $ProductClass2 = $Product2->getProductClasses()->first();
        $ProductClass2->setStock(111);

        // Product test 3
        $Product3 = $this->createProduct();
        $ProductClass3 = $Product3->getProductClasses()->first();
        $ProductClass3->setStock(111);

        $this->entityManager->persist($ProductClass1);
        $this->entityManager->persist($ProductClass2);
        $this->entityManager->persist($ProductClass3);
        $this->entityManager->flush();

        // Item of product 1
        $this->scenarioCartIn($Customer, $ProductClass1->getId());
        $this->scenarioCartIn($Customer, $ProductClass1->getId());

        // Item of product 2
        $this->scenarioCartIn($Customer, $ProductClass2->getId());
        $this->scenarioCartIn($Customer, $ProductClass2->getId());

        // Item of product 3
        $this->scenarioCartIn($Customer, $ProductClass3->getId());

        // 確認画面
        $crawler = $this->scenarioConfirm($Customer);
        // お届け先指定画面
        $this->scenarioRedirectTo($Customer, [
            '_shopping_order' => [
                'Shippings' => [
                    0 => [
                        'Delivery' => 1,
                        'DeliveryTime' => 1,
                    ],
                ],
                'Payment' => 1,
                'use_pont' => 0,
                'message' => $this->getFaker()->realText(),
                'redirect_to' => $this->generateUrl('shopping_shipping_multiple', [], UrlGeneratorInterface::ABSOLUTE_PATH),            ],
        ]);

        $multiForm = [
            '_token' => 'dummy',
            'shipping_multiple' => [
                [
                    'shipping' => [
                        [
                            'customer_address' => 0,
                            'quantity' => 1,
                        ],
                        [
                            'customer_address' => 0,
                            'quantity' => 1,
                        ],
                    ],
                ],
                [
                    'shipping' => [
                        [
                            'customer_address' => 0,
                            'quantity' => 1,
                        ],
                        [
                            'customer_address' => 0,
                            'quantity' => 1,
                        ],
                    ],
                ],
                [
                    'shipping' => [
                        [
                            'customer_address' => 0,
                            'quantity' => 1,
                        ],
                    ],
                ],
            ],
        ];

        $this->client->request(
            'POST',
            $this->generateUrl('shopping_shipping_multiple'),
            ['form' => $multiForm]
        );

        $this->assertTrue($this->client->getResponse()->isRedirect($this->generateUrl('shopping')));

        $Order = $this->orderRepository->findOneBy(['Customer' => $Customer]);

        // One shipping
        $this->assertCount(1, $Order->getShippings());
    }

    /**
     * Test add multi shipping
     */
    public function testAddMultiShippingTwoAddressesThreeItems()
    {
        $Customer = $this->createCustomer();
        $Customer->addCustomerAddress($this->createCustomerAddress($Customer));

        // Product test 1 with type 1
        $Product1 = $this->createProduct();
        $ProductClass1 = $Product1->getProductClasses()->first();
        $ProductClass1->setStock(111);

        // Product test 2
        $Product2 = $this->createProduct();
        $ProductClass2 = $Product2->getProductClasses()->first();
        $ProductClass2->setStock(111);

        // Product test 3
        $Product3 = $this->createProduct();
        $ProductClass3 = $Product3->getProductClasses()->first();
        $ProductClass3->setStock(111);

        $this->entityManager->persist($ProductClass1);
        $this->entityManager->persist($ProductClass2);
        $this->entityManager->persist($ProductClass3);
        $this->entityManager->flush();

        // Item of product 1
        $this->scenarioCartIn($Customer, $ProductClass1->getId());
        $this->scenarioCartIn($Customer, $ProductClass1->getId());

        // Item of product 2
        $this->scenarioCartIn($Customer, $ProductClass2->getId());
        $this->scenarioCartIn($Customer, $ProductClass2->getId());

        // Item of product 3
        $this->scenarioCartIn($Customer, $ProductClass3->getId());

        $this->scenarioCartIn($Customer);

        // 確認画面
        $crawler = $this->scenarioConfirm($Customer);
        // お届け先指定画面
        $this->scenarioRedirectTo($Customer, [
            '_shopping_order' => [
                'Shippings' => [
                    0 => [
                        'Delivery' => 1,
                        'DeliveryTime' => 1,
                    ],
                ],
                'Payment' => 1,
                'use_pont' => 0,
                'message' => $this->getFaker()->realText(),
                'redirect_to' => $this->generateUrl('shopping_shipping_multiple', [], UrlGeneratorInterface::ABSOLUTE_PATH),            ],
        ]);

        $multiForm = [
            '_token' => 'dummy',
            'shipping_multiple' => [
                [
                    'shipping' => [
                        [
                            'customer_address' => 0,
                            'quantity' => 1,
                        ],
                        [
                            'customer_address' => 1,
                            'quantity' => 1,
                        ],
                    ],
                ],
                [
                    'shipping' => [
                        [
                            'customer_address' => 0,
                            'quantity' => 1,
                        ],
                        [
                            'customer_address' => 1,
                            'quantity' => 1,
                        ],
                    ],
                ],
                [
                    'shipping' => [
                        [
                            'customer_address' => 0,
                            'quantity' => 1,
                        ],
                    ],
                ],
            ],
        ];

        $this->client->request(
            'POST',
            $this->generateUrl('shopping_shipping_multiple'),
            ['form' => $multiForm]
        );

        $this->assertTrue($this->client->getResponse()->isRedirect($this->generateUrl('shopping')));

        $Order = $this->orderRepository->findOneBy(['Customer' => $Customer]);

        // Two shipping
        $this->assertCount(2, $Order->getShippings());
    }

    /**
     * Test add multi shipping
     */
    public function testAddMultiShippingThreeAddressesThreeItems()
    {
        $Customer = $this->createCustomer();
        $Customer->addCustomerAddress($this->createCustomerAddress($Customer));
        $Customer->addCustomerAddress($this->createCustomerAddress($Customer));

        // Product test 1 with type 1
        $Product1 = $this->createProduct();
        $ProductClass1 = $Product1->getProductClasses()->first();
        $ProductClass1->setStock(111);

        // Product test 2
        $Product2 = $this->createProduct();
        $ProductClass2 = $Product2->getProductClasses()->first();
        $ProductClass2->setStock(111);

        // Product test 3
        $Product3 = $this->createProduct();
        $ProductClass3 = $Product3->getProductClasses()->first();
        $ProductClass3->setStock(111);

        $this->entityManager->persist($ProductClass1);
        $this->entityManager->persist($ProductClass2);
        $this->entityManager->persist($ProductClass3);
        $this->entityManager->flush();

        // Item of product 1
        $this->scenarioCartIn($Customer, $ProductClass1->getId());
        $this->scenarioCartIn($Customer, $ProductClass1->getId());

        // Item of product 2
        $this->scenarioCartIn($Customer, $ProductClass2->getId());
        $this->scenarioCartIn($Customer, $ProductClass2->getId());

        // Item of product 3
        $this->scenarioCartIn($Customer, $ProductClass3->getId());

        $this->scenarioCartIn($Customer);

        // 確認画面
        $crawler = $this->scenarioConfirm($Customer);

        // お届け先指定画面
        $this->scenarioRedirectTo($Customer, [
            '_shopping_order' => [
                'Shippings' => [
                    0 => [
                        'Delivery' => 1,
                        'DeliveryTime' => 1,
                    ],
                ],
                'Payment' => 1,
                'use_pont' => 0,
                'message' => $this->getFaker()->realText(),
                'redirect_to' => $this->generateUrl('shopping_shipping_multiple', [], UrlGeneratorInterface::ABSOLUTE_PATH),            ],
        ]);

        $multiForm = [
            '_token' => 'dummy',
            'shipping_multiple' => [
                [
                    'shipping' => [
                        [
                            'customer_address' => 0,
                            'quantity' => 1,
                        ],
                        [
                            'customer_address' => 1,
                            'quantity' => 1,
                        ],
                    ],
                ],
                [
                    'shipping' => [
                        [
                            'customer_address' => 0,
                            'quantity' => 1,
                        ],
                        [
                            'customer_address' => 1,
                            'quantity' => 1,
                        ],
                    ],
                ],
                [
                    'shipping' => [
                        [
                            'customer_address' => 2,
                            'quantity' => 1,
                        ],
                    ],
                ],
            ],
        ];

        $this->client->request(
            'POST',
            $this->generateUrl('shopping_shipping_multiple'),
            ['form' => $multiForm]
        );

        $this->assertTrue($this->client->getResponse()->isRedirect($this->generateUrl('shopping')));

        $Order = $this->orderRepository->findOneBy(['Customer' => $Customer]);

        // Three shipping
        $this->assertCount(3, $Order->getShippings());
    }

    /**
     * Test add multi shipping
     */
    public function testAddMultiShippingWithoutCart()
    {
        $this->markTestIncomplete('カートのクリア処理');

        $Customer = $this->createCustomer();
        $Customer->addCustomerAddress($this->createCustomerAddress($Customer));
        $Customer->addCustomerAddress($this->createCustomerAddress($Customer));

        $this->client->request('POST', '/cart/add', ['product_class_id' => 10, 'quantity' => 2]);
        $this->client->request('POST', '/cart/add', ['product_class_id' => 1, 'quantity' => 1]);
        $this->client->request('POST', '/cart/add', ['product_class_id' => 2, 'quantity' => 1]);

        $this->scenarioCartIn($Customer);

        // 確認画面
        $crawler = $this->scenarioConfirm($Customer);
        // お届け先指定画面
        $this->scenarioRedirectTo($Customer, [
            '_shopping_order' => [
                'Shippings' => [
                    0 => [
                        'Delivery' => 1,
                        'DeliveryTime' => 1,
                    ],
                ],
                'Payment' => 1,
                'use_pont' => 0,
                'message' => $this->getFaker()->realText(),
                'redirect_to' => $this->generateUrl('shopping_shipping_multiple', [], UrlGeneratorInterface::ABSOLUTE_PATH),            ],
        ]);

        $arrCustomerAddress = $Customer->getCustomerAddresses();
        $secondCustomerAddress = $arrCustomerAddress->next();

        $multiForm = [
            '_token' => 'dummy',
            'shipping_multiple' => [
                [
                    'shipping' => [
                        [
                            'customer_address' => $arrCustomerAddress->first()->getId(),
                            'quantity' => 1,
                        ],
                        [
                            'customer_address' => $arrCustomerAddress->last()->getId(),
                            'quantity' => 1,
                        ],
                    ],
                ],
                [
                    'shipping' => [
                        [
                            'customer_address' => $arrCustomerAddress->first()->getId(),
                            'quantity' => 1,
                        ],
                        [
                            'customer_address' => $arrCustomerAddress->last()->getId(),
                            'quantity' => 1,
                        ],
                    ],
                ],
                [
                    'shipping' => [
                        [
                            'customer_address' => $secondCustomerAddress->getId(),
                            'quantity' => 1,
                        ],
                    ],
                ],
            ],
        ];

        $cartService = $this->container->get(CartService::class);
        $cartService->clear();

        $this->client->request(
            'POST',
            $this->generateUrl('shopping_shipping_multiple'),
            ['form' => $multiForm]
        );

        $this->assertTrue($this->client->getResponse()->isRedirect($this->generateUrl('cart')));
    }

    /**
     * Test add multi shipping
     */
    public function testAddMultiShippingWithShippingEarlier()
    {
        $Customer = $this->createCustomer();
        $Customer->addCustomerAddress($this->createCustomerAddress($Customer));
        $Customer->addCustomerAddress($this->createCustomerAddress($Customer));

        // Product test 1 with type 1
        $Product1 = $this->createProduct();
        $ProductClass1 = $Product1->getProductClasses()->first();
        $ProductClass1->setStock(111);

        // Product test 2
        $Product2 = $this->createProduct();
        $ProductClass2 = $Product2->getProductClasses()->first();
        $ProductClass2->setStock(111);

        // Product test 3
        $Product3 = $this->createProduct();
        $ProductClass3 = $Product3->getProductClasses()->first();
        $ProductClass3->setStock(111);

        $this->entityManager->persist($ProductClass1);
        $this->entityManager->persist($ProductClass2);
        $this->entityManager->persist($ProductClass3);
        $this->entityManager->flush();

        // Item of product 1
        $this->scenarioCartIn($Customer, $ProductClass1->getId());
        $this->scenarioCartIn($Customer, $ProductClass1->getId());

        // Item of product 2
        $this->scenarioCartIn($Customer, $ProductClass2->getId());
        $this->scenarioCartIn($Customer, $ProductClass2->getId());

        // Item of product 3
        $this->scenarioCartIn($Customer, $ProductClass3->getId());

        // 確認画面
        $crawler = $this->scenarioConfirm($Customer);
        // お届け先指定画面
        $this->scenarioRedirectTo($Customer, [
            '_shopping_order' => [
                'Shippings' => [
                    0 => [
                        'Delivery' => 1,
                        'DeliveryTime' => 1,
                    ],
                ],
                'Payment' => 1,
                'use_pont' => 0,
                'message' => $this->getFaker()->realText(),
                'redirect_to' => $this->generateUrl('shopping_shipping_multiple', [], UrlGeneratorInterface::ABSOLUTE_PATH),            ],
        ]);

        // Before multi shipping
        // Only shipped to one address
        $beforeForm = [
            '_token' => 'dummy',
            'shipping_multiple' => [
                [
                    'shipping' => [
                        [
                            'customer_address' => 0,
                            'quantity' => 2,
                        ],
                    ],
                ],
                [
                    'shipping' => [
                        [
                            'customer_address' => 0,
                            'quantity' => 2,
                        ],
                    ],
                ],
                [
                    'shipping' => [
                        [
                            'customer_address' => 0,
                            'quantity' => 1,
                        ],
                    ],
                ],
            ],
        ];

        // Multi shipping form
        // Shipped to three addresses
        $afterForm = [
            '_token' => 'dummy',
            'shipping_multiple' => [
                [
                    'shipping' => [
                        [
                            'customer_address' => 0,
                            'quantity' => 1,
                        ],
                        [
                            'customer_address' => 1,
                            'quantity' => 1,
                        ],
                    ],
                ],
                [
                    'shipping' => [
                        [
                            'customer_address' => 0,
                            'quantity' => 1,
                        ],
                        [
                            'customer_address' => 1,
                            'quantity' => 1,
                        ],
                    ],
                ],
                [
                    'shipping' => [
                        [
                            'customer_address' => 2,
                            'quantity' => 1,
                        ],
                    ],
                ],
            ],
        ];

        $this->client->request(
            'POST',
            $this->generateUrl('shopping_shipping_multiple'),
            ['form' => $beforeForm]
        );

        $this->assertTrue($this->client->getResponse()->isRedirect($this->generateUrl('shopping')));

        $this->client->request(
            'POST',
            $this->generateUrl('shopping_shipping_multiple'),
            ['form' => $afterForm]
        );

        $this->assertTrue($this->client->getResponse()->isRedirect($this->generateUrl('shopping')));

        $Order = $this->orderRepository->findOneBy(['Customer' => $Customer]);
        $Shipping = $Order->getShippings();

        // Three shipping
<<<<<<< HEAD
        $this->actual = count($Shipping);
        $this->expected = count($arrCustomerAddress);
        $this->verify();
    }

    /**
     * Max address need to test
     * Test add multi shipping
     */
    public function testAddMultiShippingExceedNAddress()
    {
        // Max address need to test
        $maxAddress = 25;

        $User = $this->logIn();
        $client = $this->client;

        $client->request('POST', '/cart/add', array('product_class_id' => 1, 'quantity' => $maxAddress));
        $this->scenarioCartIn($client);

        // 確認画面
        $this->scenarioConfirm($client);

        for ($i = 0; $i < $maxAddress; $i++) {
            $CustomerAddress = $this->createCustomerAddress($User);
            $User->addCustomerAddress($CustomerAddress);
        }

        $crawler = $client->request('GET', $this->app->path('shopping_shipping_multiple'));

        $shipping = $crawler->filter('#form_shipping_multiple_0_shipping_0_customer_address > option')->each(
            function ($node, $i) {
                return array(
                    'customer_address' => $node->attr('value'),
                    'quantity' => 1
                );
            }
        );
        $multiForm = array(
            '_token' => 'dummy',
            'shipping_multiple' => array(
                array(
                    'shipping' => $shipping
                ),
            ),
        );

        $client->request(
            'POST',
            $this->app->url('shopping_shipping_multiple'),
            array("form" => $multiForm)
        );

        $this->assertTrue($client->getResponse()->isRedirect($this->app->url('shopping')));

        $Order = $this->app['eccube.repository.order']->findOneBy(array('Customer' => $User));
        $Shipping = $Order->getShippings();

        $this->actual = count($Shipping);
        $this->expected = $maxAddress + 1;
        $this->verify();

        // 確認画面
        $crawler = $this->scenarioConfirm($client);

        // shipping number on the screen
        $lastShipping = $crawler->filter('.is-edit h3')->last()->text();
        $this->assertContains((string)$this->expected, $lastShipping);
    }

    /**
     * Test add multi shipping when there are two types of products.
     *
     * Give:
     * - Product type A x 1
     * - Product type B x 1
     * - Address x 1
     *
     * When:
     * - Shipment item:
     *  + Product type A x1
     *  + Product type B x1
     * - Delivery: 1 (for product type A)
     * - Delivery: 2 (for product type B)
     *
     * Then:
     * - Number of Shipping: 2
     *  + Product type A x 1 - address 1
     *  + Product type B x 1 - address 1
     * - Delivery 1: サンプル業者
     * - Mail content: ◎お届け先2
     */
    public function testAddMultiShippingWithProductTypeOfOneShippingAreNotSame()
    {
        $User = $this->logIn();
        $client = $this->client;
        // Address 1
        $CustomerAddress = $this->createCustomerAddress($User);
        $User->addCustomerAddress($CustomerAddress);

        // Product with other type (2)
        $Product = $this->createProduct();
        $ProductType = $this->app['eccube.repository.master.product_type']->find(2);
        $ProductClass = $Product->getProductClasses()->first();
        $ProductClass->setProductType($ProductType)->setStock(111);
        $this->app['orm.em']->persist($ProductClass);
        $this->app['orm.em']->flush();

        // product type 1
        $this->scenarioCartIn($client);
        // product type 2
        $this->scenarioCartIn($client, $ProductClass->getId());

        // 確認画面
        $crawler = $this->scenarioConfirm($client);
        $this->expected = 'ご注文内容のご確認';
        $this->actual = $crawler->filter('h1.page-heading')->text();
        $this->verify();

        $arrCustomerAddress = $User->getCustomerAddresses();

        // Before multi shipping
        // Only shipped to one address
        $multiForm = array(
            '_token' => 'dummy',
            'shipping_multiple' => array(
                array(
                    'shipping' => array(
                        array(
                            'customer_address' => $arrCustomerAddress->first()->getId(),
                            'quantity' => 1,
                        ),
                    ),
                ),
                array(
                    'shipping' => array(
                        array(
                            'customer_address' => $arrCustomerAddress->first()->getId(),
                            'quantity' => 1,
                        ),
                    ),
                ),
            ),
        );

        $client->request(
            'POST',
            $this->app->url('shopping_shipping_multiple'),
            array("form" => $multiForm)
        );

        $this->assertTrue($client->getResponse()->isRedirect($this->app->url('shopping')));

        $Order = $this->app['eccube.repository.order']->findOneBy(array('Customer' => $User));
        $Shipping = $Order->getShippings();

        // total delivery fee
        $this->actual = $Order->getDeliveryFeeTotal();
        $this->expected = 1000;
        $this->verify();

        $this->actual = count($Shipping);
        $this->expected = 2;
        $this->verify();

        // 確認画面
        $crawler = $this->scenarioConfirm($client);

        // shipping number on the screen
        $lastShipping = $crawler->filter('.is-edit h3')->last()->text();
        $this->assertContains((string)$this->expected, $lastShipping);

        $deliver = $crawler->filter('#shopping_shippings_0_delivery > option')->each(
            function ($node, $i) {
                return $node->text();
            }
        );

        // Testcase from https://github.com/EC-CUBE/ec-cube/pull/1745#issuecomment-245550676
        $this->expected = 'サンプル宅配';
        $this->actual = $deliver;
        $this->assertTrue(in_array($this->expected, $this->actual));

        // 完了画面
        $this->scenarioComplete(
            $client,
            $this->app->path('shopping_confirm'),
            array(
                // 配送先1
                array(
                    'delivery' => 2,
                ),
                // 配送先2
                array(
                    'delivery' => 1,
                    'deliveryTime' => 1,
                ),
            ),
            3
        );

        $this->assertTrue($client->getResponse()->isRedirect($this->app->url('shopping_complete')));

        $BaseInfo = $this->app['eccube.repository.base_info']->get();
        $Messages = $this->getMailCatcherMessages();
        $Message = $this->getMailCatcherMessage($Messages[0]->id);

        $this->expected = '[' . $BaseInfo->getShopName() . '] ご注文ありがとうございます';
        $this->actual = $Message->subject;
        $this->verify();

        $body = $this->parseMailCatcherSource($Message);
        $this->assertRegexp('/◎お届け先2/', $body, '複数配送のため, お届け先2が存在する');

        // 生成された受注のチェック
        $Order = $this->app['eccube.repository.order']->findOneBy(
            array(
                'Customer' => $User
            )
        );

        $OrderNew = $this->app['eccube.repository.order_status']->find($this->app['config']['order_new']);
        $this->expected = $OrderNew;
        $this->actual = $Order->getOrderStatus();
        $this->verify();

        $this->expected = $User->getName01();
        $this->actual = $Order->getName01();
        $this->verify();
=======
        $this->assertCount(3, $Shipping);
>>>>>>> 2c09ba75d7b7fba1a3b27dbc46b98417f7fffe0d
    }

    /**
     * Test add multi shipping item merge
     *
     * Give:
     * - Product A x 3
     * - Address x 1
     *
     * When:
     * - Shipment item:
     *  + Product A x1
     *  + Product A x1
     *  + Product A x1
     * - Delivery: 1 (for product type 1)
     *
     * Then:
     * - Number of Shipping: 1
     * - Shipment item: Product A x 3
     * - Delivery 1: サンプル業者
     * - Mail content: ◎お届け先
     */
    public function testAddMultiShippingThreeItemsOfOneProduct()
    {
        $Customer = $this->createCustomer();

        // Product
        $Product = $this->createProduct();
        $ProductClass = $Product->getProductClasses()->first();
        $ProductClass->setStock(111);
        $this->entityManager->persist($ProductClass);
        $this->entityManager->flush();

        // three items of one product
        $this->scenarioCartIn($Customer, $ProductClass->getId());
        $this->scenarioCartIn($Customer, $ProductClass->getId());
        $this->scenarioCartIn($Customer, $ProductClass->getId());

        // 確認画面
        $crawler = $this->scenarioConfirm($Customer);
        $this->expected = 'ご注文手続き';
        $this->actual = $crawler->filter('div.ec-pageHeader h1')->text();
        $this->verify();

        // Before multi shipping
        // Only shipped to one address
        $multiForm = [
            '_token' => 'dummy',
            'shipping_multiple' => [
                [
                    'shipping' => [
                        // number 3
                        [
                            'customer_address' => 0,
                            'quantity' => 1,
                        ],
                        [
                            'customer_address' => 0,
                            'quantity' => 1,
                        ],
                        [
                            'customer_address' => 0,
                            'quantity' => 1,
                        ],
                    ],
                ],
            ],
        ];

        $this->client->request(
            'POST',
            $this->generateUrl('shopping_shipping_multiple'),
            ['form' => $multiForm]
        );

        $this->assertTrue($this->client->getResponse()->isRedirect($this->generateUrl('shopping')));

        $Order = $this->orderRepository->findOneBy(['Customer' => $Customer]);
        $Shipping = $Order->getShippings();

        // still only one shipping
        $this->actual = count($Shipping);
        $this->expected = 1;
        $this->verify();

        // 確認画面
        $crawler = $this->scenarioConfirm($Customer);

        // item number on the screen
        $shipping = $crawler->filter('#shopping-form > div > div.ec-orderRole__detail > div.ec-orderDelivery > div.ec-orderDelivery__item > ul')->text();
        $this->assertContains('× 3', $shipping);

        $deliver = $crawler->filter('#shopping_order_Shippings_0_Delivery > option')->each(
            function ($node, $i) {
                return $node->text();
            }
        );

        $this->expected = 'サンプル業者';
        $this->actual = $deliver;
        $this->assertTrue(in_array($this->expected, $this->actual));

        // 完了画面
        $this->scenarioComplete(
<<<<<<< HEAD
            $client,
            $this->app->path('shopping_confirm'),
            array(
                array(
                    'delivery' => 1,
                    'deliveryTime' => 1,
                ),
            )
        );

        $this->assertTrue($client->getResponse()->isRedirect($this->app->url('shopping_complete')));

        $BaseInfo = $this->app['eccube.repository.base_info']->get();
        $Messages = $this->getMailCatcherMessages();
        $Message = $this->getMailCatcherMessage($Messages[0]->id);

        $this->expected = '[' . $BaseInfo->getShopName() . '] ご注文ありがとうございます';
        $this->actual = $Message->subject;
        $this->verify();

        $body = $this->parseMailCatcherSource($Message);
        $this->assertRegexp('/◎お届け先/', $body, '複数配送のため, お届け先1が存在する');

        // 生成された受注のチェック
        $Order = $this->app['eccube.repository.order']->findOneBy(
            array(
                'Customer' => $User
            )
        );

        $OrderNew = $this->app['eccube.repository.order_status']->find($this->app['config']['order_new']);
        $this->expected = $OrderNew;
        $this->actual = $Order->getOrderStatus();
        $this->verify();

        $this->expected = $User->getName01();
        $this->actual = $Order->getName01();
        $this->verify();
    }

    /**
     * Test add multi shipping
     *
     * Give:
     * - Product type A x 3
     * - Product type B x 3
     * - Address x 2
     *
     * When:
     * - Shipment item:
     *  + Product type A x1 - address 1
     *  + Product type A x1 - address 2
     *  + Product type A x1 - address 1
     *  + Product type B x1 - address 1
     *  + Product type B x1 - address 2
     *  + Product type B x1 - address 1
     * - Delivery: 1 - product type A - address 1
     * - Delivery: 1 - product type A - address 2
     * - Delivery: 2 - product type B - address 1
     * - Delivery: 2 - product type B - address 2
     *
     * Then:
     * - Number of Shipping: 4
     *  + Shipping 1: Product type B x2 - address 1
     *  + Shipping 2: Product type A x2 - address 1
     *  + Shipping 3: Product type B x1 - address 2
     *  + Shipping 4: Product type A x1 - address 2
     * - Delivery 3: サンプル業者
     * - Mail content: ◎お届け先4
     */
    public function testAddMultiShippingThreeItemsOfTwoProductHasTwoTypeWithTwoAddress()
    {
        $User = $this->logIn();
        $client = $this->client;
        // Two address
        // Address 1
        $CustomerAddress = $this->createCustomerAddress($User);
        $User->addCustomerAddress($CustomerAddress);
        // Address 2
        $CustomerAddress = $this->createCustomerAddress($User);
        $User->addCustomerAddress($CustomerAddress);

        // Product test 1 with type 1
        $Product = $this->createProduct();
        $ProductClass = $Product->getProductClasses()->first();
        $ProductClass->setStock(111);

        // Product test 2 with type 2
        $Product2 = $this->createProduct();
        $ProductType = $this->app['eccube.repository.master.product_type']->find(2);
        $ProductClass2 = $Product2->getProductClasses()->first();
        $ProductClass2->setProductType($ProductType)->setStock(111);

        $this->app['orm.em']->persist($ProductClass);
        $this->app['orm.em']->persist($ProductClass2);
        $this->app['orm.em']->flush();

        // Three item of product 1
        $this->scenarioCartIn($client, $ProductClass->getId());
        $this->scenarioCartIn($client, $ProductClass->getId());
        $this->scenarioCartIn($client, $ProductClass->getId());

        // Three item of product 2
        $this->scenarioCartIn($client, $ProductClass2->getId());
        $this->scenarioCartIn($client, $ProductClass2->getId());
        $this->scenarioCartIn($client, $ProductClass2->getId());

        // 確認画面
        $crawler = $this->scenarioConfirm($client);
        $this->expected = 'ご注文内容のご確認';
        $this->actual = $crawler->filter('h1.page-heading')->text();
        $this->verify();

        $arrCustomerAddress = $User->getCustomerAddresses();

        // Before multi shipping
        // Shipped to two address
        $multiForm = array(
            '_token' => 'dummy',
            'shipping_multiple' => array(
                // product type 2
                array(
                    'shipping' => array(
                        array(
                            'customer_address' => $arrCustomerAddress->first()->getId(),
                            'quantity' => 1,
                        ),
                        array(
                            'customer_address' => $arrCustomerAddress->last()->getId(),
                            'quantity' => 1,
                        ),
                        array(
                            'customer_address' => $arrCustomerAddress->first()->getId(),
                            'quantity' => 1,
                        ),
                    ),
                ),
                // product type 1
                array(
                    'shipping' => array(
                        array(
                            'customer_address' => $arrCustomerAddress->first()->getId(),
                            'quantity' => 1,
                        ),
                        array(
                            'customer_address' => $arrCustomerAddress->last()->getId(),
                            'quantity' => 1,
                        ),
                        array(
                            'customer_address' => $arrCustomerAddress->first()->getId(),
                            'quantity' => 1,
                        ),
                    ),
                ),
            ),
        );

        $client->request(
            'POST',
            $this->app->url('shopping_shipping_multiple'),
            array("form" => $multiForm)
        );

        $this->assertTrue($client->getResponse()->isRedirect($this->app->url('shopping')));

        $Order = $this->app['eccube.repository.order']->findOneBy(array('Customer' => $User));
        $Shipping = $Order->getShippings();

        // four shipping
        $this->actual = count($Shipping);
        $this->expected = 4;
        $this->verify();

        // total delivery fee
        $this->actual = $Order->getDeliveryFeeTotal();
        $this->expected = 2000;
        $this->verify();

        // 確認画面
        $crawler = $this->scenarioConfirm($client);

        // item number on the screen
        $shipping = $crawler->filter('.is-edit .cart_item')->first()->text();
        $this->assertContains('× 2', $shipping);

        // check delivery of last shipping
        $deliver = $crawler->filter('#shopping_shippings_3_delivery > option')->each(
            function ($node, $i) {
                return $node->text();
            }
        );
        $this->expected = 'サンプル業者';
        $this->actual = $deliver;
        $this->assertTrue(in_array($this->expected, $this->actual));

        // 完了画面
        $this->scenarioComplete(
            $client,
            $this->app->url('shopping_confirm'),
            array(
                // Product type 2 with address 1 (two item)
                array(
                    'delivery' => 2,
                ),
                // Product type 1 with address 1 (two item)
                array(
                    'delivery' => 1,
                    'deliveryTime' => 1,
                ),
                // Product type 2 with address 2 (one item)
                array(
                    'delivery' => 2,
                ),
                // Product type 1 with address 2 (one item)
                array(
                    'delivery' => 1,
                    'deliveryTime' => 1,
                ),
            ),
            3
=======
            $Customer,
            $this->generateUrl('shopping_confirm'),
            [
                [
                    'Delivery' => 1,
                    'DeliveryTime' => 1,
                ],
            ]
>>>>>>> 2c09ba75d7b7fba1a3b27dbc46b98417f7fffe0d
        );

        $this->scenarioCheckout($Customer);

        $BaseInfo = $this->baseInfoRepository->get();
        /** @var \Swift_Message[] $Messages */
        $Messages = $this->getMailCollector(false)->getMessages();
        $Message = $Messages[0];

        $this->expected = '['.$BaseInfo->getShopName().'] ご注文ありがとうございます';
        $this->actual = $Message->getSubject();
        $this->verify();

        $body = $Message->getBody();
        $this->assertRegexp('/◎お届け先/u', $body, '複数配送のため, お届け先1が存在する');

        // 生成された受注のチェック
        /** @var Order $Order */
        $Order = $this->orderRepository->findOneBy(
            [
                'Customer' => $Customer,
            ]
        );

        // FIXME ユニットテストではステータスが変わらない
        /* @var OrderStatus $OrderNew */
//        $OrderNew = $this->orderStatusRepository->find(OrderStatus::NEW);
//        $this->expected = $OrderNew->getId();
//        $this->actual = $Order->getOrderStatus()->getId();
//        $this->verify();

        $this->expected = $Customer->getName01();
        $this->actual = $Order->getName01();
        $this->verify();
    }
}
