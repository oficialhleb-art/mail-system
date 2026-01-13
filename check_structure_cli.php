<?php
// mail-system/check_structure_cli.php
// Проверка структуры через командную строку

if (php_sapi_name() !== 'cli') {
    die("Этот скрипт работает только в командной строке!\n");
}

echo "🔍 Проверка структуры модулей...\n";
echo "===============================\n\n";

$base_dir = __DIR__;

// Проверяем основные папки
$required_dirs = [
    'modules',
    'modules/admin',
    'modules/admin/views',
    'modules/admin/controllers',
    'modules/api',
    'modules/api/v1',
    'modules/api/endpoints',
    'modules/services',
    'modules/services/mail',
    'modules/services/google',
    'modules/services/telegram',
    'core',
    'cron',
    'assets',
    'assets/css',
    'assets/js',
    'assets/images'
];

echo "📁 ПРОВЕРКА ПАПОК:\n";
echo "-----------------\n";

$dir_errors = 0;
foreach ($required_dirs as $dir) {
    $full_path = $base_dir . '/' . $dir;
    
    if (is_dir($full_path)) {
        echo "✅ $dir\n";
    } else {
        echo "❌ $dir - ОТСУТСТВУЕТ!\n";
        $dir_errors++;
    }
}

echo "\n";

// Проверяем обязательные файлы
$required_files = [
    'core/Database.php',
    'core/AddressManager.php',
    'modules/admin/views/dashboard.php',
    'modules/admin/controllers/orders.php',
    'modules/admin/views/orders.php',
    'modules/api/v1/router.php',
    'modules/services/mail/parser.php',
    'modules/services/google/sync.php',
    'modules/services/telegram/bot.php',
    'modules/config.php',
    'index.php'
];

echo "📄 ПРОВЕРКА ФАЙЛОВ:\n";
echo "------------------\n";

$file_errors = 0;
foreach ($required_files as $file) {
    $full_path = $base_dir . '/' . $file;
    
    if (file_exists($full_path)) {
        $size = filesize($full_path);
        echo "✅ $file (" . round($size/1024, 2) . " KB)\n";
    } else {
        echo "❌ $file - ОТСУТСТВУЕТ!\n";
        $file_errors++;
    }
}

echo "\n";

// Проверяем рабочие файлы
echo "🔧 РАБОЧИЕ ФАЙЛЫ:\n";
echo "-----------------\n";

$working_files = [
    'admin_legacy.php',
    'config_loader.php',
    'mail_parser.php',
    'addresses.json',
    'sync_to_sheets_simple.php'
];

foreach ($working_files as $file) {
    $full_path = $base_dir . '/' . $file;
    
    if (file_exists($full_path)) {
        $size = filesize($full_path);
        echo "✅ $file (" . round($size/1024, 2) . " KB)\n";
    } else {
        echo "⚠️  $file - Отсутствует (возможно удален)\n";
    }
}

echo "\n";

// Итоги
echo "📊 ИТОГИ:\n";
echo "---------\n";
echo "Папок проверено: " . count($required_dirs) . "\n";
echo "Ошибок папок: $dir_errors\n";
echo "Файлов проверено: " . count($required_files) . "\n";
echo "Ошибок файлов: $file_errors\n";
echo "Всего ошибок: " . ($dir_errors + $file_errors) . "\n\n";

if ($dir_errors + $file_errors === 0) {
    echo "🎉 СТРУКТУРА КОРРЕКТНАЯ!\n";
    echo "Система готова к работе.\n";
} else {
    echo "⚠️  ЕСТЬ ОШИБКИ В СТРУКТУРЕ!\n";
    echo "Создайте отсутствующие папки и файлы.\n";
}

// Предлагаем создать отсутствующие папки
if ($dir_errors > 0) {
    echo "\nХотите создать отсутствующие папки? (y/n): ";
    $handle = fopen("php://stdin", "r");
    $input = trim(fgets($handle));
    
    if (strtolower($input) === 'y') {
        foreach ($required_dirs as $dir) {
            $full_path = $base_dir . '/' . $dir;
            
            if (!is_dir($full_path)) {
                mkdir($full_path, 0755, true);
                echo "Создана папка: $dir\n";
            }
        }
        echo "✅ Папки созданы!\n";
    }
}
?>