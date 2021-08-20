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
use Ergebnis\Test\Util\Helper;
use PHPUnit\Framework\TestCase;
use function Safe\json_encode;
use function Safe\sprintf;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Test is copied from:.
 *
 * @see https://github.com/symfony/symfony/pull/41714
 */
final class SendgridDynamicTemplateTransportTest extends TestCase
{
    use Helper;

    /**
     * @dataProvider getTransportData
     *
     * @test
     */
    public function toStringTest(SendgridDynamicTemplateTransport $transport, string $expected): void
    {
        self::assertSame($expected, (string) $transport);
    }

    /**
     * @return array<array{0: SendgridDynamicTemplateTransport, 1: string}>
     */
    public function getTransportData(): array
    {
        return [
            [
                new SendgridDynamicTemplateTransport('KEY'),
                'sendgrid+template://api.sendgrid.com',
            ],
            [
                (new SendgridDynamicTemplateTransport('KEY'))->setHost('example.com'),
                'sendgrid+template://example.com',
            ],
            [
                (new SendgridDynamicTemplateTransport('KEY'))->setHost('example.com')->setPort(99),
                'sendgrid+template://example.com:99',
            ],
        ];
    }

    /**
     * @test
     */
    public function send(): void
    {
        $email = new Email();
        $email->from(new Address('foo@example.com', 'Ms. Foo Bar'))
            ->to(new Address('bar@example.com', 'Mr. Recipient'))
            ->bcc('baz@example.com')
            ->text('content');

        $response = $this->createMock(ResponseInterface::class);

        $response
            ->expects(self::once())
            ->method('getStatusCode')
            ->willReturn(202);
        $response
            ->expects(self::once())
            ->method('getHeaders')
            ->willReturn(['x-message-id' => '1']);

        $httpClient = $this->createMock(HttpClientInterface::class);

        $httpClient
            ->expects(self::once())
            ->method('request')
            ->with('POST', 'https://api.sendgrid.com/v3/mail/send', [
                'json' => [
                    'personalizations' => [
                        [
                            'to' => [[
                                'email' => 'bar@example.com',
                                'name' => 'Mr. Recipient',
                            ]],
                            'subject' => null,
                            'bcc' => [['email' => 'baz@example.com']],
                        ],
                    ],
                    'from' => [
                        'email' => 'foo@example.com',
                        'name' => 'Ms. Foo Bar',
                    ],
                    'content' => [
                        ['type' => 'text/plain', 'value' => 'content'],
                    ],
                ],
                'auth_bearer' => 'foo',
            ])
            ->willReturn($response);

        $mailer = new SendgridDynamicTemplateTransport('foo', $httpClient);
        $mailer->send($email);
    }

    /**
     * @test
     */
    public function lineBreaksInEncodedAttachment(): void
    {
        $email = new Email();
        $email->from('foo@example.com')
            ->to('bar@example.com')
            // even if content doesn't include new lines, the base64 encoding performed later may add them
            ->attach('Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod', 'lorem.txt');

        $response = $this->createMock(ResponseInterface::class);

        $response
            ->expects(self::once())
            ->method('getStatusCode')
            ->willReturn(202);
        $response
            ->expects(self::once())
            ->method('getHeaders')
            ->willReturn(['x-message-id' => '1']);

        $httpClient = $this->createMock(HttpClientInterface::class);

        $httpClient
            ->expects(self::once())
            ->method('request')
            ->with('POST', 'https://api.sendgrid.com/v3/mail/send', [
                'json' => [
                    'personalizations' => [
                        [
                            'to' => [['email' => 'bar@example.com']],
                            'subject' => null,
                        ],
                    ],
                    'from' => ['email' => 'foo@example.com'],
                    'content' => [],
                    'attachments' => [
                        [
                            'content' => 'TG9yZW0gaXBzdW0gZG9sb3Igc2l0IGFtZXQsIGNvbnNlY3RldHVyIGFkaXBpc2NpbmcgZWxpdCwgc2VkIGRvIGVpdXNtb2Q=',
                            'filename' => 'lorem.txt',
                            'type' => 'application/octet-stream',
                            'disposition' => 'attachment',
                        ],
                    ],
                ],
                'auth_bearer' => 'foo',
            ])
            ->willReturn($response);

        $mailer = new SendgridDynamicTemplateTransport('foo', $httpClient);

        $mailer->send($email);
    }

    /**
     * @test
     */
    public function customHeader(): void
    {
        $email = new Email();
        $email->getHeaders()->addTextHeader('foo', 'bar');
        $envelope = new Envelope(new Address('alice@system.com'), [new Address('bob@system.com')]);

        $transport = new SendgridDynamicTemplateTransport('ACCESS_KEY');
        $method = new \ReflectionMethod(SendgridDynamicTemplateTransport::class, 'getPayload');
        $method->setAccessible(true);
        $payload = $method->invoke($transport, $email, $envelope);

        self::assertArrayHasKey('headers', $payload);
        self::assertArrayHasKey('foo', $payload['headers']);
        self::assertEquals('bar', $payload['headers']['foo']);
    }

    /**
     * @test
     */
    public function replyTo(): void
    {
        $from = 'from@example.com';
        $to = 'to@example.com';
        $replyTo = 'replyto@example.com';
        $email = new Email();
        $email->from($from)
            ->to($to)
            ->replyTo($replyTo)
            ->text('content');
        $envelope = new Envelope(new Address($from), [new Address($to)]);

        $transport = new SendgridDynamicTemplateTransport('ACCESS_KEY');
        $method = new \ReflectionMethod(SendgridDynamicTemplateTransport::class, 'getPayload');
        $method->setAccessible(true);
        $payload = $method->invoke($transport, $email, $envelope);

        self::assertArrayHasKey('from', $payload);
        self::assertArrayHasKey('email', $payload['from']);
        self::assertSame($from, $payload['from']['email']);

        self::assertArrayHasKey('reply_to', $payload);
        self::assertArrayHasKey('email', $payload['reply_to']);
        self::assertSame($replyTo, $payload['reply_to']['email']);
    }

    /**
     * @test
     */
    public function envelopeSenderAndRecipients(): void
    {
        $from = 'from@example.com';
        $to = 'to@example.com';
        $envelopeFrom = 'envelopefrom@example.com';
        $envelopeTo = 'envelopeto@example.com';
        $email = new Email();
        $email->from($from)
            ->to($to)
            ->cc('cc@example.com')
            ->bcc('bcc@example.com')
            ->text('content');
        $envelope = new Envelope(new Address($envelopeFrom), [new Address($envelopeTo)]);

        $transport = new SendgridDynamicTemplateTransport('ACCESS_KEY');
        $method = new \ReflectionMethod(SendgridDynamicTemplateTransport::class, 'getPayload');
        $method->setAccessible(true);
        $payload = $method->invoke($transport, $email, $envelope);

        self::assertArrayHasKey('from', $payload);
        self::assertArrayHasKey('email', $payload['from']);
        self::assertSame($envelopeFrom, $payload['from']['email']);

        self::assertArrayHasKey('personalizations', $payload);
        self::assertArrayHasKey('to', $payload['personalizations'][0]);
        self::assertArrayHasKey('email', $payload['personalizations'][0]['to'][0]);
        self::assertCount(1, $payload['personalizations'][0]['to']);
        self::assertSame($envelopeTo, $payload['personalizations'][0]['to'][0]['email']);
    }

    /**
     * @test
     */
    public function sendTemplateIdWithDynamicTemplateData(): void
    {
        $templateId = 'd-0aac27809ad64ae98d5ebaf896ea8b33';
        $dynamicTemplateData = [
            'foo' => 'bar',
        ];

        $email = new Email();
        $email->from(new Address('foo@example.com', 'Ms. Foo Bar'))
            ->to(new Address('bar@example.com', 'Mr. Recipient'))
            ->bcc('baz@example.com')
            ->text('content');

        $email->getHeaders()->addTextHeader('X-Template-ID', $templateId);
        $email->getHeaders()->addTextHeader('X-Dynamic-Template-Data', json_encode($dynamicTemplateData));

        $response = $this->createMock(ResponseInterface::class);

        $response
            ->expects(self::once())
            ->method('getStatusCode')
            ->willReturn(202);
        $response
            ->expects(self::once())
            ->method('getHeaders')
            ->willReturn(['x-message-id' => '1']);

        $httpClient = $this->createMock(HttpClientInterface::class);

        $httpClient
            ->expects(self::once())
            ->method('request')
            ->with('POST', 'https://api.sendgrid.com/v3/mail/send', [
                'json' => [
                    'personalizations' => [
                        [
                            'to' => [[
                                'email' => 'bar@example.com',
                                'name' => 'Mr. Recipient',
                            ]],
                            'subject' => null,
                            'dynamic_template_data' => $dynamicTemplateData,
                            'bcc' => [['email' => 'baz@example.com']],
                        ],
                    ],
                    'from' => [
                        'email' => 'foo@example.com',
                        'name' => 'Ms. Foo Bar',
                    ],
                    'content' => [
                        ['type' => 'text/plain', 'value' => 'content'],
                    ],
                    'template_id' => $templateId,
                ],
                'auth_bearer' => 'foo',
            ])
            ->willReturn($response);

        $mailer = new SendgridDynamicTemplateTransport('foo', $httpClient);
        $mailer->send($email);
    }

    /**
     * @test
     */
    public function sendWithInvalidTemplateIdThrowsInvalidArgumentException(): void
    {
        $invalidTemplateId = 'd-abcd';

        $email = new Email();
        $email->from(new Address('foo@example.com', 'Ms. Foo Bar'))
            ->to(new Address('bar@example.com', 'Mr. Recipient'))
            ->bcc('baz@example.com')
            ->text('content');

        $email->getHeaders()->addTextHeader('X-Template-ID', $invalidTemplateId);

        $mailer = new SendgridDynamicTemplateTransport('foo', $this->createMock(HttpClientInterface::class));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('Invalid TemplateID. Got: "%s"', $invalidTemplateId));

        $mailer->send($email);
    }

    /**
     * @test
     */
    public function sendWithSandboxModeEnabled(): void
    {
        $email = new Email();
        $email->from(new Address('foo@example.com', 'Ms. Foo Bar'))
            ->to(new Address('bar@example.com', 'Mr. Recipient'))
            ->bcc('baz@example.com')
            ->text('content');

        // no matter which value is set, if the header is present it will enable sandbox mode!
        $email->getHeaders()->addTextHeader('X-Sandbox-Mode', self::faker()->word());

        $response = $this->createMock(ResponseInterface::class);

        $response
            ->expects(self::once())
            ->method('getStatusCode')
            ->willReturn(202);
        $response
            ->expects(self::once())
            ->method('getHeaders')
            ->willReturn(['x-message-id' => '1']);

        $httpClient = $this->createMock(HttpClientInterface::class);

        $httpClient
            ->expects(self::once())
            ->method('request')
            ->with('POST', 'https://api.sendgrid.com/v3/mail/send', [
                'json' => [
                    'personalizations' => [
                        [
                            'to' => [[
                                'email' => 'bar@example.com',
                                'name' => 'Mr. Recipient',
                            ]],
                            'subject' => null,
                            'bcc' => [['email' => 'baz@example.com']],
                        ],
                    ],
                    'from' => [
                        'email' => 'foo@example.com',
                        'name' => 'Ms. Foo Bar',
                    ],
                    'content' => [
                        ['type' => 'text/plain', 'value' => 'content'],
                    ],
                    'mail_settings' => [
                        'sandbox_mode' => [
                            'enable' => true,
                        ],
                    ],
                ],
                'auth_bearer' => 'foo',
            ])
            ->willReturn($response);

        $mailer = new SendgridDynamicTemplateTransport('foo', $httpClient);
        $mailer->send($email);
    }
}
