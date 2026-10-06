<?php
declare(strict_types=1);

require_once __DIR__ . '/paths.php';

date_default_timezone_set('Europe/Moscow');

const VG_MAX_UPLOAD_PIXELS = 60000000;
const VG_BACKUPS_KEEP = 15;

function vg_root(): string { return dirname(__DIR__); }

function vg_dir(string $sub = ''): string {
    $dir = volgina_data_dir() . ($sub !== '' ? '/' . $sub : '');
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/* ---------- storage ---------- */

function vg_read_json(string $path, $fallback) {
    if (!is_file($path)) return $fallback;
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') return $fallback;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $fallback;
}

function vg_write_atomic(string $path, string $contents, int $mode = 0600): bool {
    $dir = dirname($path);
    $tmp = @tempnam($dir, 'tmp-');
    if ($tmp === false) return false;
    $ok = @file_put_contents($tmp, $contents) === strlen($contents);
    if ($ok) { @chmod($tmp, $mode); $ok = @rename($tmp, $path); }
    if (!$ok) @unlink($tmp);
    return $ok;
}

function vg_with_lock(string $name, callable $fn) {
    $handle = @fopen(vg_dir() . '/' . $name . '.lock', 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) throw new RuntimeException('Не удалось получить доступ к хранилищу.');
    try { return $fn(); } finally { flock($handle, LOCK_UN); fclose($handle); }
}

function vg_schema(): array {
    static $schema = null;
    if ($schema === null) $schema = json_decode((string)file_get_contents(__DIR__ . '/schema.json'), true);
    return $schema;
}

function vg_content(): array {
    $c = vg_read_json(vg_dir() . '/content.json', []);
    foreach (['texts', 'photos'] as $k) if (!isset($c[$k]) || !is_array($c[$k])) $c[$k] = [];
    if (!isset($c['settings']) || !is_array($c['settings'])) $c['settings'] = [];
    return $c;
}

function vg_content_update(callable $mutate): array {
    return vg_with_lock('content', function () use ($mutate) {
        $c = vg_content();
        $mutate($c);
        $json = json_encode($c, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false || !vg_write_atomic(vg_dir() . '/content.json', $json)) throw new RuntimeException('Не удалось сохранить изменения.');
        return $c;
    });
}

/* ---------- reviews (same file and lock as submit-review.php) ---------- */

function vg_reviews_update(callable $mutate): array {
    return vg_with_lock('reviews', function () use ($mutate) {
        $path = vg_dir() . '/reviews.json';
        $raw = is_file($path) ? (string)@file_get_contents($path) : '';
        $list = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($list)) throw new RuntimeException('Файл отзывов повреждён.');
        $mutate($list);
        $json = json_encode(array_values($list), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false || !vg_write_atomic(vg_dir() . '/reviews.json', $json)) throw new RuntimeException('Не удалось сохранить отзывы.');
        return $list;
    });
}

function vg_reviews(): array {
    $content = vg_content();
    if (empty($content['seeded'])) {
        vg_reviews_update(function (array &$list) {
            foreach (vg_schema()['seeds'] as $i => $seed) {
                $list[] = ['id' => $seed['id'], 'name' => $seed['name'], 'occasion' => '', 'text' => $seed['text'],
                    'status' => 'published', 'order' => $i + 1, 'created_at' => '2026-01-01T00:00:00+00:00',
                    'published_at' => '2026-01-01T00:00:00+00:00', 'original' => $seed['original'], 'seed' => true];
            }
        });
        vg_content_update(function (array &$c) { $c['seeded'] = true; });
    }
    $list = vg_read_json(vg_dir() . '/reviews.json', []);
    foreach ($list as &$r) {
        if (!isset($r['status'])) $r['status'] = 'pending';
        if (!isset($r['order'])) $r['order'] = 1000000;
    }
    return $list;
}

function vg_reviews_by(string $status): array {
    $out = array_values(array_filter(vg_reviews(), fn($r) => $r['status'] === $status));
    if ($status === 'published') usort($out, fn($a, $b) => [$a['order'], $a['published_at'] ?? ''] <=> [$b['order'], $b['published_at'] ?? '']);
    else usort($out, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
    return $out;
}

function vg_review_counts(): array {
    $c = ['pending' => 0, 'published' => 0, 'rejected' => 0];
    foreach (vg_reviews() as $r) if (isset($c[$r['status']])) $c[$r['status']]++;
    return $c;
}

/* ---------- text rendering ---------- */

function vg_text($v): string {
    $v = str_replace(["\r\n", "\r"], "\n", (string)$v);
    return str_replace("\n", '<br> ', htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
}

function vg_attr($v): string {
    return htmlspecialchars(preg_replace('/[\r\n\t]+/', ' ', (string)$v), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function vg_field_defaults(): array {
    static $d = null;
    if ($d === null) { $d = []; foreach (vg_schema()['fields'] as $f) $d[$f['key']] = $f['default']; }
    return $d;
}

function vg_text_value(array $content, string $key): string {
    return array_key_exists($key, $content['texts']) ? (string)$content['texts'][$key] : (vg_field_defaults()[$key] ?? '');
}

/* ---------- photos ---------- */

function vg_replace_alt(string $markup, string $alt): string {
    return preg_replace_callback('/(<img [^>]*?alt=")[^"]*(")/', fn($m) => $m[1] . vg_attr($alt) . $m[2], $markup, 1);
}

function vg_upload_picture(array $u, string $alt, string $sizes): string {
    $ext = $u['ext'] ?? 'webp';
    $srcset = [];
    foreach ($u['widths'] as $w) $srcset[] = './images/uploads/' . $u['base'] . '-' . $w . '.' . $ext . ' ' . $w . 'w';
    $mid = in_array(640, $u['widths'], true) ? 640 : end($u['widths']);
    return '<picture class="responsive-photo"><img src="./images/uploads/' . $u['base'] . '-' . $mid . '.' . $ext . '" alt="' . vg_attr($alt)
        . '" loading="eager" fetchpriority="low" width="' . (int)$u['w'] . '" height="' . (int)$u['h'] . '" srcset="' . implode(', ', $srcset)
        . '" sizes="' . e($sizes) . '" decoding="async"></picture>';
}

function vg_slot_markup(array $content, array $slot): string {
    $rec = $content['photos'][$slot['id']] ?? [];
    $alt = $rec['alt'] ?? $slot['alt'];
    if (!empty($rec['upload'])) return vg_upload_picture($rec['upload'], $alt, $slot['sizes']);
    return isset($rec['alt']) ? vg_replace_alt($slot['markup'], $alt) : $slot['markup'];
}

function vg_gallery_items(array $content): array {
    if (isset($content['gallery']) && is_array($content['gallery'])) return $content['gallery'];
    $items = [];
    foreach (vg_schema()['gallery'] as $g) $items[] = ['id' => $g['id'], 'type' => 'default', 'alt' => $g['alt']];
    return $items;
}

function vg_gallery_default(string $id): ?array {
    foreach (vg_schema()['gallery'] as $g) if ($g['id'] === $id) return $g;
    return null;
}

function vg_gallery_link(array $item): string {
    if (($item['type'] ?? '') === 'upload') {
        $ext = $item['upload']['ext'] ?? 'webp';
        $href = './images/uploads/' . $item['upload']['base'] . '-full.' . $ext;
        $picture = vg_upload_picture($item['upload'], $item['alt'], '(max-width: 760px) 46vw, (max-width: 1440px) 30vw, 420px');
    } else {
        $g = vg_gallery_default($item['id']);
        if (!$g) return '';
        $href = $g['full'];
        $picture = vg_replace_alt($g['markup'], $item['alt']);
    }
    return '<a class="gallery-photo" href="' . e($href) . '" data-lightbox>' . $picture . '</a>';
}

function vg_review_card(array $r): string {
    $name = htmlspecialchars((string)$r['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $text = str_replace("\n", '<br>', htmlspecialchars(str_replace(["\r\n", "\r"], "\n", (string)$r['text']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    $html = '<article class="review-card review-photo review-text"><h3 class="review-author">' . $name . '</h3><blockquote><p>' . $text . '</p></blockquote>';
    if (!empty($r['original']['href'])) {
        $o = $r['original'];
        $alt = 'Оригинал отзыва: ' . $r['name'];
        $html .= '<a class="review-original" href="' . e($o['href']) . '" data-review="' . e($o['href']) . '" data-photo-width="' . (int)$o['w']
            . '" data-photo-height="' . (int)$o['h'] . '" data-photo-alt="' . vg_attr($alt) . '" aria-label="Посмотреть оригинал отзыва: ' . vg_attr($r['name'])
            . '">Посмотреть оригинал <span aria-hidden="true">↗</span></a>';
    }
    return $html . '</article>';
}

/* ---------- contacts ---------- */

function vg_settings(array $content): array {
    $s = $content['settings'] + vg_schema()['contacts'];
    return $s;
}

function vg_tel(string $phone): string { return 'tel:' . preg_replace('/[^+0-9]/', '', $phone); }

/* ---------- page generation ---------- */

function vg_render_page(?array $content = null): string {
    $content = $content ?? vg_content();
    $schema = vg_schema();
    $settings = vg_settings($content);
    $slots = [];
    foreach ($schema['photos'] as $p) $slots[$p['id']] = $p;
    $items = vg_gallery_items($content);
    $main = implode('', array_map('vg_gallery_link', array_slice($items, 0, 4)));
    $extra = implode('', array_map('vg_gallery_link', array_slice($items, 4)));
    $reviews = implode('', array_map('vg_review_card', vg_reviews_by('published')));
    $links = '';
    if ($settings['vk'] !== '') $links .= '<a href="' . e($settings['vk']) . '" target="_blank" rel="noopener">ВК</a>';
    if ($settings['max'] !== '') $links .= '<a href="' . e($settings['max']) . '" target="_blank" rel="noopener">MAX</a>';
    $phone = $settings['phone'] !== '' ? '<a class="contact-phone" href="' . e(vg_tel($settings['phone'])) . '">' . e($settings['phone']) . '</a>' : '<a class="contact-phone" hidden></a>';

    $tpl = (string)file_get_contents(__DIR__ . '/template.html');
    return preg_replace_callback('/\{\{(t|a|photo|gallery_main|gallery_extra|reviews|contact_links|contact_phone)(?::([a-z0-9._]+))?\}\}/',
        function ($m) use ($content, $slots, $main, $extra, $reviews, $links, $phone) {
            switch ($m[1]) {
                case 't': return vg_text(vg_text_value($content, $m[2]));
                case 'a': return vg_attr(vg_text_value($content, $m[2]));
                case 'photo': return isset($slots[$m[2]]) ? vg_slot_markup($content, $slots[$m[2]]) : '';
                case 'gallery_main': return $main;
                case 'gallery_extra': return $extra;
                case 'reviews': return $reviews;
                case 'contact_links': return $links;
                default: return $phone;
            }
        }, $tpl);
}

function vg_publish(): void {
    $path = vg_root() . '/index.html';
    $html = vg_render_page();
    if (is_file($path)) {
        $backups = vg_dir('backups');
        @copy($path, $backups . '/index-' . date('Ymd-His') . '.html');
        $old = glob($backups . '/index-*.html') ?: [];
        rsort($old);
        foreach (array_slice($old, VG_BACKUPS_KEEP) as $f) @unlink($f);
    }
    if (!vg_write_atomic($path, $html, 0644)) throw new RuntimeException('Не удалось обновить страницу сайта. Проверьте права на запись в папке сайта.');
}

/* ---------- images ---------- */

function vg_default_thumb(string $base): string {
    foreach ([360, 480, 640, 960] as $w) if (is_file(vg_root() . '/images/optimized/' . $base . '-' . $w . '.webp')) return '../images/optimized/' . $base . '-' . $w . '.webp';
    return '../images/optimized/' . $base . '-360.webp';
}

function vg_uploads_dir(): string {
    $dir = vg_root() . '/images/uploads';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

function vg_ini_bytes(string $v): int {
    $n = (int)$v; $u = strtolower(substr(trim($v), -1));
    return $u === 'g' ? $n << 30 : ($u === 'm' ? $n << 20 : ($u === 'k' ? $n << 10 : $n));
}

function vg_upload_limit_bytes(): int {
    return min(vg_ini_bytes((string)ini_get('upload_max_filesize')) ?: PHP_INT_MAX, vg_ini_bytes((string)ini_get('post_max_size')) ?: PHP_INT_MAX);
}

function vg_upload_limit_text(): string {
    $b = vg_upload_limit_bytes();
    return $b === PHP_INT_MAX ? 'без ограничений' : max(1, (int)round($b / 1048576)) . ' МБ';
}

function vg_resize($im, int $w) {
    $w0 = imagesx($im); $h0 = imagesy($im);
    $h = max(1, (int)round($h0 * $w / $w0));
    $out = imagecreatetruecolor($w, $h);
    imagealphablending($out, false); imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 255, 255, 255, 127));
    return imagecopyresampled($out, $im, 0, 0, 0, 0, $w, $h, $w0, $h0) ? $out : false;
}

/** Validates and converts an uploaded picture into web variants. Throws RuntimeException with a human message. */
function vg_process_upload(array $file, int $maxFull = 2000): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        if (in_array($file['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) throw new RuntimeException('Файл слишком большой для сервера (лимит ' . vg_upload_limit_text() . '). Уменьшите фото и попробуйте снова.');
        throw new RuntimeException('Файл не загрузился. Попробуйте ещё раз.');
    }
    if (!is_uploaded_file($file['tmp_name'])) throw new RuntimeException('Файл не загрузился.');
    $info = @getimagesize($file['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpeg', IMAGETYPE_PNG => 'png'];
    if (defined('IMAGETYPE_WEBP')) $types[IMAGETYPE_WEBP] = 'webp';
    if (!$info || !isset($types[$info[2]])) throw new RuntimeException('Подойдут фотографии в формате JPG, PNG или WebP.');
    if ($info[0] * $info[1] > VG_MAX_UPLOAD_PIXELS) throw new RuntimeException('Фотография слишком большая по размеру в пикселях.');
    @ini_set('memory_limit', '768M');
    $create = 'imagecreatefrom' . $types[$info[2]];
    if (!function_exists($create)) throw new RuntimeException('Сервер не умеет читать этот формат.');
    $im = @$create($file['tmp_name']);
    if (!$im) throw new RuntimeException('Не удалось прочитать фотографию.');
    if ($types[$info[2]] === 'jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($file['tmp_name']);
        $rot = [3 => 180, 6 => -90, 8 => 90][$exif['Orientation'] ?? 0] ?? 0;
        if ($rot) { $r = imagerotate($im, $rot, 0); if ($r) $im = $r; }
    }
    $webp = function_exists('imagewebp');
    $ext = $webp ? 'webp' : 'jpg';
    $w0 = imagesx($im); $h0 = imagesy($im);
    $base = 'u' . bin2hex(random_bytes(6));
    $dir = vg_uploads_dir();
    $save = function ($img, string $path) use ($webp) {
        imagealphablending($img, false); imagesavealpha($img, true);
        return $webp ? imagewebp($img, $path, 82) : imagejpeg($img, $path, 85);
    };
    $widths = [];
    foreach ([360, 640, 960, 1440] as $w) if ($w < $w0 || $w === 360) $widths[] = min($w, $w0);
    if (!in_array($w0, $widths, true) && $w0 <= 1440) $widths[] = $w0;
    $widths = array_values(array_unique($widths)); sort($widths);
    $lastW = 0; $lastH = 0;
    foreach ($widths as $w) {
        $img = $w === $w0 ? $im : vg_resize($im, $w);
        if (!$img || !$save($img, $dir . '/' . $base . '-' . $w . '.' . $ext)) throw new RuntimeException('Не удалось сохранить фотографию.');
        $lastW = $w; $lastH = imagesy($img);
    }
    $fullW = min($maxFull, $w0);
    $full = $fullW === $w0 ? $im : vg_resize($im, $fullW);
    if (!$full || !$save($full, $dir . '/' . $base . '-full.' . $ext)) throw new RuntimeException('Не удалось сохранить фотографию.');
    return ['base' => $base, 'ext' => $ext, 'widths' => $widths, 'w' => $lastW, 'h' => $lastH, 'full_w' => $fullW, 'full_h' => imagesy($full)];
}

function vg_delete_upload(?array $u): void {
    if (!$u || empty($u['base']) || !preg_match('/^u[0-9a-f]{12}$/', $u['base'])) return;
    foreach (glob(vg_uploads_dir() . '/' . $u['base'] . '-*') ?: [] as $f) @unlink($f);
}

/* ---------- auth ---------- */

function vg_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $path = vg_dir('sessions');
    session_save_path($path);
    session_name('vg_admin');
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params(['lifetime' => 0, 'path' => rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
    ini_set('session.gc_maxlifetime', '43200');
    ini_set('session.use_strict_mode', '1');
    session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
}

function vg_admin_record(): ?array { return vg_read_json(vg_dir() . '/admin.json', []) ?: null; }

function vg_logged_in(): bool {
    if (empty($_SESSION['auth'])) return false;
    if (time() - (int)($_SESSION['last'] ?? 0) > 43200) { $_SESSION = []; return false; }
    $_SESSION['last'] = time();
    return true;
}

function vg_csrf_ok(): bool {
    return is_string($_POST['csrf'] ?? null) && hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf']);
}

function vg_client_key(): string { return hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . vg_dir()); }

function vg_login_blocked(): int {
    $data = vg_read_json(vg_dir() . '/throttle.json', []);
    $recent = array_filter($data[vg_client_key()] ?? [], fn($t) => $t > time() - 900);
    return count($recent) >= 5 ? (int)(min($recent) + 900 - time()) : 0;
}

function vg_login_failed(): void {
    vg_with_lock('throttle', function () {
        $path = vg_dir() . '/throttle.json';
        $data = vg_read_json($path, []);
        foreach ($data as $k => $list) { $data[$k] = array_values(array_filter($list, fn($t) => $t > time() - 900)); if (!$data[$k]) unset($data[$k]); }
        $data[vg_client_key()][] = time();
        vg_write_atomic($path, json_encode($data));
    });
}

function vg_login_ok(): void {
    session_regenerate_id(true);
    $_SESSION['auth'] = 1;
    $_SESSION['last'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
}

function vg_setup_code(): ?string {
    $file = __DIR__ . '/config.local.php';
    if (!is_file($file)) return null;
    $cfg = include $file;
    return is_array($cfg) && !empty($cfg['setup_code']) ? (string)$cfg['setup_code'] : null;
}

function vg_set_password(string $password): void {
    $rec = ['hash' => password_hash($password, PASSWORD_DEFAULT), 'changed_at' => gmdate('c')];
    if (!vg_write_atomic(vg_dir() . '/admin.json', json_encode($rec))) throw new RuntimeException('Не удалось сохранить пароль.');
}

/* ---------- helpers for views ---------- */

function vg_ru_date(string $iso): string {
    $t = strtotime($iso);
    if (!$t) return '';
    $m = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
    return date('j', $t) . ' ' . $m[(int)date('n', $t) - 1] . ' ' . date('Y', $t) . ', ' . date('H:i', $t);
}

function vg_str_len(string $s): int { return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s); }

function vg_clean_text(string $s, int $max): string {
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);
    $s = trim($s);
    return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
}

function vg_valid_url(string $url): bool {
    return (bool)preg_match('~^https?://[^\s<>"\']+$~i', $url) && filter_var($url, FILTER_VALIDATE_URL) !== false;
}
