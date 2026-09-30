<?php
/**
 * Admin Categories Management
 * Phase 3.3
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_role('admin');

$page_title = "Categories Management";
$page_heading = "Manage Categories";

$admin_id = $_SESSION['user_id'];

// Handle Actions (Add, Edit, Enable, Disable, Soft Delete) — BEFORE header include
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf();
    $action = sanitize_input($_POST['action']);

    if ($action === 'add') {
        $name_en = sanitize_input($_POST['name_en']);
        $name_ur = sanitize_input($_POST['name_ur'] ?? '');
        $section = sanitize_input($_POST['section'] ?? 'birds');
        $status  = sanitize_input($_POST['status'] ?? 'active');
        $slug    = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name_en)));

        $image = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../assets/images/categories/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $ext        = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            $image_name = 'cat_' . bin2hex(random_bytes(6)) . '.' . $ext;
            if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . $image_name)) {
                $image = 'categories/' . $image_name;
            }
        }

        $stmt = $conn->prepare("INSERT INTO categories (name_en, name_ur, section, slug, image, status) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssss", $name_en, $name_ur, $section, $slug, $image, $status);
        if ($stmt->execute()) {
            log_admin_activity($conn, $admin_id, "Added Category", "Added: $name_en");
            $_SESSION['success'] = "Category added successfully.";
        } else {
            $_SESSION['error'] = "Error adding category.";
        }

    } elseif ($action === 'edit' && isset($_POST['category_id'])) {
        $cat_id  = (int)$_POST['category_id'];
        $name_en = sanitize_input($_POST['name_en']);
        $name_ur = sanitize_input($_POST['name_ur'] ?? '');
        $section = sanitize_input($_POST['section'] ?? 'birds');
        $status  = sanitize_input($_POST['status'] ?? 'active');
        $slug    = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name_en)));

        $image_sql = '';
        $params    = [$name_en, $name_ur, $section, $slug, $status];
        $types     = 'sssss';

        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../assets/images/categories/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $ext        = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            $image_name = 'cat_' . bin2hex(random_bytes(6)) . '.' . $ext;
            if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . $image_name)) {
                $image_sql  = ', image = ?';
                $params[]   = 'categories/' . $image_name;
                $types     .= 's';
            }
        }

        $params[] = $cat_id;
        $types   .= 'i';

        $stmt = $conn->prepare("UPDATE categories SET name_en=?, name_ur=?, section=?, slug=?, status=? $image_sql WHERE id=?");
        $bind = array_merge([$types], $params);
        $tmp  = [];
        foreach ($bind as $k => $v) $tmp[$k] = &$bind[$k];
        call_user_func_array([$stmt, 'bind_param'], $tmp);

        if ($stmt->execute()) {
            log_admin_activity($conn, $admin_id, "Edited Category", "Edited ID: $cat_id");
            $_SESSION['success'] = "Category updated successfully.";
        } else {
            $_SESSION['error'] = "Error updating category.";
        }

    } elseif ($action === 'enable' && isset($_POST['category_id'])) {
        $cat_id = (int)$_POST['category_id'];
        $stmt   = $conn->prepare("UPDATE categories SET status='active' WHERE id=?");
        $stmt->bind_param("i", $cat_id);
        if ($stmt->execute()) {
            log_admin_activity($conn, $admin_id, "Enabled Category", "ID: $cat_id");
            $_SESSION['success'] = "Category enabled.";
        }

    } elseif ($action === 'disable' && isset($_POST['category_id'])) {
        $cat_id = (int)$_POST['category_id'];
        $stmt   = $conn->prepare("UPDATE categories SET status='inactive' WHERE id=?");
        $stmt->bind_param("i", $cat_id);
        if ($stmt->execute()) {
            log_admin_activity($conn, $admin_id, "Disabled Category", "ID: $cat_id");
            $_SESSION['success'] = "Category disabled.";
        }

    } elseif ($action === 'delete' && isset($_POST['category_id'])) {
        $cat_id = (int)$_POST['category_id'];
        // Check for active products first
        $chk = $conn->prepare("SELECT COUNT(*) AS cnt FROM products WHERE category_id=? AND is_deleted=0");
        $chk->bind_param("i", $cat_id);
        $chk->execute();
        $cnt = $chk->get_result()->fetch_assoc()['cnt'];
        if ($cnt > 0) {
            $_SESSION['error'] = "Cannot delete: this category has $cnt active product(s). Disable them first.";
        } else {
            $stmt = $conn->prepare("UPDATE categories SET is_deleted=1, deleted_at=CURRENT_TIMESTAMP WHERE id=?");
            $stmt->bind_param("i", $cat_id);
            if ($stmt->execute()) {
                log_admin_activity($conn, $admin_id, "Deleted Category", "ID: $cat_id");
                $_SESSION['success'] = "Category deleted.";
            } else {
                $_SESSION['error'] = "Failed to delete category.";
            }
        }
    }

    header('Location: categories.php');
    exit;
}

// Fetch all categories
$sql        = "SELECT c.*,
               (SELECT COUNT(*) FROM products WHERE category_id=c.id AND is_deleted=0) AS product_count
               FROM categories c
               WHERE c.is_deleted=0
               ORDER BY c.section ASC, c.name_en ASC";
$categories = $conn->query($sql);

include __DIR__ . '/partials/header.php';
?>

<div class="container-fluid py-4 px-4">
    <!-- Page header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold mb-0 text-dark"><i class="bi bi-tags text-primary me-2"></i>Manage Categories</h3>
        <button class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
            <i class="bi bi-plus-lg me-1"></i> Add Category
        </button>
    </div>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light" style="font-size:.83rem;letter-spacing:.5px;">
                        <tr>
                            <th class="ps-4 py-3 fw-semibold border-0 text-muted">CATEGORY</th>
                            <th class="py-3 fw-semibold border-0 text-muted">SECTION</th>
                            <th class="py-3 fw-semibold border-0 text-center text-muted">PRODUCTS</th>
                            <th class="py-3 fw-semibold border-0 text-center text-muted">STATUS</th>
                            <th class="pe-4 py-3 fw-semibold border-0 text-end text-muted">ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody class="border-top-0">
                        <?php if ($categories && $categories->num_rows > 0): ?>
                            <?php while ($c = $categories->fetch_assoc()): ?>
                                <?php
                                // Build image URL — fallback to generated avatar if NULL
                                $img_url = !empty($c['image'])
                                    ? BASE_URL . '/assets/images/' . htmlspecialchars($c['image'])
                                    : 'https://ui-avatars.com/api/?name=' . urlencode($c['name_en']) . '&background=e8f4fd&color=1a73e8&size=100&font-size=0.4&bold=true';
                                $img_err = 'this.src=\'https://ui-avatars.com/api/?name=' . urlencode($c['name_en']) . '&background=e8f4fd&color=1a73e8&size=100&font-size=0.4&bold=true\'';
                                ?>
                                <tr>
                                    <td class="ps-4 py-3">
                                        <div class="d-flex align-items-center">
                                            <img src="<?= $img_url ?>"
                                                 alt="<?= htmlspecialchars($c['name_en']) ?>"
                                                 onerror="<?= $img_err ?>"
                                                 class="rounded-3 border shadow-sm"
                                                 style="width:48px;height:48px;object-fit:cover;flex-shrink:0;">
                                            <div class="ms-3">
                                                <h6 class="mb-0 fw-bold text-dark"><?= htmlspecialchars($c['name_en']) ?></h6>
                                                <?php if (!empty($c['name_ur'])): ?>
                                                    <small class="text-muted"><?= htmlspecialchars($c['name_ur']) ?></small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="py-3">
                                        <?php
                                        $section_colors = ['birds'=>'info','animals'=>'warning','accessories'=>'secondary','foods'=>'success','market'=>'primary'];
                                        $sc = $section_colors[$c['section']] ?? 'secondary';
                                        $text_class = in_array($sc, ['warning', 'info', 'light']) ? 'text-dark' : 'text-white';
                                        ?>
                                        <span class="badge bg-<?= $sc ?> <?= $text_class ?> rounded-pill px-3">
                                            <?= htmlspecialchars(ucfirst($c['section'] ?? '')) ?>
                                        </span>
                                    </td>
                                    <td class="py-3 text-center">
                                        <span class="badge bg-light text-dark border rounded-pill px-3"><?= (int)$c['product_count'] ?></span>
                                    </td>
                                    <td class="py-3 text-center">
                                        <?php if ($c['status'] === 'active'): ?>
                                            <span class="badge bg-success rounded-pill px-3">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary rounded-pill px-3">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="pe-4 py-3 text-end">
                                        <div class="dropdown">
                                            <button class="btn btn-light btn-sm rounded-circle border shadow-sm" type="button" data-bs-toggle="dropdown">
                                                <i class="bi bi-three-dots-vertical"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 rounded-3">
                                                <li>
                                                    <a class="dropdown-item edit-btn py-2" href="#"
                                                       data-category='<?= htmlspecialchars(json_encode($c), ENT_QUOTES) ?>'
                                                       data-bs-toggle="modal" data-bs-target="#editCategoryModal">
                                                        <i class="bi bi-pencil-square me-2 text-primary"></i>Edit
                                                    </a>
                                                </li>
                                                <?php if ($c['status'] === 'active'): ?>
                                                    <li>
                                                        <form method="POST">
                                                            <?php csrf_field(); ?>
                                                            <input type="hidden" name="action" value="disable">
                                                            <input type="hidden" name="category_id" value="<?= $c['id'] ?>">
                                                            <button class="dropdown-item py-2" type="submit">
                                                                <i class="bi bi-eye-slash me-2 text-warning"></i>Disable
                                                            </button>
                                                        </form>
                                                    </li>
                                                <?php else: ?>
                                                    <li>
                                                        <form method="POST">
                                                            <?php csrf_field(); ?>
                                                            <input type="hidden" name="action" value="enable">
                                                            <input type="hidden" name="category_id" value="<?= $c['id'] ?>">
                                                            <button class="dropdown-item py-2" type="submit">
                                                                <i class="bi bi-eye me-2 text-success"></i>Enable
                                                            </button>
                                                        </form>
                                                    </li>
                                                <?php endif; ?>
                                                <li><hr class="dropdown-divider my-1"></li>
                                                <li>
                                                    <form method="POST" onsubmit="return confirm('Delete this category?');">
                                                        <?php csrf_field(); ?>
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="category_id" value="<?= $c['id'] ?>">
                                                        <button class="dropdown-item text-danger py-2" type="submit">
                                                            <i class="bi bi-trash3 me-2"></i>Delete
                                                        </button>
                                                    </form>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center py-5">
                                    <i class="bi bi-tags fs-1 text-muted d-block mb-2"></i>
                                    <span class="text-muted">No categories yet. Add your first category.</span>
                                </td>
                            </tr>
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
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-0 bg-light rounded-top-4 px-4 pt-4 pb-3">
                <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle me-2 text-primary"></i>Add Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="categories.php" method="POST" enctype="multipart/form-data">
                <div class="modal-body px-4 py-3">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="add">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Name (English) <span class="text-danger">*</span></label>
                        <input type="text" class="form-control rounded-3 border-0 bg-light" name="name_en" required placeholder="e.g. Parrots">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Name (Urdu)</label>
                        <input type="text" class="form-control rounded-3 border-0 bg-light" name="name_ur" placeholder="اردو نام">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Section <span class="text-danger">*</span></label>
                        <select name="section" class="form-select rounded-3 border-0 bg-light" required>
                            <option value="birds">🦜 Birds</option>
                            <option value="animals">🐾 Animals</option>
                            <option value="accessories">🧰 Accessories</option>
                            <option value="foods">🌾 Foods</option>
                            <option value="market">🏪 Market</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Status</label>
                        <select name="status" class="form-select rounded-3 border-0 bg-light">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Category Image</label>
                        <input type="file" class="form-control rounded-3 border-0 bg-light" name="image" accept="image/*">
                        <div class="form-text">Recommended: 400×400px (JPG, PNG)</div>
                    </div>
                </div>
                <div class="modal-footer border-0 px-4 pb-4">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">Save Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Category Modal -->
<div class="modal fade" id="editCategoryModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-0 bg-light rounded-top-4 px-4 pt-4 pb-3">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2 text-primary"></i>Edit Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="categories.php" method="POST" enctype="multipart/form-data">
                <div class="modal-body px-4 py-3">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="category_id" id="edit_category_id">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Name (English) <span class="text-danger">*</span></label>
                        <input type="text" class="form-control rounded-3 border-0 bg-light" name="name_en" id="edit_name_en" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Name (Urdu)</label>
                        <input type="text" class="form-control rounded-3 border-0 bg-light" name="name_ur" id="edit_name_ur">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Section <span class="text-danger">*</span></label>
                        <select name="section" id="edit_section" class="form-select rounded-3 border-0 bg-light" required>
                            <option value="birds">🦜 Birds</option>
                            <option value="animals">🐾 Animals</option>
                            <option value="accessories">🧰 Accessories</option>
                            <option value="foods">🌾 Foods</option>
                            <option value="market">🏪 Market</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Status</label>
                        <select name="status" id="edit_status" class="form-select rounded-3 border-0 bg-light">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold small">Category Image</label>
                        <div class="mb-2" id="edit_img_wrap" style="display:none;">
                            <img id="edit_image_preview" src="" alt="Current" class="rounded-3 border shadow-sm" style="width:60px;height:60px;object-fit:cover;">
                            <small class="text-muted ms-2">Current image</small>
                        </div>
                        <input type="file" class="form-control rounded-3 border-0 bg-light" name="image" accept="image/*">
                        <div class="form-text">Leave blank to keep existing image.</div>
                    </div>
                </div>
                <div class="modal-footer border-0 px-4 pb-4">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">Update Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.edit-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const cat = JSON.parse(this.getAttribute('data-category'));
            document.getElementById('edit_category_id').value = cat.id;
            document.getElementById('edit_name_en').value = cat.name_en || '';
            document.getElementById('edit_name_ur').value = cat.name_ur || '';
            document.getElementById('edit_section').value = cat.section || 'birds';
            document.getElementById('edit_status').value = cat.status || 'active';

            const wrap = document.getElementById('edit_img_wrap');
            const img  = document.getElementById('edit_image_preview');
            if (cat.image) {
                img.src = '<?= BASE_URL ?>/assets/images/' + cat.image;
                wrap.style.display = 'block';
            } else {
                wrap.style.display = 'none';
            }
        });
    });
});
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
