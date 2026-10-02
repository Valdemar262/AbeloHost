<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$pageTitle} — {$appName}</title>
    <link rel="stylesheet" href="{$stylesheetUrl|default:'/assets/css/app.css'}">
</head>
<body>
    <a class="skip-link" href="#content">Перейти к содержимому</a>
    <header class="site-header">
        <a class="brand" href="/">{$appName}</a>
    </header>
    <main id="content" class="container" tabindex="-1">
        {block name="content"}{/block}
    </main>
    <footer class="site-footer">{$appName}</footer>
</body>
</html>
