<?php

declare(strict_types=1);

/*
 * This file is part of Datapool-Api.
 *
 * (c) Datana GmbH <info@datana.rocks>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Datana\Bridge\Symfony\Mailer\Sendgrid\Transport\Tests;

use Datana\Bridge\Symfony\Mailer\Sendgrid\Transport\SendgridDynamicTemplateTransport;
use Datana\Bridge\Symfony\Mailer\Sendgrid\Transport\SendgridDynamicTemplateTransportFactory;
use Symfony\Component\Mailer\Test\TransportFactoryTestCase;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportFactoryInterface;

final class SendgridDynamicTemplateTransportFactoryTest extends TransportFactoryTestCase
{
    public function getFactory(): TransportFactoryInterface
    {
        return new SendgridDynamicTemplateTransportFactory($this->getDispatcher(), $this->getClient(), $this->getLogger());
    }

    /**
     * @return iterable<array{0: Dsn, 1: bool}>
     */
    public function supportsProvider(): iterable
    {
        yield [
            new Dsn('sendgrid+template', 'default'),
            true,
        ];
    }

    /**
     * @return iterable<array{0: Dsn, 1: SendgridDynamicTemplateTransport}>
     */
    public function createProvider(): iterable
    {
        $dispatcher = $this->getDispatcher();
        $logger = $this->getLogger();

        yield [
            new Dsn('sendgrid+template', 'default', self::USER),
            new SendgridDynamicTemplateTransport(self::USER, $this->getClient(), $dispatcher, $logger),
        ];

        yield [
            new Dsn('sendgrid+template', 'example.com', self::USER, '', 8080),
            (new SendgridDynamicTemplateTransport(self::USER, $this->getClient(), $dispatcher, $logger))->setHost('example.com')->setPort(8080),
        ];
    }

    /**
     * @return iterable<array{0: Dsn}>
     */
    public function incompleteDsnProvider(): iterable
    {
        yield [new Dsn('sendgrid+template', 'default')];
    }
}
