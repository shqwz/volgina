<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self'; script-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'none'");

vg_session_start();

function flash(string $type, string $msg): void { $_SESSION['flash'] = ['type' => $type, 'msg' => $msg]; }

function go(string $section = 'reviews', array $q = []): void {
    $q = ['s' => $section] + $q;
    header('Location: ./?' . http_build_query($q));
    exit;
}

function sections(): array {
    return ['reviews' => 'Отзывы', 'texts' => 'Тексты', 'photos' => 'Фотографии', 'settings' => 'Настройки'];
}

/* ===================================================== actions ===== */

function act_review(): void {
    $op = (string)($_POST['op'] ?? '');
    $id = (string)($_POST['id'] ?? '');
    $filter = in_array($_POST['filter'] ?? '', ['pending', 'published', 'rejected'], true) ? $_POST['filter'] : 'pending';
    $msg = 'Готово.';
    $now = gmdate('c');

    if ($op === 'add') {
        $name = vg_clean_text((string)($_POST['name'] ?? ''), 100);
        $text = vg_clean_text((string)($_POST['text'] ?? ''), 3000);
        if ($name === '' || vg_str_len($text) < 5) throw new RuntimeException('Укажите имя и текст отзыва.');
        $original = null;
        if (!empty($_FILES['shot']['name'])) {
            $p = vg_process_upload($_FILES['shot'], 1600);
            $original = ['href' => './images/uploads/' . $p['base'] . '-full.' . $p['ext'], 'w' => $p['full_w'], 'h' => $p['full_h']];
        }
        vg_reviews_update(function (array &$list) use ($name, $text, $original, $now) {
            $max = 0; foreach ($list as $r) if (($r['status'] ?? '') === 'published') $max = max($max, (int)($r['order'] ?? 0));
            $rec = ['id' => bin2hex(random_bytes(12)), 'name' => $name, 'occasion' => '', 'text' => $text, 'status' => 'published',
                'order' => $max + 1, 'created_at' => $now, 'published_at' => $now, 'manual' => true];
            if ($original) $rec['original'] = $original;
            $list[] = $rec;
        });
        vg_publish();
        flash('ok', 'Отзыв добавлен и уже на сайте.');
        go('reviews', ['f' => 'published']);
    }

    if (!preg_match('/^[a-z0-9-]{4,40}$/', $id)) throw new RuntimeException('Отзыв не найден.');

    if ($op === 'shot_add' || $op === 'shot_remove') {
        $original = null;
        if ($op === 'shot_add') {
            $p = vg_process_upload($_FILES['shot'] ?? [], 1600);
            $original = ['href' => './images/uploads/' . $p['base'] . '-full.' . $p['ext'], 'w' => $p['full_w'], 'h' => $p['full_h']];
        }
        $found = false; $old = null;
        vg_reviews_update(function (array &$list) use ($id, $original, &$found, &$old) {
            foreach ($list as &$r) {
                if (($r['id'] ?? '') !== $id) continue;
                $found = true; $old = $r['original']['href'] ?? null;
                if ($original) $r['original'] = $original; else unset($r['original']);
            }
        });
        if (!$found) throw new RuntimeException('Отзыв не найден — возможно, он уже удалён.');
        if ($old && preg_match('~/uploads/(u[0-9a-f]{12})-full\.~', $old, $m)) vg_delete_upload(['base' => $m[1]]);
        vg_publish();
        flash('ok', $original ? 'Фото к отзыву добавлено.' : 'Фото убрано из отзыва.');
        go('reviews', ['f' => $filter]);
    }
    $found = false; $filterAfter = $filter; $removedOriginal = null;

    vg_reviews_update(function (array &$list) use ($op, $id, $now, &$found, &$msg, &$filterAfter, &$removedOriginal) {
        foreach ($list as $i => &$r) {
            if (($r['id'] ?? '') !== $id) continue;
            $found = true;
            switch ($op) {
                case 'publish':
                    $max = 0; foreach ($list as $x) if (($x['status'] ?? '') === 'published') $max = max($max, (int)($x['order'] ?? 0));
                    $r['status'] = 'published'; $r['published_at'] = $now; $r['order'] = $max + 1;
                    $msg = 'Отзыв опубликован — он уже на сайте.';
                    break;
                case 'move': break;
                case 'reject': $r['status'] = 'rejected'; $msg = 'Отзыв отклонён. Посетители его не увидят.'; break;
                case 'restore': $r['status'] = 'pending'; $msg = 'Отзыв возвращён на проверку.'; break;
                case 'unpublish': $r['status'] = 'pending'; $msg = 'Отзыв снят с сайта и ждёт решения.'; $filterAfter = 'pending'; break;
                case 'edit':
                    $name = vg_clean_text((string)($_POST['name'] ?? ''), 100);
                    $text = vg_clean_text((string)($_POST['text'] ?? ''), 3000);
                    if ($name === '' || vg_str_len($text) < 5) throw new RuntimeException('Имя и текст не должны быть пустыми.');
                    $r['name'] = $name; $r['text'] = $text; $msg = 'Изменения сохранены.';
                    break;
                case 'delete':
                    if (!empty($r['original']['href']) && strpos($r['original']['href'], '/uploads/') !== false) $removedOriginal = $r['original']['href'];
                    unset($list[$i]); $msg = 'Отзыв удалён навсегда.';
                    break;
                default: throw new RuntimeException('Неизвестное действие.');
            }
            break;
        }
        unset($r);
        if ($op === 'move') {
            $pub = []; foreach ($list as $k => $r) if (($r['status'] ?? '') === 'published') $pub[] = $k;
            usort($pub, fn($a, $b) => [$list[$a]['order'] ?? 0, $list[$a]['published_at'] ?? ''] <=> [$list[$b]['order'] ?? 0, $list[$b]['published_at'] ?? '']);
            $pos = false; foreach ($pub as $n => $k) if ($list[$k]['id'] === $id) $pos = $n;
            if ($pos !== false) {
                $found = true;
                $to = $pos + (($_POST['dir'] ?? '') === 'up' ? -1 : 1);
                if (isset($pub[$to])) { $t = $pub[$pos]; $pub[$pos] = $pub[$to]; $pub[$to] = $t; }
                foreach ($pub as $n => $k) $list[$k]['order'] = $n + 1;
                $msg = 'Порядок отзывов изменён.';
            }
        }
    });
    if (!$found) throw new RuntimeException('Отзыв не найден — возможно, он уже удалён.');
    if ($removedOriginal && preg_match('~/uploads/(u[0-9a-f]{12})-full\.~', $removedOriginal, $m)) vg_delete_upload(['base' => $m[1]]);
    vg_publish();
    flash('ok', $msg);
    go('reviews', ['f' => $filterAfter]);
}

function act_texts(): void {
    $in = $_POST['t'] ?? [];
    if (!is_array($in)) throw new RuntimeException('Не удалось прочитать форму.');
    $changed = 0;
    vg_content_update(function (array &$c) use ($in, &$changed) {
        foreach (vg_schema()['fields'] as $f) {
            if (!isset($in[$f['key']]) || !is_string($in[$f['key']])) continue;
            $v = vg_clean_text($in[$f['key']], (int)$f['maxlen']);
            if ($f['kind'] === 'line') $v = trim(preg_replace('/\s*\n\s*/', ' ', $v));
            if ($v === '' ) $v = $f['default'];
            $had = array_key_exists($f['key'], $c['texts']);
            if ($v === $f['default']) { if ($had) { unset($c['texts'][$f['key']]); $changed++; } }
            elseif (!$had || $c['texts'][$f['key']] !== $v) { $c['texts'][$f['key']] = $v; $changed++; }
        }
    });
    vg_publish();
    flash('ok', $changed ? 'Тексты сохранены и опубликованы на сайте.' : 'Изменений нет — всё уже на сайте.');
    go('texts', ['g' => (string)($_POST['g'] ?? '')]);
}

function act_photo(): void {
    $op = (string)($_POST['op'] ?? '');
    $slotId = (string)($_POST['slot'] ?? '');
    $slot = null; foreach (vg_schema()['photos'] as $p) if ($p['id'] === $slotId) $slot = $p;
    if (!$slot) throw new RuntimeException('Фото не найдено.');
    $msg = 'Готово.';
    if ($op === 'upload') {
        $p = vg_process_upload($_FILES['file'] ?? []);
        $old = null;
        vg_content_update(function (array &$c) use ($slotId, $p, &$old) {
            $old = $c['photos'][$slotId]['upload'] ?? null;
            $c['photos'][$slotId]['upload'] = $p;
        });
        vg_delete_upload($old);
        $msg = 'Фото заменено и уже на сайте.';
    } elseif ($op === 'reset') {
        $old = null;
        vg_content_update(function (array &$c) use ($slotId, &$old) {
            $old = $c['photos'][$slotId]['upload'] ?? null;
            unset($c['photos'][$slotId]['upload']);
            if (empty($c['photos'][$slotId])) unset($c['photos'][$slotId]);
        });
        vg_delete_upload($old);
        $msg = 'Возвращено исходное фото.';
    } elseif ($op === 'alt') {
        $alt = vg_clean_text((string)($_POST['alt'] ?? ''), 160);
        vg_content_update(function (array &$c) use ($slotId, $alt, $slot) {
            if ($alt === '' || $alt === $slot['alt']) unset($c['photos'][$slotId]['alt']); else $c['photos'][$slotId]['alt'] = $alt;
            if (empty($c['photos'][$slotId])) unset($c['photos'][$slotId]);
        });
        $msg = 'Описание фото сохранено.';
    } else throw new RuntimeException('Неизвестное действие.');
    vg_publish();
    flash('ok', $msg);
    go('photos');
}

function act_gallery(): void {
    $op = (string)($_POST['op'] ?? '');
    $id = (string)($_POST['id'] ?? '');
    $msg = 'Готово.';
    $dropped = [];
    $added = 0; $errors = [];

    if ($op === 'add') {
        $files = [];
        $f = $_FILES['files'] ?? null;
        if ($f && is_array($f['name'])) foreach ($f['name'] as $i => $n) if ($n !== '') $files[] = ['name' => $n, 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
        if (!$files) throw new RuntimeException('Выберите фотографии для загрузки.');
        $new = [];
        foreach ($files as $file) {
            try {
                $p = vg_process_upload($file);
                $new[] = ['id' => $p['base'], 'type' => 'upload', 'alt' => 'Фото с праздника', 'upload' => $p];
            } catch (RuntimeException $ex) { $errors[] = $file['name'] . ': ' . $ex->getMessage(); }
        }
        if ($new) vg_content_update(function (array &$c) use ($new) { $items = vg_gallery_items($c); $c['gallery'] = array_merge($items, $new); });
        $added = count($new);
        $msg = $added ? 'Добавлено фото: ' . $added . '. Они в конце галереи.' : 'Ничего не добавлено.';
        if ($errors) $msg .= ' Не получилось: ' . implode(' · ', $errors);
        vg_publish();
        flash($added ? 'ok' : 'err', $msg);
        go('photos');
    }

    vg_content_update(function (array &$c) use ($op, $id, &$msg, &$dropped) {
        $items = vg_gallery_items($c);
        $pos = null; foreach ($items as $i => $it) if ($it['id'] === $id) $pos = $i;
        if ($op === 'restore') {
            if (!vg_gallery_default($id)) throw new RuntimeException('Фото не найдено.');
            foreach ($items as $it) if ($it['id'] === $id) throw new RuntimeException('Это фото уже в галерее.');
            $g = vg_gallery_default($id);
            $items[] = ['id' => $id, 'type' => 'default', 'alt' => $g['alt']];
            $msg = 'Фото возвращено в галерею.';
        } else {
            if ($pos === null) throw new RuntimeException('Фото не найдено — возможно, оно уже удалено.');
            if ($op === 'delete') {
                if (count($items) <= 1) throw new RuntimeException('В галерее должно остаться хотя бы одно фото.');
                if (($items[$pos]['type'] ?? '') === 'upload') $dropped[] = $items[$pos]['upload'];
                array_splice($items, $pos, 1); $msg = 'Фото убрано из галереи.';
            } elseif ($op === 'move') {
                $to = $pos + (($_POST['dir'] ?? '') === 'left' ? -1 : 1);
                if (isset($items[$to])) { $t = $items[$pos]; $items[$pos] = $items[$to]; $items[$to] = $t; }
                $msg = 'Порядок фото изменён.';
            } elseif ($op === 'alt') {
                $alt = vg_clean_text((string)($_POST['alt'] ?? ''), 160);
                $items[$pos]['alt'] = $alt !== '' ? $alt : 'Фото с праздника'; $msg = 'Описание фото сохранено.';
            } else throw new RuntimeException('Неизвестное действие.');
        }
        $c['gallery'] = array_values($items);
    });
    foreach ($dropped as $u) vg_delete_upload($u);
    vg_publish();
    flash('ok', $msg);
    go('photos');
}

function act_settings(): void {
    $vk = trim((string)($_POST['vk'] ?? '')); $max = trim((string)($_POST['max'] ?? '')); $phone = trim((string)($_POST['phone'] ?? ''));
    if (($vk !== '' && !vg_valid_url($vk)) || ($max !== '' && !vg_valid_url($max))) throw new RuntimeException('Ссылки должны начинаться с https://');
    if ($phone !== '' && !preg_match('/^[+0-9()\s\-]{5,25}$/', $phone)) throw new RuntimeException('Телефон: только цифры, пробелы, скобки, дефис и плюс.');
    if ($vk === '' && $max === '' && $phone === '') throw new RuntimeException('Оставьте хотя бы один способ связи.');
    vg_content_update(function (array &$c) use ($vk, $max, $phone) { $c['settings'] = ['vk' => $vk, 'max' => $max, 'phone' => $phone]; });
    vg_publish();
    flash('ok', 'Контакты сохранены и обновлены на сайте.');
    go('settings');
}

function act_password(): void {
    $rec = vg_admin_record();
    $old = (string)($_POST['current'] ?? ''); $new = (string)($_POST['new'] ?? ''); $again = (string)($_POST['again'] ?? '');
    if (!$rec || !password_verify($old, $rec['hash'])) throw new RuntimeException('Текущий пароль указан неверно.');
    if (strlen($new) < 10) throw new RuntimeException('Новый пароль — не короче 10 символов.');
    if ($new !== $again) throw new RuntimeException('Новые пароли не совпадают.');
    vg_set_password($new);
    session_regenerate_id(true);
    flash('ok', 'Пароль изменён.');
    go('settings');
}

/* ===================================================== views ===== */

function icon(string $name): string {
    $p = [
        'reviews' => '<path d="M5 6.5h14v9H12l-4 3.2v-3.2H5z"/><path d="M8.5 10h7M8.5 12.6h4"/>',
        'texts' => '<path d="M5 6h14M5 10.5h14M5 15h9M5 19h6"/>',
        'photos' => '<rect x="4" y="5.5" width="16" height="13" rx="1.5"/><circle cx="9" cy="10.3" r="1.5"/><path d="m5 17 4.6-4.4 3.4 3.2 2.4-2.2L19.5 17"/>',
        'settings' => '<path d="M5 8h8M17 8h2M5 16h2M11 16h8"/><circle cx="15" cy="8" r="2"/><circle cx="9" cy="16" r="2"/>',
        'arrow' => '<path d="M5 19 19 5M5 5h14v14"/>',
        'up' => '<path d="m6 14 6-6 6 6"/>', 'down' => '<path d="m6 10 6 6 6-6"/>',
        'left' => '<path d="m14 6-6 6 6 6"/>', 'right' => '<path d="m10 6 6 6-6 6"/>',
        'check' => '<path d="m5 12.5 4.5 4.5L19 7.5"/>', 'x' => '<path d="m6 6 12 12M18 6 6 18"/>',
        'trash' => '<path d="M5 7h14M10 7V5h4v2M7 7l.8 12h8.4L17 7"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>', 'eye' => '<path d="M3 12s3.5-6 9-6 9 6 9 6-3.5 6-9 6-9-6-9-6z"/><circle cx="12" cy="12" r="2.3"/>',
        'pencil' => '<path d="m5 19 1-4 9.5-9.5 3 3L9 18z"/>', 'undo' => '<path d="M9 7 5 11l4 4M5 11h9a5 5 0 0 1 0 10h-2"/>',
        'upload' => '<path d="M12 16V5m0 0-4 4m4-4 4 4M5 19h14"/>',
    ];
    return '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}

function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">'; }

function page(string $title, string $body, bool $chrome = true, string $active = ''): void {
    $flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
    ?><!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="theme-color" content="#FAF8F4">
<title><?= e($title) ?> — управление сайтом</title>
<link rel="icon" type="image/svg+xml" href="../favicon.svg">
<link rel="preload" href="../fonts/onest-fast.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="../fonts.css"><link rel="stylesheet" href="admin.css?v=1"><script src="admin.js?v=1" defer></script></head>
<body class="<?= $chrome ? 'with-chrome' : 'bare' ?>">
<?php if ($chrome): $counts = vg_review_counts(); ?>
<header class="topbar"><a class="brand" href="./"><span class="brand-name">Ирина Волгина</span><span class="brand-sub">управление сайтом</span></a>
<div class="top-actions"><a class="chip" href="../" target="_blank" rel="noopener">Открыть сайт <?= icon('arrow') ?></a>
<form method="post" action="./"><?= csrf_field() ?><input type="hidden" name="action" value="logout"><button class="chip chip-quiet" type="submit">Выйти</button></form></div></header>
<nav class="sidenav" aria-label="Разделы"><?php foreach (sections() as $k => $label): ?>
<a href="./?s=<?= $k ?>"<?= $active === $k ? ' aria-current="page"' : '' ?>><?= icon($k) ?><span><?= e($label) ?></span><?php if ($k === 'reviews' && $counts['pending']): ?><b class="badge" aria-label="Ждут проверки: <?= $counts['pending'] ?>"><?= $counts['pending'] ?></b><?php endif; ?></a>
<?php endforeach; ?></nav>
<?php endif; ?>
<main class="content" id="main">
<?php if ($flash): ?><div class="flash flash-<?= e($flash['type']) ?>" role="status"><?= icon($flash['type'] === 'ok' ? 'check' : 'x') ?><p><?= e($flash['msg']) ?></p></div><?php endif; ?>
<?= $body ?>
</main>
<dialog class="confirm" id="confirm"><form method="dialog"><p id="confirm-text"></p><div class="confirm-actions"><button value="cancel" class="btn btn-ghost">Отмена</button><button value="ok" class="btn btn-danger" id="confirm-ok">Да, удалить</button></div></form></dialog>
</body></html><?php
}

function view_login(?string $error = null, int $blocked = 0): void {
    ob_start(); ?>
<section class="auth-card"><p class="auth-eyebrow">Ирина Волгина</p><h1>Вход в управление сайтом</h1>
<p class="muted">Здесь можно менять тексты и фотографии, а также проверять отзывы гостей.</p>
<?php if ($error): ?><p class="field-error" role="alert"><?= e($error) ?></p><?php endif; ?>
<form method="post" action="./" autocomplete="on"><?= csrf_field() ?><input type="hidden" name="action" value="login">
<label class="field"><span>Пароль</span><span class="pw"><input type="password" name="password" required autocomplete="current-password" autofocus<?= $blocked ? ' disabled' : '' ?>><button type="button" class="pw-toggle" data-toggle-password aria-label="Показать пароль"><?= icon('eye') ?></button></span></label>
<button class="btn btn-primary btn-wide" type="submit"<?= $blocked ? ' disabled' : '' ?>>Войти</button></form>
</section>
<?php page('Вход', ob_get_clean(), false);
}

function view_setup(?string $error = null): void {
    ob_start(); $code = vg_setup_code(); ?>
<section class="auth-card"><p class="auth-eyebrow">Первый запуск</p><h1>Придумайте пароль</h1>
<?php if (!$code): ?><p class="field-error">Не найден файл <code>admin/config.local.php</code> с кодом первого входа. Загрузите его на сайт вместе с остальными файлами.</p>
<?php else: ?><p class="muted">Это нужно сделать один раз. Код первого входа вам передали вместе со ссылкой на эту страницу.</p>
<?php if ($error): ?><p class="field-error" role="alert"><?= e($error) ?></p><?php endif; ?>
<form method="post" action="./"><?= csrf_field() ?><input type="hidden" name="action" value="setup">
<label class="field"><span>Код первого входа</span><input name="code" required autocomplete="off" autofocus></label>
<label class="field"><span>Новый пароль</span><span class="pw"><input type="password" name="new" required minlength="10" autocomplete="new-password"><button type="button" class="pw-toggle" data-toggle-password aria-label="Показать пароль"><?= icon('eye') ?></button></span><small>Не короче 10 символов. Лучше фраза из нескольких слов.</small></label>
<label class="field"><span>Повторите пароль</span><input type="password" name="again" required minlength="10" autocomplete="new-password"></label>
<button class="btn btn-primary btn-wide" type="submit">Сохранить и войти</button></form><?php endif; ?></section>
<?php page('Первый запуск', ob_get_clean(), false);
}

function section_head(string $title, string $lead): string {
    return '<header class="section-head"><h1>' . e($title) . '</h1><p class="lead">' . e($lead) . '</p></header>';
}

function view_reviews(): string {
    $counts = vg_review_counts();
    $f = $_GET['f'] ?? ($counts['pending'] ? 'pending' : 'published');
    if (!in_array($f, ['pending', 'published', 'rejected'], true)) $f = 'pending';
    $list = vg_reviews_by($f);
    $tabs = ['pending' => 'Ждут проверки', 'published' => 'На сайте', 'rejected' => 'Отклонённые'];
    ob_start();
    echo section_head('Отзывы', 'Новые отзывы с сайта попадают сюда. Посетители увидят только те, которые вы опубликуете.');
    echo '<div class="tabs" role="tablist">';
    foreach ($tabs as $k => $label) echo '<a role="tab" href="./?s=reviews&amp;f=' . $k . '"' . ($f === $k ? ' aria-selected="true"' : '') . '>' . e($label) . ' <span class="count' . ($k === 'pending' && $counts[$k] ? ' count-hot' : '') . '">' . $counts[$k] . '</span></a>';
    echo '</div>';
    if (!$list) {
        $empty = ['pending' => ['Новых отзывов нет', 'Когда гость отправит отзыв через форму на сайте, он появится здесь.'], 'published' => ['Пока ничего не опубликовано', 'Опубликованные отзывы показываются на сайте.'], 'rejected' => ['Отклонённых нет', 'Сюда попадают отзывы, которые вы решили не публиковать.']][$f];
        echo '<div class="empty">' . icon('reviews') . '<h2>' . e($empty[0]) . '</h2><p>' . e($empty[1]) . '</p></div>';
    }
    $n = 0; $total = count($list);
    foreach ($list as $r) {
        $n++;
        $id = e($r['id']);
        echo '<article class="review"><div class="review-top"><h2>' . e($r['name']) . '</h2><time datetime="' . e($r['created_at'] ?? '') . '">' . e(!empty($r['seed']) ? 'Исходный отзыв' : vg_ru_date($r['created_at'] ?? '')) . '</time></div>';
        echo '<blockquote>' . nl2br(e($r['text'])) . '</blockquote>';
        if (!empty($r['original']['href'])) echo '<a class="small link" href="' . e('.' . $r['original']['href']) . '" target="_blank" rel="noopener">Скриншот оригинала ' . icon('arrow') . '</a>';
        echo '<div class="review-actions">';
        $btn = function (string $op, string $label, string $cls, string $ic, string $confirm = '') use ($id, $f) {
            return '<form method="post" action="./"' . ($confirm ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . csrf_field() . '<input type="hidden" name="action" value="review"><input type="hidden" name="op" value="' . $op . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="filter" value="' . $f . '"><button class="btn ' . $cls . '" type="submit">' . icon($ic) . '<span>' . $label . '</span></button></form>';
        };
        if ($f === 'pending') echo $btn('publish', 'Опубликовать', 'btn-primary', 'check') . $btn('reject', 'Отклонить', 'btn-ghost', 'x');
        if ($f === 'published') {
            if ($total > 1) {
                $mv = function (string $dir, string $ic, string $label, bool $off) use ($id) { return '<form method="post" action="./">' . csrf_field() . '<input type="hidden" name="action" value="review"><input type="hidden" name="op" value="move"><input type="hidden" name="dir" value="' . $dir . '"><input type="hidden" name="id" value="' . $id . '"><button class="btn btn-icon" type="submit" aria-label="' . $label . '"' . ($off ? ' disabled' : '') . '>' . icon($ic) . '</button></form>'; };
                echo '<div class="movers">' . $mv('up', 'up', 'Поднять выше', $n === 1) . $mv('down', 'down', 'Опустить ниже', $n === $total) . '</div>';
            }
            echo $btn('unpublish', 'Снять с сайта', 'btn-ghost', 'undo');
        }
        if ($f === 'rejected') echo $btn('restore', 'Вернуть на проверку', 'btn-ghost', 'undo');
        echo '<details class="edit"><summary class="btn btn-quiet">' . icon('pencil') . '<span>Изменить текст и фото</span></summary><form method="post" action="./" class="edit-form">' . csrf_field()
            . '<input type="hidden" name="action" value="review"><input type="hidden" name="op" value="edit"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="filter" value="' . $f . '">'
            . '<label class="field"><span>Имя</span><input name="name" value="' . e($r['name']) . '" maxlength="100" required></label>'
            . '<label class="field"><span>Текст отзыва</span><textarea name="text" rows="5" maxlength="3000" required data-autosize>' . e($r['text']) . '</textarea></label>'
            . '<button class="btn btn-primary" type="submit">Сохранить' . ($f === 'published' ? ' и обновить сайт' : '') . '</button></form>'
            . '<div class="shot-box"><h3>Фото к отзыву</h3>'
            . (!empty($r['original']['href']) ? '<div class="shot-current"><img src="' . e('.' . $r['original']['href']) . '" alt="" loading="lazy"><p class="small muted">Гости открывают его по ссылке «Посмотреть оригинал».</p></div>' : '<p class="small muted">Сейчас у отзыва нет фото. Можно добавить скриншот или снимок переписки.</p>')
            . '<form method="post" action="./" enctype="multipart/form-data" class="upload-form">' . csrf_field() . '<input type="hidden" name="action" value="review"><input type="hidden" name="op" value="shot_add"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="filter" value="' . $f . '">'
            . '<label class="btn btn-ghost file-btn">' . icon('upload') . '<span>' . (!empty($r['original']['href']) ? 'Заменить фото' : 'Добавить фото') . '</span><input type="file" name="shot" accept="image/jpeg,image/png,image/webp" required data-autosubmit></label></form>'
            . (!empty($r['original']['href']) ? '<form method="post" action="./" data-confirm="Убрать фото из отзыва? Текст отзыва останется.">' . csrf_field() . '<input type="hidden" name="action" value="review"><input type="hidden" name="op" value="shot_remove"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="filter" value="' . $f . '"><button class="link-btn" type="submit">' . icon('trash') . ' Убрать фото</button></form>' : '')
            . '</div></details>';
        echo $btn('delete', 'Удалить', 'btn-danger-quiet', 'trash', 'Удалить отзыв от «' . $r['name'] . '» навсегда? Вернуть его будет нельзя.');
        echo '</div></article>';
    }
    ?>
<details class="card add-review"><summary><span class="btn btn-ghost"><?= icon('plus') ?><span>Добавить отзыв вручную</span></span></summary>
<p class="muted">Например, отзыв, который прислали в мессенджере. Он сразу появится на сайте.</p>
<form method="post" action="./" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="review"><input type="hidden" name="op" value="add">
<label class="field"><span>Имя</span><input name="name" maxlength="100" required></label>
<label class="field"><span>Текст отзыва</span><textarea name="text" rows="5" maxlength="3000" required data-autosize></textarea></label>
<label class="field"><span>Скриншот оригинала <em>(по желанию)</em></span><input type="file" name="shot" accept="image/jpeg,image/png,image/webp"><small>Гости смогут открыть его по ссылке «Посмотреть оригинал».</small></label>
<button class="btn btn-primary" type="submit">Опубликовать</button></form></details>
<?php
    return ob_get_clean();
}

function view_texts(): string {
    $content = vg_content(); $schema = vg_schema();
    $groups = []; foreach ($schema['fields'] as $f) $groups[$f['group']][] = $f;
    $open = (string)($_GET['g'] ?? '');
    ob_start();
    echo section_head('Тексты', 'Измените любой текст и нажмите «Сохранить». Сайт обновится сразу. Если стереть поле — вернётся исходный текст.');
    echo '<form method="post" action="./" class="texts-form" data-dirty-guard>' . csrf_field() . '<input type="hidden" name="action" value="texts">';
    $first = true;
    foreach ($groups as $name => $fields) {
        $edited = 0; foreach ($fields as $f) if (array_key_exists($f['key'], $content['texts'])) $edited++;
        echo '<details class="group card"' . (($open === '' ? $first : $open === $name) ? ' open' : '') . '><summary><h2>' . e($name) . '</h2>' . ($edited ? '<span class="count">изменено: ' . $edited . '</span>' : '') . '<span class="chev">' . icon('down') . '</span></summary><div class="group-body">';
        foreach ($fields as $f) {
            $val = vg_text_value($content, $f['key']);
            $id = 'f-' . preg_replace('/[^a-z0-9]/', '-', $f['key']);
            echo '<div class="field"><div class="field-head"><label for="' . $id . '">' . e($f['label']) . '</label><button type="button" class="link-btn" data-reset hidden>' . icon('undo') . ' Вернуть исходный</button></div>';
            if ($f['kind'] === 'text') echo '<textarea id="' . $id . '" name="t[' . e($f['key']) . ']" rows="2" maxlength="' . (int)$f['maxlen'] . '" data-default="' . e($f['default']) . '" data-autosize data-count>' . e($val) . '</textarea>';
            else echo '<input id="' . $id . '" name="t[' . e($f['key']) . ']" value="' . e($val) . '" maxlength="' . (int)$f['maxlen'] . '" data-default="' . e($f['default']) . '" data-count>';
            if ($f['hint']) echo '<small>' . e($f['hint']) . '</small>';
            echo '</div>';
        }
        echo '</div></details>';
        $first = false;
    }
    echo '<div class="savebar"><p class="muted" data-dirty-note>Изменений нет</p><button class="btn btn-primary" type="submit">Сохранить и опубликовать</button></div></form>';
    return ob_get_clean();
}

function view_photos(): string {
    $content = vg_content(); $schema = vg_schema();
    $items = vg_gallery_items($content);
    ob_start();
    echo section_head('Фотографии', 'Заменяйте фото и собирайте галерею. Загружайте JPG, PNG или WebP — размеры подберутся сами.');
    echo '<h2 class="h2">Фото на страницах</h2><div class="slots">';
    foreach ($schema['photos'] as $s) {
        $rec = $content['photos'][$s['id']] ?? [];
        $alt = $rec['alt'] ?? $s['alt'];
        $thumb = !empty($rec['upload']) ? '../images/uploads/' . $rec['upload']['base'] . '-360.' . ($rec['upload']['ext'] ?? 'webp') : vg_default_thumb($s['base']);
        echo '<article class="slot card"><div class="thumb"><img src="' . e($thumb) . '" alt="" loading="lazy"></div><div class="slot-body"><h3>' . e($s['label']) . '</h3>';
        echo '<form method="post" action="./" enctype="multipart/form-data" class="upload-form">' . csrf_field() . '<input type="hidden" name="action" value="photo"><input type="hidden" name="op" value="upload"><input type="hidden" name="slot" value="' . e($s['id']) . '">'
            . '<label class="btn btn-ghost file-btn">' . icon('upload') . '<span>Выбрать новое фото</span><input type="file" name="file" accept="image/jpeg,image/png,image/webp" required data-autosubmit></label></form>';
        if (!empty($rec['upload'])) echo '<form method="post" action="./" data-confirm="Вернуть исходное фото? Загруженное будет удалено.">' . csrf_field() . '<input type="hidden" name="action" value="photo"><input type="hidden" name="op" value="reset"><input type="hidden" name="slot" value="' . e($s['id']) . '"><button class="link-btn" type="submit">' . icon('undo') . ' Вернуть исходное</button></form>';
        echo '<form method="post" action="./" class="alt-form">' . csrf_field() . '<input type="hidden" name="action" value="photo"><input type="hidden" name="op" value="alt"><input type="hidden" name="slot" value="' . e($s['id']) . '"><label class="field"><span>Описание для незрячих и поисковиков</span><span class="inline"><input name="alt" value="' . e($alt) . '" maxlength="160"><button class="btn btn-quiet" type="submit">Сохранить</button></span></label></form>';
        echo '</div></article>';
    }
    echo '</div><p class="note">Главное фото на первом экране (с вырезанным фоном) заменяется отдельно — для него нужна отдельная обработка.</p>';

    echo '<h2 class="h2" id="gallery">Галерея «Жизнь в кадре» <span class="count">' . count($items) . '</span></h2>';
    echo '<p class="muted">Первые четыре фото — на большой сетке, остальные открываются кнопкой «Показать ещё». Порядок меняется стрелками.</p>';
    echo '<form method="post" action="./" enctype="multipart/form-data" class="card upload-card upload-form">' . csrf_field() . '<input type="hidden" name="action" value="gallery"><input type="hidden" name="op" value="add">'
        . '<label class="dropzone">' . icon('plus') . '<strong>Добавить фотографии</strong><span>Можно выбрать сразу несколько. Лимит сервера на один раз — ' . e(vg_upload_limit_text()) . '.</span><input type="file" name="files[]" accept="image/jpeg,image/png,image/webp" multiple required data-autosubmit></label></form>';
    echo '<ol class="gallery-admin">';
    $n = 0; $total = count($items);
    foreach ($items as $it) {
        $n++;
        $thumb = ($it['type'] ?? '') === 'upload' ? '../images/uploads/' . $it['upload']['base'] . '-360.' . ($it['upload']['ext'] ?? 'webp') : '../images/optimized/' . $it['id'] . '-360.webp';
        $id = e($it['id']);
        $act = function (string $op, string $extra = '') use ($id) { return '<input type="hidden" name="action" value="gallery"><input type="hidden" name="op" value="' . $op . '"><input type="hidden" name="id" value="' . $id . '">' . $extra; };
        echo '<li class="gcard' . ($n === 5 ? ' gcard-break' : '') . '"><div class="thumb"><img src="' . e($thumb) . '" alt="' . e($it['alt']) . '" loading="lazy"><span class="num">' . $n . '</span></div><div class="gbar">'
            . '<form method="post" action="./">' . csrf_field() . $act('move', '<input type="hidden" name="dir" value="left">') . '<button class="btn btn-icon" type="submit" aria-label="Сдвинуть раньше"' . ($n === 1 ? ' disabled' : '') . '>' . icon('left') . '</button></form>'
            . '<form method="post" action="./">' . csrf_field() . $act('move', '<input type="hidden" name="dir" value="right">') . '<button class="btn btn-icon" type="submit" aria-label="Сдвинуть позже"' . ($n === $total ? ' disabled' : '') . '>' . icon('right') . '</button></form>'
            . '<details class="gedit"><summary class="btn btn-icon" aria-label="Описание фото">' . icon('pencil') . '</summary><form method="post" action="./" class="gedit-pop">' . csrf_field() . $act('alt') . '<label class="field"><span>Описание</span><input name="alt" value="' . e($it['alt']) . '" maxlength="160"></label><button class="btn btn-primary" type="submit">Сохранить</button></form></details>'
            . '<form method="post" action="./" data-confirm="Убрать это фото из галереи?">' . csrf_field() . $act('delete') . '<button class="btn btn-icon btn-danger-quiet" type="submit" aria-label="Убрать фото">' . icon('trash') . '</button></form>'
            . '</div></li>';
    }
    echo '</ol>';
    $present = array_column($items, 'id'); $removed = [];
    foreach ($schema['gallery'] as $g) if (!in_array($g['id'], $present, true)) $removed[] = $g;
    if ($removed) {
        echo '<details class="card removed"><summary><span class="btn btn-ghost">Убранные исходные фото · ' . count($removed) . '</span></summary><ul class="removed-list">';
        foreach ($removed as $g) echo '<li><img src="../images/optimized/' . e($g['id']) . '-360.webp" alt="' . e($g['alt']) . '"><form method="post" action="./">' . csrf_field() . '<input type="hidden" name="action" value="gallery"><input type="hidden" name="op" value="restore"><input type="hidden" name="id" value="' . e($g['id']) . '"><button class="btn btn-quiet" type="submit">Вернуть</button></form></li>';
        echo '</ul></details>';
    }
    return ob_get_clean();
}

function view_settings(): string {
    $content = vg_content(); $s = vg_settings($content);
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $adminUrl = $proto . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/';
    $checks = [
        ['Страница сайта обновляется', is_writable(vg_root() . '/index.html') || is_writable(vg_root())],
        ['Загрузка фотографий работает', is_writable(vg_uploads_dir())],
        ['Отзывы и тексты хранятся приватно', is_writable(vg_dir())],
        ['Сжатие фотографий в WebP', function_exists('imagewebp')],
        ['Обработка фотографий (GD)', function_exists('imagecreatefromjpeg')],
    ];
    ob_start();
    echo section_head('Настройки', 'Контакты на сайте, пароль и проверка, что всё работает.');
    ?>
<section class="card"><h2>Контакты на сайте</h2><p class="muted">Показываются внизу страницы. Пустое поле — кнопка не появится.</p>
<form method="post" action="./" data-dirty-guard><?= csrf_field() ?><input type="hidden" name="action" value="settings">
<label class="field"><span>Ссылка на ВКонтакте</span><input name="vk" type="url" value="<?= e($s['vk']) ?>" placeholder="https://vk.ru/…"></label>
<label class="field"><span>Ссылка на MAX</span><input name="max" type="url" value="<?= e($s['max']) ?>" placeholder="https://max.ru/…"></label>
<label class="field"><span>Телефон <em>(по желанию)</em></span><input name="phone" value="<?= e($s['phone']) ?>" placeholder="+7 900 000-00-00" inputmode="tel"><small>Появится под кнопками, по нажатию с телефона набирается номер.</small></label>
<button class="btn btn-primary" type="submit">Сохранить контакты</button></form></section>

<section class="card"><h2>Как сюда заходить</h2>
<p>Адрес панели:</p><p class="url"><code><?= e($adminUrl) ?></code></p>
<p class="muted">Сохраните адрес в закладки. На сайте для посетителей ссылки сюда нет. Сессия живёт 12 часов, после нажатия «Выйти» вход потребует пароль.</p></section>

<section class="card"><h2>Сменить пароль</h2>
<form method="post" action="./" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="password">
<label class="field"><span>Текущий пароль</span><input type="password" name="current" required autocomplete="current-password"></label>
<label class="field"><span>Новый пароль</span><input type="password" name="new" required minlength="10" autocomplete="new-password"><small>Не короче 10 символов.</small></label>
<label class="field"><span>Повторите новый пароль</span><input type="password" name="again" required minlength="10" autocomplete="new-password"></label>
<button class="btn btn-primary" type="submit">Сменить пароль</button></form></section>

<section class="card"><h2>Проверка сервера</h2><ul class="checks">
<?php foreach ($checks as [$label, $ok]): ?><li class="<?= $ok ? 'ok' : 'bad' ?>"><?= icon($ok ? 'check' : 'x') ?><span><?= e($label) ?></span></li><?php endforeach; ?>
</ul><p class="small muted">Версия PHP <?= e(PHP_VERSION) ?>. Загрузка файлов: до <?= e(vg_upload_limit_text()) ?>. Копии страницы перед каждым изменением (последние <?= VG_BACKUPS_KEEP ?>) лежат в приватной папке данных.</p></section>
<?php
    return ob_get_clean();
}

/* ===================================================== router ===== */

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST' && !$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    flash('err', 'Файлы слишком большие для сервера (лимит ' . vg_upload_limit_text() . '). Загрузите фото поменьше или по одному.');
    go((string)($_GET['s'] ?? 'photos'));
}

$record = vg_admin_record();
$action = $method === 'POST' ? (string)($_POST['action'] ?? '') : '';

if ($method === 'POST' && !vg_csrf_ok()) {
    flash('err', 'Страница устарела. Обновите её и попробуйте снова.');
    header('Location: ./'); exit;
}

if (!$record) {
    if ($action === 'setup') {
        $code = vg_setup_code(); $new = (string)($_POST['new'] ?? '');
        if (!$code || !hash_equals($code, trim((string)($_POST['code'] ?? '')))) view_setup('Код первого входа не подходит.');
        elseif (strlen($new) < 10) view_setup('Пароль — не короче 10 символов.');
        elseif ($new !== (string)($_POST['again'] ?? '')) view_setup('Пароли не совпадают.');
        else { vg_set_password($new); vg_login_ok(); flash('ok', 'Пароль сохранён. Добро пожаловать!'); go('reviews'); }
        exit;
    }
    view_setup(); exit;
}

if (!vg_logged_in()) {
    if ($action === 'login') {
        $wait = vg_login_blocked();
        if ($wait > 0) { view_login('Слишком много попыток. Подождите ' . (int)ceil($wait / 60) . ' мин.', $wait); exit; }
        if (password_verify((string)($_POST['password'] ?? ''), $record['hash'])) { vg_login_ok(); go('reviews'); }
        vg_login_failed();
        usleep(400000);
        view_login('Пароль не подошёл. Проверьте раскладку и попробуйте ещё раз.'); exit;
    }
    view_login(null, vg_login_blocked()); exit;
}

if ($method === 'POST') {
    if ($action === 'logout') { $_SESSION = []; session_destroy(); header('Location: ./'); exit; }
    $handlers = ['review' => 'act_review', 'texts' => 'act_texts', 'photo' => 'act_photo', 'gallery' => 'act_gallery', 'settings' => 'act_settings', 'password' => 'act_password'];
    $section = ['review' => 'reviews', 'texts' => 'texts', 'photo' => 'photos', 'gallery' => 'photos', 'settings' => 'settings', 'password' => 'settings'][$action] ?? 'reviews';
    if (!isset($handlers[$action])) go('reviews');
    try { $handlers[$action](); }
    catch (RuntimeException $ex) { flash('err', $ex->getMessage()); go($section); }
    catch (Throwable $ex) { error_log('[volgina-admin] ' . $ex->getMessage()); flash('err', 'Что-то пошло не так. Попробуйте ещё раз.'); go($section); }
    exit;
}

$s = (string)($_GET['s'] ?? 'reviews');
if (!isset(sections()[$s])) $s = 'reviews';
$views = ['reviews' => 'view_reviews', 'texts' => 'view_texts', 'photos' => 'view_photos', 'settings' => 'view_settings'];
page(sections()[$s], $views[$s](), true, $s);
