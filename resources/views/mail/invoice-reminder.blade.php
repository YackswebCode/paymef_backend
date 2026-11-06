<!DOCTYPE html>
<html>
<body style="font-family: Arial, sans-serif; color: #111; line-height: 1.5">
    <h2 style="margin-bottom: 4px">Invoice reminder</h2>
    <p style="margin-top: 0; color: #555">{{ $invoice->reference }}</p>

    <p>
        This invoice is due{{ $dueDate ? ' on '.$dueDate : '' }} and is still unpaid.
    </p>

    <table cellpadding="6" style="border-collapse: collapse">
        <tr>
            <td><strong>Amount</strong></td>
            <td>{{ $invoice->currency }} {{ $amount }}</td>
        </tr>
        @if ($invoice->description)
            <tr>
                <td><strong>Description</strong></td>
                <td>{{ $invoice->description }}</td>
            </tr>
        @endif
        <tr>
            <td><strong>Status</strong></td>
            <td>{{ $invoice->status }}</td>
        </tr>
    </table>

    <p>Sign in to PayMef to pay this invoice.</p>
</body>
</html>
