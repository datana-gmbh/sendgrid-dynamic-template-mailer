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

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use function Safe\json_decode;
use function Safe\preg_match;
use function Safe\sprintf;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractApiTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\HeaderInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Webmozart\Assert\Assert;

/**
 * This class can be replaced if the PR is merged:.
 *
 * @see https://github.com/symfony/symfony/pull/41714
 *
 * Afterwards the DSN scheme needs to be changed from `sendgrid+template` to `sendgrid+api`!
 */
final class SendgridDynamicTemplateTransport extends AbstractApiTransport implements \Stringable
{
    private const HOST = 'api.sendgrid.com';

    public function __construct(private string $key, ?HttpClientInterface $client = null, ?EventDispatcherInterface $dispatcher = null, ?LoggerInterface $logger = null)
    {
        parent::__construct($client, $dispatcher, $logger);
    }

    public function __toString(): string
    {
        return sprintf('sendgrid+template://%s', $this->getEndpoint());
    }

    protected function doSendApi(SentMessage $sentMessage, Email $email, Envelope $envelope): ResponseInterface
    {
        $response = $this->client->request(Request::METHOD_POST, 'https://'.$this->getEndpoint().'/v3/mail/send', [
            'json' => $this->getPayload($email, $envelope),
            'auth_bearer' => $this->key,
        ]);

        try {
            $statusCode = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw new HttpTransportException('Could not reach the remote Sendgrid server.', $response, 0, $e);
        }

        if (202 !== $statusCode) {
            try {
                $result = $response->toArray(false);

                throw new HttpTransportException('Unable to send an email: '.implode('; ', array_column($result['errors'], 'message')).sprintf(' (code %d).', $statusCode), $response);
            } catch (DecodingExceptionInterface $e) {
                throw new HttpTransportException('Unable to send an email: '.$response->getContent(false).sprintf(' (code %d).', $statusCode), $response, 0, $e);
            }
        }

        $sentMessage->setMessageId($response->getHeaders(false)['x-message-id'][0]);

        return $response;
    }

    /**
     * @return array<mixed>
     */
    private function getPayload(Email $email, Envelope $envelope): array
    {
        $addressStringifier = function (Address $address): array {
            $stringified = ['email' => $address->getAddress()];

            if ($address->getName()) {
                $stringified['name'] = $address->getName();
            }

            return $stringified;
        };

        $payload = [
            'personalizations' => [],
            'from' => $addressStringifier($envelope->getSender()),
            'content' => $this->getContent($email),
        ];

        if ($email->getAttachments()) {
            $payload['attachments'] = $this->getAttachments($email);
        }

        $personalization = [
            'to' => array_map($addressStringifier, $this->getRecipients($email, $envelope)),
            'subject' => $email->getSubject(),
        ];

        if ($emails = array_map($addressStringifier, $email->getCc())) {
            $personalization['cc'] = $emails;
        }

        if ($emails = array_map($addressStringifier, $email->getBcc())) {
            $personalization['bcc'] = $emails;
        }

        if ($emails = array_map($addressStringifier, $email->getReplyTo())) {
            // Email class supports an array of reply-to addresses,
            // but SendGrid only supports a single address
            $payload['reply_to'] = $emails[0];
        }

        $payload['personalizations'][] = $personalization;

        $dynamicTemplateData = [];

        // these headers can't be overwritten according to Sendgrid docs
        // see https://sendgrid.api-docs.io/v3.0/mail-send/mail-send-errors#-Headers-Errors
        $headersToBypass = ['x-sg-id', 'x-sg-eid', 'received', 'dkim-signature', 'content-transfer-encoding', 'from', 'to', 'cc', 'bcc', 'subject', 'content-type', 'reply-to'];

        foreach ($email->getHeaders()->all() as $name => $header) {
            if (\in_array($name, $headersToBypass, true)) {
                continue;
            }

            if ('x-template-id' === $name) {
                $templateId = $header->getBodyAsString();

                if (!preg_match('/^d\-[a-z0-9]{32}$/', $templateId)) {
                    throw new InvalidArgumentException(sprintf('Invalid TemplateID. Got: "%s".', $templateId));
                }

                $payload['template_id'] = $templateId;

                continue;
            }

            if ('x-dynamic-template-data' === $name) {
                $dynamicTemplateData = json_decode($header->getBodyAsString(), true);

                continue;
            }

            if ('x-sandbox-mode' === $name) {
                $payload = array_merge(
                    $payload,
                    [
                        'mail_settings' => [
                            'sandbox_mode' => [
                                'enable' => true,
                            ],
                        ],
                    ]
                );

                continue;
            }

            $payload['headers'][$name] = $header->getBodyAsString();
        }

        if ([] !== $dynamicTemplateData) {
            // we need to add the substitutions to every available personalization
            foreach ($payload['personalizations'] as $key => $personalization) {
                $payload['personalizations'][$key] = array_merge($payload['personalizations'][$key], ['dynamic_template_data' => $dynamicTemplateData]);
            }
        }

        return $payload;
    }

    /**
     * @return array<mixed>
     */
    private function getContent(Email $email): array
    {
        $content = [];

        if (null !== $text = $email->getTextBody()) {
            $content[] = ['type' => 'text/plain', 'value' => $text];
        }

        if (null !== $html = $email->getHtmlBody()) {
            $content[] = ['type' => 'text/html', 'value' => $html];
        }

        return $content;
    }

    /**
     * @return array<mixed>
     */
    private function getAttachments(Email $email): array
    {
        $attachments = [];

        foreach ($email->getAttachments() as $attachment) {
            $headers = $attachment->getPreparedHeaders();
            $filename = $headers->getHeaderParameter('Content-Disposition', 'filename');
            $disposition = $headers->getHeaderBody('Content-Disposition');

            $contentTypeHeader = $headers->get('Content-Type');
            Assert::isInstanceOf($contentTypeHeader, HeaderInterface::class);

            $att = [
                'content' => str_replace("\r\n", '', $attachment->bodyToString()),
                'type' => $contentTypeHeader->getBody(),
                'filename' => $filename,
                'disposition' => $disposition,
            ];

            if ('inline' === $disposition) {
                $att['content_id'] = $filename;
            }

            $attachments[] = $att;
        }

        return $attachments;
    }

    private function getEndpoint(): string
    {
        return ($this->host ?: self::HOST).($this->port ? ':'.$this->port : '');
    }
}
