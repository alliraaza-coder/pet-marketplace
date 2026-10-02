<?php
/**
 * Seller Profile
 * - Name, Email, Phone are LOCKED (read-only) after registration
 * - Profile picture can be changed at any time via camera or gallery
 * - Address/city/state/zip are fully editable
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';

// Require seller role
require_role('seller');

$user    = current_user($conn);
$user_id = $user['id'];
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? 'update_profile';

    // ── Update profile picture ─────────────────────────────────────────────
    if ($action === 'update_avatar') {
        $img_data = $_POST['avatar_data'] ?? '';
        if (empty($img_data)) {
            $_SESSION['error'] = "No image data received. Please try again.";
        } elseif (!preg_match('/^data:image\/(jpeg|png|webp);base64,/', $img_data, $m)) {
            $_SESSION['error'] = "Invalid image format. Use JPEG or PNG.";
        } else {
            $ext      = $m[1] === 'jpeg' ? 'jpg' : $m[1];
            $raw      = base64_decode(substr($img_data, strpos($img_data, ',') + 1));
            if (strlen($raw) > 3 * 1024 * 1024) {
                $_SESSION['error'] = "Image too large. Max 3 MB.";
            } else {
                $upload_dir = __DIR__ . '/../assets/images/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

                // Delete old profile pic if it's not the default
                if (!empty($user['profile_pic']) && $user['profile_pic'] !== 'default-user.png') {
                    $old = $upload_dir . $user['profile_pic'];
                    if (file_exists($old)) @unlink($old);
                }

                $filename = 'user_' . $user_id . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                file_put_contents($upload_dir . $filename, $raw);

                $upd = $conn->prepare("UPDATE users SET profile_pic = ? WHERE id = ?");
                $upd->bind_param("si", $filename, $user_id);
                $upd->execute();

                $_SESSION['success'] = "Profile picture updated successfully!";
                $user = current_user($conn);
            }
        }
        header("Location: profile.php");
        exit;
    }

    // ── Update profile (editable fields only) ─────────────────────────────
    $address  = sanitize_input($_POST['address']  ?? '');
    $city     = sanitize_input($_POST['city']     ?? '');
    $state    = sanitize_input($_POST['state']    ?? '');
    $zip_code = sanitize_input($_POST['zip_code'] ?? '');

    $upd = $conn->prepare("UPDATE users SET address=?, city=?, state=?, zip_code=? WHERE id=?");
    $upd->bind_param("ssssi", $address, $city, $state, $zip_code, $user_id);
    if ($upd->execute()) {
        $_SESSION['success'] = "Profile updated successfully!";
        $user = current_user($conn);
    } else {
        $error = "Failed to update profile. Please try again.";
    }
}

include '../includes/header.php';
?>

<style>
.avatar-wrapper { position: relative; display: inline-block; cursor: pointer; }
.avatar-wrapper img { width: 110px; height: 110px; object-fit: cover; }
.avatar-edit-btn {
    position: absolute; bottom: 4px; right: 4px;
    width: 32px; height: 32px; border-radius: 50%;
    background: #0d6efd; color: #fff; border: 2px solid #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: .85rem; box-shadow: 0 2px 6px rgba(0,0,0,.25);
    transition: transform .15s;
}
.avatar-edit-btn:hover { transform: scale(1.12); }
.locked-field { background: #f8f9fa; cursor: not-allowed; }
.lock-badge { font-size: .7rem; }
</style>

<div class="container-fluid py-4 px-4">
    <div class="row g-4">
        <!-- ─── Sidebar ────────────────────────────────────────── -->
        <div class="col-lg-2 mb-4 mb-lg-0">
            <?php include 'partials/sidebar.php'; ?>
        </div>

        <!-- ─── Main Content ────────────────────────────────────── -->
        <div class="col-lg-10">
            <?php display_messages(); ?>
            <?php if ($error): ?>
                <div class="alert alert-danger rounded-3"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= $error ?></div>
            <?php endif; ?>

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="fw-bold mb-1">
                        <i class="bi bi-person-gear text-primary me-2"></i>My Profile
                    </h3>
                    <p class="text-muted mb-0 small">Update your personal and contact information.</p>
                </div>
            </div>

            <!-- Profile Picture Card -->
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3"><i class="bi bi-camera me-2 text-primary"></i>Profile Picture</h5>
                    <div class="d-flex align-items-center gap-4 flex-wrap">
                        <div class="avatar-wrapper rounded-circle border border-3 border-success" onclick="openAvatarChoice()" title="Change profile picture">
                            <img src="<?= BASE_URL ?>/assets/images/<?= htmlspecialchars($user['profile_pic'] ?? 'default-user.png') ?>"
                                 onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($user['first_name'].' '.$user['last_name']) ?>&background=random'"
                                 alt="Profile" class="rounded-circle" id="mainAvatar">
                            <div class="avatar-edit-btn"><i class="bi bi-camera-fill"></i></div>
                        </div>
                        <div>
                            <p class="mb-1 fw-semibold">Click on your photo to change it</p>
                            <p class="text-muted small mb-2">You can take a new photo or upload from your gallery.<br>Max size: 3 MB. Supported: JPEG, PNG.</p>
                            <button class="btn btn-outline-primary btn-sm rounded-pill" onclick="openAvatarChoice()">
                                <i class="bi bi-camera me-1"></i> Change Photo
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Personal Info (Locked) -->
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white border-0 pt-4 pb-0 px-4">
                    <h5 class="fw-bold mb-0"><i class="bi bi-lock me-2 text-warning"></i>Personal Information
                        <span class="badge bg-warning text-dark ms-2 fw-normal lock-badge">Locked — cannot be changed</span>
                    </h5>
                    <p class="text-muted small mt-1 mb-0">These details were verified at registration and are permanently locked for security.</p>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium text-muted">First Name</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-lock-fill text-warning small"></i></span>
                                <input type="text" class="form-control locked-field" value="<?= htmlspecialchars($user['first_name']) ?>" readonly>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium text-muted">Last Name</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-lock-fill text-warning small"></i></span>
                                <input type="text" class="form-control locked-field" value="<?= htmlspecialchars($user['last_name']) ?>" readonly>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium text-muted">Email Address</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-lock-fill text-warning small"></i></span>
                                <input type="email" class="form-control locked-field" value="<?= htmlspecialchars($user['email']) ?>" readonly>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium text-muted">Phone Number</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-lock-fill text-warning small"></i></span>
                                <input type="tel" class="form-control locked-field" value="<?= htmlspecialchars($user['phone'] ?? 'Not provided') ?>" readonly>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Editable Fields -->
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-0 pt-4 pb-0 px-4">
                    <h5 class="fw-bold mb-0"><i class="bi bi-geo-alt me-2 text-success"></i>Business / Contact Address</h5>
                    <p class="text-muted small mt-1 mb-0">These details can be updated at any time.</p>
                </div>
                <div class="card-body p-4">
                    <form action="profile.php" method="POST">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="update_profile">
                        <div class="mb-3">
                            <label class="form-label fw-medium">Full Address</label>
                            <input type="text" class="form-control" name="address"
                                   value="<?= htmlspecialchars($user['address'] ?? '') ?>"
                                   placeholder="Street, Building, Area">
                        </div>
                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label class="form-label fw-medium">City</label>
                                <input type="text" class="form-control" name="city"
                                       value="<?= htmlspecialchars($user['city'] ?? '') ?>" placeholder="Karachi">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-medium">State / Province</label>
                                <input type="text" class="form-control" name="state"
                                       value="<?= htmlspecialchars($user['state'] ?? '') ?>" placeholder="Sindh">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-medium">Zip Code</label>
                                <input type="text" class="form-control" name="zip_code"
                                       value="<?= htmlspecialchars($user['zip_code'] ?? '') ?>" placeholder="75500">
                            </div>
                        </div>
                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary rounded-pill px-5 fw-bold shadow-sm">
                                <i class="bi bi-check2 me-1"></i>Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
        </div>
    </div>
</div>

<!-- Avatar Upload Choice Modal -->
<div class="modal fade" id="avatarChoiceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-body p-4 text-center">
                <h6 class="fw-bold mb-3"><i class="bi bi-camera me-1"></i>Update Profile Photo</h6>
                <div class="d-grid gap-2">
                    <button class="btn btn-primary rounded-3 fw-semibold" onclick="openAvatarCamera()">
                        <i class="bi bi-camera-fill me-2"></i>Use Camera
                    </button>
                    <button class="btn btn-outline-secondary rounded-3 fw-semibold" onclick="openAvatarGallery()">
                        <i class="bi bi-image me-2"></i>Choose from Gallery
                    </button>
                </div>
                <button class="btn btn-link text-muted mt-2 small" data-bs-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>

<!-- Camera Modal -->
<div class="modal fade" id="avatarCameraModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-camera me-2"></i>Take Selfie</h5>
                <button type="button" class="btn-close" onclick="stopAvatarCamera()" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <video id="avatarCameraFeed" autoplay playsinline muted class="w-100 rounded-3 bg-dark" style="max-height:320px;"></video>
                <canvas id="avatarCanvas" class="d-none"></canvas>
                <div id="avatarCameraError" class="alert alert-danger d-none mt-3 rounded-3 small">
                    <i class="bi bi-exclamation-triangle me-1"></i>Camera unavailable. Please allow camera permission or use gallery.
                </div>
                <div class="d-flex justify-content-end mt-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" onclick="switchAvatarCamera()">
                        <i class="bi bi-arrow-repeat me-1"></i>Switch Camera
                    </button>
                </div>
            </div>
            <div class="modal-footer border-0 justify-content-center pb-4">
                <button type="button" class="btn btn-primary rounded-pill px-5 fw-bold" onclick="takeAvatarSnap()">
                    <i class="bi bi-camera-fill me-2"></i>Capture
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Hidden form for avatar POST -->
<form id="avatarForm" action="profile.php" method="POST" class="d-none">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="update_avatar">
    <input type="hidden" name="avatar_data" id="avatarDataInput">
</form>

<!-- Hidden file input -->
<input type="file" id="avatarGalleryInput" accept="image/*" class="d-none">

<script>
let avatarStream = null;
let avatarFacing = 'user'; // front camera for selfies

function openAvatarChoice() {
    new bootstrap.Modal(document.getElementById('avatarChoiceModal')).show();
}

// ── Camera ─────────────────────────────────────────────
async function openAvatarCamera() {
    bootstrap.Modal.getInstance(document.getElementById('avatarChoiceModal'))?.hide();
    document.getElementById('avatarCameraError').classList.add('d-none');
    new bootstrap.Modal(document.getElementById('avatarCameraModal')).show();
    await startAvatarStream();
}

async function startAvatarStream() {
    if (avatarStream) avatarStream.getTracks().forEach(t => t.stop());
    try {
        avatarStream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: avatarFacing, width: { ideal: 640 }, height: { ideal: 640 } }
        });
        document.getElementById('avatarCameraFeed').srcObject = avatarStream;
    } catch(e) {
        document.getElementById('avatarCameraError').classList.remove('d-none');
    }
}

async function switchAvatarCamera() {
    avatarFacing = (avatarFacing === 'user') ? 'environment' : 'user';
    await startAvatarStream();
}

function takeAvatarSnap() {
    const video  = document.getElementById('avatarCameraFeed');
    const canvas = document.getElementById('avatarCanvas');
    const size   = Math.min(video.videoWidth, video.videoHeight);
    canvas.width = canvas.height = size;
    const ctx = canvas.getContext('2d');
    // Center-crop to square
    const ox = (video.videoWidth  - size) / 2;
    const oy = (video.videoHeight - size) / 2;
    ctx.drawImage(video, ox, oy, size, size, 0, 0, size, size);
    const dataUrl = canvas.toDataURL('image/jpeg', 0.88);
    applyAvatarPhoto(dataUrl);
    stopAvatarCamera();
    bootstrap.Modal.getInstance(document.getElementById('avatarCameraModal'))?.hide();
}

function stopAvatarCamera() {
    if (avatarStream) { avatarStream.getTracks().forEach(t => t.stop()); avatarStream = null; }
}
document.getElementById('avatarCameraModal').addEventListener('hidden.bs.modal', stopAvatarCamera);

// ── Gallery ─────────────────────────────────────────────
function openAvatarGallery() {
    bootstrap.Modal.getInstance(document.getElementById('avatarChoiceModal'))?.hide();
    document.getElementById('avatarGalleryInput').click();
}
document.getElementById('avatarGalleryInput').addEventListener('change', function() {
    const file = this.files[0];
    if (!file) return;
    if (file.size > 3 * 1024 * 1024) { alert('Image too large. Max 3 MB.'); return; }
    const reader = new FileReader();
    reader.onload = e => applyAvatarPhoto(e.target.result);
    reader.readAsDataURL(file);
    this.value = '';
});

// ── Apply & submit ────────────────────────────────────
function applyAvatarPhoto(dataUrl) {
    // Preview immediately
    document.getElementById('mainAvatar').src    = dataUrl;
    
    // Check if sidebar avatar exists before trying to update it
    const sidebarAvatar = document.getElementById('sidebarAvatar');
    if (sidebarAvatar) {
        sidebarAvatar.src = dataUrl;
    }
    
    // Submit
    document.getElementById('avatarDataInput').value = dataUrl;
    document.getElementById('avatarForm').submit();
}
</script>

<?php include '../includes/footer.php'; ?>
