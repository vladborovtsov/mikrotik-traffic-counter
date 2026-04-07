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
            <div class="hero-title-row">
                <button id="headerBackButton" class="btn-secondary header-back-button" type="button" hidden aria-label="Back to list">←</button>
                <button id="headerHomeButton" class="hero-title-button" type="button">Mikrotik Traffic Counter</button>
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
    <script src="assets/chart-mode.js"></script>
    <script src="assets/app.js"></script>
</body>
</html>
