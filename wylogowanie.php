<?php

require_once __DIR__ . '/includes/auth.php';

// Wylogowanie wykonujemy po wysłaniu formularza.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');

    exit('Użyj przycisku „Wyloguj się” w panelu.');
}

if (!sprawdzCsrf()) {
    http_response_code(403);

    exit('Formularz wygasł. Wróć do panelu i odśwież stronę.');
}

// Usuwamy dane przechowywane w sesji.
$_SESSION = [];

// Usuwamy ciasteczko identyfikujące sesję.
if (ini_get('session.use_cookies')) {
    $parametry = session_get_cookie_params();

    setcookie(session_name(), '', [
        'expires' => time() - 3600,
        'path' => $parametry['path'],
        'domain' => $parametry['domain'],
        'secure' => $parametry['secure'],
        'httponly' => $parametry['httponly'],
        'samesite' => $parametry['samesite'] ?? 'Lax',
    ]);
}

// Kończymy sesję po stronie serwera.
session_destroy();

przekieruj('/logowanie.php');