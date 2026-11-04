<?php

declare(strict_types=1);

namespace Waypost\Mail;

use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Waypost\Enums\ReportFrequency;
use Waypost\Models\Report;

/**
 * A report e-mail (spec: Jelentések): HTML, in Hungarian, from waypost.mail.from (the application's mail.from without
 * an address); the weekly and monthly one with its PDF attached.
 */
final class ReportMail extends Mailable
{
    /**
     * @param  view-string  $template
     * @param  array<string, mixed>  $content  the view's variables (ReportContent)
     */
    public function __construct(
        public readonly Report $report,
        public readonly string $template,
        public readonly array $content,
        public readonly string $subjectLine,
        public readonly ?string $pdf = null,
        public readonly ?string $pdfName = null,
    ) {
    }

    public function envelope(): Envelope
    {
        $address = config('waypost.mail.from.address');

        return new Envelope(
            from: is_string($address) && $address !== '' ? new Address($address, (string) config('waypost.mail.from.name')) : null,
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(view: $this->template, with: $this->content);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        if ($this->pdf === null) {
            return [];
        }
        $pdf = $this->pdf;

        return [Attachment::fromData(fn (): string => $pdf, $this->pdfName ?? 'waypost.pdf')->withMime('application/pdf')];
    }

    public function type(): ReportFrequency
    {
        return $this->report->type;
    }
}
