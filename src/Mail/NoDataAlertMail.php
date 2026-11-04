<?php

declare(strict_types=1);

namespace Waypost\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Waypost\Models\NoDataAlert;

/** The "Nincs adat" alert (spec: „Nincs adat” figyelmeztetés): HTML, in Hungarian, from waypost.mail.from. */
final class NoDataAlertMail extends Mailable
{
    /** @param  array<string, mixed>  $content  the view's variables (SendNoDataAlerts::content()) */
    public function __construct(public readonly NoDataAlert $alert, public readonly array $content)
    {
    }

    public function envelope(): Envelope
    {
        $address = config('waypost.mail.from.address');

        return new Envelope(
            from: is_string($address) && $address !== '' ? new Address($address, (string) config('waypost.mail.from.name')) : null,
            subject: "Nincs adat – {$this->content['site']['name']}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'waypost::mail.no-data-alert', with: $this->content);
    }
}
