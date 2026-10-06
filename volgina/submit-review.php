<?php
declare(strict_types=1);

// Public submissions remain private and pending until approved in the future admin UI.
function respond(int $code, bool $ok, string $message): void {
    http_response_code($code);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Отзыв — Ирина Волгина</title><body style="background:#faf8f4;color:#252322;font:18px/1.6 Arial,sans-serif;max-width:650px;margin:12vh auto;padding:24px"><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p><a href="./index.html#reviews">Вернуться на сайт</a></body></html>';
    }
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, false, 'Используйте форму на сайте, чтобы оставить отзыв.');
}
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 32768) {
    respond(413, false, 'Отзыв слишком длинный. Сократите текст и попробуйте ещё раз.');
}
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $parts = parse_url($origin);
    $originHost = strtolower(($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : ''));
    if ($originHost !== strtolower($_SERVER['HTTP_HOST'] ?? '')) {
        respond(403, false, 'Отправьте отзыв через форму на сайте.');
    }
}
foreach (['name', 'occasion', 'review', 'website'] as $key) {
    if (isset($_POST[$key]) && !is_string($_POST[$key])) respond(422, false, 'Проверьте заполненные поля.');
}
if (trim($_POST['website'] ?? '') !== '') respond(200, true, 'Спасибо! Отзыв отправлен на проверку.');
$name = trim($_POST['name'] ?? '');
$occasion = trim($_POST['occasion'] ?? '');
$text = trim($_POST['review'] ?? '');
foreach ([$name, $occasion, $text] as $value) {
    if (!preg_match('//u', $value) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
        respond(422, false, 'Проверьте заполненные поля.');
    }
}
function lengthOf(string $value): int { return preg_match_all('/./us', $value); }
if (lengthOf($name) < 1 || lengthOf($name) > 100 || lengthOf($occasion) > 150 || lengthOf($text) < 20 || lengthOf($text) > 3000) {
    respond(422, false, 'Укажите имя и отзыв от 20 до 3000 символов.');
}
// /home/lazurin/public_html/volgina -> /home/lazurin/volgina-review-data
$directory = getenv('VOLGINA_REVIEW_DATA_DIR') ?: dirname(__DIR__, 2) . '/volgina-review-data';
umask(0077);
if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
    respond(503, false, 'Не удалось сохранить отзыв. Попробуйте позже.');
}
$storage = @fopen($directory . '/reviews.lock', 'c+');
if (!$storage || !flock($storage, LOCK_EX)) respond(503, false, 'Не удалось сохранить отзыв. Попробуйте позже.');
$path = $directory . '/reviews.json';
$content = is_file($path) ? @file_get_contents($path) : '';
$reviews = $content === '' ? [] : json_decode($content, true);
if (!is_array($reviews)) {
    fclose($storage);
    respond(503, false, 'Не удалось сохранить отзыв. Попробуйте позже.');
}
$now = time();
$client = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . $directory);
$recent = 0;
foreach ($reviews as $item) {
    if (($item['client'] ?? '') === $client && strtotime($item['created_at'] ?? '') > $now - 3600) $recent++;
}
if ($recent >= 3) {
    fclose($storage);
    respond(429, false, 'Вы уже отправили несколько отзывов. Попробуйте немного позже.');
}
$reviews[] = ['id' => bin2hex(random_bytes(12)), 'name' => $name, 'occasion' => $occasion, 'text' => $text,
    'status' => 'pending', 'created_at' => gmdate('c', $now), 'client' => $client];
$encoded = json_encode($reviews, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
$temporary = @tempnam($directory, 'pending-');
$written = $encoded !== false && $temporary !== false && @file_put_contents($temporary, $encoded) === strlen($encoded);
if ($written) $written = @rename($temporary, $path);
if (!$written && $temporary !== false) @unlink($temporary);
flock($storage, LOCK_UN);
fclose($storage);
if (!$written) respond(503, false, 'Не удалось сохранить отзыв. Попробуйте позже.');
respond(200, true, 'Спасибо! Отзыв отправлен на проверку.');
