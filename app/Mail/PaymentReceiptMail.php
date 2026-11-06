<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Models\PaymentAttempt;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Invoice $invoice,
        public PaymentAttempt $attempt
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Payment received for invoice '.$this->invoice->reference,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.payment-receipt',
            with: [
                'invoice' => $this->invoice,
                'attempt' => $this->attempt,
                'amount' => number_format($this->attempt->amount_minor / 100, 2),
            ],
        );
    }
}
