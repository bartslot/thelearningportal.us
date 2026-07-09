{{-- Branded email layout — The Learning Portal / History Portal.
     Table-based, inline styles only, no external assets. Max width 600px.
     Usage: @component('emails.layout') ... body html ... @endcomponent --}}
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>{{ config('app.name', 'The Learning Portal') }}</title>
</head>
<body style="margin: 0; padding: 0; width: 100%; background-color: #f1f5f9; -webkit-text-size-adjust: 100%;" bgcolor="#f1f5f9">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f1f5f9" style="background-color: #f1f5f9;">
        <tr>
            <td align="center" bgcolor="#f1f5f9" style="background-color: #f1f5f9; padding: 32px 16px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width: 100%; max-width: 600px;">

                    {{-- Header band: deep navy with text-based wordmark --}}
                    <tr>
                        <td align="center" bgcolor="#0f172a" style="background-color: #0f172a; padding: 28px 24px; border-radius: 8px 8px 0 0;">
                            <span style="display: inline-block; font-family: Georgia, 'Times New Roman', serif; font-size: 24px; line-height: 1.2; letter-spacing: 2px; color: #fbbf24; text-transform: uppercase;">History&nbsp;Portal</span>
                            <br>
                            <span style="display: inline-block; padding-top: 6px; font-family: Helvetica, Arial, sans-serif; font-size: 11px; line-height: 1.4; letter-spacing: 3px; color: #bae6fd; text-transform: uppercase;">The Learning Portal</span>
                        </td>
                    </tr>

                    {{-- Amber accent divider --}}
                    <tr>
                        <td bgcolor="#fbbf24" style="background-color: #fbbf24; height: 4px; line-height: 4px; font-size: 4px;">&nbsp;</td>
                    </tr>

                    {{-- Body card --}}
                    <tr>
                        <td bgcolor="#ffffff" style="background-color: #ffffff; padding: 32px 32px 36px 32px; font-family: Helvetica, Arial, sans-serif; font-size: 15px; line-height: 1.6; color: #0f172a; border-radius: 0 0 8px 8px;">
                            {{ $slot }}
                        </td>
                    </tr>

                    {{-- Footer: tagline + legal --}}
                    <tr>
                        <td align="center" bgcolor="#f1f5f9" style="background-color: #f1f5f9; padding: 24px 24px 8px 24px;">
                            <span style="font-family: Georgia, 'Times New Roman', serif; font-size: 13px; font-style: italic; line-height: 1.5; color: #475569;">Where Storytelling Meets Learning.</span>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" bgcolor="#f1f5f9" style="background-color: #f1f5f9; padding: 0 24px 32px 24px;">
                            <span style="font-family: Helvetica, Arial, sans-serif; font-size: 11px; line-height: 1.5; color: #64748b;">&copy; {{ date('Y') }} {{ config('app.name', 'The Learning Portal') }} &middot; thelearningportal.us<br>You are receiving this email because of your account on {{ config('app.name', 'The Learning Portal') }}.</span>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
