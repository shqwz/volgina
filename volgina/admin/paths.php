<?php
declare(strict_types=1);

// Private data (reviews, texts, password hash, sessions, backups) lives outside the public web root.
function volgina_data_dir(): string {
    $env = getenv('VOLGINA_REVIEW_DATA_DIR');
    if ($env) return rtrim($env, '/');
    // Local development with `php -S`: keep data inside the project, git-ignored.
    if (PHP_SAPI === 'cli-server') return dirname(__DIR__, 2) . '/.dev-data';
    // /home/lazurin/public_html/volgina/admin -> /home/lazurin/volgina-review-data
    return dirname(__DIR__, 3) . '/volgina-review-data';
}
