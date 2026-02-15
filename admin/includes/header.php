<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/functions.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="/favicon.png" />
    <title><?php echo $pageTitle ?? 'Painel Admin'; ?> - OfertasJá</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-gray-100">
    <div class="flex h-screen">
        <!-- Sidebar -->
        <aside class="w-64 bg-gray-800 text-white flex flex-col">
            <div class="p-6 border-b border-gray-700">
                <h2 class="text-xl font-bold">OfertasJá Admin</h2>
            </div>
            
            <nav class="flex-1 p-4">
                <a href="index.php" 
                   class="flex items-center gap-3 p-3 rounded-lg mb-2 transition-colors <?php echo $currentPage === 'index.php' ? 'bg-orange-500 text-white' : 'text-gray-300 hover:bg-gray-700'; ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                    </svg>
                    Dashboard
                </a>
                
                <a href="produtos.php" 
                   class="flex items-center gap-3 p-3 rounded-lg mb-2 transition-colors <?php echo $currentPage === 'produtos.php' || strpos($currentPage, 'produto') !== false ? 'bg-orange-500 text-white' : 'text-gray-300 hover:bg-gray-700'; ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                    </svg>
                    Produtos
                </a>
                
                <a href="categorias.php" 
                   class="flex items-center gap-3 p-3 rounded-lg mb-2 transition-colors <?php echo $currentPage === 'categorias.php' ? 'bg-orange-500 text-white' : 'text-gray-300 hover:bg-gray-700'; ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                    </svg>
                    Categorias
                </a>
                
                <a href="mercadolivre.php" 
                   class="flex items-center gap-3 p-3 rounded-lg mb-2 transition-colors <?php echo $currentPage === 'mercadolivre.php' ? 'bg-orange-500 text-white' : 'text-gray-300 hover:bg-gray-700'; ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                    </svg>
                    Mercado Livre
                </a>
                
                <a href="shopee.php" 
                   class="flex items-center gap-3 p-3 rounded-lg mb-2 transition-colors <?php echo $currentPage === 'shopee.php' ? 'bg-orange-500 text-white' : 'text-gray-300 hover:bg-gray-700'; ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                    </svg>
                    Shopee
                </a>
                
                <a href="magalu.php" 
                   class="flex items-center gap-3 p-3 rounded-lg mb-2 transition-colors <?php echo $currentPage === 'magalu.php' ? 'bg-orange-500 text-white' : 'text-gray-300 hover:bg-gray-700'; ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                    </svg>
                    Magalu
                </a>
                
                <a href="configuracoes.php" 
                   class="flex items-center gap-3 p-3 rounded-lg mb-2 transition-colors <?php echo $currentPage === 'configuracoes.php' ? 'bg-orange-500 text-white' : 'text-gray-300 hover:bg-gray-700'; ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    </svg>
                    Configurações
                </a>
            </nav>
            
            <div class="p-4 border-t border-gray-700">
                <div class="mb-3 text-sm text-gray-400">
                    Logado como: <strong class="text-white"><?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?></strong>
                </div>
                <a href="logout.php" 
                   class="flex items-center gap-3 p-3 rounded-lg text-gray-300 hover:bg-gray-700 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                    </svg>
                    Sair
                </a>
            </div>
        </aside>
