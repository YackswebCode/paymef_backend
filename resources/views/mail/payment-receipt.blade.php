<!DOCTYPE html>
<html>
<body style="font-family: Arial, sans-serif; color: #111; line-height: 1.5">
    <h2 style="margin-bottom: 4px">Payment received</h2>
    <p style="margin-top: 0; color: #555">Invoice {{ $invoice->reference }}</p>

    <p>We received your payment. Thank you.</p>

    <table cellpadding="6" style="border-collapse: collapse">
        <tr>
            <td><strong>Amount</strong></td>
            <td>{{ $attempt->currency }} {{ $amount }}</td>
        </tr>
        <tr>
            <td><strong>Reference</strong></td>
            <td>{{ $attempt->provider_reference }}</td>
        </tr>
        <tr>
            <td><strong>Invoice</strong></td>
            <td>{{ $invoice->reference }}</td>
        </tr>
        @if ($invoice->description)
            <tr>
                <td><strong>Description</strong></td>
                <td>{{ $invoice->description }}</td>
            </tr>
        @endif
        <tr>
            <td><strong>Date</strong></td>
            <td>{{ $attempt->updated_at?->toDayDateTimeString() }}</td>
        </tr>
    </table>

    <p style="color: #555; font-size: 13px">
        Keep this email as your receipt. If you did not make this payment, contact your school immediately.
    </p>
</body>
</html>
