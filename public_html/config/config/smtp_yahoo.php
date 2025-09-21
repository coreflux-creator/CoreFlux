<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Manually include PHPMailer classes
require_once __DIR__ . '/../lib/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../lib/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/../lib/PHPMailer/src/Exception.php';

function sendConfirmationEmail($to, $name) {
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.mail.yahoo.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'no-reply@corefluxapp.com';
        $mail->Password = 'rpevtweukxlgnkll';
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;

        $mail->setFrom('no-reply@corefluxapp.com', 'CoreFlux Notifications');
        $mail->addAddress($to, $name);

        $mail->isHTML(true);
        $mail->Subject = 'Confirm your CoreFlux account';
        $mail->Body    = 'Hi ' . htmlspecialchars($name) . ',<br><br>Welcome to CoreFlux!';

        $mail->send();
    } catch (Exception $e) {
        error_log("Email could not be sent. Mailer Error: {$mail->ErrorInfo}");
    }
}
