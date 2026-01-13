<?php
// mail_parser_NEW.php - ПАРСЕР С ВНЕШНИМИ КОНФИГАМИ
header('Content-Type: text/plain; charset=utf-8');

// 1. ПОДКЛЮЧАЕМ КОНФИГУРАЦИЮ (вместо паролей в коде)
require_once __DIR__ . '/config_loader.php';

echo "=== Запуск парсера (новая версия с конфигами) ===\n\n";

// 2. ИСПОЛЬЗУЕМ КОНСТАНТЫ ИЗ КОНФИГОВ
echo "1. Подключение к почте...\n";
$imap = @imap_open(IMAP_SERVER, IMAP_USER, IMAP_PASS);
if (!$imap) die("❌ Ошибка почты: " . imap_last_error() . "\n");
echo "✅ Успех.\n";

// 3. БЕЛЫЙ СПИСОК ИЗ КОНФИГА
$allowedSenders = unserialize(ALLOWED_SENDERS);

// 4. ПОИСК ТОЛЬКО НЕПРОЧИТАННЫХ В INBOX
echo "2. Поиск непрочитанных писем в INBOX...\n";
$emails = imap_search($imap, 'UNSEEN');

if (!$emails) {
    echo "ℹ️  Новых писем не найдено.\n";
    imap_close($imap);
    exit;
}
echo "✅ Найдено писем: " . count($emails) . "\n";

// 5. ПОДКЛЮЧЕНИЕ К БАЗЕ (из конфига)
echo "3. Подключение к БД...\n";
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", 
        DB_USER, 
        DB_PASS
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✅ Успех.\n";
} catch (PDOException $e) {
    imap_close($imap);
    die("❌ Ошибка БД: " . $e->getMessage() . "\n");
}

// 6. ОБРАБОТКА (остальной код БЕЗ изменений)
echo "4. Обработка...\n";
$ordersAdded = 0;
$ordersSkipped = 0;

foreach ($emails as $emailId) {
    echo "\n--- Письмо #$emailId ---\n";
    
    $header = imap_headerinfo($imap, $emailId);
    $imapUid = imap_uid($imap, $emailId);
    
    $fromAddress = strtolower($header->from[0]->mailbox . '@' . $header->from[0]->host);
    $subject = isset($header->subject) ? mb_decode_mimeheader($header->subject) : '(Без темы)';
    $dateReceived = date('Y-m-d H:i:s', $header->udate);

    echo "От: $fromAddress\n";
    echo "Тема: $subject\n";
    echo "Дата письма: $dateReceived\n";
    echo "IMAP UID: $imapUid\n";
    
    // Проверка Re:/Fwd:
    if (preg_match('/^\s*(Re\s*:|Fwd\s*:|Ответ\s*:|Пересланное\s*:|RE\s*:|FWD\s*:)/i', $subject)) {
        echo "⏭️  Пропуск (ответ/пересылка в теме).\n";
        imap_setflag_full($imap, $emailId, '\\Seen');
        $ordersSkipped++;
        continue;
    }
    
    // ПРОВЕРКА БЕЛОГО СПИСКА
    if (!in_array($fromAddress, $allowedSenders)) {
        echo "⏭️  Пропуск (отправитель не в списке).\n";
        imap_setflag_full($imap, $emailId, '\\Seen');
        $ordersSkipped++;
        continue;
    }
    echo "✅ Отправитель в белом списке.\n";

    // ПРОВЕРКА ДУБЛЕЙ ПО UID
    $checkStmt = $pdo->prepare("SELECT id FROM orders WHERE imap_uid = ?");
    $checkStmt->execute([$imapUid]);
    $existingOrder = $checkStmt->fetch();

    if ($existingOrder) {
        echo "⏭️  Пропуск (письмо с UID $imapUid уже обработано, ID заказа: {$existingOrder['id']}).\n";
        imap_setflag_full($imap, $emailId, '\\Seen');
        $ordersSkipped++;
        continue;
    }
    echo "✅ Письмо новое, продолжаем...\n";

    // ГЕНЕРАЦИЯ НОМЕРА ЗАКАЗА
    $orderNumber = 'ORD-' . date('Ymd') . '-' . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
    $fullOrderSubject = "[$orderNumber] $subject";
    echo "📊 Номер заказа: $orderNumber\n";

    // СОХРАНЕНИЕ В БАЗУ
    try {
        $stmtCheck = $pdo->query("SHOW COLUMNS FROM orders LIKE 'imap_uid'");
        $hasUidField = ($stmtCheck->rowCount() > 0);
        
        if ($hasUidField) {
            $stmt = $pdo->prepare("INSERT INTO orders (customer_email, order_subject, order_number, imap_uid, status) VALUES (?, ?, ?, ?, 'new')");
            $stmt->execute([$fromAddress, $fullOrderSubject, $orderNumber, $imapUid]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO orders (customer_email, order_subject, order_number, status) VALUES (?, ?, ?, 'new')");
            $stmt->execute([$fromAddress, $fullOrderSubject, $orderNumber]);
        }

        $orderId = $pdo->lastInsertId();
        echo "🎉 Заказ добавлен! ID в БД = $orderId\n";
        imap_setflag_full($imap, $emailId, '\\Seen');
        $ordersAdded++;
    } catch (Exception $e) {
        echo "❌ Ошибка БД: " . $e->getMessage() . "\n";
    }
}

// 7. ЗАВЕРШЕНИЕ
imap_close($imap);
echo "\n=== Готово ===\n";
echo "Добавлено новых заказов: $ordersAdded\n";
echo "Пропущено (дубли/не из списка): $ordersSkipped\n";

$stmt = $pdo->query("SELECT COUNT(*) as total FROM orders");
echo "Всего записей в таблице: " . $stmt->fetch()['total'] . "\n";
?>