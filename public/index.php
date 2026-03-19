<?php

declare(strict_types=1);
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mikrotik Traffic Counter</title>
    <link rel="stylesheet" href="assets/app.css">
    <script src="https://www.gstatic.com/charts/loader.js"></script>
    <script>
        google.charts.load('current', { packages: ['corechart', 'controls'] });
    </script>
</head>
<body>
    <div class="shell">
        <div class="hero">
            <div>
                <h1>Mikrotik Traffic Counter</h1>
                <p>Async interface-aware traffic monitoring with device summaries, per-interface views, and in-place navigation.</p>
            </div>
            <div class="badge">v2 async client</div>
        </div>
        <div id="app" class="app-grid">
            <div class="loading">Loading...</div>
        </div>
    </div>
    <script src="assets/app.js"></script>
</body>
</html>
