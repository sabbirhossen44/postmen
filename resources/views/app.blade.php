<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Postmen — API Client</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap"
        rel="stylesheet"
    >
    <script>
        // Theme before first paint: dark by default, persisted choice wins.
        try {
            document.documentElement.setAttribute('data-theme', localStorage.getItem('postmen_theme') || 'dark')
        } catch (e) {
            document.documentElement.setAttribute('data-theme', 'dark')
        }
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="pm-canvas pm-text antialiased">
    <div id="app"></div>
</body>
</html>
