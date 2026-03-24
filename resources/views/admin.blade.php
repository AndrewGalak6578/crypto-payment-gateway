<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ config('app.name', 'Laravel') }} - Admin</title>

    @vite('resources/js/admin-portal/main.js')
</head>
<body>
<div id="admin-app"></div>
</body>
</html>
