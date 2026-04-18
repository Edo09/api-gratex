<?php

function sendClientWelcomeEmail(array $clientData)
{
    $templatePath = __DIR__ . '/../../contents.html';
    if (!is_file($templatePath)) {
        return ['success' => false, 'message' => 'Welcome email template not found'];
    }

    $template = file_get_contents($templatePath);
    if ($template === false) {
        return ['success' => false, 'message' => 'Unable to read welcome email template'];
    }

    $htmlContent = buildClientWelcomeEmailHtml($template, $clientData);
    $to = sanitizeWelcomeEmailAddress($clientData['email'] ?? '');
    if ($to === '') {
        return ['success' => false, 'message' => 'Client email is invalid'];
    }

    $subject = 'Bienvenido a Gratex';
    $from = 'info@gratex.net';
    $fromName = 'Gratex';

    $to .= ', edwin@gratex.net';
    $to .= ', gratexrd@gmail.com';

    $semi_rand = md5(time());
    $mixed_boundary = "==Mixed_Boundary_x{$semi_rand}x";
    $related_boundary = "==Related_Boundary_x{$semi_rand}x";

    $logoPath = __DIR__ . '/../../logo2020.png';
    $logoData = is_file($logoPath) ? base64_encode(file_get_contents($logoPath)) : null;

    $headers = "From: $fromName <$from>\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed;\r\n boundary=\"{$mixed_boundary}\"";

    // multipart/related wrapper (HTML + inline image)
    $message = "--{$mixed_boundary}\r\n";
    $message .= "Content-Type: multipart/related;\r\n boundary=\"{$related_boundary}\"\r\n\r\n";

    // HTML part
    $message .= "--{$related_boundary}\r\n";
    $message .= "Content-Type: text/html; charset=\"UTF-8\"\r\n";
    $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    $message .= $htmlContent . "\r\n\r\n";

    // Inline logo image
    if ($logoData !== null) {
        $message .= "--{$related_boundary}\r\n";
        $message .= "Content-Type: image/png; name=\"logo2020.png\"\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n";
        $message .= "Content-ID: <logo>\r\n";
        $message .= "Content-Disposition: inline; filename=\"logo2020.png\"\r\n\r\n";
        $message .= chunk_split($logoData) . "\r\n";
    }

    $message .= "--{$related_boundary}--\r\n\r\n";
    $message .= "--{$mixed_boundary}--\r\n";

    $returnPath = '-f' . $from;
    $sent = @mail($to, $subject, $message, $headers, $returnPath);

    return [
        'success' => $sent,
        'message' => $sent ? 'Welcome email sent' : 'Client saved, but welcome email could not be sent'
    ];
}

function buildClientWelcomeEmailHtml($template, array $clientData)
{
    $replacements = [
        '%nombre%' => escapeWelcomeEmailValue($clientData['client_name'] ?? ''),
        '%codigo%' => escapeWelcomeEmailValue((string) ($clientData['id'] ?? '')),
        '%usuario%' => escapeWelcomeEmailValue($clientData['email'] ?? ''),
        '%password%' => 'Pendiente de asignar',
        '%correo%' => escapeWelcomeEmailValue($clientData['email'] ?? '')
    ];

    $html = strtr($template, $replacements);
    $html = str_replace(
        'A continuación los datos de su cuenta de usuario:',
        'A continuación los datos de su registro:',
        $html
    );
    $html = str_replace('Nombre de usuario:', 'Correo de contacto:', $html);
    $html = str_replace('Contraseña:', 'Estado de acceso:', $html);

    // Strip cid: inline images EXCEPT the logo
    $html = preg_replace('/<img[^>]*src="cid:(?!logo)[^"]*"[^>]*>/i', '', $html);
    // Strip all hyperlinks (replace <a href="...">text</a> with just the text)
    $html = preg_replace('/<a\s[^>]*>(.*?)<\/a>/is', '$1', $html);
    return $html;
}

function sanitizeWelcomeEmailAddress($email)
{
    $sanitizedEmail = filter_var(trim((string) $email), FILTER_SANITIZE_EMAIL);
    return filter_var($sanitizedEmail, FILTER_VALIDATE_EMAIL) ? $sanitizedEmail : '';
}

function escapeWelcomeEmailValue($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}