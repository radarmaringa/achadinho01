<?php
$pageTitle = 'Categorias';
require_once __DIR__ . '/includes/header.php';

$pdo = getDB();
$message = '';
$messageType = '';

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? null;
    $nome = trim($_POST['nome'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $ordem = (int)($_POST['ordem'] ?? 0);
    $ativo = isset($_POST['ativo']) ? 1 : 0;
    
    if (empty($slug)) {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $nome));
        $slug = trim($slug, '-');
    }
    
    if (empty($nome)) {
        $message = 'O nome da categoria é obrigatório!';
        $messageType = 'error';
    } else {
        if ($id) {
            // Editar
            $stmt = $pdo->prepare("UPDATE categorias SET nome = ?, slug = ?, ordem = ?, ativo = ? WHERE id = ?");
            $stmt->execute([$nome, $slug, $ordem, $ativo, $id]);
            $message = 'Categoria atualizada com sucesso!';
        } else {
            // Criar
            $stmt = $pdo->prepare("INSERT INTO categorias (nome, slug, ordem, ativo) VALUES (?, ?, ?, ?)");
            $stmt->execute([$nome, $slug, $ordem, $ativo]);
            $message = 'Categoria cadastrada com sucesso!';
        }
        $messageType = 'success';
    }
}

// Deletar categoria
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    // Verificar se há produtos usando esta categoria
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM produtos WHERE categoria_id = ?");
    $stmt->execute([$id]);
    $count = $stmt->fetchColumn();
    
    if ($count > 0) {
        $message = 'Não é possível deletar esta categoria pois existem produtos associados a ela!';
        $messageType = 'error';
    } else {
        $stmt = $pdo->prepare("DELETE FROM categorias WHERE id = ?");
        $stmt->execute([$id]);
        $message = 'Categoria deletada com sucesso!';
        $messageType = 'success';
    }
}

// Editar categoria
$editCategoria = null;
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM categorias WHERE id = ?");
    $stmt->execute([$id]);
    $editCategoria = $stmt->fetch();
}

// Listar categorias
$categorias = $pdo->query("SELECT c.*, COUNT(p.id) as total_produtos FROM categorias c LEFT JOIN produtos p ON c.id = p.categoria_id GROUP BY c.id ORDER BY c.ordem, c.nome")->fetchAll();
?>
        <main class="flex-1 overflow-y-auto p-8">
            <div class="flex justify-between items-center mb-8">
                <h1 class="text-3xl font-bold text-gray-800">Categorias</h1>
                <button onclick="showForm()" class="bg-orange-500 hover:bg-orange-600 text-white font-bold py-2 px-4 rounded transition-colors">
                    + Nova Categoria
                </button>
            </div>
            
            <?php if ($message): ?>
            <div class="mb-4 p-4 rounded <?php echo $messageType === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>
            
            <!-- Formulário -->
            <div id="formContainer" class="bg-white rounded-lg shadow p-6 mb-8 <?php echo $editCategoria ? '' : 'hidden'; ?>">
                <h2 class="text-xl font-bold text-gray-800 mb-4">
                    <?php echo $editCategoria ? 'Editar Categoria' : 'Nova Categoria'; ?>
                </h2>
                
                <form method="POST">
                    <?php if ($editCategoria): ?>
                    <input type="hidden" name="id" value="<?php echo $editCategoria['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="nome" class="block text-sm font-medium text-gray-700 mb-2">Nome *</label>
                            <input type="text" id="nome" name="nome" required
                                   value="<?php echo htmlspecialchars($editCategoria['nome'] ?? ''); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        
                        <div>
                            <label for="slug" class="block text-sm font-medium text-gray-700 mb-2">Slug</label>
                            <input type="text" id="slug" name="slug"
                                   value="<?php echo htmlspecialchars($editCategoria['slug'] ?? ''); ?>"
                                   placeholder="auto-gerado"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                            <p class="mt-1 text-xs text-gray-500">URL amigável (será gerada automaticamente se vazio)</p>
                        </div>
                        
                        <div>
                            <label for="ordem" class="block text-sm font-medium text-gray-700 mb-2">Ordem</label>
                            <input type="number" id="ordem" name="ordem" min="0"
                                   value="<?php echo $editCategoria['ordem'] ?? 0; ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        
                        <div class="flex items-center mt-6">
                            <label class="flex items-center">
                                <input type="checkbox" name="ativo" value="1"
                                       <?php echo ($editCategoria && $editCategoria['ativo']) ? 'checked' : ''; ?>
                                       class="rounded border-gray-300 text-orange-500 focus:ring-orange-500">
                                <span class="ml-2 text-sm text-gray-700">Ativo</span>
                            </label>
                        </div>
                    </div>
                    
                    <div class="mt-6 flex gap-4">
                        <button type="submit" class="bg-orange-500 hover:bg-orange-600 text-white font-bold py-2 px-6 rounded transition-colors">
                            Salvar
                        </button>
                        <button type="button" onclick="hideForm()" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-6 rounded transition-colors">
                            Cancelar
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Lista de categorias -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nome</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Slug</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Ordem</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Produtos</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Ações</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php if (empty($categorias)): ?>
                            <tr>
                                <td colspan="7" class="px-6 py-4 text-center text-gray-500">Nenhuma categoria cadastrada ainda.</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($categorias as $categoria): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">#<?php echo $categoria['id']; ?></td>
                                <td class="px-6 py-4 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($categoria['nome']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($categoria['slug']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $categoria['ordem']; ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $categoria['total_produtos']; ?></td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php if ($categoria['ativo']): ?>
                                    <span class="px-2 py-1 text-xs rounded bg-green-100 text-green-800">Ativo</span>
                                    <?php else: ?>
                                    <span class="px-2 py-1 text-xs rounded bg-red-100 text-red-800">Inativo</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <a href="?edit=<?php echo $categoria['id']; ?>" class="text-orange-500 hover:text-orange-700 mr-3">Editar</a>
                                    <a href="?delete=<?php echo $categoria['id']; ?>"
                                       onclick="return confirm('Tem certeza que deseja deletar esta categoria?')"
                                       class="text-red-500 hover:text-red-700">Deletar</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
        
        <script>
        function showForm() {
            document.getElementById('formContainer').classList.remove('hidden');
            document.getElementById('formContainer').scrollIntoView({ behavior: 'smooth' });
        }
        
        function hideForm() {
            document.getElementById('formContainer').classList.add('hidden');
            window.location.href = 'categorias.php';
        }
        </script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
