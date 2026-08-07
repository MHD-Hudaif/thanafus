<?php

require_once __DIR__ . '/../config/env.php';

/**
 * Sends an email using SMTP with SSL/TLS authentication.
 * Tailored for Google App Passwords.
 * 
 * @param string $to
 * @param string $subject
 * @param string $message (HTML body)
 * @return bool
 * @throws Exception
 */
function send_smtp_email(string $to, string $subject, string $message): bool {
    $host = env('SMTP_HOST', 'smtp.gmail.com');
    $port = (int)env('SMTP_PORT', 465);
    $username = env('SMTP_USER', '');
    $password = env('SMTP_PASS', '');
    $from = env('SMTP_FROM', $username);
    $fromName = env('APP_NAME', 'Kauzariyya Musabaqa');

    if (empty($username) || empty($password)) {
        throw new Exception("SMTP credentials are not configured in .env");
    }

    $socket = fsockopen("ssl://{$host}", $port, $errno, $errstr, 15);
    if (!$socket) {
        throw new Exception("Could not connect to SMTP server {$host}:{$port} - $errstr ($errno)");
    }

    $readResponse = function($socket, $expected) {
        $response = "";
        while (substr($response, 3, 1) !== ' ') {
            $line = fgets($socket, 512);
            if ($line === false) break;
            $response .= $line;
        }
        $code = substr($response, 0, 3);
        if ($code != $expected) {
            throw new Exception("SMTP Error: Expected $expected, got: $response");
        }
        return $response;
    };

    try {
        $readResponse($socket, "220");

        $clientHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
        fwrite($socket, "EHLO " . $clientHost . "\r\n");
        $readResponse($socket, "250");

        fwrite($socket, "AUTH LOGIN\r\n");
        $readResponse($socket, "334");

        fwrite($socket, base64_encode($username) . "\r\n");
        $readResponse($socket, "334");

        fwrite($socket, base64_encode($password) . "\r\n");
        $readResponse($socket, "235");

        fwrite($socket, "MAIL FROM:<{$from}>\r\n");
        $readResponse($socket, "250");

        fwrite($socket, "RCPT TO:<{$to}>\r\n");
        $readResponse($socket, "250");

        fwrite($socket, "DATA\r\n");
        $readResponse($socket, "354");

        $headers = [
            "MIME-Version: 1.0",
            "Content-Type: text/html; charset=UTF-8",
            "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$from}>",
            "To: <{$to}>",
            "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=",
            "Date: " . date('r'),
            "Message-ID: <" . time() . "-" . uniqid() . "@" . $host . ">"
        ];

        $data = implode("\r\n", $headers) . "\r\n\r\n" . $message . "\r\n.\r\n";
        fwrite($socket, $data);
        $readResponse($socket, "250");

        fwrite($socket, "QUIT\r\n");
        fclose($socket);
        return true;
    } catch (Exception $e) {
        if (is_resource($socket)) {
            fclose($socket);
        }
        throw $e;
    }
}
