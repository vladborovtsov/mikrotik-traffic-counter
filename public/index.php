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
            </div>
            <div class="hero-actions">
                <button id="openGlobalSettings" class="btn-secondary" type="button">Settings</button>
                <label class="theme-switch">
                    <span>Theme</span>
                    <select id="themeModeSelector">
                        <option value="auto">Auto</option>
                        <option value="light">Light</option>
                        <option value="dark">Dark</option>
                    </select>
                </label>
            </div>
        </div>
        <div id="app" class="app-grid">
            <div class="loading">Loading...</div>
        </div>
    </div>
    <script src="assets/app.js"></script>
</body>
</html>
