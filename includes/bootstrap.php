<?php

// Ustawienia muszą być przed uruchomieniem sesji.
ini_set('session.use_strict_mode', '1');

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== 'off',
]);

session_start();

// Adres folderu aplikacji w przeglądarce.
const BASE_URL = '/reserve-beauty';

// Bezpieczne wyświetlanie tekstu w HTML.
function e(string $tekst): string
{
    return htmlspecialchars(
        $tekst,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

// Przekierowanie na inną stronę.
function przekieruj(string $sciezka): void
{
    header('Location: ' . BASE_URL . $sciezka);
    exit;
}

// Token chroniący formularze.
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function sprawdzCsrf(): bool
{
    $token = $_POST['csrf_token'] ?? '';

    return is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

// Odczytujemy tylko wartości tekstowe.
function poleTekstowe(string $nazwa): string
{
    $wartosc = $_POST[$nazwa] ?? '';

    return is_string($wartosc) ? $wartosc : '';
}

