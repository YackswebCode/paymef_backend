<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoiceReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Invoice $invoice) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reminder: invoice '.$this->invoice->reference.' is due',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.invoice-reminder',
            with: [
                'invoice' => $this->invoice,
                'amount' => number_format($this->invoice->amount_minor / 100, 2),
                'dueDate' => $this->invoice->due_date?->toDateString(),
            ],
        );
    }
}
