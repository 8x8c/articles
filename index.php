<?php
declare(strict_types=1);

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

// 🔁 Handle Comment Submission
if (isset($_GET['comment']) && isset($_GET['id']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $articleId = (int) $_GET['id'];
    $commentText = trim($_POST['comment'] ?? '');

    if ($articleId > 0 && $commentText !== '') {
        $stmt = $db->prepare('INSERT INTO comments (article_id, comment_text, created_at) 
                              VALUES (:aid, :ctext, :cat)');
        $stmt->bindValue(':aid', $articleId, SQLITE3_INTEGER);
        $stmt->bindValue(':ctext', $commentText, SQLITE3_TEXT);
        $stmt->bindValue(':cat', date('Y-m-d H:i:s'), SQLITE3_TEXT);
        $stmt->execute();
    }

    // Rebuild article page
    $articleQuery = $db->prepare('SELECT title, content FROM articles WHERE id = :aid LIMIT 1');
    $articleQuery->bindValue(':aid', $articleId, SQLITE3_INTEGER);
    $articleRow = $articleQuery->execute()->fetchArray(SQLITE3_ASSOC);

    if (!$articleRow) {
        header('Location: index.php');
        exit;
    }

    $title = htmlspecialchars($articleRow['title'], ENT_QUOTES, 'UTF-8');
    $articleText = nl2br(htmlspecialchars($articleRow['content'], ENT_QUOTES, 'UTF-8'));
    $articleDir = __DIR__ . '/' . $articleId;

    $uploadedMedia = '';
    $dirContents = scandir($articleDir);
    foreach ($dirContents as $item) {
        if ($item !== '.' && $item !== '..' && $item !== 'index.html') {
            $safeItem = htmlspecialchars($item, ENT_QUOTES, 'UTF-8');
            $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
            if (in_array($ext, ['mp4','webm'])) {
                $uploadedMedia = <<<HTML
<div class="media-container">
    <video controls class="video-player">
        <source src="{$safeItem}" type="video/{$ext}">
    </video>
</div>
HTML;
            } else {
                $uploadedMedia = <<<HTML
<div class="media-container">
    <img src="{$safeItem}" alt="Uploaded" class="image-uploaded">
</div>
HTML;
            }
        }
    }

    // Load comments
    $comments = '';
    $stmt = $db->prepare('SELECT comment_text FROM comments WHERE article_id = :aid ORDER BY comment_id ASC');
    $stmt->bindValue(':aid', $articleId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $safe = nl2br(htmlspecialchars($row['comment_text'], ENT_QUOTES, 'UTF-8'));
        $comments .= "<div class=\"comment-item\">{$safe}</div>\n";
    }

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{$title}</title>
    <link rel="stylesheet" href="../style.css">
    <script>
        function toggleTheme() {
            const body = document.body;
            body.classList.toggle('dark-mode');
            localStorage.setItem('theme', body.classList.contains('dark-mode') ? 'dark' : 'light');
        }
        function toggleCommentForm() {
            const container = document.getElementById('commentFormContainer');
            container.style.display = (container.style.display === 'none' || container.style.display === '') ? 'block' : 'none';
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
    <h1 class="article-title">{$title}</h1>
    {$uploadedMedia}
    <div class="article-body">{$articleText}</div>

    <h2 class="comments-title">Comments</h2>
    <button onclick="toggleCommentForm()" class="new-article-btn">Add Comment</button>
    <div id="commentFormContainer" style="display: none;">
        <form action="../index.php?comment=1&id={$articleId}" method="post" class="comment-form">
            <label for="comment" class="comment-label">Add a comment:</label>
            <textarea name="comment" id="comment" required class="comment-textarea"></textarea>
            <button type="submit" class="comment-submit">Submit Comment</button>
        </form>
    </div>
    <div id="comment-list" class="comment-list">{$comments}</div>
</div>
</body>
</html>
HTML;

    file_put_contents("{$articleDir}/index.html", $html);
    header("Location: {$articleId}/index.html");
    exit;
}

// ✍️ Handle new article submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['articleText'] ?? '');
    if ($title === '' || strlen($title) > 100 || $content === '') {
        header('Location: index.php');
        exit;
    }

    $stmt = $db->prepare('INSERT INTO articles (title, content) VALUES (:title, :content)');
    $stmt->bindValue(':title', $title, SQLITE3_TEXT);
    $stmt->bindValue(':content', $content, SQLITE3_TEXT);
    $stmt->execute();
    $articleId = $db->lastInsertRowID();

    $dir = __DIR__ . '/' . $articleId;
    mkdir($dir, 0775);

    $uploadedFile = '';
    if (!empty($_FILES['upload']['name'])) {
        if ($_FILES['upload']['size'] <= 20 * 1024 * 1024) {
            $ext = strtolower(pathinfo($_FILES['upload']['name'], PATHINFO_EXTENSION));
            $allowed = ['png','jpg','jpeg','gif','webp','mp4','webm'];
            if (in_array($ext, $allowed, true)) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($_FILES['upload']['tmp_name']);
                $validMime = [
                    'image/jpeg','image/png','image/gif','image/webp',
                    'video/mp4','video/webm'
                ];
                if (in_array($mime, $validMime, true)) {
                    $uploadedFile = basename($_FILES['upload']['name']);
                    move_uploaded_file($_FILES['upload']['tmp_name'], "{$dir}/{$uploadedFile}");
                }
            }
        }
    }

    // Rebuild static HTML
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeContent = nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8'));

    $media = '';
    if ($uploadedFile !== '') {
        $ext = strtolower(pathinfo($uploadedFile, PATHINFO_EXTENSION));
        $src = htmlspecialchars($uploadedFile, ENT_QUOTES, 'UTF-8');
        if (in_array($ext, ['mp4','webm'])) {
            $media = <<<HTML
<div class="media-container">
    <video controls class="video-player">
        <source src="{$src}" type="video/{$ext}">
    </video>
</div>
HTML;
        } else {
            $media = <<<HTML
<div class="media-container">
    <img src="{$src}" alt="Uploaded" class="image-uploaded">
</div>
HTML;
        }
    }

    $html = <<<HTML
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
            localStorage.setItem('theme', body.classList.contains('dark-mode') ? 'dark' : 'light');
        }
        function toggleCommentForm() {
            const container = document.getElementById('commentFormContainer');
            container.style.display = (container.style.display === 'none' || container.style.display === '') ? 'block' : 'none';
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
    {$media}
    <div class="article-body">{$safeContent}</div>
    <h2 class="comments-title">Comments</h2>
    <button onclick="toggleCommentForm()" class="new-article-btn">Add Comment</button>
    <div id="commentFormContainer" style="display: none;">
        <form action="../index.php?comment=1&id={$articleId}" method="post" class="comment-form">
            <label for="comment" class="comment-label">Add a comment:</label>
            <textarea name="comment" id="comment" required class="comment-textarea"></textarea>
            <button type="submit" class="comment-submit">Submit Comment</button>
        </form>
    </div>
    <div id="comment-list" class="comment-list"></div>
</div>
</body>
</html>
HTML;

    file_put_contents("{$dir}/index.html", $html);
    header("Location: index.php");
    exit;
}

// 🧾 Display homepage
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
            const f = document.getElementById('newArticleFormContainer');
            f.style.display = f.style.display === 'block' ? 'none' : 'block';
        }
        function toggleTheme() {
            const body = document.body;
            body.classList.toggle('dark-mode');
            localStorage.setItem('theme', body.classList.contains('dark-mode') ? 'dark' : 'light');
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

    <button class="new-article-btn" onclick="toggleNewArticleForm()">New Article</button>

    <div id="newArticleFormContainer" style="display: none;">
        <form action="" method="post" enctype="multipart/form-data" class="article-form">
            <div class="form-group">
                <label for="title" class="form-label">Article Title:</label>
                <input type="text" name="title" id="title" required class="form-input" maxlength="100">
            </div>
            <div class="form-group">
                <label for="articleText" class="form-label">Article Text:</label>
                <textarea name="articleText" id="articleText" required class="form-textarea"></textarea>
            </div>
            <div class="form-group">
                <label for="upload" class="form-label">Image or Video (optional):</label>
                <input type="file" name="upload" id="upload" accept=".png,.jpg,.jpeg,.gif,.webp,.mp4,.webm" class="form-input">
                <p class="allowed-types">Allowed: PNG, JPG, JPEG, GIF, WEBP, MP4, WEBM. Max 20MB</p>
            </div>
            <button type="submit" class="submit-article-btn">Submit Article</button>
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
