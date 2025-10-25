<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Duo - Todo List Demo</title>
    <script src="https://cdn.tailwindcss.com"></script>
    @vite(['resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-gray-100 min-h-screen py-8">
    <livewire:todo-list />
    @livewireScripts
</body>
</html>
