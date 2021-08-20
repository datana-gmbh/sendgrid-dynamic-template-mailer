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

namespace Datana\Bridge\Symfony\Mailer\Sendgrid\Transport;

use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * This test is copied and adjusted from Symfony 5.4 branch.
 */
final class SendgridDynamicTemplateTransportFactory extends AbstractTransportFactory
{
    public function create(Dsn $dsn): TransportInterface
    {
        $scheme = $dsn->getScheme();
        $key = $this->getUser($dsn);

        if ('sendgrid+template' === $scheme) {
            $host = 'default' === $dsn->getHost() ? null : $dsn->getHost();
            $port = $dsn->getPort();

            return (new SendgridDynamicTemplateTransport($key, $this->client, $this->dispatcher, $this->logger))->setHost($host)->setPort($port);
        }

        throw new UnsupportedSchemeException($dsn, 'sendgrid+template', $this->getSupportedSchemes());
    }

    /**
     * @return string[]
     */
    protected function getSupportedSchemes(): array
    {
        return ['sendgrid+template'];
    }
}
