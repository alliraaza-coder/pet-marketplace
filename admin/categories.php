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
        $name_ur = sanitize_input($_POST['name_ur'] ?? '');
        $section = sanitize_input($_POST['section'] ?? 'birds');
        $status = sanitize_input($_POST['status'] ?? 'active');
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name_en)));

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

        $stmt = $conn->prepare("INSERT INTO categories (name_en, name_ur, section, slug, image, status) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssss", $name_en, $name_ur, $section, $slug, $image, $status);
        
        if ($stmt->execute()) {
            log_admin_activity($conn, $admin_id, "Added Category", "Added Category: $name_en");
            $_SESSION['success'] = "Category added successfully.";
        } else {
            $_SESSION['error'] = "Error adding category.";
        }
    } elseif ($action === 'edit' && isset($_POST['category_id'])) {
        $cat_id = (int)$_POST['category_id'];
        $name_en = sanitize_input($_POST['name_en']);
        $name_ur = sanitize_input($_POST['name_ur'] ?? '');
        $section = sanitize_input($_POST['section'] ?? 'birds');
        $status = sanitize_input($_POST['status'] ?? 'active');
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name_en)));

        // Handle Image Upload
        $image_query = "";
        $params = [$name_en, $name_ur, $section, $slug, $status];
        $types = "sssss";

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

        $stmt = $conn->prepare("UPDATE categories SET name_en = ?, name_ur = ?, section = ?, slug = ?, status = ? $image_query WHERE id = ?");
        $bind_params = array_merge([$types], $params);
        $tmp = [];
        foreach($bind_params as $key => $value) $tmp[$key] = &$bind_params[$key];
        call_user_func_array([$stmt, 'bind_param'], $tmp);
        
        if ($stmt->execute()) {
            log_admin_activity($conn, $admin_id, "Edited Category", "Edited Category ID: $cat_id");
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
        
        // Soft delete
        $stmt = $conn->prepare("UPDATE categories SET is_deleted = 1, deleted_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->bind_param("i", $cat_id);
        if ($stmt->execute()) {
            log_admin_activity($conn, $admin_id, "Deleted Category", "Soft Deleted Category ID: $cat_id");
            $_SESSION['success'] = "Category deleted successfully.";
        } else {
            $_SESSION['error'] = "Failed to delete category.";
        }
    }
    header('Location: categories.php');
    exit;
}

// Fetch All Categories
$sql = "SELECT c.*, 
        (SELECT COUNT(*) FROM products WHERE category_id = c.id AND is_deleted = 0) AS product_count 
        FROM categories c 
        WHERE c.is_deleted = 0 
        ORDER BY c.section ASC, c.name_en ASC";
$categories = $conn->query($sql);
?>

<div class="container-fluid py-4 px-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold mb-0 text-dark"><i class="bi bi-tags text-primary me-2"></i>Categories</h3>
        <button class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
            <i class="bi bi-plus-lg me-1"></i> Add Category
        </button>
    </div>

    <?php display_messages(); ?>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light text-muted" style="font-size: 0.85rem; letter-spacing: 0.5px;">
                        <tr>
                            <th class="ps-4 py-3 fw-semibold border-0">CATEGORY</th>
                            <th class="py-3 fw-semibold border-0">SECTION</th>
                            <th class="py-3 fw-semibold border-0 text-center">PRODUCTS</th>
                            <th class="py-3 fw-semibold border-0 text-center">STATUS</th>
                            <th class="pe-4 py-3 fw-semibold border-0 text-end">ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody class="border-top-0">
                        <?php if ($categories->num_rows > 0): ?>
                            <?php while ($c = $categories->fetch_assoc()): ?>
                                <tr>
                                    <td class="ps-4 py-3">
                                        <div class="d-flex align-items-center">
                                            <img src="<?= BASE_URL ?>/assets/images/<?= htmlspecialchars($c['image']) ?>" 
                                                 alt="<?= htmlspecialchars($c['name_en']) ?>" 
                                                 class="rounded-3 shadow-sm border" 
                                                 style="width: 48px; height: 48px; object-fit: cover;">
                                            <div class="ms-3">
                                                <h6 class="mb-1 fw-bold text-dark"><?= htmlspecialchars($c['name_en']) ?></h6>
                                                <small class="text-muted"><?= htmlspecialchars($c['name_ur'] ?? '') ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="py-3">
                                        <span class="badge bg-info text-dark rounded-pill"><?= htmlspecialchars(ucfirst($c['section'])) ?></span>
                                    </td>
                                    <td class="py-3 text-center">
                                        <span class="badge bg-light text-dark border px-3 rounded-pill"><?= $c['product_count'] ?></span>
                                    </td>
                                    <td class="py-3 text-center">
                                        <?php if ($c['status'] === 'active'): ?>
                                            <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-3">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill px-3">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="pe-4 py-3 text-end">
                                        <div class="dropdown">
                                            <button class="btn btn-light btn-sm rounded-circle shadow-sm border" type="button" data-bs-toggle="dropdown">
                                                <i class="bi bi-three-dots-vertical"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                                                <li><a class="dropdown-item edit-btn" href="#" data-category='<?= htmlspecialchars(json_encode($c), ENT_QUOTES) ?>' data-bs-toggle="modal" data-bs-target="#editCategoryModal"><i class="bi bi-pencil-square me-2 text-primary"></i>Edit</a></li>
                                                <?php if ($c['status'] === 'active'): ?>
                                                    <li>
                                                        <form method="POST">
                                                            <?php csrf_field(); ?>
                                                            <input type="hidden" name="action" value="disable">
                                                            <input type="hidden" name="category_id" value="<?= $c['id'] ?>">
                                                            <button class="dropdown-item" type="submit"><i class="bi bi-eye-slash me-2 text-warning"></i>Disable</button>
                                                        </form>
                                                    </li>
                                                <?php else: ?>
                                                    <li>
                                                        <form method="POST">
                                                            <?php csrf_field(); ?>
                                                            <input type="hidden" name="action" value="enable">
                                                            <input type="hidden" name="category_id" value="<?= $c['id'] ?>">
                                                            <button class="dropdown-item" type="submit"><i class="bi bi-eye me-2 text-success"></i>Enable</button>
                                                        </form>
                                                    </li>
                                                <?php endif; ?>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <form method="POST" onsubmit="return confirm('Delete this category? Products might be affected.');">
                                                        <?php csrf_field(); ?>
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="category_id" value="<?= $c['id'] ?>">
                                                        <button class="dropdown-item text-danger" type="submit"><i class="bi bi-trash3 me-2"></i>Delete</button>
                                                    </form>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="text-center py-5 text-muted">No categories found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add Category Modal -->
<div class="modal fade" id="addCategoryModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header border-0 bg-light rounded-top-4 px-4 py-3">
                <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle me-2 text-primary"></i>Add Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="categories.php" method="POST" enctype="multipart/form-data">
                <div class="modal-body px-4 py-4">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="add">
                    
                    <div class="mb-3">
                        <label class="form-label fw-medium">Name (English) <span class="text-danger">*</span></label>
                        <input type="text" class="form-control bg-light border-0" name="name_en" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-medium">Name (Urdu)</label>
                        <input type="text" class="form-control bg-light border-0" name="name_ur">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-medium">Section <span class="text-danger">*</span></label>
                        <select name="section" class="form-select bg-light border-0" required>
                            <option value="birds">Birds</option>
                            <option value="animals">Animals</option>
                            <option value="accessories">Accessories</option>
                            <option value="foods">Foods</option>
                            <option value="market">Market</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Status</label>
                        <select name="status" class="form-select bg-light border-0">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Category Image</label>
                        <input type="file" class="form-control bg-light border-0" name="image" accept="image/*">
                        <div class="form-text">Recommended size: 400x400px</div>
                    </div>
                </div>
                <div class="modal-footer border-0 px-4 pb-4">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm">Save Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Category Modal -->
<div class="modal fade" id="editCategoryModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header border-0 bg-light rounded-top-4 px-4 py-3">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2 text-primary"></i>Edit Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="categories.php" method="POST" enctype="multipart/form-data">
                <div class="modal-body px-4 py-4">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="category_id" id="edit_category_id">
                    
                    <div class="mb-3">
                        <label class="form-label fw-medium">Name (English) <span class="text-danger">*</span></label>
                        <input type="text" class="form-control bg-light border-0" name="name_en" id="edit_name_en" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-medium">Name (Urdu)</label>
                        <input type="text" class="form-control bg-light border-0" name="name_ur" id="edit_name_ur">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-medium">Section <span class="text-danger">*</span></label>
                        <select name="section" id="edit_section" class="form-select bg-light border-0" required>
                            <option value="birds">Birds</option>
                            <option value="animals">Animals</option>
                            <option value="accessories">Accessories</option>
                            <option value="foods">Foods</option>
                            <option value="market">Market</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Status</label>
                        <select name="status" id="edit_status" class="form-select bg-light border-0">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Category Image</label>
                        <input type="file" class="form-control bg-light border-0" name="image" accept="image/*">
                        <div class="form-text">Leave blank to keep existing image.</div>
                        <img id="edit_image_preview" src="" alt="Preview" class="mt-2 rounded-3 border" style="width:60px; height:60px; object-fit:cover; display:none;">
                    </div>
                </div>
                <div class="modal-footer border-0 px-4 pb-4">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm">Update Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const editBtns = document.querySelectorAll('.edit-btn');
    editBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const cat = JSON.parse(this.getAttribute('data-category'));
            document.getElementById('edit_category_id').value = cat.id;
            document.getElementById('edit_name_en').value = cat.name_en;
            document.getElementById('edit_name_ur').value = cat.name_ur || '';
            document.getElementById('edit_section').value = cat.section || 'birds';
            document.getElementById('edit_status').value = cat.status;
            
            if(cat.image) {
                const img = document.getElementById('edit_image_preview');
                img.src = '<?= BASE_URL ?>/assets/images/' + cat.image;
                img.style.display = 'block';
            }
        });
    });
});
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
