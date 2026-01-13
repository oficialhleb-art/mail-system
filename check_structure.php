<?php
// mail-system/check_structure.php
// ============================================
// СКРИПТ ДЛЯ ПРОВЕРКИ СТРУКТУРЫ МОДУЛЕЙ
// ============================================

session_start();

// Простая авторизация
$correct_password = 'structure123'; // ИЗМЕНИТЕ ПАРОЛЬ!

if (!isset($_SESSION['structure_auth'])) {
    if (isset($_POST['password'])) {
        if ($_POST['password'] === $correct_password) {
            $_SESSION['structure_auth'] = true;
        } else {
            $error = "Неверный пароль!";
        }
    }
    
    if (!isset($_SESSION['structure_auth'])) {
        showLoginForm($error ?? '');
        exit;
    }
}

// Удаление самого себя после проверки
if (isset($_GET['delete'])) {
    unlink(__FILE__);
    header('Location: ./');
    exit;
}

// Функция для отображения структуры
function checkStructure() {
    $base_dir = __DIR__;
    $results = [
        'modules' => [],
        'core' => [],
        'cron' => [],
        'assets' => [],
        'errors' => []
    ];
    
    // Проверяем обязательные директории
    $required_dirs = [
        'modules' => [
            'admin' => ['views', 'controllers'],
            'api' => ['v1', 'endpoints'],
            'services' => ['mail', 'google', 'telegram']
        ],
        'core' => [],
        'cron' => [],
        'assets' => ['css', 'js', 'images']
    ];
    
    // Проверяем каждый каталог
    foreach ($required_dirs as $dir => $subdirs) {
        $full_path = $base_dir . '/' . $dir;
        
        if (is_dir($full_path)) {
            $results[$dir]['status'] = '✅ Существует';
            $results[$dir]['path'] = $full_path;
            
            // Проверяем подкаталоги
            if (!empty($subdirs)) {
                $results[$dir]['subdirs'] = [];
                
                if (is_array($subdirs)) {
                    // Если это ассоциативный массив (modules)
                    foreach ($subdirs as $subdir => $subsubdirs) {
                        $sub_path = $full_path . '/' . $subdir;
                        
                        if (is_dir($sub_path)) {
                            $results[$dir]['subdirs'][$subdir] = '✅ Существует';
                            
                            // Проверяем под-подкаталоги
                            if (!empty($subsubdirs) && is_array($subsubdirs)) {
                                foreach ($subsubdirs as $subsubdir) {
                                    $subsub_path = $sub_path . '/' . $subsubdir;
                                    
                                    if (is_dir($subsub_path)) {
                                        $results[$dir]['subdirs'][$subdir . '/' . $subsubdir] = '✅ Существует';
                                    } else {
                                        $results[$dir]['subdirs'][$subdir . '/' . $subsubdir] = '❌ Отсутствует';
                                        $results['errors'][] = "Отсутствует: $dir/$subdir/$subsubdir";
                                    }
                                }
                            }
                        } else {
                            $results[$dir]['subdirs'][$subdir] = '❌ Отсутствует';
                            $results['errors'][] = "Отсутствует: $dir/$subdir";
                        }
                    }
                } else {
                    // Если это простой массив (assets)
                    foreach ($subdirs as $subdir) {
                        $sub_path = $full_path . '/' . $subdir;
                        
                        if (is_dir($sub_path)) {
                            $results[$dir]['subdirs'][$subdir] = '✅ Существует';
                        } else {
                            $results[$dir]['subdirs'][$subdir] = '❌ Отсутствует';
                            $results['errors'][] = "Отсутствует: $dir/$subdir";
                        }
                    }
                }
            }
        } else {
            $results[$dir]['status'] = '❌ Отсутствует';
            $results['errors'][] = "Отсутствует основная папка: $dir";
        }
    }
    
    // Проверяем обязательные файлы
    $required_files = [
        'core/Database.php' => 'Класс для работы с БД',
        'core/AddressManager.php' => 'Класс для работы с адресами',
        'modules/admin/views/dashboard.php' => 'Главная страница админки',
        'modules/admin/controllers/orders.php' => 'Контроллер заказов',
        'modules/admin/views/orders.php' => 'Представление заказов',
        'modules/api/v1/router.php' => 'API роутер',
        'modules/services/mail/parser.php' => 'Парсер почты',
        'modules/services/google/sync.php' => 'Синхронизация с Google',
        'modules/services/telegram/bot.php' => 'Telegram бот',
        'modules/config.php' => 'Конфигурация модулей'
    ];
    
    $results['files'] = [];
    
    foreach ($required_files as $file => $description) {
        $full_path = $base_dir . '/' . $file;
        
        if (file_exists($full_path)) {
            $size = filesize($full_path);
            $results['files'][$file] = [
                'status' => '✅ Существует',
                'size' => round($size / 1024, 2) . ' KB',
                'description' => $description
            ];
        } else {
            $results['files'][$file] = [
                'status' => '❌ Отсутствует',
                'size' => '0 KB',
                'description' => $description
            ];
            $results['errors'][] = "Отсутствует файл: $file ($description)";
        }
    }
    
    // Проверяем рабочие файлы в корне
    $working_files = [
        'index.php' => 'Главный файл системы',
        'admin_legacy.php' => 'Старая админка',
        'config_loader.php' => 'Загрузчик конфигов',
        'mail_parser.php' => 'Парсер почты (старый)',
        'addresses.json' => 'База адресов',
        'sync_to_sheets_simple.php' => 'Синхронизация (старая)'
    ];
    
    $results['working_files'] = [];
    
    foreach ($working_files as $file => $description) {
        if (file_exists($base_dir . '/' . $file)) {
            $size = filesize($base_dir . '/' . $file);
            $results['working_files'][$file] = [
                'status' => '✅ Существует',
                'size' => round($size / 1024, 2) . ' KB',
                'description' => $description
            ];
        } else {
            $results['working_files'][$file] = [
                'status' => '⚠️ Отсутствует',
                'size' => '0 KB',
                'description' => $description
            ];
        }
    }
    
    return $results;
}

function showLoginForm($error = '') {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Проверка структуры - Авторизация</title>
        <style>
            body { font-family: Arial, sans-serif; background: #1a202c; color: #e2e8f0; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
            .login-container { background: #2d3748; padding: 40px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); width: 100%; max-width: 400px; border: 1px solid #4a5568; }
            h1 { color: #4299e1; text-align: center; margin-bottom: 30px; }
            .error { background: rgba(229, 62, 62, 0.1); border-left: 4px solid #e53e3e; padding: 12px; border-radius: 4px; margin-bottom: 20px; color: #fc8181; }
            .info { background: rgba(56, 161, 105, 0.1); border-left: 4px solid #38a169; padding: 12px; border-radius: 4px; margin-bottom: 20px; color: #68d391; }
            label { display: block; margin-bottom: 8px; font-weight: 600; color: #cbd5e0; }
            input[type="password"] { width: 100%; padding: 12px 15px; background: #2d3748; border: 1px solid #4a5568; border-radius: 6px; color: #e2e8f0; font-size: 16px; margin-bottom: 20px; }
            button { width: 100%; padding: 14px; background: #4299e1; color: white; border: none; border-radius: 6px; font-size: 16px; font-weight: 600; cursor: pointer; }
            button:hover { background: #3182ce; }
        </style>
    </head>
    <body>
        <div class="login-container">
            <h1>🔍 Проверка структуры</h1>
            
            <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <div class="info">
                Введите пароль для проверки структуры модулей
            </div>
            
            <form method="POST">
                <label for="password">Пароль:</label>
                <input type="password" id="password" name="password" required autofocus>
                <button type="submit">Войти</button>
            </form>
        </div>
    </body>
    </html>
    <?php
}

// Получаем результаты проверки
$results = checkStructure();

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Проверка структуры модулей</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --steel-dark: #2d3748;
            --steel-medium: #4a5568;
            --steel-light: #718096;
            --steel-accent: #4299e1;
            --steel-success: #38a169;
            --steel-warning: #d69e2e;
            --steel-danger: #e53e3e;
            --steel-bg: #1a202c;
            --steel-card: #2d3748;
        }
        
        * { 
            box-sizing: border-box; 
            margin: 0; 
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
        }
        
        body { 
            background: var(--steel-bg); 
            color: #e2e8f0;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: var(--steel-card);
            border-radius: 12px;
            padding: 30px;
        }
        
        h1 { 
            color: var(--steel-accent); 
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .section {
            margin: 30px 0;
            padding: 20px;
            background: var(--steel-dark);
            border-radius: 8px;
            border: 1px solid var(--steel-medium);
        }
        
        .section-title {
            color: var(--steel-accent);
            font-size: 1.2rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .directory-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
        }
        
        .dir-item {
            padding: 15px;
            background: rgba(0,0,0,0.2);
            border-radius: 6px;
            border-left: 4px solid var(--steel-accent);
        }
        
        .dir-item.error {
            border-left-color: var(--steel-danger);
        }
        
        .dir-item.success {
            border-left-color: var(--steel-success);
        }
        
        .file-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 15px;
        }
        
        .file-item {
            padding: 15px;
            background: rgba(0,0,0,0.2);
            border-radius: 6px;
            border-left: 4px solid var(--steel-light);
        }
        
        .file-item.success {
            border-left-color: var(--steel-success);
        }
        
        .file-item.error {
            border-left-color: var(--steel-danger);
        }
        
        .file-item.warning {
            border-left-color: var(--steel-warning);
        }
        
        .file-info {
            display: flex;
            justify-content: space-between;
            margin-top: 10px;
            font-size: 0.9em;
            color: var(--steel-light);
        }
        
        .error-summary {
            background: rgba(229, 62, 62, 0.1);
            border-left: 4px solid var(--steel-danger);
            padding: 20px;
            border-radius: 8px;
            margin-top: 30px;
        }
        
        .error-list {
            margin-top: 10px;
            padding-left: 20px;
        }
        
        .error-list li {
            margin: 5px 0;
            color: #fc8181;
        }
        
        .buttons {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 24px;
            background: var(--steel-accent);
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            border: none;
        }
        
        .btn:hover {
            background: #3182ce;
        }
        
        .btn-danger {
            background: var(--steel-danger);
        }
        
        .btn-danger:hover {
            background: #c53030;
        }
        
        .info-box {
            background: rgba(56, 161, 105, 0.1);
            border-left: 4px solid var(--steel-success);
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .summary-card {
            background: var(--steel-dark);
            padding: 20px;
            border-radius: 8px;
            text-align: center;
        }
        
        .summary-value {
            font-size: 2rem;
            font-weight: bold;
            color: var(--steel-accent);
            margin-bottom: 5px;
        }
        
        .summary-label {
            color: var(--steel-light);
            font-size: 0.9em;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1><i class="fas fa-folder-tree"></i> Проверка структуры модулей</h1>
        
        <div class="info-box">
            <p><i class="fas fa-info-circle"></i> <strong>Текущая папка:</strong> <?php echo htmlspecialchars(__DIR__); ?></p>
            <p><i class="fas fa-calendar"></i> <strong>Время проверки:</strong> <?php echo date('d.m.Y H:i:s'); ?></p>
        </div>
        
        <!-- Сводная статистика -->
        <?php
        $total_dirs = 0;
        $total_files = 0;
        $errors_count = count($results['errors']);
        
        foreach (['modules', 'core', 'cron', 'assets'] as $dir) {
            if (isset($results[$dir]['subdirs'])) {
                $total_dirs += count($results[$dir]['subdirs']);
            }
        }
        
        $total_files = count($results['files']) + count($results['working_files']);
        ?>
        
        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-value">4</div>
                <div class="summary-label">Основных папок</div>
            </div>
            <div class="summary-card">
                <div class="summary-value"><?php echo $total_dirs; ?></div>
                <div class="summary-label">Подпапок</div>
            </div>
            <div class="summary-card">
                <div class="summary-value"><?php echo $total_files; ?></div>
                <div class="summary-label">Проверенных файлов</div>
            </div>
            <div class="summary-card">
                <div class="summary-value"><?php echo $errors_count; ?></div>
                <div class="summary-label">Ошибок</div>
            </div>
        </div>
        
        <!-- Основные папки -->
        <div class="section">
            <h3 class="section-title"><i class="fas fa-folder"></i> Основные папки</h3>
            <div class="directory-list">
                <?php foreach (['modules', 'core', 'cron', 'assets'] as $dir): ?>
                <div class="dir-item <?php echo $results[$dir]['status'] === '✅ Существует' ? 'success' : 'error'; ?>">
                    <strong><?php echo strtoupper($dir); ?></strong><br>
                    <span><?php echo $results[$dir]['status']; ?></span>
                    <?php if (isset($results[$dir]['subdirs'])): ?>
                    <div style="margin-top: 10px; font-size: 0.9em;">
                        <?php foreach ($results[$dir]['subdirs'] as $subdir => $status): ?>
                        <div style="margin: 5px 0; padding-left: 10px;">
                            <?php echo $status === '✅ Существует' ? '✅' : '❌'; ?>
                            <?php echo $subdir; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Файлы модулей -->
        <div class="section">
            <h3 class="section-title"><i class="fas fa-file-code"></i> Файлы модулей (обязательные)</h3>
            <div class="file-list">
                <?php foreach ($results['files'] as $file => $info): ?>
                <div class="file-item <?php echo strpos($info['status'], '✅') !== false ? 'success' : 'error'; ?>">
                    <div>
                        <strong><?php echo $file; ?></strong><br>
                        <small><?php echo $info['description']; ?></small>
                    </div>
                    <div class="file-info">
                        <span><?php echo $info['status']; ?></span>
                        <span><?php echo $info['size']; ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Рабочие файлы в корне -->
        <div class="section">
            <h3 class="section-title"><i class="fas fa-file"></i> Рабочие файлы в корне</h3>
            <div class="file-list">
                <?php foreach ($results['working_files'] as $file => $info): ?>
                <div class="file-item <?php 
                    echo strpos($info['status'], '✅') !== false ? 'success' : 
                    (strpos($info['status'], '⚠️') !== false ? 'warning' : 'error');
                ?>">
                    <div>
                        <strong><?php echo $file; ?></strong><br>
                        <small><?php echo $info['description']; ?></small>
                    </div>
                    <div class="file-info">
                        <span><?php echo $info['status']; ?></span>
                        <span><?php echo $info['size']; ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Ошибки -->
        <?php if (!empty($results['errors'])): ?>
        <div class="error-summary">
            <h3 class="section-title"><i class="fas fa-exclamation-triangle"></i> Найдены ошибки (<?php echo count($results['errors']); ?>):</h3>
            <ul class="error-list">
                <?php foreach ($results['errors'] as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
        
        <!-- Действия -->
        <div class="buttons">
            <button onclick="window.location.reload()" class="btn">
                <i class="fas fa-sync-alt"></i> Обновить проверку
            </button>
            
            <button onclick="if(confirm('Перейти в старую админку?')) window.location='admin_legacy.php'" class="btn">
                <i class="fas fa-history"></i> Старая админка
            </button>
            
            <a href="index.php" class="btn">
                <i class="fas fa-home"></i> Новая админка
            </a>
            
            <button onclick="if(confirm('Удалить этот скрипт проверки?\nЭто рекомендуется для безопасности.')) window.location='?delete=1'" class="btn btn-danger">
                <i class="fas fa-trash"></i> Удалить скрипт
            </button>
        </div>
        
        <!-- Инструкция -->
        <div style="margin-top: 30px; padding: 20px; background: var(--steel-dark); border-radius: 8px; font-size: 0.9em; color: var(--steel-light);">
            <p><strong>Инструкция:</strong></p>
            <p>1. Все файлы должны находиться в папке <code>mail-system/</code></p>
            <p>2. Папка <code>modules/</code> должна содержать все подпапки и файлы</p>
            <p>3. После проверки удалите этот скрипт командой выше</p>
        </div>
    </div>
    
    <script>
        // Автоматическое обновление через 30 секунд
        setTimeout(() => {
            if (confirm('Обновить проверку структуры?')) {
                window.location.reload();
            }
        }, 30000);
    </script>
</body>
</html>