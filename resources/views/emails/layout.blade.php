{{-- Branded email layout — The Learning Portal / History Portal.
     Table-based, inline styles only. Max width 600px.
     Usage: @component('emails.layout') ... body html ... @endcomponent

     Every email the app sends goes through here, so this file IS the house style for our email.
     Add a new mailable by wrapping its body in this component; do not hand-roll a second shell.

     The page sits on the brand's deep navy (#040B1A) with the content in a white card, so an email
     reads as ours before a word of it is read. Two things about that are deliberate:

     - The logo is a PNG, not the SVG the site uses. Gmail, Outlook and Yahoo all refuse SVG, so an
       SVG logo is a broken image for most of the people receiving this. Regenerate it with
       `rsvg-convert` if the mark ever changes; see docs/brand-guidelines.md.
     - The logo's background is BAKED to #040B1A rather than transparent. A transparent PNG gets
       composited by the client, and a dark-mode client that inverts the backdrop would put a white
       halo round the mark. Baked-in, it matches the band it sits on in every client.

     Colour is repeated as both a bgcolor attribute and an inline style throughout: Outlook honours
     the attribute, everything else the style, and dropping either leaves white gaps in one of them. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    {{-- Only light: the palette is already dark, and letting a client "helpfully" invert it turns
         the white card grey and the navy band white. --}}
    <meta name="color-scheme" content="light only">
    <meta name="supported-color-schemes" content="light only">
    <title>{{ config('app.name', 'The Learning Portal') }}</title>
</head>
<body style="margin: 0; padding: 0; width: 100%; background-color: #040B1A; -webkit-text-size-adjust: 100%;" bgcolor="#040B1A">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#040B1A" style="background-color: #040B1A;">
        <tr>
            <td align="center" bgcolor="#040B1A" style="background-color: #040B1A; padding: 40px 16px 48px 16px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width: 100%; max-width: 600px;">

                    {{-- Logo, on the dark page rather than inside the card, so the mark reads as the
                         sender and the card reads as the message. --}}
                    <tr>
                        <td align="left" bgcolor="#040B1A" style="background-color: #040B1A; padding: 0 4px 24px 4px;">
                            <img src="{{ asset('assets/email/logo-history-portal.png') }}"
                                 alt="{{ config('app.name', 'The Learning Portal') }}"
                                 width="140" height="123"
                                 style="display: block; width: 140px; height: 123px; border: 0; outline: none; text-decoration: none;">
                        </td>
                    </tr>

                    {{-- Body card --}}
                    <tr>
                        <td bgcolor="#ffffff" style="background-color: #ffffff; padding: 36px 36px 40px 36px; font-family: Helvetica, Arial, sans-serif; font-size: 15px; line-height: 1.6; color: #0f172a; border-radius: 14px;">
                            {{ $slot }}
                        </td>
                    </tr>

                    {{-- Footer, on the dark page. Muted enough to stay out of the way, light enough
                         to pass contrast against #040B1A. --}}
                    <tr>
                        <td align="center" bgcolor="#040B1A" style="background-color: #040B1A; padding: 28px 24px 6px 24px;">
                            {{-- The tagline is a brand asset, like the logo, and stays in English
                                 in every language. Translating it would make it a different
                                 tagline in each market. --}}
                            <span style="font-family: Georgia, 'Times New Roman', serif; font-size: 13px; font-style: italic; line-height: 1.5; color: #bae6fd;">Where Storytelling Meets Learning.</span>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" bgcolor="#040B1A" style="background-color: #040B1A; padding: 0 24px;">
                            <span style="font-family: Helvetica, Arial, sans-serif; font-size: 11px; line-height: 1.6; color: #94a3b8;">&copy; {{ date('Y') }} {{ config('app.name', 'The Learning Portal') }} &middot; thelearningportal.us<br>{{ __('You are receiving this email because of your account on :app.', ['app' => config('app.name', 'The Learning Portal')]) }}</span>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
