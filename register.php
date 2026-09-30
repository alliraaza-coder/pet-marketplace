<?php
/**
 * Registration with KYC verification
 * - Buyer/Seller role selection
 * - ID card front/back + selfie upload (camera or gallery)
 * - Account pending admin approval
 * - Name, email, phone LOCKED after submission
 */
require_once 'includes/config.php';
require_once 'includes/auth.php';

redirect_if_logged_in();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $first_name = sanitize_input($_POST['first_name'] ?? '');
    $last_name  = sanitize_input($_POST['last_name']  ?? '');
    $email      = sanitize_input($_POST['email']      ?? '');
    $password   = $_POST['password'] ?? '';
    $confirm    = $_POST['confirm_password'] ?? '';
    $phone      = sanitize_input($_POST['phone'] ?? '');
    $role       = (isset($_POST['role']) && $_POST['role'] === 'seller') ? 'seller' : 'user';

    // ── Basic validation ─────────────────────────────────────────────────────
    if (empty($first_name) || empty($last_name) || empty($email) || empty($password)) {
        $error = "Please fill in all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    } elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters long.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (empty($phone)) {
        $error = "Phone number is required.";
    } elseif (empty($_POST['id_card_front_data']) || empty($_POST['id_card_back_data']) || empty($_POST['selfie_data'])) {
        $error = "All three photos (ID Card Front, ID Card Back & Selfie) are required.";
    } else {
        // ── Check email uniqueness ───────────────────────────────────────────
        $chk = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $chk->bind_param("s", $email);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $error = "This email address is already registered.";
        } else {

            // ── Save Base64 images ───────────────────────────────────────────
            $kyc_dir = __DIR__ . '/assets/images/kyc/';
            if (!is_dir($kyc_dir)) mkdir($kyc_dir, 0755, true);

            function save_base64_image($base64_data, $dir, $prefix) {
                // Strip header e.g. "data:image/jpeg;base64,"
                if (preg_match('/^data:image\/(\w+);base64,/', $base64_data, $matches)) {
                    $ext       = strtolower($matches[1]) === 'jpeg' ? 'jpg' : strtolower($matches[1]);
                    $img_data  = base64_decode(substr($base64_data, strpos($base64_data, ',') + 1));
                    if ($img_data === false) return null;
                    // Max 5 MB check
                    if (strlen($img_data) > 5 * 1024 * 1024) return null;
                    $filename  = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    file_put_contents($dir . $filename, $img_data);
                    return $filename;
                }
                return null;
            }

            $id_front = save_base64_image($_POST['id_card_front_data'], $kyc_dir, 'id_front');
            $id_back  = save_base64_image($_POST['id_card_back_data'],  $kyc_dir, 'id_back');
            $selfie   = save_base64_image($_POST['selfie_data'],         $kyc_dir, 'selfie');

            if (!$id_front || !$id_back || !$selfie) {
                $error = "Failed to save one or more photos. Please try again (max 5 MB each, JPEG/PNG only).";
            } else {
                $hashed_pwd = password_hash($password, PASSWORD_DEFAULT);
                $status     = 'pending';

                $ins = $conn->prepare("
                    INSERT INTO users 
                    (first_name, last_name, email, password, phone, role, status, id_card_front, id_card_back, selfie_photo)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $ins->bind_param("ssssssssss",
                    $first_name, $last_name, $email, $hashed_pwd,
                    $phone, $role, $status, $id_front, $id_back, $selfie
                );

                if ($ins->execute()) {
                    $new_id = $conn->insert_id;

                    // Notify admin
                    $n_title = "New Registration Request";
                    $n_msg   = "New user {$first_name} {$last_name} has requested to register as a " . ucfirst($role) . ".";
                    $n_link  = "user_approval.php?id={$new_id}";
                    $n = $conn->prepare("INSERT INTO admin_notifications (type, title, message, link) VALUES ('registration', ?, ?, ?)");
                    $n->bind_param("sss", $n_title, $n_msg, $n_link);
                    $n->execute();

                    $_SESSION['success'] = "Your registration has been submitted! Your account is pending admin approval. We will notify you soon.";
                    header('Location: login.php');
                    exit;
                } else {
                    $error = "Registration failed. Please try again later.";
                }
            }
        }
    }
}

include 'includes/header.php';
?>

<style>
/* ── KYC Photo Upload Styles ─── */
.kyc-upload-box {
    border: 2.5px dashed #dee2e6;
    border-radius: 16px;
    background: #f8f9fa;
    cursor: pointer;
    transition: all .25s ease;
    min-height: 200px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    position: relative;
    overflow: hidden;
}
.kyc-upload-box:hover { border-color: #0d6efd; background: #eef3ff; }
.kyc-upload-box.has-image { border-style: solid; border-color: #198754; }
.kyc-upload-box .preview-img {
    width: 100%; height: 200px;
    object-fit: cover;
    border-radius: 13px;
    display: none;
}
.kyc-upload-box.has-image .placeholder-content { display: none; }
.kyc-upload-box.has-image .preview-img { display: block; }
.kyc-upload-box .remove-btn {
    position: absolute; top: 8px; right: 8px;
    background: rgba(220,53,69,.9);
    border: none; border-radius: 50%;
    width: 28px; height: 28px;
    color: #fff; font-size: .75rem;
    display: none; align-items: center; justify-content: center;
    z-index: 5; cursor: pointer;
}
.kyc-upload-box.has-image .remove-btn { display: flex; }

/* Camera modal */
#cameraFeed { width: 100%; border-radius: 12px; max-height: 340px; }
.snap-btn { 
    width: 64px; height: 64px; border-radius: 50%;
    background: #fff; border: 4px solid #0d6efd;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.6rem; cursor: pointer; transition: transform .15s;
}
.snap-btn:hover { transform: scale(1.1); }

.alert-kyc-lock {
    background: linear-gradient(135deg,#fff3cd,#ffe69c);
    border-left: 5px solid #ffc107;
    border-radius: 12px;
    padding: 16px 20px;
}
</style>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-9 col-lg-8">
            <div class="card border-0 shadow-lg rounded-4">
                <div class="card-body p-4 p-md-5">
                    <div class="text-center mb-4">
                        <i class="bi bi-person-vcard fs-1 text-primary"></i>
                        <h2 class="fw-bold mt-2">Create Your Account</h2>
                        <p class="text-muted">Join PetMarket — complete KYC verification to get started</p>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger rounded-3"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= $error ?></div>
                    <?php endif; ?>

                    <!-- LOCKED FIELDS WARNING -->
                    <div class="alert-kyc-lock mb-4">
                        <div class="d-flex gap-2 align-items-start">
                            <i class="bi bi-lock-fill text-warning fs-5 mt-1"></i>
                            <div>
                                <strong>Important Notice:</strong>
                                <ul class="mb-0 mt-1 small">
                                    <li>Enter your <strong>full legal name exactly as it appears</strong> on your <strong>CNIC / Passport</strong>.</li>
                                    <li>Your <strong>Name, Email, and Phone Number cannot be changed</strong> after registration.</li>
                                    <li>All three identity photos are <strong>required</strong> for account verification.</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <form action="register.php" method="POST" id="regForm">
                        <?php csrf_field(); ?>
                        <!-- Hidden base64 image fields -->
                        <input type="hidden" name="id_card_front_data" id="id_card_front_data">
                        <input type="hidden" name="id_card_back_data"  id="id_card_back_data">
                        <input type="hidden" name="selfie_data"         id="selfie_data">

                        <!-- ── Role Selection ── -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold d-block">I want to register as: <span class="text-danger">*</span></label>
                            <div class="row g-3">
                                <div class="col-6">
                                    <input type="radio" class="btn-check" name="role" id="role_buyer" value="user" checked>
                                    <label class="btn btn-outline-primary w-100 py-3 rounded-3 d-flex flex-column align-items-center gap-1" for="role_buyer">
                                        <i class="bi bi-cart-heart fs-4"></i>
                                        <strong>Buyer</strong>
                                        <small class="text-muted fw-normal">Browse &amp; purchase pets</small>
                                    </label>
                                </div>
                                <div class="col-6">
                                    <input type="radio" class="btn-check" name="role" id="role_seller" value="seller">
                                    <label class="btn btn-outline-success w-100 py-3 rounded-3 d-flex flex-column align-items-center gap-1" for="role_seller">
                                        <i class="bi bi-shop fs-4"></i>
                                        <strong>Seller</strong>
                                        <small class="text-muted fw-normal">List &amp; sell pets</small>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <hr class="opacity-25 my-4">
                        <h6 class="fw-bold text-dark mb-3"><i class="bi bi-person-lines-fill me-2 text-primary"></i>Personal Information <small class="text-danger">(Locked after submission)</small></h6>

                        <!-- Name Row -->
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-medium">First Name <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-lock-fill text-warning small"></i></span>
                                    <input type="text" class="form-control" name="first_name" required placeholder="As on CNIC / Passport"
                                           value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-medium">Last Name <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-lock-fill text-warning small"></i></span>
                                    <input type="text" class="form-control" name="last_name" required placeholder="As on CNIC / Passport"
                                           value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Email -->
                        <div class="mb-3">
                            <label class="form-label fw-medium">Email Address <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-lock-fill text-warning small"></i></span>
                                <input type="email" class="form-control" name="email" required placeholder="your@email.com"
                                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                            </div>
                        </div>

                        <!-- Phone -->
                        <div class="mb-3">
                            <label class="form-label fw-medium">Phone Number <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-lock-fill text-warning small"></i></span>
                                <input type="tel" class="form-control" name="phone" required placeholder="03XX-XXXXXXX"
                                       value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                            </div>
                        </div>

                        <!-- Password Row -->
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label fw-medium">Password <span class="text-danger">*</span></label>
                                <input type="password" class="form-control" name="password" required minlength="8" placeholder="Min. 8 characters">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-medium">Confirm Password <span class="text-danger">*</span></label>
                                <input type="password" class="form-control" name="confirm_password" required minlength="8" placeholder="Re-enter password">
                            </div>
                        </div>

                        <hr class="opacity-25 my-4">
                        <h6 class="fw-bold text-dark mb-1"><i class="bi bi-camera-fill me-2 text-primary"></i>Identity Verification (KYC Photos)</h6>
                        <p class="text-muted small mb-3">Take photos using your camera <strong>or</strong> upload from your gallery. Max 5 MB each.</p>

                        <div class="row g-4 mb-4">
                            <!-- ID Card Front -->
                            <div class="col-md-4">
                                <label class="form-label fw-semibold text-center d-block mb-2">
                                    <i class="bi bi-card-heading me-1 text-primary"></i>CNIC / Passport Front <span class="text-danger">*</span>
                                </label>
                                <div class="kyc-upload-box" id="box_front" onclick="openUpload('front')">
                                    <div class="placeholder-content text-center p-3">
                                        <i class="bi bi-card-front fs-2 text-muted mb-2 d-block"></i>
                                        <span class="text-muted small">Tap to capture or upload</span>
                                        <div class="d-flex gap-2 justify-content-center mt-2">
                                            <span class="badge bg-light text-dark border"><i class="bi bi-camera me-1"></i>Camera</span>
                                            <span class="badge bg-light text-dark border"><i class="bi bi-image me-1"></i>Gallery</span>
                                        </div>
                                    </div>
                                    <img class="preview-img" id="prev_front" alt="ID Front Preview">
                                    <button type="button" class="remove-btn" onclick="removePhoto(event,'front')"><i class="bi bi-x"></i></button>
                                </div>
                            </div>
                            <!-- ID Card Back -->
                            <div class="col-md-4">
                                <label class="form-label fw-semibold text-center d-block mb-2">
                                    <i class="bi bi-card-text me-1 text-primary"></i>CNIC / Passport Back <span class="text-danger">*</span>
                                </label>
                                <div class="kyc-upload-box" id="box_back" onclick="openUpload('back')">
                                    <div class="placeholder-content text-center p-3">
                                        <i class="bi bi-card-back fs-2 text-muted mb-2 d-block"></i>
                                        <span class="text-muted small">Tap to capture or upload</span>
                                        <div class="d-flex gap-2 justify-content-center mt-2">
                                            <span class="badge bg-light text-dark border"><i class="bi bi-camera me-1"></i>Camera</span>
                                            <span class="badge bg-light text-dark border"><i class="bi bi-image me-1"></i>Gallery</span>
                                        </div>
                                    </div>
                                    <img class="preview-img" id="prev_back" alt="ID Back Preview">
                                    <button type="button" class="remove-btn" onclick="removePhoto(event,'back')"><i class="bi bi-x"></i></button>
                                </div>
                            </div>
                            <!-- Selfie -->
                            <div class="col-md-4">
                                <label class="form-label fw-semibold text-center d-block mb-2">
                                    <i class="bi bi-person-circle me-1 text-primary"></i>Live Selfie <span class="text-danger">*</span>
                                </label>
                                <div class="kyc-upload-box" id="box_selfie" onclick="openUpload('selfie')">
                                    <div class="placeholder-content text-center p-3">
                                        <i class="bi bi-person-bounding-box fs-2 text-muted mb-2 d-block"></i>
                                        <span class="text-muted small">Tap to capture or upload</span>
                                        <div class="d-flex gap-2 justify-content-center mt-2">
                                            <span class="badge bg-light text-dark border"><i class="bi bi-camera me-1"></i>Camera</span>
                                            <span class="badge bg-light text-dark border"><i class="bi bi-image me-1"></i>Gallery</span>
                                        </div>
                                    </div>
                                    <img class="preview-img" id="prev_selfie" alt="Selfie Preview">
                                    <button type="button" class="remove-btn" onclick="removePhoto(event,'selfie')"><i class="bi bi-x"></i></button>
                                </div>
                            </div>
                        </div>

                        <!-- Terms -->
                        <div class="mb-4 form-check">
                            <input type="checkbox" class="form-check-input" id="terms" required>
                            <label class="form-check-label text-muted small" for="terms">
                                I agree to the <a href="terms.php" class="text-primary">Terms &amp; Conditions</a> and <a href="privacy.php" class="text-primary">Privacy Policy</a>. I confirm that the information and photos provided are genuine.
                            </label>
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary btn-lg rounded-pill fw-bold" id="submitBtn">
                                <i class="bi bi-send me-2"></i>Submit Registration Request
                            </button>
                        </div>
                    </form>

                    <div class="text-center mt-4 pt-3 border-top">
                        <p class="mb-0 text-muted">Already have an account? <a href="login.php" class="text-primary fw-bold text-decoration-none">Login here</a></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Upload Choice Modal -->
<div class="modal fade" id="uploadChoiceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-body p-4 text-center">
                <h6 class="fw-bold mb-3" id="uploadChoiceTitle">Choose Method</h6>
                <div class="d-grid gap-2">
                    <button class="btn btn-primary rounded-3 fw-semibold" onclick="openCamera()">
                        <i class="bi bi-camera-fill me-2"></i>Use Camera
                    </button>
                    <button class="btn btn-outline-secondary rounded-3 fw-semibold" onclick="openGallery()">
                        <i class="bi bi-image me-2"></i>Choose from Gallery
                    </button>
                </div>
                <button class="btn btn-link text-muted mt-2 small" data-bs-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>

<!-- Camera Modal -->
<div class="modal fade" id="cameraModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold" id="cameraModalTitle"><i class="bi bi-camera me-2"></i>Take Photo</h5>
                <button type="button" class="btn-close" onclick="stopCamera()" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <video id="cameraFeed" autoplay playsinline muted class="bg-dark rounded-3"></video>
                <canvas id="snapCanvas" class="d-none"></canvas>
                <div id="cameraError" class="alert alert-danger d-none mt-3 rounded-3">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    Camera access denied or unavailable. Please allow camera permissions or use gallery upload.
                </div>
                <!-- Switch camera button for mobile -->
                <div class="d-flex justify-content-end mt-2">
                    <button class="btn btn-sm btn-outline-secondary rounded-pill" type="button" onclick="switchCamera()">
                        <i class="bi bi-arrow-repeat me-1"></i>Switch Camera
                    </button>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0 justify-content-center pb-4">
                <button type="button" class="snap-btn" title="Take Photo" onclick="takeSnapshot()">
                    <i class="bi bi-camera-fill text-primary"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Hidden file input for gallery -->
<input type="file" id="galleryInput" accept="image/*" class="d-none">

<script>
let currentField  = null;   // 'front' | 'back' | 'selfie'
let cameraStream  = null;
let facingMode    = 'environment'; // default rear camera

const labels = { front: 'ID Card Front', back: 'ID Card Back', selfie: 'Selfie' };

/* ─── Open upload choice modal ─────────────────────── */
function openUpload(field) {
    currentField = field;
    const box = document.getElementById('box_' + field);
    if (box.classList.contains('has-image')) return; // already has image, ignore click
    document.getElementById('uploadChoiceTitle').textContent = 'Upload ' + labels[field];
    new bootstrap.Modal(document.getElementById('uploadChoiceModal')).show();
}

/* ─── Camera ────────────────────────────────────────── */
async function openCamera() {
    bootstrap.Modal.getInstance(document.getElementById('uploadChoiceModal'))?.hide();
    document.getElementById('cameraModalTitle').textContent = '📷  ' + labels[currentField];
    document.getElementById('cameraError').classList.add('d-none');
    const camModal = new bootstrap.Modal(document.getElementById('cameraModal'));
    camModal.show();

    await startCameraStream();
}

async function startCameraStream() {
    if (cameraStream) { cameraStream.getTracks().forEach(t => t.stop()); }
    try {
        cameraStream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: facingMode, width: { ideal: 1280 }, height: { ideal: 720 } }
        });
        document.getElementById('cameraFeed').srcObject = cameraStream;
    } catch (e) {
        document.getElementById('cameraError').classList.remove('d-none');
    }
}

async function switchCamera() {
    facingMode = (facingMode === 'environment') ? 'user' : 'environment';
    await startCameraStream();
}

function takeSnapshot() {
    const video  = document.getElementById('cameraFeed');
    const canvas = document.getElementById('snapCanvas');
    canvas.width  = video.videoWidth  || 640;
    canvas.height = video.videoHeight || 480;
    canvas.getContext('2d').drawImage(video, 0, 0);
    const dataUrl = canvas.toDataURL('image/jpeg', 0.85);
    applyPhoto(dataUrl);
    stopCamera();
    bootstrap.Modal.getInstance(document.getElementById('cameraModal'))?.hide();
}

function stopCamera() {
    if (cameraStream) { cameraStream.getTracks().forEach(t => t.stop()); cameraStream = null; }
}

/* ─── Gallery ───────────────────────────────────────── */
function openGallery() {
    bootstrap.Modal.getInstance(document.getElementById('uploadChoiceModal'))?.hide();
    document.getElementById('galleryInput').click();
}

document.getElementById('galleryInput').addEventListener('change', function() {
    const file = this.files[0];
    if (!file) return;
    if (file.size > 5 * 1024 * 1024) { alert('File is too large. Max 5 MB.'); return; }
    const reader = new FileReader();
    reader.onload = e => applyPhoto(e.target.result);
    reader.readAsDataURL(file);
    this.value = ''; // reset so same file can be re-selected
});

/* ─── Apply photo to the correct field ─────────────── */
function applyPhoto(dataUrl) {
    const hiddenInput = document.getElementById(fieldKey(currentField));
    const preview     = document.getElementById('prev_' + currentField);
    const box         = document.getElementById('box_' + currentField);

    hiddenInput.value = dataUrl;
    preview.src       = dataUrl;
    box.classList.add('has-image');
}

function fieldKey(f) {
    return { front: 'id_card_front_data', back: 'id_card_back_data', selfie: 'selfie_data' }[f];
}

/* ─── Remove photo ──────────────────────────────────── */
function removePhoto(event, field) {
    event.stopPropagation();
    document.getElementById(fieldKey(field)).value = '';
    document.getElementById('prev_' + field).src   = '';
    document.getElementById('box_' + field).classList.remove('has-image');
}

/* ─── Stop camera when modal is closed ─────────────── */
document.getElementById('cameraModal').addEventListener('hidden.bs.modal', stopCamera);

/* ─── Form submission validation ───────────────────── */
document.getElementById('regForm').addEventListener('submit', function(e) {
    const front  = document.getElementById('id_card_front_data').value;
    const back   = document.getElementById('id_card_back_data').value;
    const selfie = document.getElementById('selfie_data').value;

    if (!front || !back || !selfie) {
        e.preventDefault();
        alert('⚠️ All three photos are required:\n1. CNIC/Passport Front\n2. CNIC/Passport Back\n3. Selfie');
    }
});
</script>

<?php include 'includes/footer.php'; ?>
