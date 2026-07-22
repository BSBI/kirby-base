<?php
/**
 * HTML view of RSS feed for browsers that don't support XSL
 */

if (!isset($feedTitle)) :
    throw new Exception('No $feedTitle supplied');
endif;

if (!isset($feedLink)) :
    throw new Exception('No $feedLink supplied');
endif;

if (!isset($feedDescription)) :
    throw new Exception('No $feedDescription supplied');
endif;

if (!isset($feedUrl)) :
    throw new Exception('No $feedUrl supplied');
endif;

if (!isset($posts)) :
    throw new Exception('No $posts supplied');
endif;
?>
<!DOCTYPE html>
<html <?php snippet('html-lang') ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($feedTitle) ?> - RSS Feed</title>
    <style>
        :root {
            --text-color: #1a1a1a;
            --bg-color: #ffffff;
            --link-color: #0066cc;
            --border-color: #e0e0e0;
            --secondary-text: #666666;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --text-color: #e0e0e0;
                --bg-color: #1a1a1a;
                --link-color: #6db3f2;
                --border-color: #333333;
                --secondary-text: #999999;
            }
        }
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: var(--text-color);
            background: var(--bg-color);
            max-width: 700px;
            margin: 0 auto;
            padding: 2rem 1rem;
        }
        .feed-header {
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--border-color);
        }
        .feed-header h1 {
            margin: 0 0 0.5rem 0;
            font-size: 1.75rem;
        }
        .feed-header p {
            margin: 0;
            color: var(--secondary-text);
        }
        .feed-notice {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 4px;
            padding: 1rem;
            margin-bottom: 2rem;
            color: #856404;
        }
        @media (prefers-color-scheme: dark) {
            .feed-notice {
                background: #332701;
                border-color: #665200;
                color: #ffc107;
            }
        }
        .feed-notice a {
            color: inherit;
            font-weight: bold;
        }
        .feed-item {
            margin-bottom: 1.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--border-color);
        }
        .feed-item:last-child {
            border-bottom: none;
        }
        .feed-item h2 {
            margin: 0 0 0.25rem 0;
            font-size: 1.25rem;
        }
        .feed-item h2 a {
            color: var(--link-color);
            text-decoration: none;
        }
        .feed-item h2 a:hover {
            text-decoration: underline;
        }
        .feed-item .meta {
            color: var(--secondary-text);
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
        }
        .feed-item .description {
            margin: 0;
        }
        .no-items {
            color: var(--secondary-text);
            font-style: italic;
        }
    </style>
</head>
<body>
    <header class="feed-header">
        <h1><?= esc($feedTitle) ?></h1>
        <p><?= esc($feedDescription) ?></p>
    </header>

    <div class="feed-notice">
        <strong>This is an RSS feed.</strong> Subscribe by copying the URL into your RSS reader.
        <a href="<?= esc($feedUrl) ?>"><?= esc($feedUrl) ?></a>
    </div>

    <?php if ($posts->count() > 0): ?>
        <?php foreach($posts as $post): ?>
        <article class="feed-item">
            <h2><a href="<?= $post->url() ?>"><?= esc($post->title()) ?></a></h2>
            <div class="meta">
                <?php if ($post->publishedDate()->isNotEmpty()): ?>
                    <?= date('j F Y', $post->publishedDate()->toDate()) ?>
                <?php endif ?>
            </div>
            <p class="description">
                <?= $post->mainContent()->toBlocks()->excerpt(200) ?>
            </p>
        </article>
        <?php endforeach ?>
    <?php else: ?>
        <p class="no-items">No items in this feed.</p>
    <?php endif ?>
</body>
</html>