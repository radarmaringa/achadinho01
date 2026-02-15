<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/header.php';

$pdo = getDB();

// Estatísticas
$totalProdutos = $pdo->query("SELECT COUNT(*) FROM produtos")->fetchColumn();
$totalCategorias = $pdo->query("SELECT COUNT(*) FROM categorias WHERE ativo = 1")->fetchColumn();
$produtosDestaque = $pdo->query("SELECT COUNT(*) FROM produtos WHERE destaque = 1 AND ativo = 1")->fetchColumn();
$produtosAtivos = $pdo->query("SELECT COUNT(*) FROM produtos WHERE ativo = 1")->fetchColumn();

// Produtos recentes
$produtosRecentes = $pdo->query("
    SELECT p.*, c.nome as categoria_nome 
    FROM produtos p 
    LEFT JOIN categorias c ON p.categoria_id = c.id 
    ORDER BY p.created_at DESC 
    LIMIT 5
")->fetchAll();
?>
        <!-- Main Content -->
        <main class="flex-1 overflow-y-auto p-8">
            <h1 class="text-3xl font-bold text-gray-800 mb-8">Dashboard</h1>
            
            <!-- Estatísticas -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="bg-white p-6 rounded-lg shadow">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-gray-500 text-sm">Total de Produtos</p>
                            <p class="text-3xl font-bold text-gray-800 mt-2"><?php echo $totalProdutos; ?></p>
                        </div>
                        <div class="bg-orange-100 p-3 rounded-full">
                            <svg class="w-8 h-8 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                            </svg>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white p-6 rounded-lg shadow">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-gray-500 text-sm">Categorias Ativas</p>
                            <p class="text-3xl font-bold text-gray-800 mt-2"><?php echo $totalCategorias; ?></p>
                        </div>
                        <div class="bg-blue-100 p-3 rounded-full">
                            <svg class="w-8 h-8 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                            </svg>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white p-6 rounded-lg shadow">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-gray-500 text-sm">Produtos em Destaque</p>
                            <p class="text-3xl font-bold text-gray-800 mt-2"><?php echo $produtosDestaque; ?></p>
                        </div>
                        <div class="bg-green-100 p-3 rounded-full">
                            <svg class="w-8 h-8 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"></path>
                            </svg>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white p-6 rounded-lg shadow">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-gray-500 text-sm">Produtos Ativos</p>
                            <p class="text-3xl font-bold text-gray-800 mt-2"><?php echo $produtosAtivos; ?></p>
                        </div>
                        <div class="bg-purple-100 p-3 rounded-full">
                            <svg class="w-8 h-8 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Produtos Recentes -->
            <div class="bg-white rounded-lg shadow">
                <div class="p-6 border-b border-gray-200">
                    <h2 class="text-xl font-bold text-gray-800">Produtos Recentes</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Imagem</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nome</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Categoria</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Preço</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Ações</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php if (empty($produtosRecentes)): ?>
                            <tr>
                                <td colspan="6" class="px-6 py-4 text-center text-gray-500">Nenhum produto cadastrado ainda.</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($produtosRecentes as $produto): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">#<?php echo $produto['id']; ?></td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php if (!empty($produto['imagem'])): ?>
                                    <img src="../<?php echo htmlspecialchars($produto['imagem']); ?>" alt="" class="w-12 h-12 object-cover rounded">
                                    <?php else: ?>
                                    <div class="w-12 h-12 bg-gray-200 rounded flex items-center justify-center text-gray-400">Sem imagem</div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900"><?php echo htmlspecialchars($produto['nome']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($produto['categoria_nome'] ?? 'Sem categoria'); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">R$ <?php echo number_format($produto['preco'] ?? 0, 2, ',', '.'); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <a href="produtos.php?edit=<?php echo $produto['id']; ?>" class="text-orange-500 hover:text-orange-700">Editar</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
