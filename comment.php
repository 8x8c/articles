<?php
declare(strict_types=1);
// <!-- comment.php -->
$db = new SQLite3(__DIR__ . '/articles.db');
$db->exec('PRAGMA journal_mode = WAL;');

$articleId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($articleId < 1) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $commentText = trim($_POST['comment'] ?? '');
    if ($commentText !== '') {
        $stmt = $db->prepare('INSERT INTO comments (article_id, comment_text, created_at) 
                              VALUES (:aid, :ctext, :cat)');
        $stmt->bindValue(':aid', $articleId, SQLITE3_INTEGER);
        $stmt->bindValue(':ctext', $commentText, SQLITE3_TEXT);
        $stmt->bindValue(':cat', date('Y-m-d H:i:s'), SQLITE3_TEXT);
        $stmt->execute();
    }

    // Rebuild
    $articleQuery = $db->prepare('SELECT title, content FROM articles WHERE id = :aid LIMIT 1');
    $articleQuery->bindValue(':aid', $articleId, SQLITE3_INTEGER);
    $articleRow = $articleQuery->execute()->fetchArray(SQLITE3_ASSOC);

    if (!$articleRow) {
        header('Location: index.php');
        exit;
    }

    $title       = htmlspecialchars($articleRow['title'], ENT_QUOTES, 'UTF-8');
    $articleText = nl2br(htmlspecialchars($articleRow['content'], ENT_QUOTES, 'UTF-8'));
    $articleDir  = __DIR__ . '/' . $articleId;

    // Check for uploaded media
    $dirContents   = scandir($articleDir);
    $uploadedMedia = '';
    foreach ($dirContents as $item) {
        if ($item !== '.' && $item !== '..' && $item !== 'index.html') {
            $safeItem = htmlspecialchars($item, ENT_QUOTES, 'UTF-8');
            $ext      = strtolower(pathinfo($item, PATHINFO_EXTENSION));
            if ($ext === 'mp4' || $ext === 'webm') {
                $uploadedMedia = <<<HTML

    <div class="media-container">
        <video controls class="video-player">
            <source src="{$safeItem}" type="video/{$ext}">
            Your browser does not support the video tag.
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

    // Gather comments
    $commentStmt = $db->prepare('SELECT comment_text FROM comments 
                                 WHERE article_id = :aid ORDER BY comment_id ASC');
    $commentStmt->bindValue(':aid', $articleId, SQLITE3_INTEGER);
    $commentResult = $commentStmt->execute();

    $commentsHtml = '';
    while ($cRow = $commentResult->fetchArray(SQLITE3_ASSOC)) {
        $safeComment = nl2br(htmlspecialchars($cRow['comment_text'], ENT_QUOTES, 'UTF-8'));
        $commentsHtml .= "<div class=\"comment-item\">{$safeComment}</div>\n";
    }

    // Rebuild article HTML
    $newHtml = <<<HTML
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
    <h1 class="article-title">{$title}</h1>
    <div class="article-body">{$articleText}</div>
    {$uploadedMedia}

    <h2 class="comments-title">Comments</h2>
    <form action="../comment.php?id={$articleId}" method="post" class="comment-form">
        <label for="comment" class="comment-label">Add a comment:</label><br>
        <textarea name="comment" id="comment" rows="4" cols="100" required class="comment-textarea"></textarea><br><br>
        <button type="submit" class="comment-submit">Submit Comment</button>
    </form>

    <div id="comment-list" class="comment-list">
        {$commentsHtml}
    </div>
</div>
</body>
</html>
HTML;

    file_put_contents($articleDir . '/index.html', $newHtml);
    header('Location: ' . $articleId . '/index.html');
    exit;
}

header('Location: index.php');
exit;
