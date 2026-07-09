{{-- Bulletproof branded email button.
     Usage: @include('emails.partials.button', ['url' => 'https://…', 'label' => 'Click me']) --}}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin: 24px auto;">
    <tr>
        <td align="center" bgcolor="#fbbf24" style="background-color: #fbbf24; border-radius: 6px;">
            <a href="{{ $url }}" target="_blank" rel="noopener" style="display: inline-block; padding: 12px 28px; font-family: Helvetica, Arial, sans-serif; font-size: 15px; font-weight: bold; line-height: 1.2; color: #0f172a; text-decoration: none; border-radius: 6px;">{{ $label }}</a>
        </td>
    </tr>
</table>
