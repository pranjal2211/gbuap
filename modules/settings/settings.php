<?php
session_start();
require '../../config/db.php';

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    header('Location: ../auth/login.php');
    exit;
}

// Load current settings
$stmt = $pdo->prepare("SELECT theme, language FROM settings WHERE user_id = ?");
$stmt->execute([$user_id]);
$row = $stmt->fetch();
$theme = $row['theme'] ?? $_SESSION['theme'] ?? 'light';
$language = $row['language'] ?? $_SESSION['language'] ?? 'en';

// Handle form submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $theme = $_POST['theme'] ?? 'light';
    $language = $_POST['language'] ?? 'en';
    $stmt = $pdo->prepare("REPLACE INTO settings (user_id, theme, language) VALUES (?, ?, ?)");
    $stmt->execute([$user_id, $theme, $language]);
    $_SESSION['theme'] = $theme;
    $_SESSION['language'] = $language;
    header("Location: settings.php?saved=1");
    exit;
}

// Simple language array for demo
$lang = [
    'en' => [
        'SETTINGS' => 'Settings',
        'THEME' => 'Theme',
        'LANGUAGE' => 'Language',
        'LIGHT' => 'Light',
        'DARK' => 'Dark',
        'ENGLISH' => 'English',
        'HINDI' => 'Hindi',
        'SAVE' => 'Save Settings',
        'SAVED' => 'Settings saved successfully!',
    ],
    'hi' => [
        'SETTINGS' => 'सेटिंग्स',
        'THEME' => 'थीम',
        'LANGUAGE' => 'भाषा',
        'LIGHT' => 'हल्का',
        'DARK' => 'डार्क',
        'ENGLISH' => 'अंग्रेज़ी',
        'HINDI' => 'हिंदी',
        'SAVE' => 'सेटिंग्स सहेजें',
        'SAVED' => 'सेटिंग्स सफलतापूर्वक सहेजी गई!',
    ]
];
$text = $lang[$language];
?>
<!DOCTYPE html>
<html lang="<?= $language ?>">
<head>
    <meta charset="UTF-8">
    <title><?= $text['SETTINGS'] ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        body[data-theme="dark"] {
            background: #181a1b !important;
            color: #e0e0e0 !important;
        }
        body[data-theme="dark"] .main-content,
        body[data-theme="dark"] .card,
        body[data-theme="dark"] .sidebar {
            background: #23272b !important;
            color: #e0e0e0 !important;
        }
        body[data-theme="dark"] .form-control,
        body[data-theme="dark"] .form-select {
            background: #23272b !important;
            color: #e0e0e0 !important;
            border-color: #444;
        }
        body[data-theme="dark"] .nav-link {
            color: #e0e0e0 !important;
        }
        body[data-theme="dark"] .nav-link.active, body[data-theme="dark"] .nav-link:hover {
            background: #181a1b !important;
            color: #d32f2f !important;
        }
        .main-content {
            margin-left: 230px;
            min-height: 100vh;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding-top: 3rem;
        }
        .settings-card {
            width: 100%;
            max-width: 400px;
            background: #fff;
            border-radius: 1.2rem;
            box-shadow: 0 4px 24px rgba(0,0,0,0.07);
            padding: 2.5rem 2rem 2rem 2rem;
            border: 1.5px solid #d32f2f22;
        }
        body[data-theme="dark"] .settings-card { background: #23272b !important; }
        .settings-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #d32f2f;
            margin-bottom: 2rem;
            text-align: center;
            letter-spacing: 1px;
        }
    </style>
</head>
<body data-theme="<?= htmlspecialchars($theme) ?>">
<?php include '../sidebar.php'; ?>
<div class="main-content">
    <div class="settings-card">
        <div class="settings-title"><?= $text['SETTINGS'] ?></div>
        <?php if (isset($_GET['saved'])): ?>
            <div class="alert alert-success"><?= $text['SAVED'] ?></div>
        <?php endif; ?>
        <form method="post" autocomplete="off">
            <div class="mb-4">
                <label class="form-label"><?= $text['THEME'] ?></label>
                <select name="theme" class="form-select" required>
                    <option value="light" <?= $theme=='light'?'selected':'' ?>><?= $text['LIGHT'] ?></option>
                    <option value="dark" <?= $theme=='dark'?'selected':'' ?>><?= $text['DARK'] ?></option>
                </select>
            </div>
            <div class="mb-4">
                <label class="form-label"><?= $text['LANGUAGE'] ?></label>
                <select name="language" class="form-select" required>
                    <option value="en" <?= $language=='en'?'selected':'' ?>><?= $text['ENGLISH'] ?></option>
                    <option value="hi" <?= $language=='hi'?'selected':'' ?>><?= $text['HINDI'] ?></option>
                </select>
            </div>
            <button type="submit" class="btn btn-danger w-100"><?= $text['SAVE'] ?></button>
        </form>
    </div>
</div>
</body>
</html>
