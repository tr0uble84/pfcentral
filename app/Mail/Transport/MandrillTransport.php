<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;

/**
 * Mandrill (Mailchimp Transactional) over its HTTPS API: messages/send-raw.
 * Sends the complete MIME message Laravel built, so attachments, HTML and headers are kept as they are.
 * No extra Composer package needed (the in-app updater doesn't run composer install).
 */
class MandrillTransport extends AbstractTransport
{
    public const ENDPOINT = 'https://mandrillapp.com/api/1.0/messages/send-raw.json';

    public function __construct(private string $key)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $envelope = $message->getEnvelope();
        $to = array_map(fn (Address $a) => $a->getAddress(), $envelope->getRecipients());
        $from = $envelope->getSender();

        try {
            $res = Http::timeout(30)->acceptJson()->post(self::ENDPOINT, [
                'key' => $this->key,
                'raw_message' => $message->toString(),
                'from_email' => $from->getAddress(),
                'from_name' => $from->getName() ?: null,
                'to' => $to,
                'async' => false,
            ]);
        } catch (\Throwable $e) {
            throw new TransportException('Could not reach Mandrill: ' . $e->getMessage(), 0, $e);
        }

        $body = $res->json();
        if (!$res->successful() || !is_array($body) || ($body['status'] ?? null) === 'error') {
            $msg = is_array($body) ? ($body['message'] ?? $body['name'] ?? '') : '';
            throw new TransportException('Mandrill refused the message' . ($msg !== '' ? ": {$msg}" : " (HTTP {$res->status()})"));
        }

        $failed = [];
        foreach ($body as $r) {
            if (in_array($r['status'] ?? '', ['rejected', 'invalid'], true)) {
                $failed[] = ($r['email'] ?? '?') . ' (' . ($r['reject_reason'] ?? $r['status']) . ')';
            }
        }
        if ($failed && count($failed) === count($body)) {
            throw new TransportException('Mandrill rejected: ' . implode(', ', $failed));
        }
        if (!empty($body[0]['_id'])) {
            $message->setMessageId((string) $body[0]['_id']);
        }
    }

    public function __toString(): string
    {
        return 'mandrill+api://mandrillapp.com';
    }
}
