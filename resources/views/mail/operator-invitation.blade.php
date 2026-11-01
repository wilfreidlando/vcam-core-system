<!DOCTYPE html>
<html lang="fr">
{{-- La lettre d'invitation : des styles en ligne (un client de messagerie
     ne charge pas de feuille de style externe), une table pour la mise en
     page, et le jeton en clair — c'est le seul endroit où il doit se
     trouver (ADR-0081 S-07). --}}
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Votre invitation à la console opérateur</title>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0f172a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f1f5f9;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background-color:#ffffff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">
                    <tr>
                        <td style="background-color:#4f46e5;padding:20px 32px;">
                            <p style="margin:0;font-size:18px;font-weight:600;color:#ffffff;">Console opérateur</p>
                            <p style="margin:2px 0 0;font-size:14px;color:#c7d2fe;">Enterprise Core</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <h1 style="margin:0 0 16px;font-size:22px;font-weight:600;">Vous êtes invité</h1>
                            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#334155;">
                                Un compte d'opérateur a été créé pour vous sur la console. Vous choisissez
                                vous-même votre mot de passe, et vous configurerez votre
                                authentificateur juste après.
                            </p>
                            <p style="margin:0 0 8px;font-size:14px;color:#64748b;">
                                Votre jeton d'activation, à usage unique, valable jusqu'au
                                <strong style="color:#0f172a;">{{ $expiresAt }}</strong> :
                            </p>
                            <p style="margin:0 0 24px;padding:12px 14px;background-color:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;word-break:break-all;color:#0f172a;">
                                {{ $token }}
                            </p>
                            <p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#334155;">
                                Ouvrez cette page, collez le jeton, puis choisissez votre mot de passe :
                            </p>
                            <p style="margin:0 0 24px;">
                                <a href="{{ $invitationUrl }}" style="display:inline-block;padding:12px 20px;background-color:#4f46e5;color:#ffffff;text-decoration:none;border-radius:8px;font-size:15px;font-weight:500;">Activer mon compte</a>
                            </p>
                            <p style="margin:0;font-size:13px;line-height:1.6;color:#64748b;">
                                Si vous n'êtes pas à l'origine de cette invitation, ignorez ce message :
                                le jeton ne sert à rien sans le mot de passe que vous choisirez.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 32px;background-color:#f8fafc;border-top:1px solid #e2e8f0;">
                            <p style="margin:0;font-size:12px;color:#94a3b8;">
                                Envoyé à {{ $email }} — Enterprise Core, console opérateur.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
