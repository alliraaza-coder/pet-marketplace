<?php
/**
 * Admin Categories Management
 * Phase 3.3
 */
$page_title = "Categories Management";
$page_heading = "Manage Categories";
include __DIR__ . '/partials/header.php';

$admin_id = $_SESSION['user_id'];

// Handle Actions (Add, Edit, Enable, Disable, Soft Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = sanitize_input($_POST['action']);

    if ($action === 'add') {
        $name_en = sanitize_input($_POST['name_en']);
        $name_ar = sanitize_input($_POST['name_ar'] ?? '');
        $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : NULL;
        $status = sanitize_input($_POST['status'] ?? 'active');

        // Handle Image Upload
        $image = 'default-category.png';
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../assets/images/categories/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $image_name = 'cat_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
            if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . $image_name)) {
                $image = 'categories/' . $image_name;
            }
        }

        $stmt = $conn->prepare("INSERT INTO categories (name_en, name_ar, parent_id, image, status) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("ssiss", $name_en, $name_ar, $parent_id, $image, $status);
        
        if ($stmt->execute()) {
            log_admin_activity($conn, $admin_id, "Added Category", "Added Category: $name_en");
            $_SESSION['success'] = "Category added successfully.";
        } else {
            $_SESSION['error'] = "Error adding category.";
        }
    } elseif ($action === 'edit' && isset($_POST['category_id'])) {
        $cat_id = (int)$_POST['category_id'];
        $name_en = sanitize_input($_POST['name_en']);
        $name_ar = sanitize_input($_POST['name_ar'] ?? '');
        $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : NULL;
        $status = sanitize_input($_POST['status'] ?? 'active');

        // Handle Image Upload
        $image_query = "";
        $params = [$name_en, $name_ar, $parent_id, $status];
        $types = "ssis";

        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../assets/images/categories/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $image_name = 'cat_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
            if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . $image_name)) {
                $image_query = ", image = ?";
                $params[] = 'categories/' . $image_name;
                $types .= "s";
            }
        }

        $params[] = $cat_id;
        $types .= "i";

        $stmt = $conn->prepare("UPDATE categories SET name_en = ?, name_ar = ?, parent_id = ?, status = ? $image_query WHERE id = ?");
        $stmt->bind_param($types, ...$params);
        
        if ($stmt->execute()) {
            log_admin_activity($conn, $admin_id, "Edited Category", "Edited Category ID: $cat_id ($name_en)");
            $_SESSION['success'] = "Category updated successfully.";
        } else {
            $_SESSION['error'] = "Error updating category.";
        }
    } elseif ($action === 'enable' && isset($_POST['category_id'])) {
        $cat_id = (int)$_POST['category_id'];
        $stmt = $conn->prepare("UPDATE categories SET status = 'active' WHERE id = ?");
        $stmt->bind_param("i", $cat_id);
        if ($stmt->execute()) {
            log_admin_activity($conn, $admin_id, "Enabled Category", "Enabled Category ID: $cat_id");
            $_SESSION['success'] = "Category enabled successfully.";
        }
    } elseif ($action === 'disable' && isset($_POST['category_id'])) {
        $cat_id = (int)$_POST['category_id'];
        $stmt = $conn->prepare("UPDATE categories SET status = 'inactive' WHERE id = ?");
        $stmt->bind_param("i", $cat_id);
        if ($stmt->execute()) {
            log_admin_activity($conn, $admin_id, "Disabled Category", "Disabled Category ID: $cat_id");
            $_SESSION['success'] = "Category disabled successfully.";
        }
    } elseif ($action === 'delete' && isset($_POST['category_id'])) {
        $cat_id = (int)$_POST['category_id'];
        // Check if it has subcategories
        $sub_stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM categories WHERE parent_id = ? AND is_deleted = 0");
        $sub_stmt->bind_param("i", $cat_id);
        $sub_stmt->execute();
        if ($sub_stmt->get_result()->fetch_assoc()['cnt'] > 0) {
            $_SESSION['error'] = "Cannot delete category because it has active subcategories.";
        } else {
            $stmt = $conn->prepare("UPDATE categories SET is_deleted = 1, deleted_at = NOW() WHERE id = ?");
            $stmt->bind_param("i", $cat_id);
            if ($stmt->execute()) {
                log_admin_activity($conn, $admin_id, "Soft Deleted Category", "Soft deleted Category ID: $cat_id");
                $_SESSION['success'] = "Category soft deleted successfully.";
            }
        }
    }
    header('Location: categories.php');
    exit;
}

// Fetch Parent Categories for dropdown
$parent_cat_stmt = $conn->query("SELECT id, name_en FROM categories WHERE parent_id IS NULL AND is_deleted = 0 ORDER BY name_en ASC");
$parent_categories = [];
while ($c = $parent_cat_stmt->fetch_assoc()) $parent_categories[] = $c;

// Fetch All Categories
$sql = "SELECT c.*, p.name_en AS parent_name, 
        (SELECT COUNT(*) FROM products WHERE category_id = c.id AND is_deleted = 0) AS product_count 
        FROM categories c 
        LEFT JOIN categories p ON c.parent_id = p.id 
        WHERE c.is_deleted = 0 
        ORDER BY p.name_en ASC, c.name_en ASC";
$categories = $conn->query($sql);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-1">Categories Management</h4>
        <p class="text-muted small mb-0">Manage product categories and subcategories</p>
    </div>
    <button class="btn btn-primary rounded-pill px-4 fw-bold" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
        <i class="bi bi-plus-circle me-2"></i> Add Category
    </button>
</div>

<div class="card border-0 shadow-sm rounded-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Image</th>
                        <th>Name (English)</th>
                        <th>Name (Arabic)</th>
                        <th>Parent Category</th>
                        <th>Products</th>
                        <th>Status</th>
                        <th class="pe-4 text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($categories && $categories->num_rows > 0): ?>
                        <?php while ($cat = $categories->fetch_assoc()): ?>
                            <tr>
                                <td class="ps-4">
                                    <img src="<?php echo BASE_URL; ?>/assets/images/<?php echo htmlspecialchars($cat['image'] ?: 'default-category.png'); ?>" class="rounded bg-light object-fit-cover" width="40" height="40" onerror="this.src='<?php echo BASE_URL; ?>/assets/images/default-category.png'">
                                </td>
                                <td class="fw-bold"><?php echo htmlspecialchars($cat['name_en']); ?></td>
                                <td dir="rtl"><?php echo htmlspecialchars($cat['name_ar']); ?></td>
                                <td>
                                    <?php if ($cat['parent_name']): ?>
                                        <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($cat['parent_name']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted small">— Main Category —</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-secondary rounded-pill"><?php echo $cat['product_count']; ?></span></td>
                                <td>
                                    <?php if ($cat['status'] === 'active'): ?>
                                        <span class="badge bg-success rounded-pill px-3 py-1">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger rounded-pill px-3 py-1">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="pe-4 text-end">
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-light border rounded-circle" type="button" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical"></i></button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow">
                                            <li>
                                                <button class="dropdown-item" onclick="editCategory(<?php echo htmlspecialchars(json_encode($cat)); ?>)">
                                                    <i class="bi bi-pencil me-2"></i> Edit Category
                                                </button>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            
                                            <?php if ($cat['status'] === 'active'): ?>
                                                <li>
                                                    <form action="categories.php" method="POST" class="d-inline">
                                                        <input type="hidden" name="action" value="disable">
                                                        <input type="hidden" name="category_id" value="<?php echo $cat['id']; ?>">
                                                        <button type="submit" class="dropdown-item text-warning"><i class="bi bi-x-circle me-2"></i> Disable</button>
                                                    </form>
                                                </li>
                                            <?php else: ?>
                                                <li>
                                                    <form action="categories.php" method="POST" class="d-inline">
                                                        <input type="hidden" name="action" value="enable">
                                                        <input type="hidden" name="category_id" value="<?php echo $cat['id']; ?>">
                                                        <button type="submit" class="dropdown-item text-success"><i class="bi bi-check-circle me-2"></i> Enable</button>
                                                    </form>
                                                </li>
                                            <?php endif; ?>
                                            
                                            <li>
                                                <form action="categories.php" method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="category_id" value="<?php echo $cat['id']; ?>">
                                                    <button type="submit" class="dropdown-item text-danger" onclick="return confirm('Soft delete this category?');"><i class="bi bi-trash me-2"></i> Delete</button>
                                                </form>
                                            </li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="7" class="text-center py-5 text-muted">No categories found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Category Modal -->
<div class="modal fade" id="addCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content rounded-4 border-0 shadow">
            <form action="categories.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold">Add Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Name (English) <span class="text-danger">*</span></label>
                        <input type="text" name="name_en" class="form-control bg-light border-0" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Name (Arabic)</label>
                        <input type="text" name="name_ar" class="form-control bg-light border-0" dir="rtl">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Parent Category</label>
                        <select name="parent_id" class="form-select bg-light border-0">
                            <option value="">— None (Main Category) —</option>
                            <?php foreach ($parent_categories as $pcat): ?>
                                <option value="<?php echo $pcat['id']; ?>"><?php echo htmlspecialchars($pcat['name_en']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Status</label>
                        <select name="status" class="form-select bg-light border-0">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Category Image</label>
                        <input type="file" name="image" class="form-control bg-light border-0" accept="image/*">
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">Save Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Category Modal -->
<div class="modal fade" id="editCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content rounded-4 border-0 shadow">
            <form action="categories.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="category_id" id="edit_category_id">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold">Edit Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Name (English) <span class="text-danger">*</span></label>
                        <input type="text" name="name_en" id="edit_name_en" class="form-control bg-light border-0" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Name (Arabic)</label>
                        <input type="text" name="name_ar" id="edit_name_ar" class="form-control bg-light border-0" dir="rtl">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Parent Category</label>
                        <select name="parent_id" id="edit_parent_id" class="form-select bg-light border-0">
                            <option value="">— None (Main Category) —</option>
                            <?php foreach ($parent_categories as $pcat): ?>
                                <option value="<?php echo $pcat['id']; ?>"><?php echo htmlspecialchars($pcat['name_en']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Status</label>
                        <select name="status" id="edit_status" class="form-select bg-light border-0">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Category Image (Leave empty to keep current)</label>
                        <input type="file" name="image" class="form-control bg-light border-0" accept="image/*">
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">Update Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editCategory(cat) {
    document.getElementById('edit_category_id').value = cat.id;
    document.getElementById('edit_name_en').value = cat.name_en;
    document.getElementById('edit_name_ar').value = cat.name_ar;
    document.getElementById('edit_parent_id').value = cat.parent_id || '';
    document.getElementById('edit_status').value = cat.status;
    
    var editModal = new bootstrap.Modal(document.getElementById('editCategoryModal'));
    editModal.show();
}
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
