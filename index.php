<?php
declare(strict_types=1);
// <!-- index.php -->
$db = new SQLite3(__DIR__ . '/articles.db');
$db->exec('PRAGMA journal_mode = WAL;');
$db->exec('CREATE TABLE IF NOT EXISTS articles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    content TEXT NOT NULL
)');
$db->exec('CREATE TABLE IF NOT EXISTS comments (
    comment_id INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL,
    comment_text TEXT NOT NULL,
    created_at TEXT NOT NULL
)');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $articleText = trim($_POST['articleText'] ?? '');
    if ($title === '' || $articleText === '') {
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    // Insert article
    $stmt = $db->prepare('INSERT INTO articles (title, content) VALUES (:title, :content)');
    $stmt->bindValue(':title', $title, SQLITE3_TEXT);
    $stmt->bindValue(':content', $articleText, SQLITE3_TEXT);
    $stmt->execute();
    $articleId = $db->lastInsertRowID();

    // Create folder for the article
    $articleDir = __DIR__ . '/' . $articleId;
    mkdir($articleDir, 0775);

    // Handle file upload (20MB max, allow only certain types)
    if (!empty($_FILES['upload']['name'])) {
        if ($_FILES['upload']['size'] > 20 * 1024 * 1024) {
            // File too large
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
        $allowedExtensions = ['png','jpg','jpeg','gif','webp','mp4','webm'];
        $tmpName = $_FILES['upload']['tmp_name'];
        $fileName = basename($_FILES['upload']['name']);
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        // Validate extension
        if (!in_array($ext, $allowedExtensions, true)) {
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }

        // Optionally, check MIME with finfo for additional security
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpName);
        // Basic check to ensure it's image/video
        $allowedMimes = [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'video/mp4',
            'video/webm'
        ];
        if (!in_array($mimeType, $allowedMimes, true)) {
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }

        $destination = $articleDir . '/' . $fileName;
        move_uploaded_file($tmpName, $destination);
        $uploadedFileName = $fileName;
    } else {
        $uploadedFileName = '';
    }

    $safeTitle   = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeArticle = nl2br(htmlspecialchars($articleText, ENT_QUOTES, 'UTF-8'));

    // Build the article HTML
    $htmlContent = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{$safeTitle}</title>
    <link rel="stylesheet" href="../style.css">
    <script>
        function toggleTheme() {
            const body = document.body;
            body.classList.toggle('dark-mode');
            if (body.classList.contains('dark-mode')) {
                localStorage.setItem('theme', 'dark');
            } else {
                localStorage.setItem('theme', 'light');
            }
        }
        window.addEventListener('DOMContentLoaded', () => {
            if (localStorage.getItem('theme') === 'dark') {
                document.body.classList.add('dark-mode');
            }
        });
    </script>
</head>
<body>
<div class="article-top-bar">
    <button onclick="window.location.href='../index.php'" class="back-button">&laquo; Back</button>
    <button onclick="toggleTheme()" class="theme-button">Toggle Theme</button>
</div>
<div class="article-container">
    <h1 class="article-title">{$safeTitle}</h1>
    <div class="article-body">{$safeArticle}</div>
HTML;

    if ($uploadedFileName !== '') {
        $mediaName = htmlspecialchars($uploadedFileName, ENT_QUOTES, 'UTF-8');
        $extension = strtolower(pathinfo($mediaName, PATHINFO_EXTENSION));
        if ($extension === 'mp4' || $extension === 'webm') {
            $htmlContent .= <<<HTML

    <div class="media-container">
        <video controls class="video-player">
            <source src="{$mediaName}" type="video/{$extension}">
            Your browser does not support the video tag.
        </video>
    </div>
HTML;
        } else {
            $htmlContent .= <<<HTML

    <div class="media-container">
        <img src="{$mediaName}" alt="Uploaded" class="image-uploaded">
    </div>
HTML;
        }
    }

    $htmlContent .= <<<HTML

    <h2 class="comments-title">Comments</h2>
    <form action="../comment.php?id={$articleId}" method="post" class="comment-form">
        <label for="comment" class="comment-label">Add a comment:</label><br>
        <textarea name="comment" id="comment" rows="4" cols="50" required class="comment-textarea"></textarea><br><br>
        <button type="submit" class="comment-submit">Submit Comment</button>
    </form>

    <div id="comment-list" class="comment-list">
        <!-- Comments will appear here -->
    </div>
</div>
</body>
</html>
HTML;

    file_put_contents($articleDir . '/index.html', $htmlContent);

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Display existing articles
$articles = $db->query('SELECT id, title FROM articles ORDER BY id DESC');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Chess Articles</title>
    <link rel="stylesheet" href="style.css">
    <script>
    function toggleNewArticleForm() {
        const formContainer = document.getElementById('newArticleFormContainer');
        if (formContainer.style.display === 'none' || formContainer.style.display === '') {
            formContainer.style.display = 'block';
        } else {
            formContainer.style.display = 'none';
        }
    }
    function toggleTheme() {
        const body = document.body;
        body.classList.toggle('dark-mode');
        if (body.classList.contains('dark-mode')) {
            localStorage.setItem('theme', 'dark');
        } else {
            localStorage.setItem('theme', 'light');
        }
    }
    window.addEventListener('DOMContentLoaded', () => {
        if (localStorage.getItem('theme') === 'dark') {
            document.body.classList.add('dark-mode');
        }
    });
    </script>
</head>
<body>
<div class="container">
    <div class="top-bar">
        <h1 class="main-title">Chess Articles</h1>
        <button onclick="toggleTheme()" class="theme-button">Toggle Theme</button>
    </div>

    <button type="button" class="new-article-btn" onclick="toggleNewArticleForm()">New Article</button>

    <div id="newArticleFormContainer" style="display: none;">
        <form action="" method="post" enctype="multipart/form-data" class="article-form">
            <div class="form-group">
                <label for="title" class="form-label">Article Title:</label><br>
                <input type="text" name="title" id="title" required class="form-input" maxlength="100">
            </div>
            <div class="form-group">
                <label for="articleText" class="form-label">Article Text:</label><br>
                <textarea name="articleText" id="articleText" rows="6" cols="60" required class="form-textarea"></textarea>
            </div>
            <div class="form-group">
                <label for="upload" class="form-label">Image or Video (optional):</label><br>
                <input type="file" name="upload" id="upload"
                    accept=".png,.jpg,.jpeg,.gif,.webp,.mp4,.webm"
                    class="form-input">
                <p class="allowed-types">Allowed: PNG, JPG, JPEG, GIF, WEBP, MP4, WEBM. Max 20MB</p>
            </div>
            <div class="form-group">
                <button type="submit" class="submit-article-btn">Submit Article</button>
            </div>
        </form>
    </div>

    <hr>
    <h2 class="articles-list-title">Articles</h2>
    <ul class="articles-list">
        <?php while ($row = $articles->fetchArray(SQLITE3_ASSOC)): ?>
            <li class="article-link-item">
                <a href="<?php echo htmlspecialchars($row['id'] . '/index.html', ENT_QUOTES, 'UTF-8'); ?>" class="article-link">
                    <?php echo htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8'); ?>
                </a>
            </li>
        <?php endwhile; ?>
    </ul>
</div>
</body>
</html>
