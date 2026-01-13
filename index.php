<?php
// mail-system/index.php
// ============================================
// ГЛАВНЫЙ ФАЙЛ СИСТЕМЫ (РЕДИРЕКТ НА НОВУЮ АДМИНКУ)
// ============================================

// Если пользователь хочет получить доступ к старой админке
if (isset($_GET['legacy']) && $_GET['legacy'] == '1') {
    require_once 'admin_legacy.php';
    exit;
}

// Включаем загрузку конфигурации
require_once 'config_loader.php';

// Начинаем сессию
session_start();

// Если пользователь не авторизован - на логин
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: config/auth/login.php');
    exit;
}

// Подключаем ядро системы
require_once 'core/Database.php';
require_once 'core/AddressManager.php';

// Определяем маршрут
$route = $_GET['route'] ?? 'dashboard';

// Маршрутизация
switch ($route) {
    case 'dashboard':
        require_once 'modules/admin/views/dashboard.php';
        break;
        
    case 'orders':
        require_once 'modules/admin/controllers/orders.php';
        break;
        
    case 'addresses':
        require_once 'modules/admin/controllers/addresses.php';
        break;
        
    case 'sync':
        require_once 'modules/admin/controllers/sync.php';
        break;
        
    case 'api':
        require_once 'modules/api/v1/router.php';
        break;
        
    default:
        require_once 'modules/admin/views/dashboard.php';
        break;
}
?>