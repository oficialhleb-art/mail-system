<?php
// mail-system/config_loader.php
// ============================================
// ЕДИНАЯ ТОЧКА ЗАГРУЗКИ КОНФИГОВ
// Расположение: /mail-system/config_loader.php
// ============================================

// ================ НАЧАЛО БЛОКА ПРОВЕРКИ ДОСТУПА ================
// Проверка прямого доступа
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    header('HTTP/1.0 403 Forbidden');
    die('Прямой доступ запрещен');
}
// ================ КОНЕЦ БЛОКА ПРОВЕРКИ ДОСТУПА ================

// ================ НАЧАЛО БЛОКА ЗАГРУЗКИ КОНФИГУРАЦИЙ ================
// Загружаем все конфигурации
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/imap.php';
require_once __DIR__ . '/config/smtp.php';
// ================ КОНЕЦ БЛОКА ЗАГРУЗКИ КОНФИГУРАЦИЙ ================

// ================ НАЧАЛО БЛОКА ОБЩИХ НАСТРОЕК ================
// Общие настройки
define('SITE_ROOT', __DIR__);
define('DEBUG_MODE', true);
// ================ КОНЕЦ БЛОКА ОБЩИХ НАСТРОЕК ================
// Подключаем конфигурацию модулей
require_once __DIR__ . '/modules/config.php';

// Подключаем автозагрузку классов
spl_autoload_register(function ($class) {
    $file = __DIR__ . '/core/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});
?>