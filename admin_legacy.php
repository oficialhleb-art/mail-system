<?php
// ===== ЗАЩИТА ДОСТУПА =====
session_start();

// Проверяем, авторизован ли пользователь
if (empty($_SESSION['admin_logged_in'])) {
    // Перенаправляем на страницу входа с АБСОЛЮТНЫМ путем
    header('Location: /mail-system/config/auth/login.php');
    exit;
}

// ===== ВЫХОД ИЗ СИСТЕМЫ =====
if (isset($_GET['logout'])) {
    // Удаляем все данные сессии
    session_unset();
    session_destroy();
    
    // Перенаправляем на страницу входа с АБСОЛЮТНЫМ путем
    header('Location: /mail-system/config/auth/login.php');
    exit;
}

// ===== КОНФИГУРАЦИЯ =====
require_once __DIR__ . '/config_loader.php'; // <-- ВМЕСТО mail_config.php

// 1. ПОДКЛЮЧЕНИЕ К БАЗЕ
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", 
        DB_USER, 
        DB_PASS
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Ошибка подключения к БД: " . $e->getMessage());
}

// 2. ЗАГРУЗКА АДРЕСОВ (JSON файл)
$addressesFile = __DIR__ . '/addresses.json';
$addresses = [];
if (file_exists($addressesFile)) {
    $addressesData = json_decode(file_get_contents($addressesFile), true);
    $addresses = $addressesData['addresses'] ?? [];
}

// 3. ОБРАБОТКА AJAX-ЗАПРОСОВ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'search_addresses') {
        $query = mb_strtolower(trim($_POST['query'] ?? ''));
        $type = $_POST['type'] ?? 'both';
        
        $results = [];
        if (!empty($query)) {
            foreach ($addresses as $addr) {
                if ($addr['type'] !== $type && $addr['type'] !== 'both' && $type !== 'both') continue;
                
                $text = mb_strtolower($addr['name'] . ' ' . $addr['full_address']);
                if (strpos($text, $query) !== false) {
                    $results[] = [
                        'name' => $addr['name'],
                        'full' => $addr['name'] . "\n" . $addr['full_address']
                    ];
                    if (count($results) >= 5) break;
                }
            }
        }
        echo json_encode(['success' => true, 'results' => $results], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if ($_POST['action'] === 'save_new_address') {
        $name = trim($_POST['name'] ?? 'Неизвестно');
        $full_address = trim($_POST['full_address'] ?? '');
        $type = $_POST['type'] ?? 'both';
        $metro = trim($_POST['metro'] ?? '');
        $contact = trim($_POST['contact'] ?? '');
        
        if (empty($full_address)) {
            echo json_encode(['success' => false, 'message' => 'Адрес обязателен']);
            exit;
        }
        
        // Формируем полный адрес для сохранения
        $fullAddressText = $full_address;
        if ($metro) {
            $fullAddressText .= "\n" . $metro;
        }
        if ($contact) {
            $fullAddressText .= "\nКонтакт: " . $contact;
        }
        
        // Генерируем новый ID
        $newId = 1;
        if (!empty($addresses)) {
            $ids = array_column($addresses, 'id');
            $newId = max($ids) + 1;
        }
        
        // Добавляем новый адрес
        $newAddress = [
            'id' => $newId,
            'name' => $name,
            'full_address' => $fullAddressText,
            'type' => $type,
            'metro' => $metro,
            'contact' => $contact
        ];
        
        $addresses[] = $newAddress;
        
        // Сохраняем в файл
        $dataToSave = ['addresses' => $addresses];
        if (file_put_contents($addressesFile, json_encode($dataToSave, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
            echo json_encode(['success' => true, 'message' => 'Адрес сохранен', 'address' => $newAddress]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Ошибка сохранения файла']);
        }
        exit;
    }
    
    if ($_POST['action'] === 'update_field') {
        $orderId = (int)$_POST['order_id'];
        $field = $_POST['field'];
        $value = $_POST['value'];
        
        $allowedFields = ['from_location', 'to_location', 'price', 'expense', 'courier', 'notes', 'status'];
        
        if (!in_array($field, $allowedFields)) {
            echo json_encode(['success' => false, 'message' => 'Неверное поле']);
            exit;
        }
        
        try {
            if ($field === 'status') {
                if ($value === 'delivered') {
                    $stmt = $pdo->prepare("UPDATE orders SET status = ?, delivered_at = NOW() WHERE id = ?");
                } else {
                    $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
                }
                $stmt->execute([$value, $orderId]);
                
                // Автоматическая отправка email при смене статуса
                sendStatusEmail($orderId, $value, $pdo);
                echo json_encode(['success' => true, 'message' => 'Статус обновлен и уведомление отправлено']);
            } else {
                $stmt = $pdo->prepare("UPDATE orders SET $field = ? WHERE id = ?");
                $stmt->execute([$value, $orderId]);
                echo json_encode(['success' => true, 'message' => 'Обновлено']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'get_order_details') {
        $orderId = (int)$_POST['order_id'];
        
        try {
            $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($order) {
                echo json_encode(['success' => true, 'order' => $order]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Заказ не найден']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()]);
        }
        exit;
    }
}

// Функция отправки email при смене статуса
function sendStatusEmail($orderId, $newStatus, $pdo) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order || empty($order['customer_email'])) {
            return false;
        }
        
        // Формируем тему письма
        $statusLabels = [
            'new' => 'Новый',
            'accepted' => 'Принят в работу',
            'received' => 'Получен курьером',
            'delivered' => 'Доставлен',
            'cancelled' => 'Отменен'
        ];
        
        $statusText = $statusLabels[$newStatus] ?? $newStatus;
        
        // Используем тему письма, если есть, иначе номер заказа
        $orderSubject = $order['order_subject'] ?: ('Заказ #' . $order['id']);
        
        // Убираем [ORD-...] из темы если есть
        $cleanSubject = preg_replace('/\[ORD-\d+-\d+\]\s*/', '', $orderSubject);
        $subject = "Статус вашего заказа: " . $statusText . " | " . $cleanSubject;
        
        $message = "<html><body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>";
        $message .= "<div style='max-width: 600px; margin: 0 auto; padding: 20px; background: #f9f9f9; border-radius: 8px;'>";
        
        $message .= "<h2 style='color: #2d3748; border-bottom: 2px solid #4299e1; padding-bottom: 10px;'>";
        $message .= "Ваш заказ: " . htmlspecialchars($cleanSubject) . "</h2>";
        
        // Откуда
        $fromLocation = $order['from_location'] ?? '';
        $message .= "<div style='margin: 15px 0; padding: 15px; background: white; border-radius: 5px; border-left: 4px solid #4299e1;'>";
        $message .= "<h3 style='margin-top: 0; color: #2d3748;'>📦 Откуда:</h3>";
        $message .= "<p style='white-space: pre-line; margin: 10px 0;'>" . nl2br(htmlspecialchars($fromLocation)) . "</p>";
        $message .= "</div>";
        
        // Куда
        $toLocation = $order['to_location'] ?? '';
        $message .= "<div style='margin: 15px 0; padding: 15px; background: white; border-radius: 5px; border-left: 4px solid #38a169;'>";
        $message .= "<h3 style='margin-top: 0; color: #2d3748;'>📍 Куда:</h3>";
        $message .= "<p style='white-space: pre-line; margin: 10px 0;'>" . nl2br(htmlspecialchars($toLocation)) . "</p>";
        $message .= "</div>";
        
        $message .= "<hr style='border: none; border-top: 1px solid #e2e8f0; margin: 20px 0;'>";
        
        // Сообщение по статусу
        switch ($newStatus) {
            case 'accepted':
                $statusMessage = "<div style='padding: 15px; background: rgba(56, 161, 105, 0.1); border-radius: 5px; border-left: 4px solid #38a169;'>";
                $statusMessage .= "<h3 style='margin-top: 0; color: #2d3748;'>✅ Ваш заказ принят в работу</h3>";
                $statusMessage .= "<p>Мы начали обработку вашего заказа. Курьер свяжется с вами для уточнения деталей.</p>";
                $statusMessage .= "</div>";
                break;
                
            case 'received':
                $statusMessage = "<div style='padding: 15px; background: rgba(66, 153, 225, 0.1); border-radius: 5px; border-left: 4px solid #4299e1;'>";
                $statusMessage .= "<h3 style='margin-top: 0; color: #2d3748;'>🚚 Ваш заказ получен курьером</h3>";
                $statusMessage .= "<p>Заказ в пути к точке назначения. Ожидайте доставки в ближайшее время.</p>";
                $statusMessage .= "</div>";
                break;
                
            case 'delivered':
                $priceText = $order['price'] ? 
                    "Стоимость доставки составляет: <strong style='color: #2d3748;'>" . number_format($order['price'], 2) . " ₽</strong>." : 
                    "Стоимость доставки уточняйте у менеджера.";
                
                $statusMessage = "<div style='padding: 15px; background: rgba(159, 122, 234, 0.1); border-radius: 5px; border-left: 4px solid #9f7aea;'>";
                $statusMessage .= "<h3 style='margin-top: 0; color: #2d3748;'>🎉 Ваш заказ доставлен!</h3>";
                $statusMessage .= "<p>" . $priceText . "</p>";
                $statusMessage .= "</div>";
                break;
                
            case 'cancelled':
                $statusMessage = "<div style='padding: 15px; background: rgba(229, 62, 62, 0.1); border-radius: 5px; border-left: 4px solid #e53e3e;'>";
                $statusMessage .= "<h3 style='margin-top: 0; color: #2d3748;'>❌ Ваш заказ отменен</h3>";
                $statusMessage .= "<p>По всем вопросам обращайтесь к менеджеру по телефону или email.</p>";
                $statusMessage .= "</div>";
                break;
                
            default:
                $statusMessage = "<div style='padding: 15px; background: rgba(160, 174, 192, 0.1); border-radius: 5px;'>";
                $statusMessage .= "<p>Статус вашего заказа изменен.</p>";
                $statusMessage .= "</div>";
        }
        
        $message .= $statusMessage;
        
        // Если есть примечания
        if (!empty($order['notes'])) {
            $message .= "<div style='margin: 15px 0; padding: 15px; background: rgba(214, 158, 46, 0.1); border-radius: 5px; border-left: 4px solid #d69e2e;'>";
            $message .= "<h4 style='margin-top: 0; color: #2d3748;'>📝 Примечания:</h4>";
            $message .= "<p style='white-space: pre-line;'>" . nl2br(htmlspecialchars($order['notes'])) . "</p>";
            $message .= "</div>";
        }
        
        $message .= "<hr style='border: none; border-top: 1px solid #e2e8f0; margin: 20px 0;'>";
        
        $message .= "<div style='text-align: center; color: #718096; font-size: 0.9em;'>";
        $message .= "<p><em>Номер заказа: #" . $order['id'] . "</em></p>";
        $message .= "<p><em>Дата создания: " . $order['created_at'] . "</em></p>";
        $message .= "</div>";
        
        $message .= "</div>";
        $message .= "</body></html>";
        
        // Отправка письма
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=utf-8',
            'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>',
            'Reply-To: ' . MAIL_FROM,
            'X-Mailer: PHP/' . phpversion()
        ];
        
        if (mail($order['customer_email'], '=?UTF-8?B?' . base64_encode($subject) . '?=', $message, implode("\r\n", $headers))) {
            return true;
        } else {
            error_log('Ошибка отправки email для заказа #' . $orderId);
            return false;
        }
        
    } catch (Exception $e) {
        error_log('Ошибка send_status_email: ' . $e->getMessage());
        return false;
    }
}

// 4. ПОЛУЧЕНИЕ ЗАКАЗОВ С ФИЛЬТРАМИ
$whereConditions = [];
$params = [];

if (!empty($_GET['client_email'])) {
    $whereConditions[] = "customer_email LIKE ?";
    $params[] = '%' . $_GET['client_email'] . '%';
}
if (!empty($_GET['date_from'])) {
    $whereConditions[] = "DATE(created_at) >= ?";
    $params[] = $_GET['date_from'];
}
if (!empty($_GET['date_to'])) {
    $whereConditions[] = "DATE(created_at) <= ?";
    $params[] = $_GET['date_to'];
}
if (!empty($_GET['status']) && $_GET['status'] !== 'all') {
    $whereConditions[] = "status = ?";
    $params[] = $_GET['status'];
}

$whereSQL = $whereConditions ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

try {
    $sql = "SELECT * FROM orders $whereSQL ORDER BY created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die("Ошибка загрузки заказов: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление заказами | GOOD POST</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* СТИЛИ ОСТАЮТСЯ ТАКИМИ ЖЕ КАК В admin_advanced.php */
        :root {
            --steel-dark: #2d3748;
            --steel-medium: #4a5568;
            --steel-light: #718096;
            --steel-accent: #4299e1;
            --steel-success: #38a169;
            --steel-warning: #d69e2e;
            --steel-danger: #e53e3e;
            --steel-purple: #9f7aea;
            --steel-gray: #a0aec0;
            --steel-bg: #1a202c;
            --steel-card: #2d3748;
            --steel-border: #4a5568;
            --steel-text: #e2e8f0;
        }
        
        * { 
            box-sizing: border-box; 
            margin: 0; 
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
        }
        
        body { 
            background: var(--steel-bg); 
            color: var(--steel-text);
            padding: 20px;
        }
        
        .container {
            max-width: 2000px;
            margin: 0 auto;
            background: var(--steel-card);
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid var(--steel-border);
        }
        
        .header {
            background: var(--steel-dark);
            padding: 20px 30px;
            border-bottom: 1px solid var(--steel-border);
        }
        
        .header-title {
            font-size: 1.8rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .controls-panel {
            padding: 20px 30px;
            background: rgba(45, 55, 72, 0.7);
            border-bottom: 1px solid var(--steel-border);
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .filter-input, .filter-select {
            width: 100%;
            background: var(--steel-dark);
            border: 1px solid var(--steel-border);
            border-radius: 6px;
            padding: 10px;
            color: var(--steel-text);
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--steel-accent);
            color: white;
        }
        
        .btn:hover {
            background: #3182ce;
        }
        
        .table-container {
            padding: 0 30px 30px;
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th {
            background: var(--steel-dark);
            color: var(--steel-text);
            font-weight: 600;
            padding: 15px 12px;
            text-align: left;
            border-bottom: 2px solid var(--steel-border);
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        td {
            padding: 12px;
            border-bottom: 1px solid var(--steel-border);
            vertical-align: top;
        }
        
        tr:hover {
            background: rgba(66, 153, 225, 0.05);
        }
        
        .editable-cell {
            position: relative;
            cursor: pointer;
            min-height: 40px;
        }
        
        .editable-cell:hover {
            background: rgba(66, 153, 225, 0.1);
        }
        
        /* СТАТУС */
        .status-select {
            width: 100%;
            background: var(--steel-dark);
            color: var(--steel-text);
            border: 1px solid var(--steel-border);
            border-radius: 6px;
            padding: 8px;
            font-size: 14px;
            cursor: pointer;
        }
        
        .status-select:focus {
            outline: none;
            border-color: var(--steel-accent);
        }
        
        .status-badge {
            display: inline-block;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            user-select: none;
            width: 100%;
            text-align: center;
            border: 1px solid transparent;
        }
        
        .status-new { background: rgba(229, 62, 62, 0.2); color: #fc8181; border-color: rgba(229, 62, 62, 0.3); }
        .status-accepted { background: rgba(56, 161, 105, 0.2); color: #68d391; border-color: rgba(56, 161, 105, 0.3); }
        .status-received { background: rgba(66, 153, 225, 0.2); color: #90cdf4; border-color: rgba(66, 153, 225, 0.3); }
        .status-delivered { background: rgba(159, 122, 234, 0.2); color: #b794f4; border-color: rgba(159, 122, 234, 0.3); }
        .status-cancelled { background: rgba(160, 174, 192, 0.2); color: #cbd5e0; border-color: rgba(160, 174, 192, 0.3); }
        
        /* АВТОДОПОЛНЕНИЕ */
        .autocomplete-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: var(--steel-dark);
            border: 1px solid var(--steel-border);
            border-radius: 6px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1001;
            display: none;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        
        .autocomplete-item {
            padding: 10px 12px;
            cursor: pointer;
            border-bottom: 1px solid var(--steel-border);
            font-size: 0.9em;
            line-height: 1.4;
            white-space: pre-line;
        }
        
        .autocomplete-item:hover {
            background: var(--steel-accent);
            color: white;
        }
        
        .autocomplete-item:last-child {
            border-bottom: none;
        }
        
        /* ПОЛЯ ВВОДА */
        .edit-input, .edit-textarea {
            width: 100%;
            min-height: 40px;
            padding: 10px;
            background: var(--steel-dark);
            color: var(--steel-text);
            border: 1px solid var(--steel-border);
            border-radius: 6px;
            resize: vertical;
            font-size: 14px;
        }
        
        .edit-textarea {
            min-height: 80px;
        }
        
        .edit-buttons {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }
        
        .edit-buttons .btn {
            flex: 1;
            padding: 8px;
            font-size: 14px;
        }
        
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 18px;
            border-radius: 6px;
            font-weight: 500;
            z-index: 9999;
            min-width: 250px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.2);
            animation: slideIn 0.3s ease;
        }
        
        .notification.success {
            background: rgba(56, 161, 105, 0.95);
            color: white;
        }
        
        .notification.error {
            background: rgba(229, 62, 62, 0.95);
            color: white;
        }
        
        .section-title {
            color: var(--steel-accent);
            font-size: 0.85em;
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .finances-cell, .logistics-cell {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .field-group {
            margin-bottom: 5px;
        }
        
        .btn-copy-task {
            margin-top: 5px;
            padding: 4px 8px;
            font-size: 0.8em;
            background: var(--steel-purple);
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .btn-copy-task:hover {
            background: #805ad5;
        }
        
        .save-new-address-btn {
            margin-top: 5px;
            padding: 6px 12px;
            font-size: 12px;
            background: var(--steel-success);
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        
        .save-new-address-btn:hover {
            background: #2f855a;
        }
        
        /* МОДАЛЬНОЕ ОКНО ДЛЯ АДРЕСОВ */
        .address-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.7);
            z-index: 10000;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease;
        }
        
        .address-modal-content {
            background: var(--steel-card);
            padding: 25px;
            border-radius: 12px;
            max-width: 500px;
            width: 90%;
            border: 1px solid var(--steel-border);
            box-shadow: 0 10px 25px rgba(0,0,0,0.3);
        }
        
        .address-modal-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        .address-modal h3 {
            color: var(--steel-accent);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .address-modal label {
            display: block;
            margin-bottom: 5px;
            font-size: 0.9em;
            font-weight: 600;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        .pre-line {
            white-space: pre-line;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-title" style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <i class="fas fa-cog"></i>
                    <span>Управление заказами | GOOD POST</span>
                </div>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 0.9em; color: var(--steel-gray);">
                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Администратор'); ?>
                    </span>
                    <a href="?logout=1" class="btn" style="background: var(--steel-danger); padding: 8px 15px; font-size: 0.9em;">
                        <i class="fas fa-sign-out-alt"></i> Выйти
                    </a>
                </div>
            </div>
        </div>
        
        <div class="controls-panel">
            <form method="GET" style="display: contents;">
                <div class="filter-grid">
                    <div>
                        <input type="text" name="client_email" value="<?php echo htmlspecialchars($_GET['client_email'] ?? ''); ?>" 
                               class="filter-input" placeholder="Email клиента">
                    </div>
                    <div>
                        <input type="date" name="date_from" value="<?php echo htmlspecialchars($_GET['date_from'] ?? ''); ?>" 
                               class="filter-input">
                    </div>
                    <div>
                        <input type="date" name="date_to" value="<?php echo htmlspecialchars($_GET['date_to'] ?? ''); ?>" 
                               class="filter-input">
                    </div>
                    <div>
                        <select name="status" class="filter-select">
                            <option value="all">Все статусы</option>
                            <option value="new" <?php echo ($_GET['status'] ?? '') == 'new' ? 'selected' : ''; ?>>Новый</option>
                            <option value="accepted" <?php echo ($_GET['status'] ?? '') == 'accepted' ? 'selected' : ''; ?>>Принят</option>
                            <option value="received" <?php echo ($_GET['status'] ?? '') == 'received' ? 'selected' : ''; ?>>Получен</option>
                            <option value="delivered" <?php echo ($_GET['status'] ?? '') == 'delivered' ? 'selected' : ''; ?>>Доставлен</option>
                            <option value="cancelled" <?php echo ($_GET['status'] ?? '') == 'cancelled' ? 'selected' : ''; ?>>Отменен</option>
                        </select>
                    </div>
                    <div>
                        <button type="submit" class="btn" style="width: 100%;">
                            <i class="fas fa-filter"></i> Применить
                        </button>
                    </div>
                </div>
            </form>
            
            <div style="display: flex; gap: 10px; margin-top: 15px;">
                <button type="button" class="btn" onclick="openExportModal()">
                    <i class="fas fa-file-export"></i> Экспорт в Excel
                </button>
                <a href="?" class="btn" style="background: var(--steel-medium);">
                    <i class="fas fa-ban"></i> Сбросить
                </a>
            </div>
        </div>
        
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Клиент</th>
                        <th>Тема</th>
                        <th>Откуда</th>
                        <th>Куда</th>
                        <th>Финансы</th>
                        <th>Логистика</th>
                        <th>Примечания</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 40px;">
                            <div>Заказы не найдены</div>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($orders as $order): ?>
                    <tr>
                        <td>
                            <strong>#<?php echo $order['id']; ?></strong>
                            <br>
                            <button type="button" class="btn-copy-task" 
                                    onclick="copyTaskToMessenger(<?php echo $order['id']; ?>)"
                                    title="Скопировать задание для курьера">
                                <i class="fas fa-copy"></i> Копировать задание
                            </button>
                        </td>
                        <td>
                            <div style="font-weight: 600;"><?php echo htmlspecialchars($order['customer_name'] ?: 'Не указано'); ?></div>
                            <div style="font-size:0.85em;"><?php echo htmlspecialchars($order['customer_email']); ?></div>
                        </td>
                        <td>
                            <div class="pre-line"><?php echo htmlspecialchars($order['order_subject'] ?: '-'); ?></div>
                        </td>
                        
                        <!-- Откуда -->
                        <td class="editable-cell" data-field="from_location" data-order-id="<?php echo $order['id']; ?>">
                            <div class="editable-content pre-line">
                                <?php echo htmlspecialchars($order['from_location'] ?: '—'); ?>
                            </div>
                        </td>
                        
                        <!-- Куда -->
                        <td class="editable-cell" data-field="to_location" data-order-id="<?php echo $order['id']; ?>">
                            <div class="editable-content pre-line">
                                <?php echo htmlspecialchars($order['to_location'] ?: '—'); ?>
                            </div>
                        </td>
                        
                        <!-- Финансы -->
                        <td>
                            <div class="field-group">
                                <div class="section-title">Цена</div>
                                <div class="editable-cell" data-field="price" data-order-id="<?php echo $order['id']; ?>">
                                    <div class="editable-content">
                                        <?php echo $order['price'] ? number_format($order['price'], 2) . ' ₽' : '—'; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="field-group">
                                <div class="section-title">Расход</div>
                                <div class="editable-cell" data-field="expense" data-order-id="<?php echo $order['id']; ?>">
                                    <div class="editable-content">
                                        <?php echo $order['expense'] ? number_format($order['expense'], 2) . ' ₽' : '—'; ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        
                        <!-- Логистика -->
                        <td>
                            <div class="field-group">
                                <div class="section-title">Курьер</div>
                                <div class="editable-cell" data-field="courier" data-order-id="<?php echo $order['id']; ?>">
                                    <div class="editable-content">
                                        <?php echo htmlspecialchars($order['courier'] ?: '—'); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="field-group">
                                <div class="section-title">Статус</div>
                                <div class="status-select-container" data-order-id="<?php echo $order['id']; ?>">
                                    <div class="status-badge status-<?php echo $order['status']; ?>">
                                        <?php 
                                        $statusLabels = [
                                            'new' => 'Новый',
                                            'accepted' => 'Принят', 
                                            'received' => 'Получен',
                                            'delivered' => 'Доставлен',
                                            'cancelled' => 'Отменен'
                                        ];
                                        echo $statusLabels[$order['status']] ?? $order['status'];
                                        ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        
                        <!-- Примечания -->
                        <td class="editable-cell" data-field="notes" data-order-id="<?php echo $order['id']; ?>">
                            <div class="editable-content pre-line">
                                <?php echo htmlspecialchars($order['notes'] ?: '—'); ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        // 1. ФУНКЦИЯ ОТОБРАЖЕНИЯ УВЕДОМЛЕНИЙ
        function showNotification(message, type = 'success') {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.innerHTML = message;
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.opacity = '0';
                setTimeout(() => notification.remove(), 300);
            }, 3000);
        }
        
        // 2. РЕДАКТИРОВАНИЕ ЯЧЕЕК
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.editable-cell').forEach(cell => {
                cell.addEventListener('click', function(e) {
                    if (e.target.closest('.edit-buttons')) return;
                    
                    const field = this.dataset.field;
                    const orderId = this.dataset.orderId;
                    const currentContent = this.querySelector('.editable-content').innerHTML.trim();
                    
                    let inputElement;
                    if (field === 'from_location' || field === 'to_location' || field === 'notes') {
                        inputElement = document.createElement('textarea');
                        inputElement.className = 'edit-textarea';
                        inputElement.value = currentContent.replace(/<br\s*\/?>/gi, '\n');
                        inputElement.rows = field === 'notes' ? 3 : 4;
                    } else {
                        inputElement = document.createElement('input');
                        inputElement.type = 'text';
                        inputElement.className = 'edit-input';
                        inputElement.value = currentContent.replace(/&nbsp;|₽/g, '').trim();
                    }
                    
                    const buttonsDiv = document.createElement('div');
                    buttonsDiv.className = 'edit-buttons';
                    
                    const saveBtn = document.createElement('button');
                    saveBtn.className = 'btn';
                    saveBtn.style.background = 'var(--steel-success)';
                    saveBtn.innerHTML = '<i class="fas fa-check"></i> Сохранить';
                    
                    const cancelBtn = document.createElement('button');
                    cancelBtn.className = 'btn';
                    cancelBtn.style.background = 'var(--steel-medium)';
                    cancelBtn.innerHTML = '<i class="fas fa-times"></i> Отмена';
                    
                    buttonsDiv.appendChild(saveBtn);
                    buttonsDiv.appendChild(cancelBtn);
                    
                    this.innerHTML = '';
                    this.appendChild(inputElement);
                    this.appendChild(buttonsDiv);
                    inputElement.focus();
                    
                    if (field === 'from_location' || field === 'to_location') {
                        inputElement.addEventListener('keydown', function(e) {
                            if (e.key === 'Enter' && !e.shiftKey) {
                                e.preventDefault();
                                saveBtn.click();
                            }
                        });
                        
                        // Кнопка "Сохранить как новый адрес"
                        const saveAddressBtn = document.createElement('button');
                        saveAddressBtn.type = 'button';
                        saveAddressBtn.className = 'save-new-address-btn';
                        saveAddressBtn.innerHTML = '<i class="fas fa-map-marker-alt"></i> Сохранить как новый адрес';
                        buttonsDiv.appendChild(saveAddressBtn);
                        
                        saveAddressBtn.addEventListener('click', function() {
                            saveNewAddress(inputElement.value, field);
                        });
                        
                        setupAutocomplete(inputElement, field);
                    }
                    
                    saveBtn.addEventListener('click', async function() {
                        const value = inputElement.value.trim();
                        
                        const formData = new FormData();
                        formData.append('action', 'update_field');
                        formData.append('order_id', orderId);
                        formData.append('field', field);
                        formData.append('value', value);
                        
                        try {
                            const response = await fetch('', {
                                method: 'POST',
                                body: formData
                            });
                            const result = await response.json();
                            
                            if (result.success) {
                                let displayValue = value;
                                if (field === 'price' || field === 'expense') {
                                    displayValue = value ? parseFloat(value).toFixed(2) + ' ₽' : '—';
                                } else if (field === 'from_location' || field === 'to_location' || field === 'notes') {
                                    displayValue = value.replace(/\n/g, '<br>');
                                }
                                
                                cell.innerHTML = `<div class="editable-content pre-line">${displayValue}</div>`;
                                showNotification('✅ ' + result.message);
                            } else {
                                showNotification('❌ ' + result.message, 'error');
                                cell.innerHTML = `<div class="editable-content">${currentContent}</div>`;
                            }
                        } catch (error) {
                            showNotification('❌ Ошибка сети', 'error');
                            cell.innerHTML = `<div class="editable-content">${currentContent}</div>`;
                        }
                    });
                    
                    cancelBtn.addEventListener('click', function() {
                        cell.innerHTML = `<div class="editable-content pre-line">${currentContent}</div>`;
                    });
                });
            });
            
            // 3. ИЗМЕНЕНИЕ СТАТУСА
            document.querySelectorAll('.status-select-container').forEach(container => {
                container.addEventListener('click', function() {
                    const orderId = this.dataset.orderId;
                    const currentBadge = this.querySelector('.status-badge');
                    const currentStatus = currentBadge.className.match(/status-(\w+)/)[1];
                    
                    const select = document.createElement('select');
                    select.className = 'status-select';
                    
                    const options = [
                        {value: 'new', text: 'Новый'},
                        {value: 'accepted', text: 'Принят'},
                        {value: 'received', text: 'Получен'},
                        {value: 'delivered', text: 'Доставлен'},
                        {value: 'cancelled', text: 'Отменен'}
                    ];
                    
                    options.forEach(opt => {
                        const option = document.createElement('option');
                        option.value = opt.value;
                        option.textContent = opt.text;
                        if (opt.value === currentStatus) option.selected = true;
                        select.appendChild(option);
                    });
                    
                    this.innerHTML = '';
                    this.appendChild(select);
                    select.focus();
                    
                    select.addEventListener('change', async function() {
                        const newStatus = this.value;
                        
                        const formData = new FormData();
                        formData.append('action', 'update_field');
                        formData.append('order_id', orderId);
                        formData.append('field', 'status');
                        formData.append('value', newStatus);
                        
                        try {
                            const response = await fetch('', {
                                method: 'POST',
                                body: formData
                            });
                            const result = await response.json();
                            
                            if (result.success) {
                                const statusText = options.find(o => o.value === newStatus).text;
                                container.innerHTML = `<div class="status-badge status-${newStatus}">${statusText}</div>`;
                                showNotification('✅ ' + result.message);
                            } else {
                                showNotification('❌ ' + result.message, 'error');
                                container.innerHTML = `<div class="status-badge status-${currentStatus}">${options.find(o => o.value === currentStatus).text}</div>`;
                            }
                        } catch (error) {
                            showNotification('❌ Ошибка сети', 'error');
                            container.innerHTML = `<div class="status-badge status-${currentStatus}">${options.find(o => o.value === currentStatus).text}</div>`;
                        }
                    });
                    
                    select.addEventListener('blur', function() {
                        setTimeout(() => {
                            if (document.activeElement !== select) {
                                container.innerHTML = `<div class="status-badge status-${currentStatus}">${options.find(o => o.value === currentStatus).text}</div>`;
                            }
                        }, 200);
                    });
                });
            });
        });
        
        // 4. АВТОДОПОЛНЕНИЕ АДРЕСОВ
        function setupAutocomplete(inputElement, fieldType) {
            let resultsContainer = document.createElement('div');
            resultsContainer.className = 'autocomplete-results';
            inputElement.parentNode.appendChild(resultsContainer);
            
            inputElement.addEventListener('input', debounce(async function() {
                const query = this.value;
                if (query.length < 2) {
                    resultsContainer.style.display = 'none';
                    return;
                }
                
                const formData = new FormData();
                formData.append('action', 'search_addresses');
                formData.append('query', query);
                formData.append('type', fieldType);
                
                try {
                    const response = await fetch('', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();
                    
                    if (result.success && result.results.length > 0) {
                        resultsContainer.innerHTML = '';
                        result.results.forEach(item => {
                            const itemDiv = document.createElement('div');
                            itemDiv.className = 'autocomplete-item';
                            itemDiv.innerHTML = item.full.replace(/\n/g, '<br>');
                            itemDiv.addEventListener('click', function() {
                                inputElement.value = item.full;
                                resultsContainer.style.display = 'none';
                                inputElement.focus();
                            });
                            resultsContainer.appendChild(itemDiv);
                        });
                        resultsContainer.style.display = 'block';
                    } else {
                        resultsContainer.style.display = 'none';
                    }
                } catch (error) {
                    resultsContainer.style.display = 'none';
                }
            }, 300));
            
            document.addEventListener('click', function(e) {
                if (!inputElement.contains(e.target) && !resultsContainer.contains(e.target)) {
                    resultsContainer.style.display = 'none';
                }
            });
        }
        
        // 5. СОХРАНЕНИЕ НОВОГО АДРЕСА
        async function saveNewAddress(addressValue, fieldType) {
            const lines = addressValue.split('\n').filter(line => line.trim() !== '');
            
            const type = fieldType === 'from_location' ? 'from' : 'to';
            
            let defaultName = 'Неизвестно';
            let defaultAddress = '';
            let defaultMetro = '';
            let defaultContact = '';
            let defaultPhone = '';
            
            if (lines.length > 0) {
                defaultName = lines[0].trim() || 'Неизвестно';
                
                for (let i = 1; i < lines.length; i++) {
                    const line = lines[i].trim();
                    if (line && !line.includes('+7') && !line.includes('Контакт:')) {
                        if (line.includes('Охотный Ряд') || line.includes('Театральная') || line.includes('Кузнецкий Мост')) {
                            defaultMetro += (defaultMetro ? ', ' : '') + line;
                        } else {
                            defaultAddress += (defaultAddress ? '\n' : '') + line;
                        }
                    }
                }
                
                for (let i = 1; i < lines.length; i++) {
                    const line = lines[i].trim();
                    if (line.includes('Контакт:')) {
                        const contactPart = line.replace('Контакт:', '').trim();
                        const phoneMatch = contactPart.match(/\+7\d{10}/);
                        if (phoneMatch) {
                            defaultPhone = phoneMatch[0];
                            defaultContact = contactPart.replace(phoneMatch[0], '').trim();
                        } else {
                            defaultContact = contactPart;
                        }
                    } else if (line.includes('+7')) {
                        defaultPhone = line;
                    }
                }
            }
            
            const modalHtml = `
                <div class="address-modal">
                    <div class="address-modal-content">
                        <h3><i class="fas fa-map-marker-alt"></i> Сохранение нового адреса</h3>
                        <div style="margin:15px 0;">
                            <div style="margin-bottom:10px;">
                                <label>Название компании:</label>
                                <input type="text" id="addressName" class="filter-input" 
                                       value="${defaultName}" placeholder="Название компании">
                            </div>
                            <div style="margin-bottom:10px;">
                                <label>Полный адрес:</label>
                                <textarea id="addressFull" class="edit-textarea" rows="3" 
                                          placeholder="Введите полный адрес">${defaultAddress}</textarea>
                            </div>
                            <div style="margin-bottom:10px;">
                                <label>Метро (опционально):</label>
                                <input type="text" id="addressMetro" class="filter-input" 
                                       value="${defaultMetro}" placeholder="Например: Охотный Ряд, Театральная">
                            </div>
                            <div style="margin-bottom:10px;">
                                <label>Контактное лицо:</label>
                                <input type="text" id="addressContact" class="filter-input" 
                                       value="${defaultContact}" placeholder="Имя контактного лица">
                            </div>
                            <div style="margin-bottom:10px;">
                                <label>Телефон:</label>
                                <input type="text" id="addressPhone" class="filter-input" 
                                       value="${defaultPhone}" placeholder="+79001234567">
                            </div>
                            <div style="margin-bottom:10px;">
                                <label>Тип адреса:</label>
                                <select id="addressType" class="filter-select">
                                    <option value="both">И откуда и куда</option>
                                    <option value="from">Только откуда</option>
                                    <option value="to">Только куда</option>
                                </select>
                            </div>
                        </div>
                        <div class="address-modal-buttons">
                            <button id="confirmSaveAddress" class="btn" style="background: var(--steel-success);">
                                <i class="fas fa-save"></i> Сохранить адрес
                            </button>
                            <button id="cancelSaveAddress" class="btn" style="background: var(--steel-medium);">
                                <i class="fas fa-times"></i> Отмена
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            const modalDiv = document.createElement('div');
            modalDiv.innerHTML = modalHtml;
            document.body.appendChild(modalDiv);
            
            setTimeout(() => {
                document.getElementById('addressName').focus();
            }, 100);
            
            modalDiv.querySelector('#addressType').value = type;
            
            modalDiv.querySelector('#confirmSaveAddress').addEventListener('click', async function() {
                const name = document.getElementById('addressName').value.trim() || 'Неизвестно';
                const full_address = document.getElementById('addressFull').value.trim();
                const metro = document.getElementById('addressMetro').value.trim();
                const contact = document.getElementById('addressContact').value.trim();
                const phone = document.getElementById('addressPhone').value.trim();
                const selectedType = document.getElementById('addressType').value;
                
                if (!full_address) {
                    showNotification('❌ Введите хотя бы адрес', 'error');
                    return;
                }
                
                try {
                    const formData = new FormData();
                    formData.append('action', 'save_new_address');
                    formData.append('name', name);
                    formData.append('full_address', full_address);
                    formData.append('type', selectedType);
                    formData.append('metro', metro);
                    formData.append('contact', (contact + ' ' + phone).trim());
                    
                    const response = await fetch('', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        showNotification('✅ Адрес сохранен в базу! Теперь он будет в автодополнении.', 'success');
                        modalDiv.remove();
                    } else {
                        showNotification('❌ Ошибка: ' + result.message, 'error');
                    }
                } catch (error) {
                    showNotification('❌ Ошибка сети', 'error');
                }
            });
            
            modalDiv.querySelector('#cancelSaveAddress').addEventListener('click', function() {
                modalDiv.remove();
            });
        }
        
        // 6. КОПИРОВАНИЕ ЗАДАНИЯ ДЛЯ КУРЬЕРА
        async function copyTaskToMessenger(orderId) {
            try {
                const formData = new FormData();
                formData.append('action', 'get_order_details');
                formData.append('order_id', orderId);
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                
                if (result.success) {
                    const order = result.order;
                    
                    let message = `Заказ #${order.id}\n`;
                    message += `Тема: ${order.order_subject || 'Без темы'}\n\n`;
                    message += `Откуда:\n${order.from_location || 'Не указано'}\n\n`;
                    message += `Куда:\n${order.to_location || 'Не указано'}\n`;
                    
                    if (order.notes) {
                        message += `\nПримечания:\n${order.notes}`;
                    }
                    
                    navigator.clipboard.writeText(message).then(() => {
                        showNotification('✅ Задание скопировано для курьера');
                    }).catch(err => {
                        const textArea = document.createElement('textarea');
                        textArea.value = message;
                        document.body.appendChild(textArea);
                        textArea.select();
                        document.execCommand('copy');
                        document.body.removeChild(textArea);
                        showNotification('✅ Задание скопировано для курьера');
                    });
                } else {
                    showNotification('❌ Ошибка загрузки данных заказа', 'error');
                }
            } catch (error) {
                showNotification('❌ Ошибка сети', 'error');
            }
        }
        
        // 7. ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }
        
        function openExportModal() {
            showNotification('Функция экспорта в Excel будет добавлена позже', 'info');
        }
        // Добавьте эту функцию в JavaScript
function importCSVToGoogle() {
    // Создаем CSV
    fetch('sync_simple.php?limit=50')
        .then(response => response.text())
        .then(csvData => {
            // Создаем Blob для скачивания
            const blob = new Blob([csvData], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            
            link.setAttribute('href', url);
            link.setAttribute('download', 'orders_' + new Date().toISOString().slice(0,10) + '.csv');
            link.style.visibility = 'hidden';
            
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            alert('CSV файл создан. Теперь:\n1. Откройте Google таблицу\n2. Файл → Импорт\n3. Загрузите CSV файл\n4. Выберите "Вставить новые строки"');
        });
}

// Или кнопка для прямого импорта через Google Sheets API (если заработает)
function importDirectToGoogle() {
    if (confirm('Это обновит вашу Google таблицу. Продолжить?')) {
        fetch('sync_to_sheets_simple.php')
            .then(response => response.text())
            .then(result => {
                alert(result);
                location.reload();
            });
    }
}
    </script>
</body>
</html>